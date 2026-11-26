<?php
// datos_personales.php

header("Content-Type: application/json; charset=UTF-8");
header("Cache-Control: no-store");
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
    // Unimos usuario con persona y filtramos solo cuentas con rol paciente
    $stmt = $pdo->prepare("SELECT p.nombres, p.apellido_paterno, p.apellido_materno,
                                p.telefono_principal AS telefono,
                                u.correo_acceso AS correo, u.estado
                            FROM usuario u
                            INNER JOIN persona p ON p.id_persona = u.id_persona
                            INNER JOIN usuario_rol ur ON ur.id_usuario = u.id_usuario
                            INNER JOIN rol r ON r.id_rol = ur.id_rol
                            WHERE u.id_usuario = ? AND r.nombre = 'paciente'");
    $stmt->execute([$idUsuario]);
    $datos = $stmt->fetch();

    if (!$datos) {
        echo json_encode([
            "exito" => false,
            "mensaje" => "No se encontró la cuenta"
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // Las cuentas no se eliminan, solo se desactivan
    if ($datos['estado'] !== 'activa') {
        echo json_encode([
            "exito" => false,
            "mensaje" => "Tu cuenta está desactivada. Comunícate con el centro médico"
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    echo json_encode([
        "exito" => true,
        "mensaje" => "",
        "nombres" => $datos['nombres'],
        "apellidoPaterno" => $datos['apellido_paterno'],
        "apellidoMaterno" => $datos['apellido_materno'] ?? "",
        "telefono" => $datos['telefono'] ?? "",
        "correo" => $datos['correo']
    ], JSON_UNESCAPED_UNICODE);

} catch (Exception $e) {
    error_log("Error al obtener los datos personales: " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        "exito" => false,
        "mensaje" => "Error al consultar los datos personales."
    ], JSON_UNESCAPED_UNICODE);
}
?>