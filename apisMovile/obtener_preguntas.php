<?php
header("Content-Type: application/json; charset=UTF-8");
require_once "config/conexion.php";

try {
    $stmt = $pdo->query("SELECT id_pregunta_secreta, texto
                        FROM pregunta_secreta
                        WHERE activo = 1
                        ORDER BY id_pregunta_secreta");

    $preguntas = [];
    foreach ($stmt->fetchAll() as $fila) {
        $preguntas[] = [
            "idPregunta" => (int)$fila['id_pregunta_secreta'],
            "texto" => $fila['texto']
        ];
    }

    echo json_encode([
        "exito" => true,
        "mensaje" => "",
        "preguntas" => $preguntas
    ], JSON_UNESCAPED_UNICODE);

} catch (Exception $e) {
    error_log("Error al obtener las preguntas secretas: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(["exito" => false, "mensaje" => "Error al cargar las preguntas."], JSON_UNESCAPED_UNICODE);
}
?>