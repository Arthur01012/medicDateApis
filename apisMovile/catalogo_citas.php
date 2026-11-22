<?php
require_once __DIR__ . "/citas_api.php";

if ($_SERVER["REQUEST_METHOD"] !== "GET") {
    header("Allow: GET");
    citasApiResponder(405, [
        "exito" => false,
        "codigo" => "METODO_NO_PERMITIDO",
        "mensaje" => "Para consultar médicos y tipos de atención, envía una solicitud GET."
    ]);
}

require_once __DIR__ . "/config/conexion.php";

try {
    $doctores = $pdo->query(
        "SELECT d.id_empleado,
                CONCAT_WS(' ', p.nombres, p.apellido_paterno, p.apellido_materno) AS medico,
                COALESCE(
                    GROUP_CONCAT(DISTINCT e.nombre ORDER BY e.nombre SEPARATOR ', '),
                    ''
                ) AS especialidades
         FROM doctor d
         INNER JOIN empleado em ON em.id_empleado = d.id_empleado
         INNER JOIN persona p ON p.id_persona = em.id_persona
         LEFT JOIN doctor_especialidad de ON de.id_empleado = d.id_empleado
         LEFT JOIN especialidad e
           ON e.id_especialidad = de.id_especialidad AND e.activo = 1
         WHERE em.estado = 'activo'
           AND EXISTS (
               SELECT 1
               FROM tipo_atencion ta
               WHERE ta.activo = 1
                 AND (
                     ta.id_especialidad IS NULL
                     OR EXISTS (
                         SELECT 1
                         FROM doctor_especialidad de2
                         INNER JOIN especialidad e2
                           ON e2.id_especialidad = de2.id_especialidad
                          AND e2.activo = 1
                         WHERE de2.id_empleado = d.id_empleado
                           AND de2.id_especialidad = ta.id_especialidad
                     )
                 )
           )
         GROUP BY d.id_empleado, p.nombres, p.apellido_paterno, p.apellido_materno
         ORDER BY medico"
    )->fetchAll();

    $tiposAtencion = $pdo->query(
        "SELECT DISTINCT d.id_empleado, ta.id_tipo_atencion,
                ta.nombre AS tipo_atencion, ta.duracion_minutos,
                ta.id_especialidad, e.nombre AS especialidad
         FROM doctor d
         INNER JOIN empleado em ON em.id_empleado = d.id_empleado
         INNER JOIN tipo_atencion ta ON ta.activo = 1
         LEFT JOIN especialidad e
           ON e.id_especialidad = ta.id_especialidad AND e.activo = 1
         WHERE em.estado = 'activo'
           AND (
               ta.id_especialidad IS NULL
               OR EXISTS (
                   SELECT 1
                   FROM doctor_especialidad de
                   INNER JOIN especialidad e2
                     ON e2.id_especialidad = de.id_especialidad
                    AND e2.activo = 1
                   WHERE de.id_empleado = d.id_empleado
                     AND de.id_especialidad = ta.id_especialidad
               )
           )
         ORDER BY d.id_empleado, ta.nombre"
    )->fetchAll();

    foreach ($doctores as &$doctor) {
        $doctor["id_empleado"] = (int) $doctor["id_empleado"];
    }
    unset($doctor);

    foreach ($tiposAtencion as &$tipoAtencion) {
        $tipoAtencion["id_empleado"] = (int) $tipoAtencion["id_empleado"];
        $tipoAtencion["id_tipo_atencion"] = (int) $tipoAtencion["id_tipo_atencion"];
        $tipoAtencion["duracion_minutos"] = (int) $tipoAtencion["duracion_minutos"];
        if ($tipoAtencion["id_especialidad"] !== null) {
            $tipoAtencion["id_especialidad"] = (int) $tipoAtencion["id_especialidad"];
        }
    }
    unset($tipoAtencion);

    citasApiResponder(200, [
        "exito" => true,
        "mensaje" => count($doctores) > 0
            ? "Selecciona un médico y el tipo de atención."
            : "No hay médicos ni tipos de atención disponibles por el momento.",
        "doctores" => $doctores,
        "tipos_atencion" => $tiposAtencion
    ]);
} catch (PDOException $e) {
    error_log("Error al consultar el catálogo de citas: " . $e->getMessage());
    citasApiResponder(500, [
        "exito" => false,
        "codigo" => "ERROR_CATALOGO",
        "mensaje" => "No se pudo cargar la lista de médicos y atenciones. Inténtalo de nuevo."
    ]);
} catch (Exception $e) {
    error_log("Error al consultar el catálogo de citas: " . $e->getMessage());
    citasApiResponder(500, [
        "exito" => false,
        "codigo" => "ERROR_CATALOGO",
        "mensaje" => "No se pudo cargar la lista de médicos y atenciones. Inténtalo de nuevo."
    ]);
}
