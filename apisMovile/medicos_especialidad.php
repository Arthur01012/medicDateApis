<?php
header("Content-Type: application/json; charset=UTF-8");
require_once "config/conexion.php";

$idEspecialidad = isset($_GET["idEspecialidad"]) ? intval($_GET["idEspecialidad"]) : 0;

if ($idEspecialidad <= 0) {
    http_response_code(400);
    echo json_encode([
        "success" => false,
        "mensaje" => "Debes enviar un idEspecialidad válido."
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    // Comprobar que la especialidad exista  y esté activa.
    $stmt = $pdo->prepare("
        SELECT id_especialidad, nombre
        FROM especialidad
        WHERE id_especialidad = ? AND activo = 1
        LIMIT 1
    ");
    $stmt->execute([$idEspecialidad]);
    $especialidad = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$especialidad) {
        http_response_code(404);
        echo json_encode([
            "success" => false,
            "mensaje" => "No se encontró la especialidad o está inactiva."
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // Obtener los médicos activos que pertenecen a esa especialidad.
    $stmt = $pdo->prepare("
        SELECT
            d.id_empleado,
            CONCAT_WS(' ', p.nombres, p.apellido_paterno, p.apellido_materno) AS nombre,
            d.cedula_profesional
        FROM doctor d
        INNER JOIN empleado em ON em.id_empleado = d.id_empleado
        INNER JOIN persona p ON p.id_persona = em.id_persona
        INNER JOIN doctor_especialidad de ON de.id_empleado = d.id_empleado
        WHERE de.id_especialidad = ?
          AND em.estado = 'activo'
        ORDER BY p.apellido_paterno, p.nombres
    ");
    $stmt->execute([$idEspecialidad]);
    $medicos = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Consultas preparadas para revisar conflictos de citas y bloqueos.
    $stmtConflictoCita = $pdo->prepare("
        SELECT COUNT(*)
        FROM cita
        WHERE estado IN ('pendiente', 'confirmada', 'en_progreso')
          AND inicio < ?
          AND fin > ?
          AND (
              id_empleado = ?
              OR (? IS NOT NULL AND id_consultorio = ?)
          )
    ");

    $stmtConflictoBloqueo = $pdo->prepare("
        SELECT COUNT(*)
        FROM bloqueo_horario
        WHERE inicio < ?
          AND fin > ?
          AND (
              id_empleado = ?
              OR (? IS NOT NULL AND id_consultorio = ?)
          )
    ");

    // La duración se toma del tipo de atención activo de la especialidad.
    $stmtDuracion = $pdo->prepare("
        SELECT MIN(duracion_minutos)
        FROM tipo_atencion
        WHERE id_especialidad = ? AND activo = 1
    ");
    $stmtDuracion->execute([$idEspecialidad]);
    $duracion = (int)$stmtDuracion->fetchColumn();

    $stmtHorarios = $pdo->prepare("
        SELECT
            h.id_horario,
            h.dia_semana,
            h.hora_inicio,
            h.hora_fin,
            h.intervalo_minutos,
            h.vigencia_desde,
            h.vigencia_hasta,
            h.id_consultorio,
            c.codigo AS consultorio
        FROM horario_atencion h
        LEFT JOIN consultorio c ON c.id_consultorio = h.id_consultorio
        WHERE h.id_empleado = ?
          AND h.activo = 1
          AND (h.id_consultorio IS NULL OR c.activo = 1)
        ORDER BY h.dia_semana, h.hora_inicio
    ");

    $hoy = new DateTime("today");
    $ahora = new DateTime();
    $limite = (clone $hoy)->modify("+30 days");

    foreach ($medicos as &$medico) {
        $idEmpleado = (int)$medico["id_empleado"];
        $stmtHorarios->execute([$idEmpleado]);
        $horarios = $stmtHorarios->fetchAll(PDO::FETCH_ASSOC);

        foreach ($horarios as &$horario) {
            $horario["id_horario"] = (int)$horario["id_horario"];
            $horario["dia_semana"] = (int)$horario["dia_semana"];
            $horario["intervalo_minutos"] = (int)$horario["intervalo_minutos"];
            if ($horario["id_consultorio"] !== null) {
                $horario["id_consultorio"] = (int)$horario["id_consultorio"];
            }
        }
        unset($horario);

        $proximoHorario = null;
        $minutosDuracion = $duracion > 0 ? $duracion : 30;

        // Buscar el primer espacio que no se cruce con citas ni bloqueos.
        for ($fecha = clone $hoy; $fecha <= $limite && $proximoHorario === null; $fecha->modify("+1 day")) {
            $diaSemana = (int)$fecha->format("N");

            foreach ($horarios as $horario) {
                if ((int)$horario["dia_semana"] !== $diaSemana) {
                    continue;
                }

                if ($fecha->format("Y-m-d") < $horario["vigencia_desde"]) {
                    continue;
                }

                if (!empty($horario["vigencia_hasta"]) &&
                    $fecha->format("Y-m-d") > $horario["vigencia_hasta"]) {
                    continue;
                }

                $inicioHorario = new DateTime($fecha->format("Y-m-d") . " " . $horario["hora_inicio"]);
                $finHorario = new DateTime($fecha->format("Y-m-d") . " " . $horario["hora_fin"]);

                $intervalo = max(1, (int)$horario["intervalo_minutos"]);
                $candidato = clone $inicioHorario;

                if ($fecha->format("Y-m-d") === $ahora->format("Y-m-d") && $candidato < $ahora) {
                    $candidato = clone $ahora;
                    $minutosDesdeInicio = (int)floor(
                        ($candidato->getTimestamp() - $inicioHorario->getTimestamp()) / 60
                    );
                    $saltos = (int)ceil($minutosDesdeInicio / $intervalo);
                    $candidato = clone $inicioHorario;
                    $candidato->modify("+" . ($saltos * $intervalo) . " minutes");
                }

                while (true) {
                    $finCandidato = (clone $candidato)->modify("+" . $minutosDuracion . " minutes");
                    if ($finCandidato > $finHorario) {
                        break;
                    }

                    $inicioTexto = $candidato->format("Y-m-d H:i:s");
                    $finTexto = $finCandidato->format("Y-m-d H:i:s");
                    $idConsultorio = $horario["id_consultorio"];

                    $stmtConflictoCita->execute([
                        $finTexto, $inicioTexto, $idEmpleado,
                        $idConsultorio, $idConsultorio
                    ]);
                    $hayCita = (int)$stmtConflictoCita->fetchColumn() > 0;

                    $stmtConflictoBloqueo->execute([
                        $finTexto, $inicioTexto, $idEmpleado,
                        $idConsultorio, $idConsultorio
                    ]);
                    $hayBloqueo = (int)$stmtConflictoBloqueo->fetchColumn() > 0;

                    if (!$hayCita && !$hayBloqueo && $candidato >= $ahora) {
                        $proximoHorario = [
                            "fecha" => $candidato->format("Y-m-d"),
                            "hora" => $candidato->format("H:i"),
                            "inicio" => $inicioTexto,
                            "fin" => $finTexto,
                            "consultorio" => $horario["consultorio"]
                        ];
                        break;
                    }

                    $candidato->modify("+" . $intervalo . " minutes");
                }

                if ($proximoHorario !== null) {
                    break;
                }
            }
        }

        $medico["id_empleado"] = $idEmpleado;
        $medico["horarios"] = $horarios;
        $medico["proximo_horario"] = $proximoHorario;
    }
    unset($medico);

    echo json_encode([
        "success" => true,
        "especialidad" => $especialidad,
        "medicos" => $medicos
    ], JSON_UNESCAPED_UNICODE);

} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode([
        "success" => false,
        "mensaje" => "Error al obtener los médicos de la especialidad.",
        "error" => $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
}
?>
