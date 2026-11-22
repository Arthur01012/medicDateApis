<?php
declare(strict_types=1);

require_once __DIR__ . '/conexion.php';

/**
 * Configura encabezados estándar JSON, CORS y no-cache para las APIs web.
 */
function inicializarApiWeb(array $metodosPermitidos = ['GET']): void
{
    $metodos = array_unique(array_merge($metodosPermitidos, ['OPTIONS']));
    header('Content-Type: application/json; charset=UTF-8');
    header('Access-Control-Allow-Origin: *');
    header('Access-Control-Allow-Methods: ' . implode(', ', $metodos));
    header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Session-Token');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Cache-Control: post-check=0, pre-check=0', false);
    header('Pragma: no-cache');

    $metodoActual = $_SERVER['REQUEST_METHOD'] ?? 'GET';

    if ($metodoActual === 'OPTIONS') {
        http_response_code(204);
        exit;
    }

    if (!in_array($metodoActual, $metodosPermitidos, true)) {
        responderJson(405, [
            'exito' => false,
            'codigo' => 'METODO_NO_PERMITIDO',
            'mensaje' => 'Método HTTP no permitido en este endpoint. Solo se permite: ' . implode(', ', $metodosPermitidos) . '.'
        ]);
    }
}

/**
 * Envía una respuesta JSON y termina la ejecución.
 */
