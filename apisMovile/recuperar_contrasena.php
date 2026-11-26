<?php
// recuperar_contrasena.php (paso 2 de recuperar la contraseña)

header("Content-Type: application/json; charset=UTF-8");
require_once "config/conexion.php";

// Datos enviados en formato JSON desde Android (@Body)
$jsonInput = file_get_contents("php://input");
$data = json_decode($jsonInput, true);

$correo = isset($data['correo']) ? trim($data['correo']) : '';
$respuesta = isset($data['respuesta']) ? trim($data['respuesta']) : '';
$claveNueva = isset($data['claveNueva']) ? trim($data['claveNueva']) : '';

// 1. Validaciones
if (empty($correo) || empty($respuesta) || empty($claveNueva)) {
    echo json_encode(["exito" => false, "mensaje" => "Completa todos los campos"], JSON_UNESCAPED_UNICODE);
    exit;
}
if (strlen($claveNueva) < 8) {
    echo json_encode(["exito" => false, "mensaje" => "La contraseña debe tener al menos 8 caracteres"], JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    // 2. Buscamos la cuenta y su respuesta secreta (solo pacientes activos)
    $stmt = $pdo->prepare("SELECT u.id_usuario, ups.respuesta_hash
                        FROM usuario u
                        INNER JOIN usuario_rol ur ON ur.id_usuario = u.id_usuario
                        INNER JOIN rol r ON r.id_rol = ur.id_rol
                        INNER JOIN usuario_pregunta_secreta ups ON ups.id_usuario = u.id_usuario
                        WHERE u.correo_acceso = ? AND r.nombre = 'paciente' AND u.estado = 'activa'");
    $stmt->execute([$correo]);
    $cuenta = $stmt->fetch();

    if (!$cuenta) {
        echo json_encode(["exito" => false, "mensaje" => "No se puede recuperar la contraseña con ese correo"], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // 3. La respuesta se compara en minusculas (igual que al registrarla)
    $respuestaLimpia = strtolower($respuesta);
    $hash = $cuenta['respuesta_hash'];

    if (strpos($hash, '$') === 0) {
        // Guardada con password_hash
        $correcta = password_verify($respuestaLimpia, $hash);
    } else {
        // Guardada con SHA-256
        $correcta = hash_equals($hash, hash('sha256', $respuestaLimpia));
    }

    if (!$correcta) {
        echo json_encode(["exito" => false, "mensaje" => "La respuesta es incorrecta"], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // 4. Guardamos la contraseña nueva con hash
    $stmt = $pdo->prepare("UPDATE usuario SET password_hash = ? WHERE id_usuario = ?");
    $stmt->execute([password_hash($claveNueva, PASSWORD_DEFAULT), $cuenta['id_usuario']]);

    echo json_encode(["exito" => true, "mensaje" => "Contraseña actualizada"], JSON_UNESCAPED_UNICODE);

} catch (Exception $e) {
    error_log("Error al recuperar la contraseña: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(["exito" => false, "mensaje" => "Error al recuperar la contraseña."], JSON_UNESCAPED_UNICODE);
}
?>
