<?php
header("Content-Type: application/json; charset=UTF-8");
require_once "config/conexion.php";

$idPaciente = isset($_GET["idPaciente"]) ? intval($_GET["idPaciente"]) : 0;

try {
    // 1. Datos del paciente (si se envía idPaciente).
    $paciente = null;
    $proximaCita = null;

    if ($idPaciente > 0) {
        $stmt = $pdo->prepare("
            SELECT
                pa.id_paciente,
                CONCAT_WS(' ', pe.nombres, pe.apellido_paterno, pe.apellido_materno) AS nombre
            FROM paciente pa
            INNER JOIN persona pe ON pe.id_persona = pa.id_persona
            WHERE pa.id_paciente = ?
              AND pa.estado = 'activo'
            LIMIT 1
        ");
        $stmt->execute([$idPaciente]);
        $paciente = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($paciente) {
            // 2. Próxima cita que todavía no ha ocurrido y no está cancelada.
            $stmt = $pdo->prepare("
                SELECT
                    ci.id_cita,
                    ci.inicio,
                    ci.fin,
                    ci.estado,
                    es.nombre AS especialidad,
                    CONCAT_WS(' ', pd.nombres, pd.apellido_paterno, pd.apellido_materno) AS medico,
                    co.codigo AS consultorio
                FROM cita ci
                INNER JOIN doctor_especialidad de
                    ON de.id_empleado = ci.id_empleado
                INNER JOIN especialidad es
                    ON es.id_especialidad = de.id_especialidad
                INNER JOIN empleado em
                    ON em.id_empleado = ci.id_empleado
                INNER JOIN persona pd
                    ON pd.id_persona = em.id_persona
                LEFT JOIN consultorio co
                    ON co.id_consultorio = ci.id_consultorio
                WHERE ci.id_paciente = ?
                  AND ci.inicio >= NOW()
                  AND ci.estado IN ('pendiente', 'confirmada')
                ORDER BY ci.inicio ASC
                LIMIT 1
            ");
            $stmt->execute([$idPaciente]);
            $proximaCita = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
        }
    }

    // 3. Especialidades activas.
    $stmt = $pdo->query("
        SELECT id_especialidad, nombre, descripcion
        FROM especialidad
        WHERE activo = 1
        ORDER BY nombre ASC
    ");
    $especialidades = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // 4. Médicos activos y las especialidades que tienen asignadas.
    $stmt = $pdo->query("
        SELECT
            d.id_empleado,
            CONCAT_WS(' ', p.nombres, p.apellido_paterno, p.apellido_materno) AS nombre,
            d.cedula_profesional,
            GROUP_CONCAT(DISTINCT e.nombre ORDER BY e.nombre SEPARATOR ', ') AS especialidades
        FROM doctor d
        INNER JOIN empleado em ON em.id_empleado = d.id_empleado
        INNER JOIN persona p ON p.id_persona = em.id_persona
        INNER JOIN doctor_especialidad de ON de.id_empleado = d.id_empleado
        INNER JOIN especialidad e ON e.id_especialidad = de.id_especialidad
        WHERE em.estado = 'activo'
          AND e.activo = 1
        GROUP BY d.id_empleado, p.nombres, p.apellido_paterno,
                 p.apellido_materno, d.cedula_profesional
        ORDER BY p.apellido_paterno, p.nombres
    ");
    $medicos = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($especialidades as &$especialidad) {
        $especialidad["id_especialidad"] = (int)$especialidad["id_especialidad"];
    }
    unset($especialidad);

    foreach ($medicos as &$medico) {
        $medico["id_empleado"] = (int)$medico["id_empleado"];
    }
    unset($medico);

    if ($paciente) {
        $paciente["id_paciente"] = (int)$paciente["id_paciente"];
    }

    echo json_encode([
        "success" => true,
        "paciente" => $paciente,
        "proxima_cita" => $proximaCita,
        "especialidades" => $especialidades,
        "medicos" => $medicos
    ], JSON_UNESCAPED_UNICODE);

} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode([
        "success" => false,
        "mensaje" => "Error al obtener la información de explorar.",
        "error" => $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
}
?>
