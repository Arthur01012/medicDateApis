<?php
header("Content-Type: application/json; charset=UTF-8");
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Cache-Control: post-check=0, pre-check=0", false);
header("Pragma: no-cache");

function citasApiResponder($codigoHttp, $respuesta)
{
    header("Content-Type: application/json; charset=UTF-8");
    header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
    header("Cache-Control: post-check=0, pre-check=0", false);
    header("Pragma: no-cache");
    http_response_code($codigoHttp);
    echo json_encode($respuesta, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

function citasApiLeerEntrada()
{
    $contenido = file_get_contents("php://input");
    if ($contenido === false || trim($contenido) === "") {
        return $_POST;
    }

    $datos = json_decode($contenido, true);
    if (json_last_error() === JSON_ERROR_NONE && is_array($datos)) {
        if (isset($datos["datos"]) && is_string($datos["datos"])) {
            $parametros = json_decode($datos["datos"], true);
            if (json_last_error() !== JSON_ERROR_NONE || !is_array($parametros)) {
                citasApiResponder(400, [
                    "exito" => false,
                    "codigo" => "DATOS_INVALIDOS",
                    "mensaje" => "El parámetro datos debe contener una cadena JSON válida."
                ]);
            }
            return $parametros;
        }
        return $datos;
    }

    if (!empty($_POST)) {
        return $_POST;
    }

    citasApiResponder(400, [
        "exito" => false,
        "codigo" => "JSON_INVALIDO",
        "mensaje" => "No se pudo leer la solicitud. Verifica los datos e inténtalo de nuevo."
    ]);
}

function citasApiLeerDatosQuery()
{
    $cadena = $_GET["datos"] ?? null;
    if (!is_string($cadena) || trim($cadena) === "") {
        citasApiResponder(400, [
            "exito" => false,
            "codigo" => "DATOS_REQUERIDOS",
            "mensaje" => "Envía los parámetros dentro de la cadena datos."
        ]);
    }

    $datos = json_decode($cadena, true);
    if (json_last_error() !== JSON_ERROR_NONE || !is_array($datos)) {
        citasApiResponder(400, [
            "exito" => false,
            "codigo" => "DATOS_INVALIDOS",
            "mensaje" => "El parámetro datos debe contener una cadena JSON válida."
        ]);
    }

    return $datos;
}

function citasApiId($valor)
{
    $id = filter_var($valor, FILTER_VALIDATE_INT, [
        "options" => ["min_range" => 1]
    ]);

    return $id === false ? null : (int) $id;
}

function citasApiFechaValida($fecha)
{
    if (!is_string($fecha) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $fecha)) {
        return false;
    }

    $partes = explode("-", $fecha);
    return checkdate((int) $partes[1], (int) $partes[2], (int) $partes[0]);
}

function citasApiInicioValido($valor)
{
    if (!is_string($valor)) {
        return null;
    }

    $valor = str_replace("T", " ", trim($valor));
    if (preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}$/', $valor)) {
        $valor .= ":00";
    }

    if (!preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:00$/', $valor)) {
        return null;
    }

    $fechaHora = DateTimeImmutable::createFromFormat("!Y-m-d H:i:s", $valor);
    $errores = DateTimeImmutable::getLastErrors();
    if ($fechaHora === false || ($errores !== false && ($errores["warning_count"] > 0 || $errores["error_count"] > 0))) {
        return null;
    }

    return $fechaHora;
}

function citasApiBuscarConflicto(PDO $pdo, $idEmpleado, $idPaciente, $idConsultorio, $inicio, $fin)
{
    $consulta = $pdo->prepare(
        "SELECT
            EXISTS (
                SELECT 1 FROM cita c
                WHERE c.id_empleado = ?
                  AND c.estado IN ('pendiente', 'confirmada', 'en_progreso')
                  AND c.inicio < ? AND c.fin > ?
            ) AS medico_ocupado,
            EXISTS (
                SELECT 1 FROM cita c
                WHERE c.id_paciente = ?
                  AND c.estado IN ('pendiente', 'confirmada', 'en_progreso')
                  AND c.inicio < ? AND c.fin > ?
            ) AS paciente_ocupado,
            EXISTS (
                SELECT 1 FROM cita c
                WHERE c.id_consultorio = ?
                  AND c.estado IN ('pendiente', 'confirmada', 'en_progreso')
                  AND c.inicio < ? AND c.fin > ?
            ) AS consultorio_ocupado,
            EXISTS (
                SELECT 1 FROM bloqueo_horario b
                WHERE b.id_empleado = ?
                  AND b.inicio < ? AND b.fin > ?
            ) AS medico_bloqueado,
            EXISTS (
                SELECT 1 FROM bloqueo_horario b
                WHERE b.id_consultorio = ?
                  AND b.inicio < ? AND b.fin > ?
            ) AS consultorio_bloqueado"
    );
    $consulta->execute([
        $idEmpleado, $fin, $inicio,
        $idPaciente, $fin, $inicio,
        $idConsultorio, $fin, $inicio,
        $idEmpleado, $fin, $inicio,
        $idConsultorio, $fin, $inicio
    ]);
    $conflictos = $consulta->fetch();

    if ($conflictos["medico_bloqueado"] || $conflictos["consultorio_bloqueado"]) {
        $stMotivo = $pdo->prepare(
            "SELECT motivo
             FROM bloqueo_horario
             WHERE (id_empleado = ? OR id_consultorio = ?)
               AND inicio < ? AND fin > ?
             ORDER BY id_bloqueo DESC
             LIMIT 1"
        );
        $stMotivo->execute([$idEmpleado, $idConsultorio, $fin, $inicio]);
        $filaBloqueo = $stMotivo->fetch();
        $motivo = isset($filaBloqueo["motivo"]) && trim((string) $filaBloqueo["motivo"]) !== ""
            ? " (" . trim((string) $filaBloqueo["motivo"]) . ")"
            : "";

        return [
            "codigo" => "AGENDA_BLOQUEADA",
            "mensaje" => "Ese horario ya no está disponible. El médico o el consultorio tiene el horario bloqueado" . $motivo . ". Elige otro horario para continuar."
        ];
    }
    if ($conflictos["medico_ocupado"]) {
        return [
            "codigo" => "MEDICO_OCUPADO",
            "mensaje" => "Ese horario ya no está disponible. Otra persona lo reservó hace un momento. Elige otro horario para continuar."
        ];
    }
    if ($conflictos["paciente_ocupado"]) {
        return [
            "codigo" => "PACIENTE_OCUPADO",
            "mensaje" => "Ya tienes otra cita que coincide con ese horario. Elige una hora diferente."
        ];
    }
    if ($conflictos["consultorio_ocupado"]) {
        return [
            "codigo" => "CONSULTORIO_OCUPADO",
            "mensaje" => "El consultorio asignado ya está ocupado en ese horario. Elige otro horario para continuar."
        ];
    }

    return null;
}
