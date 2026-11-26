<?php
// obtener_cuenta.php

header("Content-Type: application/json; charset=UTF-8");
require_once "config/conexion.php";

$idUsuario = isset($_GET['idUsuario']) ? intval($_GET['idUsuario']) : 0;

if ($idUsuario <= 0) {
    http_response_code(400);
    echo json_encode([
        "exito" => false,
        "mensaje" => "Debe enviar el id del usuario"
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    // Unimos usuario con persona para armar el nombre completo
    $stmt = $pdo->prepare("SELECT CONCAT_WS(' ', p.nombres, p.apellido_paterno, p.apellido_materno) AS nombre,
    u.correo_acceso AS correo, u.estado
                    FROM usuario u
                    INNER JOIN persona p ON p.id_persona = u.id_persona
                    WHERE u.id_usuario = ?");
    $stmt->execute([$idUsuario]);
    $cuenta = $stmt->fetch();

    if (!$cuenta) {
        echo json_encode([
            "exito" => false,
            "mensaje" => "No se encontró la cuenta"
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // Las cuentas no se eliminan, solo se desactivan
    if ($cuenta['estado'] !== 'activa') {
        echo json_encode([
            "exito" => false,
            "mensaje" => "Tu cuenta está desactivada. Comunícate con el centro médico"
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    echo json_encode([
        "exito" => true,
        "mensaje" => "",
        "nombre" => $cuenta['nombre'],
        "correo" => $cuenta['correo']
    ], JSON_UNESCAPED_UNICODE);

} catch (Exception $e) {
    error_log("Error al obtener la cuenta: " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        "exito" => false,
        "mensaje" => "Error al consultar la cuenta."
    ], JSON_UNESCAPED_UNICODE);
}
?>
