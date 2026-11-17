<?php
require_once __DIR__ . "/citas_api.php";

if ($_SERVER["REQUEST_METHOD"] !== "GET") {
    header("Allow: GET");
    citasApiResponder(405, [
        "exito" => false,
        "codigo" => "METODO_NO_PERMITIDO",
        "mensaje" => "Para consultar la disponibilidad, envía una solicitud GET."
    ]);
}

$idEmpleado = citasApiId($_GET["id_empleado"] ?? null);
$idTipoAtencion = citasApiId($_GET["id_tipo_atencion"] ?? null);
$fecha = $_GET["fecha"] ?? null;

if ($idEmpleado === null || $idTipoAtencion === null || !citasApiFechaValida($fecha)) {
    citasApiResponder(400, [
        "exito" => false,
        "codigo" => "FILTROS_INVALIDOS",
        "mensaje" => "Indica un médico, un tipo de atención y una fecha válida (AAAA-MM-DD)."
    ]);
}

if ((int) DateTimeImmutable::createFromFormat("!Y-m-d", $fecha)->format("N") >= 6) {
    citasApiResponder(422, [
        "exito" => false,
        "codigo" => "DIA_SIN_ATENCION",
        "mensaje" => "No hay citas disponibles los fines de semana. Selecciona un día de lunes a viernes."
    ]);
}

require_once __DIR__ . "/config/conexion.php";

