<?php
// Cancela una cita del paciente (solo si faltan 24 horas o mas)

header("Content-Type: application/json; charset=UTF-8");
require_once "config/conexion.php";

$id_cita = isset($_POST['id_cita']) ? intval($_POST['id_cita']) : 0;
$id_usuario = isset($_POST['id_usuario']) ? intval($_POST['id_usuario']) : 0;

if ($id_cita <= 0 || $id_usuario <= 0) {
    echo json_encode(["exito" => false, "mensaje" => "Los datos de la cita no son validos"]);
    exit;
}

try {
    // El WHERE con el usuario evita cancelar citas de otro paciente
    $stmt = $pdo->prepare(
        "SELECT c.estado, c.inicio
        FROM cita c
        INNER JOIN paciente pa ON c.id_paciente = pa.id_paciente
        INNER JOIN usuario u ON pa.id_persona = u.id_persona
        WHERE c.id_cita = ? AND u.id_usuario = ?"
    );
    $stmt->execute([$id_cita, $id_usuario]);
    $cita = $stmt->fetch();

    if (!$cita) {
        echo json_encode(["exito" => false, "mensaje" => "La cita no existe"]);
        exit;
    }

    if ($cita['estado'] != "pendiente" && $cita['estado'] != "confirmada") {
        echo json_encode(["exito" => false, "mensaje" => "Esta cita ya no se puede cancelar"]);
        exit;
    }

    // Solo se cancela si faltan 24 horas o mas
    if (strtotime($cita['inicio']) < time() + 24 * 3600) {
        echo json_encode(["exito" => false, "mensaje" => "Ya no puedes cancelar esta cita porque faltan menos de 24 horas"]);
        exit;
    }

    $stmt = $pdo->prepare("UPDATE cita SET estado = 'cancelada' WHERE id_cita = ?");
    $stmt->execute([$id_cita]);

    echo json_encode(["exito" => true, "mensaje" => "Cita cancelada"], JSON_UNESCAPED_UNICODE);
} catch (Exception $e) {
    error_log($e->getMessage());
    echo json_encode(["exito" => false, "mensaje" => "Error al cancelar la cita"]);
}
?>
