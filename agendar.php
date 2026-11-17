<?php
// Alta de cuenta de paciente en medicdate_multiplataforma.
// Recibe JSON o formulario POST y crea persona, usuario, paciente y rol en una transacción.
header("Content-Type: application/json; charset=UTF-8");
require_once __DIR__ . "/config/conexion.php";

function responder($codigo, $cuerpo)
{
    http_response_code($codigo);
    echo json_encode($cuerpo, JSON_UNESCAPED_UNICODE);
    exit;
}

try {

    $datos = json_decode(file_get_contents("php://input"), true);
    if (!is_array($datos)) {
        $datos = $_POST;
    }

    $nombres = trim((string) ($datos["nombres"] ?? ""));
    $apellidoPaterno = trim((string) ($datos["apellido_paterno"] ?? ""));
    $apellidoMaterno = trim((string) ($datos["apellido_materno"] ?? ""));
    $correo = trim((string) ($datos["correo"] ?? ""));
    $telefono = trim((string) ($datos["telefono"] ?? ""));
    $clave = (string) ($datos["clave"] ?? "");

    if ($nombres === "" || $apellidoPaterno === "" || $correo === "" || $telefono === "" || $clave === "") {
        responder(400, [
            "exito" => false,
            "mensaje" => "Debe enviar nombres, apellido paterno, correo, teléfono y contraseña."
        ]);
    }

    if (mb_strlen($nombres) > 100 || mb_strlen($apellidoPaterno) > 80 || mb_strlen($apellidoMaterno) > 80) {
        responder(400, [
            "exito" => false,
            "mensaje" => "El nombre o los apellidos superan la longitud permitida."
        ]);
    }

    if (!filter_var($correo, FILTER_VALIDATE_EMAIL) || mb_strlen($correo) > 254) {
        responder(400, [
            "exito" => false,
            "mensaje" => "El correo electrónico no es válido."
        ]);
    }

    if (mb_strlen($telefono) > 25) {
        responder(400, [
            "exito" => false,
            "mensaje" => "El teléfono supera la longitud permitida."
        ]);
    }

    if (strlen($clave) < 8 || !preg_match("/[A-Za-z]/", $clave) || !preg_match("/[0-9]/", $clave)) {
        responder(400, [
            "exito" => false,
            "mensaje" => "La contraseña debe tener al menos 8 caracteres, una letra y un número."
        ]);
    }

    $apellidoMaterno = $apellidoMaterno === "" ? null : $apellidoMaterno;
    $hash = password_hash($clave, PASSWORD_DEFAULT);
    if ($hash === false) {
        responder(500, [
            "exito" => false,
            "mensaje" => "No se pudo proteger la contraseña."
        ]);
    }

    $pdo->beginTransaction();

    $consultaCorreo = $pdo->prepare(
        "SELECT id_usuario FROM usuario WHERE correo_acceso = ? LIMIT 1 FOR UPDATE"
    );
    $consultaCorreo->execute([$correo]);
    if ($consultaCorreo->fetch()) {
        $pdo->rollBack();
        responder(409, [
            "exito" => false,
            "mensaje" => "Ya existe una cuenta con ese correo."
        ]);
    }

    $consultaPersona = $pdo->prepare(
        "SELECT id_persona FROM persona WHERE telefono_principal = ? LIMIT 1 FOR UPDATE"
    );
    $consultaPersona->execute([$telefono]);
    $persona = $consultaPersona->fetch();

    if ($persona) {
        $idPersona = (int) $persona["id_persona"];

        $consultaCuenta = $pdo->prepare(
            "SELECT id_usuario FROM usuario WHERE id_persona = ? LIMIT 1 FOR UPDATE"
        );
        $consultaCuenta->execute([$idPersona]);
        if ($consultaCuenta->fetch()) {
            $pdo->rollBack();
            responder(409, [
                "exito" => false,
                "mensaje" => "Ese teléfono ya está vinculado a una cuenta."
            ]);
        }

        $actualizarPersona = $pdo->prepare(
            "UPDATE persona
             SET correo_contacto = ?, telefono_principal = ?
             WHERE id_persona = ?"
        );
        $actualizarPersona->execute([$correo, $telefono, $idPersona]);
        $vinculada = true;
    } else {
        $insertarPersona = $pdo->prepare(
            "INSERT INTO persona (nombres, apellido_paterno, apellido_materno, correo_contacto, telefono_principal)
             VALUES (?, ?, ?, ?, ?)"
        );
        $insertarPersona->execute([
            $nombres,
            $apellidoPaterno,
            $apellidoMaterno,
            $correo,
            $telefono
        ]);
        $idPersona = (int) $pdo->lastInsertId();
        $vinculada = false;
    }

    $insertarUsuario = $pdo->prepare(
        "INSERT INTO usuario (id_persona, correo_acceso, password_hash, estado)
         VALUES (?, ?, ?, 'activa')"
    );
    $insertarUsuario->execute([$idPersona, $correo, $hash]);
    $idUsuario = (int) $pdo->lastInsertId();

    $consultaPaciente = $pdo->prepare(
        "SELECT id_paciente FROM paciente WHERE id_persona = ? LIMIT 1 FOR UPDATE"
    );
    $consultaPaciente->execute([$idPersona]);
    $paciente = $consultaPaciente->fetch();

    if ($paciente) {
        $idPaciente = (int) $paciente["id_paciente"];
    } else {
        $insertarPaciente = $pdo->prepare(
            "INSERT INTO paciente (id_persona, estado) VALUES (?, 'activo')"
        );
        $insertarPaciente->execute([$idPersona]);
        $idPaciente = (int) $pdo->lastInsertId();
    }

    $consultaRol = $pdo->prepare(
        "SELECT id_rol FROM rol WHERE nombre = 'paciente' LIMIT 1"
    );
    $consultaRol->execute();
    $rol = $consultaRol->fetch();
    if (!$rol) {
        throw new RuntimeException("No existe el rol paciente en la base de datos.");
    }

    $asignarRol = $pdo->prepare(
        "INSERT INTO usuario_rol (id_usuario, id_rol) VALUES (?, ?)"
    );
    $asignarRol->execute([$idUsuario, (int) $rol["id_rol"]]);

    $auditoria = $pdo->prepare(
        "INSERT INTO auditoria (id_usuario, modulo, accion, entidad, id_registro, resultado, observacion)
         VALUES (?, 'registro', 'crear_cuenta', 'usuario', ?, 'correcto', ?)"
    );
    $auditoria->execute([
        $idUsuario,
        (string) $idUsuario,
        $vinculada
            ? "Cuenta vinculada a una persona ya registrada por teléfono."
            : "Cuenta de paciente creada desde el registro móvil."
    ]);

    $pdo->commit();

    responder(201, [
        "exito" => true,
        "mensaje" => $vinculada
            ? "Cuenta creada y vinculada al registro existente."
            : "Cuenta creada correctamente.",
        "id_persona" => $idPersona,
        "id_usuario" => $idUsuario,
        "id_paciente" => $idPaciente
    ]);
} catch (PDOException $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log("Error al registrar paciente: " . $e->getMessage());

    if ((string) $e->getCode() === "23000") {
        responder(409, [
            "exito" => false,
            "mensaje" => "El correo o la persona ya tienen una cuenta registrada."
        ]);
    }

    responder(500, [
        "exito" => false,
        "mensaje" => "Error al registrar la cuenta."
    ]);
} catch (Exception $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log("Error al registrar paciente: " . $e->getMessage());
    responder(500, [
        "exito" => false,
        "mensaje" => "Error al registrar la cuenta."
    ]);
}
