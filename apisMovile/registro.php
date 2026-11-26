<?php
// Este es el api del registro.php (crear cuenta de paciente)

header("Content-Type: application/json; charset=UTF-8");
require_once "config/conexion.php";

// Datos enviados por Retrofit desde Android (@FormUrlEncoded)
$nombres = isset($_POST['nombres']) ? trim($_POST['nombres']) : '';
$apellidoPaterno = isset($_POST['apellidoPaterno']) ? trim($_POST['apellidoPaterno']) : '';
$apellidoMaterno = isset($_POST['apellidoMaterno']) ? trim($_POST['apellidoMaterno']) : '';
$correo = isset($_POST['correo']) ? trim($_POST['correo']) : '';
$telefono = isset($_POST['telefono']) ? trim($_POST['telefono']) : '';
$clave = isset($_POST['clave']) ? trim($_POST['clave']) : '';
$idPregunta = isset($_POST['idPregunta']) ? intval($_POST['idPregunta']) : 0;
$respuesta = isset($_POST['respuesta']) ? trim($_POST['respuesta']) : '';

// 1. Validaciones (el apellido materno es opcional)
if (empty($nombres) || empty($apellidoPaterno) || empty($correo) || empty($telefono) || empty($clave)) {
    echo json_encode(["exito" => false, "mensaje" => "Completa todos los campos obligatorios"], JSON_UNESCAPED_UNICODE);
    exit;
}
if (!filter_var($correo, FILTER_VALIDATE_EMAIL)) {
    echo json_encode(["exito" => false, "mensaje" => "El correo electrónico no es válido"], JSON_UNESCAPED_UNICODE);
    exit;
}
if (strlen($clave) < 8) {
    echo json_encode(["exito" => false, "mensaje" => "La contraseña debe tener al menos 8 caracteres"], JSON_UNESCAPED_UNICODE);
    exit;
}
if ($idPregunta <= 0 || empty($respuesta)) {
    echo json_encode(["exito" => false, "mensaje" => "Elige tu pregunta secreta y escribe tu respuesta"], JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    // 2. El correo no puede estar repetido
    $stmt = $pdo->prepare("SELECT id_usuario FROM usuario WHERE correo_acceso = ?");
    $stmt->execute([$correo]);
    if ($stmt->fetch()) {
        echo json_encode(["exito" => false, "mensaje" => "Ya existe una cuenta con ese correo"], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // 3. Si el centro medico ya registro al paciente (mismo correo o telefono, sin cuenta), se vincula
    $stmt = $pdo->prepare("SELECT p.id_persona
                        FROM persona p
                        LEFT JOIN usuario u ON u.id_persona = p.id_persona
                        WHERE u.id_usuario IS NULL AND (p.correo_contacto = ? OR p.telefono_principal = ?)
                        LIMIT 1");
    $stmt->execute([$correo, $telefono]);
    $persona = $stmt->fetch();

    $pdo->beginTransaction();

    if ($persona) {
        $idPersona = (int)$persona['id_persona'];
    } else {
        $stmt = $pdo->prepare("INSERT INTO persona (nombres, apellido_paterno, apellido_materno, correo_contacto, telefono_principal)
                            VALUES (?, ?, ?, ?, ?)");
        $stmt->execute([$nombres, $apellidoPaterno, ($apellidoMaterno === '' ? null : $apellidoMaterno), $correo, $telefono]);
        $idPersona = (int)$pdo->lastInsertId();
    }

    // 4. Creamos la cuenta (la contraseña se guarda con hash) y le damos el rol paciente
    $stmt = $pdo->prepare("INSERT INTO usuario (id_persona, correo_acceso, password_hash) VALUES (?, ?, ?)");
    $stmt->execute([$idPersona, $correo, password_hash($clave, PASSWORD_DEFAULT)]);
    $idUsuario = (int)$pdo->lastInsertId();

    $stmt = $pdo->prepare("INSERT INTO usuario_rol (id_usuario, id_rol) SELECT ?, id_rol FROM rol WHERE nombre = 'paciente'");
    $stmt->execute([$idUsuario]);

    // La respuesta secreta tambien se guarda con hash (en minusculas para que "Luna" y "luna" valgan igual)
    $stmt = $pdo->prepare("INSERT INTO usuario_pregunta_secreta (id_usuario, id_pregunta_secreta, respuesta_hash) VALUES (?, ?, ?)");
    $stmt->execute([$idUsuario, $idPregunta, password_hash(strtolower($respuesta), PASSWORD_DEFAULT)]);

        // 5. Si la persona todavia no es paciente, la registramos
    $stmt = $pdo->prepare("SELECT id_paciente FROM paciente WHERE id_persona = ?");
    $stmt->execute([$idPersona]);
    $paciente = $stmt->fetch();
    if ($paciente) {
        $idPaciente = (int)$paciente['id_paciente'];
    } else {
        $stmt = $pdo->prepare("INSERT INTO paciente (id_persona) VALUES (?)");
        $stmt->execute([$idPersona]);
        $idPaciente = (int)$pdo->lastInsertId();
    }

    $pdo->commit();

    echo json_encode([
        "exito" => true,
        "mensaje" => "¡Cuenta creada!",
        "idUsuario" => $idUsuario
    ], JSON_UNESCAPED_UNICODE);

} catch (Exception $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log("Error al registrar la cuenta: " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        "exito" => false,
        "mensaje" => "Error al crear la cuenta: " . $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
}
?>