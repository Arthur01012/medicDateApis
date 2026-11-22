<?php
declare(strict_types=1);

/**
 * API: Pacientes y ficha del asistente (SOLO LECTURA)
 * Ruta: apisWeb/apis_asist/ficha_pacientes.php
 * Método: GET
 * Reglas:
 *  - Lista de pacientes con búsqueda por nombre, correo o teléfono.
 *  - Ficha individual (?id_paciente=X) con sus citas.
 *  - Solo lectura: sin notas médicas privadas ni notas internas de citas.
 */

require_once __DIR__ . '/../config/api_helper.php';

inicializarApiWeb(['GET']);

try {
    $conexion = conectarBD();
    $entrada = leerEntradaApi();

    $contexto = validarUsuarioPorRol($conexion, ['asistente', 'administrador'], $entrada);

    $idPaciente = enteroPositivo($entrada['id_paciente'] ?? null);

    // =========================================================================
    // MODO FICHA INDIVIDUAL DEL PACIENTE (SOLO LECTURA, SIN NOTAS DEL MÉDICO)
    // =========================================================================
    if ($idPaciente !== null) {
        $stmt = $conexion->prepare(
            "SELECT
                pa.id_paciente,
                pa.estado,
                pa.fecha_registro,
                CONCAT_WS(' ', p.nombres, p.apellido_paterno, p.apellido_materno) AS nombre_completo,
                p.fecha_nacimiento,
                COALESCE(p.correo_contacto, u.correo_acceso) AS correo,
                p.telefono_principal AS telefono,
                p.telefono_secundario
             FROM paciente pa
             INNER JOIN persona p ON p.id_persona = pa.id_persona
             LEFT JOIN usuario u ON u.id_persona = p.id_persona
             WHERE pa.id_paciente = ?
             LIMIT 1"
        );
        $stmt->bind_param('i', $idPaciente);
        $stmt->execute();
        $paciente = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$paciente) {
            responderJson(404, [
                'exito' => false,
                'codigo' => 'PACIENTE_NO_ENCONTRADO',
                'mensaje' => 'No se encontró el paciente solicitado.'
            ]);
        }

        // Citas del paciente SIN notas_internas
        $stmtCitas = $conexion->prepare(
            "SELECT
                c.id_cita,
                DATE(c.inicio) AS fecha,
                TIME_FORMAT(c.inicio, '%H:%i') AS hora,
                c.estado,
                c.motivo,
                CONCAT_WS(' ', pd.nombres, pd.apellido_paterno, pd.apellido_materno) AS medico,
                ta.nombre AS tipo_atencion
             FROM cita c
             INNER JOIN doctor d ON d.id_empleado = c.id_empleado
             INNER JOIN empleado em ON em.id_empleado = d.id_empleado
             INNER JOIN persona pd ON pd.id_persona = em.id_persona
             INNER JOIN tipo_atencion ta ON ta.id_tipo_atencion = c.id_tipo_atencion
             WHERE c.id_paciente = ?
             ORDER BY c.inicio DESC"
        );
        $stmtCitas->bind_param('i', $idPaciente);
        $stmtCitas->execute();
        $resCitas = $stmtCitas->get_result();

        $citas = [];
        while ($c = $resCitas->fetch_assoc()) {
            $citas[] = [
                'id_cita' => (int) $c['id_cita'],
                'fecha' => $c['fecha'],
                'hora' => $c['hora'],
                'estado' => $c['estado'],
                'estado_etiqueta' => etiquetaEstadoCita((string) $c['estado']),
                'motivo' => $c['motivo'] ?? '',
                'medico' => trim((string) $c['medico']),
                'tipo_atencion' => $c['tipo_atencion']
            ];
        }
        $stmtCitas->close();

        responderJson(200, [
            'exito' => true,
            'solo_lectura' => true,
            'paciente' => [
                'id_paciente' => (int) $paciente['id_paciente'],
                'nombre' => trim((string) $paciente['nombre_completo']),
                'correo' => $paciente['correo'] ?? '—',
                'telefono' => $paciente['telefono'] ?? '—',
                'telefono_secundario' => $paciente['telefono_secundario'],
                'fecha_nacimiento' => $paciente['fecha_nacimiento'],
                'edad' => calcularEdad($paciente['fecha_nacimiento']),
                'estado' => $paciente['estado']
            ],
            'total_citas' => count($citas),
            'citas' => $citas
        ]);
    }

    // =========================================================================
    // MODO LISTADO DE PACIENTES CON BÚSQUEDA
    // =========================================================================
    $busqueda = isset($entrada['busqueda']) ? trim((string) $entrada['busqueda']) : '';

    $condiciones = ["pa.estado = 'activo'"];
    $tipos = '';
    $parametros = [];

    if ($busqueda !== '') {
        $patron = '%' . $busqueda . '%';
        $condiciones[] = "(
            CONCAT_WS(' ', p.nombres, p.apellido_paterno, p.apellido_materno) LIKE ?
            OR COALESCE(p.correo_contacto, u.correo_acceso, '') LIKE ?
            OR COALESCE(p.telefono_principal, '') LIKE ?
        )";
        $tipos .= 'sss';
        $parametros[] = $patron;
        $parametros[] = $patron;
        $parametros[] = $patron;
    }

    $sql = "SELECT
                pa.id_paciente,
                CONCAT_WS(' ', p.nombres, p.apellido_paterno, p.apellido_materno) AS nombre_completo,
                COALESCE(p.correo_contacto, u.correo_acceso) AS correo,
                p.telefono_principal AS telefono,
                p.fecha_nacimiento,
                (SELECT COUNT(*) FROM cita c WHERE c.id_paciente = pa.id_paciente) AS total_citas
            FROM paciente pa
            INNER JOIN persona p ON p.id_persona = pa.id_persona
            LEFT JOIN usuario u ON u.id_persona = p.id_persona
            WHERE " . implode(' AND ', $condiciones) . "
            ORDER BY p.nombres ASC, p.apellido_paterno ASC";

    $stmt = $conexion->prepare($sql);
    if ($tipos !== '') {
        $stmt->bind_param($tipos, ...$parametros);
    }
    $stmt->execute();
    $res = $stmt->get_result();

    $pacientes = [];
    while ($fila = $res->fetch_assoc()) {
        $pacientes[] = [
            'id_paciente' => (int) $fila['id_paciente'],
            'nombre' => trim((string) $fila['nombre_completo']),
            'correo' => $fila['correo'] ?? '—',
            'telefono' => $fila['telefono'] ?? '—',
            'fecha_nacimiento' => $fila['fecha_nacimiento'],
            'edad' => calcularEdad($fila['fecha_nacimiento']),
            'total_citas' => (int) $fila['total_citas']
        ];
    }
    $stmt->close();

    responderJson(200, [
        'exito' => true,
        'solo_lectura' => true,
        'usuario' => [
            'id_usuario' => $contexto['id_usuario'],
            'nombre' => $contexto['nombre_completo']
        ],
        'busqueda' => $busqueda,
        'total_pacientes' => count($pacientes),
        'pacientes' => $pacientes
    ]);
} catch (RuntimeException $e) {
    responderJson(503, [
        'exito' => false,
        'codigo' => 'ERROR_CONEXION_BD',
        'mensaje' => $e->getMessage()
    ]);
} catch (Throwable $e) {
    error_log('Error en ficha_pacientes.php: ' . $e->getMessage());
    responderJson(500, [
        'exito' => false,
        'codigo' => 'ERROR_INTERNO',
        'mensaje' => 'Ocurrió un error al consultar los pacientes.'
    ]);
}
