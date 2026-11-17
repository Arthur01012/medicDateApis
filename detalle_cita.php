<?php
// Detalle de una cita del paciente

header("Content-Type: application/json; charset=UTF-8");
require_once "config/conexion.php";

$id_cita = isset($_GET['id_cita']) ? intval($_GET['id_cita']) : 0;
$id_usuario = isset($_GET['id_usuario']) ? intval($_GET['id_usuario']) : 0;

if ($id_cita <= 0 || $id_usuario <= 0) {
    header("HTTP/1.1 400 Bad Request");
    echo json_encode(["error" => "Los datos de la cita no son validos"]);
    exit;
}

try {
    // El WHERE con el usuario evita que un paciente vea citas de otro
    $stmt = $pdo->prepare(
        "SELECT c.id_cita AS id, DATE(c.inicio) AS fecha,
        TIME_FORMAT(c.inicio, '%H:%i') AS hora,
        TIMESTAMPDIFF(MINUTE, c.inicio, c.fin) AS duracion, c.estado,
        ta.nombre AS tipo_atencion, co.nombre AS consultorio,
        CONCAT_WS(' ', pd.nombres, pd.apellido_paterno, pd.apellido_materno) AS medico,
        e.nombre AS especialidad
        FROM cita c
        INNER JOIN paciente pa ON c.id_paciente = pa.id_paciente
        INNER JOIN usuario u ON pa.id_persona = u.id_persona
        INNER JOIN doctor d ON c.id_empleado = d.id_empleado
        INNER JOIN empleado em ON d.id_empleado = em.id_empleado
        INNER JOIN persona pd ON em.id_persona = pd.id_persona
        INNER JOIN tipo_atencion ta ON c.id_tipo_atencion = ta.id_tipo_atencion
        LEFT JOIN especialidad e ON ta.id_especialidad = e.id_especialidad
        LEFT JOIN consultorio co ON c.id_consultorio = co.id_consultorio
        WHERE c.id_cita = ? AND u.id_usuario = ?"
    );
    $stmt->execute([$id_cita, $id_usuario]);
    $cita = $stmt->fetch();

    if (!$cita) {
        header("HTTP/1.1 404 Not Found");
        echo json_encode(["error" => "La cita no existe"]);
        exit;
    }

    $cita['id'] = (int)$cita['id'];
    $cita['duracion'] = (int)$cita['duracion'];

    echo json_encode($cita, JSON_UNESCAPED_UNICODE);
} catch (Exception $e) {
    error_log($e->getMessage());
    header("HTTP/1.1 500 Internal Server Error");
    echo json_encode(["error" => "Error al obtener el detalle de la cita"]);
}
?>