function responderJson(int $codigoHttp, array $payload): void
{
    http_response_code($codigoHttp);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

/**
 * Lee parámetros JSON del cuerpo o de $_POST / $_GET.
 */
function leerEntradaApi(): array
{
    $metodo = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    if ($metodo === 'GET') {
        return $_GET;
    }

    $crudo = file_get_contents('php://input');
    if ($crudo !== false && trim($crudo) !== '') {
        $decodificado = json_decode($crudo, true);
        if (json_last_error() === JSON_ERROR_NONE && is_array($decodificado)) {
            return array_merge($_GET, $decodificado);
        }
        responderJson(400, [
            'exito' => false,
            'codigo' => 'JSON_INVALIDO',
            'mensaje' => 'El cuerpo de la solicitud no contiene un JSON válido.'
        ]);
    }

    return array_merge($_GET, $_POST);
}

/**
 * Convierte un valor a entero positivo o null.
 */
function enteroPositivo($valor): ?int
{
    if ($valor === null || $valor === '') {
        return null;
    }
    $id = filter_var($valor, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    return $id === false ? null : (int) $id;
}

/**
 * Valida una fecha en formato YYYY-MM-DD.
 */
function esFechaValida(?string $fecha): bool
{
    if ($fecha === null || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $fecha)) {
        return false;
    }
    [$anio, $mes, $dia] = array_map('intval', explode('-', $fecha));
    return checkdate($mes, $dia, $anio);
}

/**
 * Extrae el token de sesión desde headers o parámetros.
 */
function obtenerTokenSesion(array $entrada): ?string
{
    $auth = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
    if (preg_match('/Bearer\s+(.+)/i', $auth, $coincidencias)) {
        return trim($coincidencias[1]);
    }
    if (!empty($_SERVER['HTTP_X_SESSION_TOKEN'])) {
        return trim((string) $_SERVER['HTTP_X_SESSION_TOKEN']);
    }
    if (!empty($entrada['token']) && is_string($entrada['token'])) {
        return trim($entrada['token']);
    }
    return null;
}

/**
 * Valida el contexto del usuario (token, id_sesion, correo_acceso, id_usuario o id_empleado)
 * usando las cuentas reales de la BD (ver Credenciales_Prueba_MedicDate.md).
 */
function validarUsuarioPorRol(mysqli $conexion, array $rolesPermitidos, array $entrada): array
{
    $token = obtenerTokenSesion($entrada);
    $idSesion = enteroPositivo($entrada['id_sesion'] ?? null);
    $correoParam = isset($entrada['correo_acceso'])
        ? trim((string) $entrada['correo_acceso'])
        : (isset($entrada['correo']) ? trim((string) $entrada['correo']) : '');
    $idUsuarioParam = enteroPositivo($entrada['id_usuario'] ?? null);
    $idEmpleadoParam = enteroPositivo($entrada['id_empleado'] ?? ($entrada['id_doctor'] ?? null));

    $idUsuario = null;

    // 1. Si llega token, resolver contra sesion_usuario
    if ($token !== null) {
        $hash = hash('sha256', $token);
        $stmt = $conexion->prepare(
            "SELECT id_usuario
             FROM sesion_usuario
             WHERE (token_hash = ? OR token_hash = ?)
               AND fecha_revocacion IS NULL
               AND fecha_expiracion > NOW()
             LIMIT 1"
        );
        $stmt->bind_param('ss', $hash, $token);
        $stmt->execute();
        $fila = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$fila) {
            responderJson(401, [
                'exito' => false,
                'codigo' => 'SESION_INVALIDA',
                'mensaje' => 'Tu sesión expiró o no es válida. Inicia sesión nuevamente.'
            ]);
        }
        $idUsuario = (int) $fila['id_usuario'];
    } elseif ($idSesion !== null) {
        $stmt = $conexion->prepare(
            "SELECT id_usuario
             FROM sesion_usuario
             WHERE id_sesion = ?
               AND fecha_revocacion IS NULL
               AND fecha_expiracion > NOW()
             LIMIT 1"
        );
        $stmt->bind_param('i', $idSesion);
        $stmt->execute();
        $fila = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$fila) {
            responderJson(401, [
                'exito' => false,
                'codigo' => 'SESION_INVALIDA',
                'mensaje' => 'La sesión especificada no es válida o ya expiró.'
            ]);
        }
        $idUsuario = (int) $fila['id_usuario'];
    } elseif ($correoParam !== '') {
        $stmt = $conexion->prepare(
            "SELECT id_usuario FROM usuario WHERE LOWER(correo_acceso) = LOWER(?) LIMIT 1"
        );
        $stmt->bind_param('s', $correoParam);
        $stmt->execute();
        $fila = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if ($fila) {
            $idUsuario = (int) $fila['id_usuario'];
        }
    }

    if ($idUsuario === null && $idUsuarioParam !== null) {
        $idUsuario = $idUsuarioParam;
    }

    if ($idUsuario === null && $idEmpleadoParam !== null && in_array('medico', $rolesPermitidos, true)) {
        $stmt = $conexion->prepare(
            "SELECT u.id_usuario
             FROM empleado e
             INNER JOIN usuario u ON u.id_persona = e.id_persona
             WHERE e.id_empleado = ?
             LIMIT 1"
        );
        $stmt->bind_param('i', $idEmpleadoParam);
        $stmt->execute();
        $fila = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if ($fila) {
            $idUsuario = (int) $fila['id_usuario'];
        }
    }

    if ($idUsuario === null) {
        responderJson(401, [
            'exito' => false,
            'codigo' => 'AUTENTICACION_REQUERIDA',
            'mensaje' => 'Se requiere identificar la sesión o el usuario autenticado.'
        ]);
    }

    // Obtener datos del usuario y empleado vinculado en la BD
    $stmt = $conexion->prepare(
        "SELECT
            u.id_usuario,
            u.id_persona,
            u.correo_acceso,
            u.estado AS estado_usuario,
            CONCAT_WS(' ', p.nombres, p.apellido_paterno, p.apellido_materno) AS nombre_completo,
            e.id_empleado,
            e.estado AS estado_empleado
         FROM usuario u
         INNER JOIN persona p ON p.id_persona = u.id_persona
         LEFT JOIN empleado e ON e.id_persona = u.id_persona
         WHERE u.id_usuario = ?
         LIMIT 1"
    );
    $stmt->bind_param('i', $idUsuario);
    $stmt->execute();
    $usuario = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$usuario) {
        responderJson(401, [
            'exito' => false,
            'codigo' => 'USUARIO_NO_ENCONTRADO',
            'mensaje' => 'No se encontró la cuenta de usuario.'
        ]);
    }

    if ($usuario['estado_usuario'] !== 'activa') {
        responderJson(403, [
            'exito' => false,
            'codigo' => 'CUENTA_INACTIVA',
            'mensaje' => 'Tu cuenta se encuentra inactiva o bloqueada.'
        ]);
    }

    // Obtener roles asignados
    $stmt = $conexion->prepare(
        "SELECT r.nombre
         FROM usuario_rol ur
         INNER JOIN rol r ON r.id_rol = ur.id_rol
         WHERE ur.id_usuario = ?"
    );
    $stmt->bind_param('i', $idUsuario);
    $stmt->execute();
    $resRoles = $stmt->get_result();
    $roles = [];
    while ($filaRol = $resRoles->fetch_assoc()) {
        $roles[] = $filaRol['nombre'];
    }
    $stmt->close();

    $tienePermiso = count(array_intersect($roles, $rolesPermitidos)) > 0;
    if (!$tienePermiso) {
        responderJson(403, [
            'exito' => false,
            'codigo' => 'ACCESO_DENEGADO',
            'mensaje' => 'No tienes permisos para acceder a este recurso.'
        ]);
    }

    $idEmpleado = $usuario['id_empleado'] !== null ? (int) $usuario['id_empleado'] : null;

    if ($rolesPermitidos === ['medico'] && $idEmpleado === null) {
        responderJson(403, [
            'exito' => false,
            'codigo' => 'MEDICO_NO_VINCULADO',
            'mensaje' => 'La cuenta no está vinculada a un perfil médico.'
        ]);
    }

    return [
        'id_usuario' => (int) $usuario['id_usuario'],
        'id_persona' => (int) $usuario['id_persona'],
        'id_empleado' => $idEmpleado,
        'nombre_completo' => trim((string) $usuario['nombre_completo']),
        'correo' => (string) $usuario['correo_acceso'],
        'roles' => $roles
    ];
}

