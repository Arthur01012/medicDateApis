<?php
// Este es el api del login.php

header("Content-Type: application/json; charset=UTF-8");
require_once "config/conexion.php";

// Decodificar el cuerpo JSON enviado por Retrofit desde Android
$jsonInput = file_get_contents("php://input");
$data = json_decode($jsonInput, true);

$correo = isset($data['correo']) ? trim($data['correo']) : '';
$clave = isset($data['clave']) ? trim($data['clave']) : '';

if (empty($correo) || empty($clave)) {
    echo json_encode([
        "exito" => false,
        "mensaje" => "Debe ingresar el correo y la contraseña"
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    // Buscamos el usuario por correo solo cuentas con rol paciente
    $stmt = $pdo->prepare("SELECT u.id_usuario, u.password_hash, u.estado
                        FROM usuario u
                        INNER JOIN usuario_rol ur ON ur.id_usuario = u.id_usuario
                        INNER JOIN rol r ON r.id_rol = ur.id_rol
                        WHERE u.correo_acceso = ? AND r.nombre = 'paciente'");
    $stmt->execute([$correo]);
    $usuario = $stmt->fetch();

    // La contraseña esta guardada con hash se compara con password_verify
    if (!$usuario || !password_verify($clave, $usuario['password_hash'])) {
        echo json_encode([
            "exito" => false,
            "mensaje" => "Correo o contraseña incorrectos"
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($usuario['estado'] !== 'activa') {
        echo json_encode([
            "exito" => false,
            "mensaje" => "Tu cuenta está desactivada. Comunícate con el centro médico"
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $stmt = $pdo->prepare("UPDATE usuario SET ultimo_acceso = NOW() WHERE id_usuario = ?");
    $stmt->execute([$usuario['id_usuario']]);

    echo json_encode([
        "exito" => true,
        "mensaje" => "¡Bienvenido/a!",
        "idUsuario" => (int)$usuario['id_usuario']
    ], JSON_UNESCAPED_UNICODE);

} catch (Exception $e) {
    error_log("Error en el login: " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        "exito" => false,
        "mensaje" => "Error en el servidor."
    ], JSON_UNESCAPED_UNICODE);
}
?>
