<?php
// Lista las citas del paciente (Proximas e Historial)

header("Content-Type: application/json; charset=UTF-8");
require_once "config/conexion.php";

$id_usuario = isset($_GET['id_usuario']) ? intval($_GET['id_usuario']) : 0;

if ($id_usuario <= 0) {
    header("HTTP/1.1 400 Bad Request");
    echo json_encode(["error" => "El id del usuario no es valido"]);
    exit;
}

try {
    // usuario , paciente (misma persona)  cita
    // medico: doctor -> empleado -> persona
    // especialidad: la del tipo de atencion de la cita (puede ser NULL)
    $stmt = $pdo->prepare(
        "SELECT c.id_cita AS id, DATE(c.inicio) AS fecha,
        TIME_FORMAT(c.inicio, '%H:%i') AS hora, c.estado,
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
        WHERE u.id_usuario = ?
        ORDER BY c.inicio DESC"
    );
    $stmt->execute([$id_usuario]);
    $citas = $stmt->fetchAll();

    $ahora = date("Y-m-d H:i:s");

    // Casteamos los tipos de datos 
    foreach ($citas as &$c) {
        $c['id'] = (int)$c['id'];
        // proxima = pendiente o confirmada que aun no pasa, lo demas es historial
        if (($c['estado'] == "pendiente" || $c['estado'] == "confirmada") && ($c['fecha'] . " " . $c['hora'] . ":00") >= $ahora) {
            $c['tipo'] = "proxima";
        } else {
            $c['tipo'] = "historial";
        }
    }

    echo json_encode($citas, JSON_UNESCAPED_UNICODE);
} catch (Exception $e) {
    error_log($e->getMessage());
    header("HTTP/1.1 500 Internal Server Error");
    echo json_encode(["error" => "Error al obtener las citas"]);
}
?>
