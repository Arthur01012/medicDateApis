<?php
require_once __DIR__ . "/citas_api.php";

$metodo = $_SERVER["REQUEST_METHOD"] ?? "GET";
if ($metodo === "POST") {
    $datos = citasApiLeerEntrada();
} else {
    $datos = citasApiLeerDatosQuery();
}
$idEmpleado = citasApiId($datos["id_empleado"] ?? null);
$idTipoAtencion = citasApiId($datos["id_tipo_atencion"] ?? null);
$idUsuario = citasApiId($datos["id_usuario"] ?? null);
$fecha = $datos["fecha"] ?? null;

if (!citasApiFechaValida($fecha)) {
    citasApiResponder(422, [
        "exito" => false,
        "codigo" => "PARAMETROS_INVALIDOS",
        "mensaje" => "Incluye una fecha válida (AAAA-MM-DD) dentro de datos."
    ]);
}

require_once __DIR__ . "/config/conexion.php";

try {
    $hoy = $pdo->query("SELECT CURDATE()")->fetchColumn();

    // Obtener los días de atención activos del médico (ISO-8601: 1=Lunes ... 7=Domingo)
    $diasAtencion = [];
    if ($idEmpleado !== null) {
        $stDias = $pdo->prepare(
            "SELECT DISTINCT h.dia_semana
             FROM horario_atencion h
             LEFT JOIN consultorio co ON co.id_consultorio = h.id_consultorio
             WHERE h.id_empleado = ?
               AND h.activo = 1
               AND (h.vigencia_hasta IS NULL OR h.vigencia_hasta >= CURDATE())
               AND (h.id_consultorio IS NULL OR co.activo = 1)
             ORDER BY h.dia_semana"
        );
        $stDias->execute([$idEmpleado]);
        $diasAtencion = array_map("intval", $stDias->fetchAll(PDO::FETCH_COLUMN));
    }

    if ($fecha <= $hoy) {
        citasApiResponder(422, [
            "exito" => false,
            "codigo" => "ANTICIPACION_MINIMA",
            "mensaje" => "La disponibilidad se consulta para fechas posteriores a hoy. Elige otro día.",
            "dias_atencion" => $diasAtencion
        ]);
    }

    if ($idTipoAtencion === null && $idEmpleado !== null) {
        $stTa = $pdo->prepare(
            "SELECT ta.id_tipo_atencion FROM tipo_atencion ta
             INNER JOIN doctor_especialidad de ON de.id_especialidad = ta.id_especialidad OR ta.id_especialidad IS NULL
             WHERE de.id_empleado = ? AND ta.activo = 1 ORDER BY ta.id_tipo_atencion LIMIT 1"
        );
        $stTa->execute([$idEmpleado]);
        $idTipoAtencion = $stTa->fetchColumn() ?: 1;
    }
    if ($idTipoAtencion === null) {
        $idTipoAtencion = 1;
    }

    $consultaServicio = $pdo->prepare(
        "SELECT ta.id_tipo_atencion, ta.nombre AS tipo_atencion,
                ta.duracion_minutos,
                CONCAT_WS(' ', p.nombres, p.apellido_paterno, p.apellido_materno) AS medico,
                e.nombre AS especialidad
         FROM tipo_atencion ta
         LEFT JOIN doctor d ON d.id_empleado = ?
         LEFT JOIN empleado em ON em.id_empleado = d.id_empleado
         LEFT JOIN persona p ON p.id_persona = em.id_persona
         LEFT JOIN especialidad e ON e.id_especialidad = ta.id_especialidad
         WHERE ta.id_tipo_atencion = ? AND ta.activo = 1
         LIMIT 1"
    );
    $consultaServicio->execute([$idEmpleado, $idTipoAtencion]);
    $servicio = $consultaServicio->fetch();

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

    if ($idEmpleado === null) {
        $horarios = [];
    } else {
        $consultaHorarios->execute([$idEmpleado, $diaSemana, $fecha, $fecha]);
        $horarios = $consultaHorarios->fetchAll();
    }

    $idPaciente = null;
    if ($idUsuario !== null) {
        $stPac = $pdo->prepare(
            "SELECT pa.id_paciente FROM usuario u
             INNER JOIN paciente pa ON pa.id_persona = u.id_persona
             WHERE u.id_usuario = ? LIMIT 1"
        );
        $stPac->execute([$idUsuario]);
        $idPaciente = $stPac->fetchColumn() ?: null;
    }

    $fechaObj = DateTimeImmutable::createFromFormat("!Y-m-d", $fecha);
    $inicioDia = $fecha . " 00:00:00";
    $finDia = $fechaObj->modify("+1 day")->format("Y-m-d") . " 00:00:00";

    // Cargar todas las citas ocupadas que intersectan esta fecha (soporta citas en el día completo)
    $stCitas = $pdo->prepare(
        "SELECT id_empleado, id_paciente, id_consultorio,
                DATE_FORMAT(inicio, '%Y-%m-%d %H:%i:%s') AS inicio,
                DATE_FORMAT(fin, '%Y-%m-%d %H:%i:%s') AS fin
         FROM cita
         WHERE inicio < ? AND fin > ?
           AND estado IN ('pendiente', 'confirmada', 'en_progreso')"
    );
    $stCitas->execute([$finDia, $inicioDia]);
    $citasOcupadas = $stCitas->fetchAll(PDO::FETCH_ASSOC);

    // Cargar bloqueos de horario que intersectan esta fecha (soporta bloqueos parciales, de día completo y multidía)
    $stBloqueos = $pdo->prepare(
        "SELECT id_empleado, id_consultorio,
                DATE_FORMAT(inicio, '%Y-%m-%d %H:%i:%s') AS inicio,
                DATE_FORMAT(fin, '%Y-%m-%d %H:%i:%s') AS fin,
                motivo
         FROM bloqueo_horario
         WHERE inicio < ? AND fin > ?"
    );
    $stBloqueos->execute([$finDia, $inicioDia]);
    $bloqueos = $stBloqueos->fetchAll(PDO::FETCH_ASSOC);

    $slots = [];
    $vistos = [];
    $duracion = 30;
    $intervalo = 30;
    $slotsBloqueados = 0;

    foreach ($horarios as $horario) {
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
                $disponible = true;
                $bloqueadoPorHorario = false;
                $motivoBloqueo = null;

                // Verificar conflicto con bloqueos de horario (comparación completa fecha+hora)
                foreach ($bloqueos as $b) {
                    $bInicio = $b["inicio"];
                    $bFin = $b["fin"];

                    $esMismoEmp = ($idEmpleado !== null && (int) $b["id_empleado"] === (int) $idEmpleado);
                    $esMismoCons = ($idConsultorio !== null && $b["id_consultorio"] !== null && (int) $b["id_consultorio"] === (int) $idConsultorio);

                    if ($esMismoEmp || $esMismoCons) {
                        if (strcmp($bInicio, $fin) < 0 && strcmp($bFin, $inicio) > 0) {
                            $disponible = false;
                            $bloqueadoPorHorario = true;
                            $motivoBloqueo = isset($b["motivo"]) && trim((string) $b["motivo"]) !== ""
                                ? trim((string) $b["motivo"])
                                : null;
                            $slotsBloqueados++;
                            break;
                        }
                    }
                }

                // Verificar conflicto con citas registradas (comparación completa fecha+hora)
                if ($disponible) {
                    foreach ($citasOcupadas as $c) {
                        $cInicio = $c["inicio"];
                        $cFin = $c["fin"];

                        $esMismoEmp = ($idEmpleado !== null && (int) $c["id_empleado"] === (int) $idEmpleado);
                        $esMismoPac = ($idPaciente !== null && (int) $c["id_paciente"] === (int) $idPaciente);
                        $esMismoCons = ($idConsultorio !== null && $c["id_consultorio"] !== null && (int) $c["id_consultorio"] === (int) $idConsultorio);

                        if ($esMismoEmp || $esMismoPac || $esMismoCons) {
                            if (strcmp($cInicio, $fin) < 0 && strcmp($cFin, $inicio) > 0) {
                                $disponible = false;
                                break;
                            }
                        }
                    }
                }

                $slot = [
                    "inicio" => $inicio,
                    "fin" => $fin,
                    "hora" => $cursor->format("H:i"),
                    "duracion_minutos" => $duracion,
                    "id_consultorio" => $idConsultorio,
                    "consultorio" => $horario["consultorio"],
                    "disponible" => (bool) $disponible,
                    "bloqueado" => (bool) $bloqueadoPorHorario
                ];

                if (!$disponible) {
                    if ($bloqueadoPorHorario) {
                        $slot["codigo"] = "AGENDA_BLOQUEADA";
                        $slot["mensaje"] = $motivoBloqueo !== null
                            ? "Horario bloqueado: " . $motivoBloqueo . "."
                            : "Este horario se encuentra bloqueado. Elige otro horario.";
                    } else {
                        $slot["codigo"] = "HORARIO_OCUPADO";
                        $slot["mensaje"] = "Este horario ya no está disponible. Elige otro horario.";
                    }
                }

                $slots[] = $slot;
                $vistos[$clave] = true;
            }

            $cursor = $cursor->modify("+" . $intervalo . " minutes");
        }
    }

    $inicios = array_column($slots, "inicio");
    $consultorios = [];
    foreach ($slots as $slot) {
        $consultorios[] = $slot["id_consultorio"] ?? 0;
    }
    array_multisort($inicios, SORT_ASC, $consultorios, SORT_ASC, $slots);

    $disponibles = 0;
    foreach ($slots as $slot) {
        if ($slot["disponible"]) {
            $disponibles++;
        }
    }

    if ($disponibles > 0) {
        $mensajeDisponibilidad = "Selecciona un horario disponible.";
    } elseif (count($slots) > 0 && $slotsBloqueados === count($slots)) {
        $mensajeDisponibilidad = "El médico tiene el horario bloqueado en esta fecha. Elige otro día.";
    } else {
        $mensajeDisponibilidad = "No hay horarios disponibles para esta fecha. Elige otro día.";
    }

    citasApiResponder(200, [
        "exito" => true,
        "mensaje" => $mensajeDisponibilidad,
        "fecha" => $fecha,
        "medico" => $servicio["medico"] ?? "",
        "tipo_atencion" => $servicio["tipo_atencion"] ?? "",
        "especialidad" => $servicio["especialidad"] ?? "",
        "duracion_minutos" => $duracion,
        "dias_atencion" => $diasAtencion,
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