try {
    $hoy = $pdo->query("SELECT CURDATE()")->fetchColumn();
    if ($fecha <= $hoy) {
        citasApiResponder(422, [
            "exito" => false,
            "codigo" => "ANTICIPACION_MINIMA",
            "mensaje" => "La disponibilidad se consulta para fechas posteriores a hoy. Elige otro día."
        ]);
    }

    $consultaServicio = $pdo->prepare(
        "SELECT ta.id_tipo_atencion, ta.nombre AS tipo_atencion,
                ta.duracion_minutos,
                CONCAT_WS(' ', p.nombres, p.apellido_paterno, p.apellido_materno) AS medico,
                e.nombre AS especialidad
         FROM tipo_atencion ta
         INNER JOIN doctor d ON d.id_empleado = ?
         INNER JOIN empleado em ON em.id_empleado = d.id_empleado
         INNER JOIN persona p ON p.id_persona = em.id_persona
         LEFT JOIN especialidad e ON e.id_especialidad = ta.id_especialidad
         WHERE ta.id_tipo_atencion = ? AND ta.activo = 1 AND em.estado = 'activo'
           AND (ta.id_especialidad IS NULL OR (
                e.activo = 1
                AND EXISTS (
                    SELECT 1 FROM doctor_especialidad de
                    WHERE de.id_empleado = d.id_empleado
                      AND de.id_especialidad = ta.id_especialidad
                )
           ))
         LIMIT 1"
    );
    $consultaServicio->execute([$idEmpleado, $idTipoAtencion]);
    $servicio = $consultaServicio->fetch();
    if (!$servicio) {
        citasApiResponder(404, [
            "exito" => false,
            "codigo" => "SERVICIO_NO_DISPONIBLE",
            "mensaje" => "El médico o tipo de atención seleccionado no está disponible. Actualiza la información."
        ]);
    }

    $diaSemana = (int) DateTimeImmutable::createFromFormat("!Y-m-d", $fecha)->format("N");
    $consultaHorarios = $pdo->prepare(
        "SELECT h.id_consultorio, h.hora_inicio, h.hora_fin,
                h.intervalo_minutos, co.nombre AS consultorio
         FROM horario_atencion h
         LEFT JOIN consultorio co ON co.id_consultorio = h.id_consultorio
         WHERE h.id_empleado = ? AND h.dia_semana = ? AND h.activo = 1
           AND h.vigencia_desde <= ?
           AND (h.vigencia_hasta IS NULL OR h.vigencia_hasta >= ?)
           AND (h.id_consultorio IS NULL OR co.activo = 1)
         ORDER BY h.hora_inicio, h.id_consultorio"
    );
    $consultaHorarios->execute([$idEmpleado, $diaSemana, $fecha, $fecha]);
    $horarios = $consultaHorarios->fetchAll();

    $consulta = $pdo->prepare(
        "SELECT
            EXISTS (
                SELECT 1 FROM cita c
                WHERE c.id_empleado = ?
                  AND c.estado IN ('pendiente', 'confirmada', 'en_progreso')
                  AND c.inicio < ? AND c.fin > ?
            ) AS medico_ocupado,
            EXISTS (
                SELECT 1 FROM cita c
                WHERE c.id_consultorio = ?
                  AND c.estado IN ('pendiente', 'confirmada', 'en_progreso')
                  AND c.inicio < ? AND c.fin > ?
            ) AS consultorio_ocupado,
            EXISTS (
                SELECT 1 FROM bloqueo_horario b
                WHERE b.id_empleado = ?
                  AND b.inicio < ? AND b.fin > ?
            ) AS medico_bloqueado,
            EXISTS (
                SELECT 1 FROM bloqueo_horario b
                WHERE b.id_consultorio = ?
                  AND b.inicio < ? AND b.fin > ?
            ) AS consultorio_bloqueado"
    );

    $slots = [];
    $vistos = [];
    $duracion = (int) $servicio["duracion_minutos"];
    foreach ($horarios as $horario) {
        $intervalo = (int) $horario["intervalo_minutos"];
        if ($duracion <= 0 || $intervalo <= 0) {
            continue;
        }

        $cursor = new DateTimeImmutable($fecha . " " . $horario["hora_inicio"]);
        $limite = new DateTimeImmutable($fecha . " " . $horario["hora_fin"]);
        while ($cursor < $limite) {
            $finSlot = $cursor->modify("+" . $duracion . " minutes");
            if ($finSlot > $limite) {
                break;
            }

            $inicio = $cursor->format("Y-m-d H:i:s");
            $fin = $finSlot->format("Y-m-d H:i:s");
            $idConsultorio = $horario["id_consultorio"] === null
                ? null
                : (int) $horario["id_consultorio"];
            $clave = $inicio . "|" . ($idConsultorio === null ? "null" : $idConsultorio);

            if (!isset($vistos[$clave])) {
                $consulta->execute([
                    $idEmpleado, $fin, $inicio,
                    $idConsultorio, $fin, $inicio,
                    $idEmpleado, $fin, $inicio,
                    $idConsultorio, $fin, $inicio
                ]);
                $resultado = $consulta->fetch();
                $disponible = !(
                    $resultado["medico_ocupado"]
                    || $resultado["consultorio_ocupado"]
                    || $resultado["medico_bloqueado"]
                    || $resultado["consultorio_bloqueado"]
                );
                $slot = [
                    "inicio" => $inicio,
                    "fin" => $fin,
                    "hora" => $cursor->format("H:i"),
                    "duracion_minutos" => $duracion,
                    "id_consultorio" => $idConsultorio,
                    "consultorio" => $horario["consultorio"],
                    "disponible" => $disponible
                ];

                if (!$disponible) {
                    $slot["codigo"] = "HORARIO_OCUPADO";
                    $slot["mensaje"] = "Este horario ya no está disponible. Elige otro horario.";
                }

                $slots[] = $slot;
                $vistos[$clave] = true;
            }

            $cursor = $cursor->modify("+" . $intervalo . " minutes");
        }
    }

    usort($slots, function ($a, $b) {
        if ($a["inicio"] === $b["inicio"]) {
            return ($a["id_consultorio"] ?? 0) <=> ($b["id_consultorio"] ?? 0);
        }
        return strcmp($a["inicio"], $b["inicio"]);
    });

    $disponibles = count(array_filter($slots, function ($slot) {
        return $slot["disponible"];
    }));

    citasApiResponder(200, [
        "exito" => true,
        "mensaje" => $disponibles > 0
            ? "Selecciona un horario disponible."
            : "No hay horarios disponibles para esta fecha. Elige otro día.",
        "fecha" => $fecha,
        "medico" => $servicio["medico"],
        "tipo_atencion" => $servicio["tipo_atencion"],
        "especialidad" => $servicio["especialidad"],
        "duracion_minutos" => $duracion,
        "horarios" => $slots
    ]);
} catch (PDOException $e) {
    error_log("Error al consultar disponibilidad: " . $e->getMessage());
    citasApiResponder(500, [
        "exito" => false,
        "codigo" => "ERROR_DISPONIBILIDAD",
        "mensaje" => "No se pudo consultar la disponibilidad. Inténtalo de nuevo."
    ]);
} catch (Exception $e) {
    error_log("Error al consultar disponibilidad: " . $e->getMessage());
    citasApiResponder(500, [
        "exito" => false,
        "codigo" => "ERROR_DISPONIBILIDAD",
        "mensaje" => "No se pudo consultar la disponibilidad. Inténtalo de nuevo."
    ]);
}