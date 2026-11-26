<?php
// pregunta_recuperacion.php (paso 1 de recuperar la contraseña)

header("Content-Type: application/json; charset=UTF-8");
require_once "config/conexion.php";

// Datos enviados en formato JSON desde Android (@Body)
$jsonInput = file_get_contents("php://input");
$data = json_decode($jsonInput, true);

$correo = isset($data['correo']) ? trim($data['correo']) : '';

if (empty($correo)) {
    echo json_encode(["exito" => false, "mensaje" => "Escribe tu correo electrónico"], JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    // Buscamos la pregunta secreta de la cuenta (solo pacientes activos)
    $stmt = $pdo->prepare("SELECT ps.texto
                        FROM usuario u
                        INNER JOIN usuario_rol ur ON ur.id_usuario = u.id_usuario
                        INNER JOIN rol r ON r.id_rol = ur.id_rol
                        INNER JOIN usuario_pregunta_secreta ups ON ups.id_usuario = u.id_usuario
                        INNER JOIN pregunta_secreta ps ON ps.id_pregunta_secreta = ups.id_pregunta_secreta
                        WHERE u.correo_acceso = ? AND r.nombre = 'paciente' AND u.estado = 'activa'");
    $stmt->execute([$correo]);
    $fila = $stmt->fetch();

    // Mensaje generico para no revelar si la cuenta existe
    if (!$fila) {
        echo json_encode(["exito" => false, "mensaje" => "No se puede recuperar la contraseña con ese correo"], JSON_UNESCAPED_UNICODE);
        exit;
    }

    echo json_encode([
        "exito" => true,
        "mensaje" => "",
        "pregunta" => $fila['texto']
    ], JSON_UNESCAPED_UNICODE);

} catch (Exception $e) {
    error_log("Error al obtener la pregunta de recuperacion: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(["exito" => false, "mensaje" => "Error al consultar la pregunta."], JSON_UNESCAPED_UNICODE);
}
?>
