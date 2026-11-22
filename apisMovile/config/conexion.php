<?php
$host = "medicdate.innego.net";
$db = "innegone_bd_medicdate";
$user = "innegone_u_medicdate"; 
$pass = "*EzsnHGD8%)mdEgu";     

try {
    $pdo = new PDO("mysql:host=$host;dbname=$db;charset=utf8mb4", $user, $pass);
    $pdo->setAttribute(PDO::ATTR_EMULATE_PREPARES, false);
    // Configuramos PDO para que lance excepciones si ocurre un error en los queries
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // Configuramos para que por defecto devuelva los datos como un array asociativo
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

} catch (PDOException $e) {
    // Si la conexion falla, devolvemos un codigo de error HTTP 500 y un JSON explicando el problema
    header("Content-Type: application/json", true, 500);
    echo json_encode([
        "exito" => false,
        "mensaje" => "Error crítico de conexión al servidor"
    ]);
    exit;
}
?>