/**
 * Calcula la edad en años a partir de una fecha YYYY-MM-DD.
 */
function calcularEdad(?string $fechaNacimiento): ?int
{
    if (!$fechaNacimiento || !esFechaValida($fechaNacimiento)) {
        return null;
    }
    try {
        $nac = new DateTimeImmutable($fechaNacimiento);
        $hoy = new DateTimeImmutable('today');
        return $nac->diff($hoy)->y;
    } catch (Exception $e) {
        return null;
    }
}

/**
 * Devuelve la etiqueta visual del prototipo (Programada, Atendida, Cancelada)
 * a partir del estado normalizado de la BD.
 */
function etiquetaEstadoCita(string $estado): string
{
    $e = strtolower(trim($estado));
    if (in_array($e, ['pendiente', 'confirmada', 'en_progreso', 'programada'], true)) {
        return 'Programada';
    }
    if (in_array($e, ['completada', 'atendida'], true)) {
        return 'Atendida';
    }
    if (in_array($e, ['cancelada', 'inasistencia'], true)) {
        return 'Cancelada';
    }
    return ucfirst($estado);
}

/**
 * Convierte número de día ISO-8601 (1..7) a abreviatura en español.
 */
function nombreDiaCorto(int $diaSemana): string
{
    $dias = [1 => 'Lun', 2 => 'Mar', 3 => 'Mié', 4 => 'Jue', 5 => 'Vie', 6 => 'Sáb', 7 => 'Dom'];
    return $dias[$diaSemana] ?? '';
}

/**
 * Registra un evento en la tabla auditoria sin interrumpir el flujo principal si falla.
 */
function registrarAuditoria(
    mysqli $conexion,
    ?int $idUsuario,
    string $modulo,
    string $accion,
    ?string $entidad,
    ?string $idRegistro,
    string $resultado = 'correcto',
    ?string $observacion = null
): void {
    try {
        $stmt = $conexion->prepare(
            "INSERT INTO auditoria (id_usuario, modulo, accion, entidad, id_registro, resultado, observacion)
             VALUES (?, ?, ?, ?, ?, ?, ?)"
        );
        $stmt->bind_param('issssss', $idUsuario, $modulo, $accion, $entidad, $idRegistro, $resultado, $observacion);
        $stmt->execute();
        $stmt->close();
    } catch (Throwable $e) {
        error_log('Error al registrar auditoría: ' . $e->getMessage());
    }
}
