<?php
require_once __DIR__ . "/citas_api.php";

if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    header("Allow: POST");
    citasApiResponder(405, [
        "exito" => false,
        "codigo" => "METODO_NO_PERMITIDO",
        "mensaje" => "Para reservar una cita, envía una solicitud POST."
    ]);
}

$datos = citasApiLeerEntrada();
$idUsuario = citasApiId($datos["id_usuario"] ?? null);
$idEmpleado = citasApiId($datos["id_empleado"] ?? null);
$idTipoAtencion = citasApiId($datos["id_tipo_atencion"] ?? null);
$fechaHora = citasApiInicioValido($datos["inicio"] ?? null);
$consultorioIndicado = array_key_exists("id_consultorio", $datos);
$idConsultorioSolicitado = $consultorioIndicado && $datos["id_consultorio"] !== null
    ? citasApiId($datos["id_consultorio"])
    : null;

if (
    $idUsuario === null
    || $idTipoAtencion === null
    || $fechaHora === null
    || ($datos["id_empleado"] ?? null) !== null && $idEmpleado === null
    || ($consultorioIndicado && $datos["id_consultorio"] !== null && $idConsultorioSolicitado === null)
) {
    citasApiResponder(422, [
        "exito" => false,
        "codigo" => "PARAMETROS_INVALIDOS",
        "mensaje" => "Incluye id_usuario, id_tipo_atencion e inicio válidos dentro de la cadena datos. id_empleado e id_consultorio pueden ser null."
    ]);
}

$inicio = $fechaHora->format("Y-m-d H:i:s");
$fecha = $fechaHora->format("Y-m-d");
$hora = $fechaHora->format("H:i:s");

require_once __DIR__ . "/config/conexion.php";

