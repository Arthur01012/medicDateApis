<?php
// actualizar_datos_personales.php (el correo no se edita)

header("Content-Type: application/json; charset=UTF-8");
require_once "config/conexion.php";

// Datos enviados en formato JSON desde Android (@Body)
$jsonInput = file_get_contents("php://input");
$data = json_decode($jsonInput, true);

$idUsuario = isset($data['idUsuario']) ? intval($data['idUsuario']) : 0;
$nombres = isset($data['nombres']) ? trim($data['nombres']) : '';
$apellidoPaterno = isset($data['apellidoPaterno']) ? trim($data['apellidoPaterno']) : '';
$apellidoMaterno = isset($data['apellidoMaterno']) ? trim($data['apellidoMaterno']) : '';
$telefono = isset($data['telefono']) ? trim($data['telefono']) : '';

// 1. Validaciones (el apellido materno es opcional)
if ($idUsuario <= 0) {
    http_response_code(400);
    echo json_encode(["exito" => false, "mensaje" => "Debe enviar el id del usuario"], JSON_UNESCAPED_UNICODE);
    exit;
}
if (empty($nombres) || empty($apellidoPaterno) || empty($telefono)) {
    echo json_encode(["exito" => false, "mensaje" => "Completa todos los campos obligatorios"], JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    // 2. Buscamos la persona de ese usuario (solo cuentas activas con rol paciente)
    $stmt = $pdo->prepare("SELECT u.id_persona
                        FROM usuario u
                        INNER JOIN usuario_rol ur ON ur.id_usuario = u.id_usuario
                        INNER JOIN rol r ON r.id_rol = ur.id_rol
                        WHERE u.id_usuario = ? AND r.nombre = 'paciente' AND u.estado = 'activa'");
    $stmt->execute([$idUsuario]);
    $usuario = $stmt->fetch();

    if (!$usuario) {
        echo json_encode(["exito" => false, "mensaje" => "No se encontró la cuenta"], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // 3. Actualizamos los datos personales
    $stmt = $pdo->prepare("UPDATE persona
                        SET nombres = ?, apellido_paterno = ?, apellido_materno = ?, telefono_principal = ?
                        WHERE id_persona = ?");
    $stmt->execute([$nombres, $apellidoPaterno, ($apellidoMaterno === '' ? null : $apellidoMaterno), $telefono, $usuario['id_persona']]);

    echo json_encode(["exito" => true, "mensaje" => "Datos actualizados"], JSON_UNESCAPED_UNICODE);

} catch (Exception $e) {
    error_log("Error al actualizar los datos personales: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(["exito" => false, "mensaje" => "Error al actualizar los datos."], JSON_UNESCAPED_UNICODE);
}
?>