try {
    $pdo->beginTransaction();

    $consultaPaciente = $pdo->prepare(
        "SELECT pa.id_paciente
         FROM usuario u
         INNER JOIN usuario_rol ur ON ur.id_usuario = u.id_usuario
         INNER JOIN rol r ON r.id_rol = ur.id_rol AND r.nombre = 'paciente'
         INNER JOIN paciente pa ON pa.id_persona = u.id_persona
         WHERE u.id_usuario = ? AND u.estado = 'activa' AND pa.estado = 'activo'
         LIMIT 1
         FOR UPDATE"
    );
    $consultaPaciente->execute([$idUsuario]);
    $paciente = $consultaPaciente->fetch();
    if (!$paciente) {
        $pdo->rollBack();
        citasApiResponder(403, [
            "exito" => false,
            "codigo" => "PACIENTE_NO_AUTORIZADO",
            "mensaje" => "No se encontró un perfil de paciente activo para esta cuenta. Inicia sesión con una cuenta de paciente activa."
        ]);
    }
    $idPaciente = (int) $paciente["id_paciente"];

    // Bloquear el registro padre serializa reservas concurrentes para el mismo médico.
    $consultaDoctor = $pdo->prepare(
        "SELECT d.id_empleado
         FROM doctor d
         INNER JOIN empleado em ON em.id_empleado = d.id_empleado
         WHERE d.id_empleado = ? AND em.estado = 'activo'
         LIMIT 1
         FOR UPDATE"
    );
    $consultaDoctor->execute([$idEmpleado]);
    if ($idEmpleado !== null && !$consultaDoctor->fetch()) {
        $pdo->rollBack();
        citasApiResponder(404, [
            "exito" => false,
            "codigo" => "MEDICO_NO_DISPONIBLE",
            "mensaje" => "El médico seleccionado ya no está disponible. Actualiza la lista y elige otro."
        ]);
    }

    $consultaServicio = $pdo->prepare(
        "SELECT ta.id_tipo_atencion, ta.nombre AS tipo_atencion,
                ta.duracion_minutos, ta.costo_base, ta.moneda,
                ta.id_especialidad,
                CONCAT_WS(' ', p.nombres, p.apellido_paterno, p.apellido_materno) AS medico,
                e.nombre AS especialidad
         FROM tipo_atencion ta
         LEFT JOIN doctor d ON d.id_empleado = ?
         LEFT JOIN empleado em ON em.id_empleado = d.id_empleado
         LEFT JOIN persona p ON p.id_persona = em.id_persona
         LEFT JOIN especialidad e ON e.id_especialidad = ta.id_especialidad
         WHERE ta.id_tipo_atencion = ? AND ta.activo = 1
           AND (? IS NULL OR em.estado = 'activo')
           AND (? IS NULL OR ta.id_especialidad IS NULL OR (
                e.activo = 1
                AND EXISTS (
                    SELECT 1 FROM doctor_especialidad de
                    WHERE de.id_empleado = d.id_empleado
                      AND de.id_especialidad = ta.id_especialidad
                )
           ))
         LIMIT 1"
    );
    $consultaServicio->execute([$idEmpleado, $idTipoAtencion, $idEmpleado, $idEmpleado]);
    $servicio = $consultaServicio->fetch();
    if (!$servicio) {
        $pdo->rollBack();
        citasApiResponder(404, [
            "exito" => false,
            "codigo" => "SERVICIO_NO_DISPONIBLE",
            "mensaje" => "El médico o tipo de atención seleccionado ya no está disponible. Actualiza la información e inténtalo de nuevo."
        ]);
    }

    $consultaFecha = $pdo->query("SELECT CURDATE()");
    $hoy = $consultaFecha->fetchColumn();
    if ($fecha <= $hoy) {
        $pdo->rollBack();
        citasApiResponder(422, [
            "exito" => false,
            "codigo" => "ANTICIPACION_MINIMA",
            "mensaje" => "Las citas deben reservarse para una fecha posterior a hoy. Elige otro día."
        ]);
    }

    $diaSemana = (int) $fechaHora->format("N");
    $consultaHorarios = $pdo->prepare(
        "SELECT h.id_horario, h.id_consultorio, h.hora_inicio, h.hora_fin,
                h.intervalo_minutos, co.nombre AS consultorio
         FROM horario_atencion h
         LEFT JOIN consultorio co ON co.id_consultorio = h.id_consultorio
         WHERE h.id_empleado = ? AND h.dia_semana = ? AND h.activo = 1
           AND h.vigencia_desde <= ?
           AND (h.vigencia_hasta IS NULL OR h.vigencia_hasta >= ?)
           AND (h.id_consultorio IS NULL OR co.activo = 1)
         ORDER BY h.id_horario
         FOR UPDATE"
    );
    if ($idEmpleado === null) {
        $horarios = [];
    } else {
        $consultaHorarios->execute([$idEmpleado, $diaSemana, $fecha, $fecha]);
        $horarios = $consultaHorarios->fetchAll();
    }

    $minutoInicio = ((int) substr($hora, 0, 2) * 60) + (int) substr($hora, 3, 2);
    $duracion = (int) $servicio["duracion_minutos"];
    $horarioElegido = null;
    foreach ($horarios as $horario) {
        if ($consultorioIndicado) {
            $coincideConsultorio = $idConsultorioSolicitado === null
                ? $horario["id_consultorio"] === null
                : (int) $horario["id_consultorio"] === $idConsultorioSolicitado;
            if (!$coincideConsultorio) {
                continue;
            }
        }

        $horaInicioHorario = substr((string) $horario["hora_inicio"], 0, 5);
        $horaFinHorario = substr((string) $horario["hora_fin"], 0, 5);
        $minutoInicioHorario = ((int) substr($horaInicioHorario, 0, 2) * 60)
            + (int) substr($horaInicioHorario, 3, 2);
        $minutoFinHorario = ((int) substr($horaFinHorario, 0, 2) * 60)
            + (int) substr($horaFinHorario, 3, 2);
        $intervalo = (int) $horario["intervalo_minutos"];

        if (
            $intervalo > 0
            && $minutoInicio >= $minutoInicioHorario
            && $minutoInicio + $duracion <= $minutoFinHorario
            && ($minutoInicio - $minutoInicioHorario) % $intervalo === 0
        ) {
            $horarioElegido = $horario;
            break;
        }
    }

    if (!$horarioElegido && $idEmpleado !== null) {
        $pdo->rollBack();
        citasApiResponder(409, [
            "exito" => false,
            "codigo" => "HORARIO_NO_DISPONIBLE",
            "mensaje" => "La fecha, hora o consultorio ya no coinciden con un horario vigente. Elige otro horario."
        ]);
    }

    $idConsultorio = $horarioElegido === null || $horarioElegido["id_consultorio"] === null
        ? null
        : (int) $horarioElegido["id_consultorio"];
    if ($idConsultorio !== null) {
        $bloquearConsultorio = $pdo->prepare(
            "SELECT id_consultorio
             FROM consultorio
             WHERE id_consultorio = ? AND activo = 1
             FOR UPDATE"
        );
        $bloquearConsultorio->execute([$idConsultorio]);
        if (!$bloquearConsultorio->fetch()) {
            $pdo->rollBack();
            citasApiResponder(409, [
                "exito" => false,
                "codigo" => "CONSULTORIO_NO_DISPONIBLE",
                "mensaje" => "El consultorio dejó de estar disponible. Elige otro horario."
            ]);
        }
    }

    $fin = $fechaHora->modify("+" . $duracion . " minutes")->format("Y-m-d H:i:s");
    $conflicto = citasApiBuscarConflicto(
        $pdo,
        $idEmpleado,
        $idPaciente,
        $idConsultorio,
        $inicio,
        $fin
    );
    if ($conflicto !== null) {
        $pdo->rollBack();
        citasApiResponder(409, [
            "exito" => false,
            "codigo" => $conflicto["codigo"],
            "mensaje" => $conflicto["mensaje"],
            "accion" => "elegir_otro_horario"
        ]);
    }

    $insertarCita = $pdo->prepare(
        "INSERT INTO cita (
            id_paciente, id_empleado, id_consultorio, id_tipo_atencion,
            inicio, fin, estado, costo, moneda, id_usuario_registro
         ) VALUES (?, ?, ?, ?, ?, ?, 'confirmada', ?, ?, ?)"
    );
    $insertarCita->execute([
        $idPaciente,
        $idEmpleado,
        $idConsultorio,
        $idTipoAtencion,
        $inicio,
        $fin,
        $servicio["costo_base"],
        $servicio["moneda"],
        $idUsuario
    ]);
    $idCita = (int) $pdo->lastInsertId();

    $historial = $pdo->prepare(
        "INSERT INTO historial_cita (id_cita, id_usuario, estado_nuevo, accion, observacion)
         VALUES (?, ?, 'confirmada', 'creacion', 'Cita confirmada desde la aplicación.')"
    );
    $historial->execute([$idCita, $idUsuario]);

    $consultorioNombre = $horarioElegido["consultorio"] ?? null;
    $lugar = $consultorioNombre === null ? "" : " en " . $consultorioNombre;
    $notificacion = $pdo->prepare(
        "INSERT INTO notificacion (
            id_usuario, id_cita, tipo, canal, estado_envio, titulo, mensaje
         ) VALUES (?, ?, 'cita_confirmada', 'interno', 'pendiente', ?, ?)"
    );
    $notificacion->execute([
        $idUsuario,
        $idCita,
        "Cita confirmada",
        "Tu cita de " . $servicio["tipo_atencion"]
            . ($servicio["medico"] ? " con " . $servicio["medico"] : "")
            . " quedó confirmada para " . $fecha . " a las " . substr($hora, 0, 5) . $lugar . "."
    ]);

    $auditoria = $pdo->prepare(
        "INSERT INTO auditoria (
            id_usuario, modulo, accion, entidad, id_registro, resultado, observacion
         ) VALUES (?, 'citas', 'reservar', 'cita', ?, 'correcto', 'Cita confirmada desde la aplicación.')"
    );
    $auditoria->execute([$idUsuario, (string) $idCita]);

    $pdo->commit();

    citasApiResponder(201, [
        "exito" => true,
        "codigo" => "CITA_CONFIRMADA",
        "mensaje" => "Cita confirmada. Te enviaremos un recordatorio antes de tu cita.",
        "cita" => [
            "id_cita" => $idCita,
            "estado" => "confirmada",
            "fecha" => $fecha,
            "hora" => substr($hora, 0, 5),
            "duracion_minutos" => $duracion,
            "medico" => $servicio["medico"],
            "tipo_atencion" => $servicio["tipo_atencion"],
            "especialidad" => $servicio["especialidad"],
            "id_consultorio" => $idConsultorio,
            "consultorio" => $consultorioNombre
        ]
    ]);
} catch (PDOException $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log("Error al reservar cita: " . $e->getMessage());

    $codigoMotor = isset($e->errorInfo[1]) ? (int) $e->errorInfo[1] : 0;
    if ((string) $e->getCode() === "40001" || $codigoMotor === 1205 || $codigoMotor === 1213) {
        citasApiResponder(409, [
            "exito" => false,
            "codigo" => "AGENDA_ACTUALIZADA",
            "mensaje" => "La agenda cambió mientras se procesaba la solicitud. Consulta la disponibilidad y elige otro horario."
        ]);
    }

    citasApiResponder(500, [
        "exito" => false,
        "codigo" => "ERROR_RESERVA",
        "mensaje" => "No se pudo confirmar la cita por un problema del servidor. Inténtalo de nuevo."
    ]);
} catch (Exception $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log("Error al reservar cita: " . $e->getMessage());
    citasApiResponder(500, [
        "exito" => false,
        "codigo" => "ERROR_RESERVA",
        "mensaje" => "No se pudo confirmar la cita por un problema del servidor. Inténtalo de nuevo."
    ]);
}
