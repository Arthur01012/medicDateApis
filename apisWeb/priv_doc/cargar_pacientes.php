<?php
declare(strict_types=1);

/**
 * API: Mis pacientes del médico (lista, historial, notas médicas y alergias)
 * Ruta: apisWeb/priv_doc/cargar_pacientes.php
 * Métodos:
 *  - GET: Lista de pacientes atendidos/agendados con este médico (con búsqueda)
 *         o detalle + historial (?id_paciente=X) incluyendo alergias, notas_medicas y notas de cada cita.
 *  - POST: Actualizar alergias y notas_medicas de un paciente propio del médico.
 */

require_once __DIR__ . '/../config/api_helper.php';

inicializarApiWeb(['GET', 'POST']);

try {
    $conexion = conectarBD();
    $entrada = leerEntradaApi();

    $contexto = validarUsuarioPorRol($conexion, ['medico'], $entrada);
    $idEmpleado = (int) $contexto['id_empleado'];
    $idUsuario = (int) $contexto['id_usuario'];
    $metodo = $_SERVER['REQUEST_METHOD'] ?? 'GET';

    // =========================================================================
    // POST: ACTUALIZAR ALERGIAS Y NOTAS MÉDICAS DE UN PACIENTE PROPIO
    // =========================================================================
    if ($metodo === 'POST') {
        $idPaciente = enteroPositivo($entrada['id_paciente'] ?? null);
        if ($idPaciente === null) {
            responderJson(422, [
                'exito' => false,
                'codigo' => 'ID_PACIENTE_REQUERIDO',
                'mensaje' => 'Debes indicar el identificador del paciente.'
            ]);
        }

        // Verificar que el paciente pertenezca a los atendidos/agendados del médico
        $stmtRel = $conexion->prepare(
            "SELECT 1 FROM cita WHERE id_paciente = ? AND id_empleado = ? LIMIT 1"
        );
        $stmtRel->bind_param('ii', $idPaciente, $idEmpleado);
        $stmtRel->execute();
        $tieneRelacion = (bool) $stmtRel->get_result()->fetch_assoc();
        $stmtRel->close();

        if (!$tieneRelacion) {
            responderJson(403, [
                'exito' => false,
                'codigo' => 'PACIENTE_NO_ASIGNADO',
                'mensaje' => 'Solo puedes actualizar notas y alergias de tus propios pacientes.'
            ]);
        }

        $alergias = isset($entrada['alergias']) ? trim((string) $entrada['alergias']) : '';
        $notasMedicas = isset($entrada['notas_medicas']) ? trim((string) $entrada['notas_medicas']) : '';

        $alergiasDb = $alergias !== '' ? $alergias : null;
        $notasDb = $notasMedicas !== '' ? $notasMedicas : null;

        $stmtUpd = $conexion->prepare(
            "UPDATE paciente SET alergias = ?, notas_medicas = ? WHERE id_paciente = ?"
        );
        $stmtUpd->bind_param('ssi', $alergiasDb, $notasDb, $idPaciente);
        $stmtUpd->execute();
        $stmtUpd->close();

        registrarAuditoria(
            $conexion,
            $idUsuario,
            'mis_pacientes',
            'actualizar_expediente_basico',
            'paciente',
            (string) $idPaciente,
            'correcto',
            'El médico actualizó alergias/notas médicas del paciente.'
        );

        responderJson(200, [
            'exito' => true,
            'mensaje' => 'Alergias y notas médicas actualizadas correctamente.',
            'paciente' => [
                'id_paciente' => $idPaciente,
                'alergias' => $alergias,
                'notas_medicas' => $notasMedicas
            ]
        ]);
    }

    // =========================================================================
    // GET DETALLE + HISTORIAL DE UN PACIENTE CON ESTE MÉDICO
    // =========================================================================
    $idPaciente = enteroPositivo($entrada['id_paciente'] ?? null);

    if ($idPaciente !== null) {
        // Confirmar relación médico-paciente
        $stmtPac = $conexion->prepare(
            "SELECT
                pa.id_paciente,
                pa.alergias,
                pa.notas_medicas,
                CONCAT_WS(' ', p.nombres, p.apellido_paterno, p.apellido_materno) AS nombre_completo,
                p.fecha_nacimiento,
                COALESCE(p.correo_contacto, u.correo_acceso) AS correo,
                p.telefono_principal AS telefono
             FROM paciente pa
             INNER JOIN persona p ON p.id_persona = pa.id_persona
             LEFT JOIN usuario u ON u.id_persona = p.id_persona
             WHERE pa.id_paciente = ?
               AND EXISTS (
                   SELECT 1 FROM cita c
                   WHERE c.id_paciente = pa.id_paciente AND c.id_empleado = ?
               )
             LIMIT 1"
        );
        $stmtPac->bind_param('ii', $idPaciente, $idEmpleado);
        $stmtPac->execute();
        $pac = $stmtPac->get_result()->fetch_assoc();
        $stmtPac->close();

        if (!$pac) {
            responderJson(404, [
                'exito' => false,
                'codigo' => 'PACIENTE_NO_ENCONTRADO',
                'mensaje' => 'No se encontró el paciente en tu historial.'
            ]);
        }

        // Historial de citas de ese paciente con este médico (incluye notas_internas)
        $stmtCitas = $conexion->prepare(
            "SELECT
                c.id_cita,
                DATE(c.inicio) AS fecha,
                TIME_FORMAT(c.inicio, '%H:%i') AS hora,
                c.estado,
                c.motivo,
                c.notas_internas,
                ta.nombre AS tipo_atencion
             FROM cita c
             INNER JOIN tipo_atencion ta ON ta.id_tipo_atencion = c.id_tipo_atencion
             WHERE c.id_paciente = ? AND c.id_empleado = ?
             ORDER BY c.inicio DESC"
        );
        $stmtCitas->bind_param('ii', $idPaciente, $idEmpleado);
        $stmtCitas->execute();
        $resCitas = $stmtCitas->get_result();

        $historial = [];
        while ($c = $resCitas->fetch_assoc()) {
            $historial[] = [
                'id_cita' => (int) $c['id_cita'],
                'fecha' => $c['fecha'],
                'hora' => $c['hora'],
                'estado' => $c['estado'],
                'estado_etiqueta' => etiquetaEstadoCita((string) $c['estado']),
                'motivo' => $c['motivo'] ?: $c['tipo_atencion'],
                'notas_internas' => $c['notas_internas'] ?? '',
                'tipo_atencion' => $c['tipo_atencion']
            ];
        }
        $stmtCitas->close();

        responderJson(200, [
            'exito' => true,
            'paciente' => [
                'id_paciente' => (int) $pac['id_paciente'],
                'nombre' => trim((string) $pac['nombre_completo']),
                'correo' => $pac['correo'] ?? '—',
                'telefono' => $pac['telefono'] ?? '—',
                'fecha_nacimiento' => $pac['fecha_nacimiento'],
                'edad' => calcularEdad($pac['fecha_nacimiento']),
                'alergias' => $pac['alergias'] ?? '',
                'notas_medicas' => $pac['notas_medicas'] ?? ''
            ],
            'total_citas' => count($historial),
            'historial' => $historial
        ]);
    }

    // =========================================================================
    // GET LISTADO DE PACIENTES DEL MÉDICO CON BÚSQUEDA
    // =========================================================================
    $busqueda = isset($entrada['busqueda']) ? trim((string) $entrada['busqueda']) : '';

    $condiciones = ['c.id_empleado = ?'];
    $tipos = 'i';
    $parametros = [$idEmpleado];

    if ($busqueda !== '') {
        $patron = '%' . $busqueda . '%';
        $condiciones[] = "(
            CONCAT_WS(' ', p.nombres, p.apellido_paterno, p.apellido_materno) LIKE ?
            OR COALESCE(p.correo_contacto, u.correo_acceso, '') LIKE ?
            OR COALESCE(pa.alergias, '') LIKE ?
        )";
        $tipos .= 'sss';
        $parametros[] = $patron;
        $parametros[] = $patron;
        $parametros[] = $patron;
    }

    $sql = "SELECT
                pa.id_paciente,
                pa.alergias,
                pa.notas_medicas,
                CONCAT_WS(' ', p.nombres, p.apellido_paterno, p.apellido_materno) AS nombre_completo,
                p.fecha_nacimiento,
                COALESCE(p.correo_contacto, u.correo_acceso) AS correo,
                p.telefono_principal AS telefono,
                SUM(CASE WHEN c.estado <> 'cancelada' THEN 1 ELSE 0 END) AS total_citas,
                MAX(CASE WHEN c.estado = 'completada' THEN DATE(c.inicio) ELSE NULL END) AS ultima_consulta,
                MIN(CASE WHEN c.estado IN ('pendiente', 'confirmada', 'en_progreso') AND DATE(c.inicio) >= CURDATE()
                         THEN c.inicio ELSE NULL END) AS proxima_cita_dt
            FROM cita c
            INNER JOIN paciente pa ON pa.id_paciente = c.id_paciente
            INNER JOIN persona p ON p.id_persona = pa.id_persona
            LEFT JOIN usuario u ON u.id_persona = p.id_persona
            WHERE " . implode(' AND ', $condiciones) . "
            GROUP BY pa.id_paciente, pa.alergias, pa.notas_medicas,
                     p.nombres, p.apellido_paterno, p.apellido_materno,
                     p.fecha_nacimiento, p.correo_contacto, u.correo_acceso, p.telefono_principal
            ORDER BY p.nombres ASC, p.apellido_paterno ASC";

    $stmt = $conexion->prepare($sql);
    $stmt->bind_param($tipos, ...$parametros);
    $stmt->execute();
    $res = $stmt->get_result();

    $pacientes = [];
    while ($fila = $res->fetch_assoc()) {
        $proxFecha = null;
        $proxHora = null;
        if (!empty($fila['proxima_cita_dt'])) {
            $proxFecha = substr((string) $fila['proxima_cita_dt'], 0, 10);
            $proxHora = substr((string) $fila['proxima_cita_dt'], 11, 5);
        }

        $pacientes[] = [
            'id_paciente' => (int) $fila['id_paciente'],
            'nombre' => trim((string) $fila['nombre_completo']),
            'correo' => $fila['correo'] ?? '—',
            'telefono' => $fila['telefono'] ?? '—',
            'fecha_nacimiento' => $fila['fecha_nacimiento'],
            'edad' => calcularEdad($fila['fecha_nacimiento']),
            'alergias' => $fila['alergias'] ?? '',
            'notas_medicas' => $fila['notas_medicas'] ?? '',
            'total_citas' => (int) $fila['total_citas'],
            'ultima_consulta' => $fila['ultima_consulta'],
            'proxima_cita_fecha' => $proxFecha,
            'proxima_cita_hora' => $proxHora
        ];
    }
    $stmt->close();

    responderJson(200, [
        'exito' => true,
        'medico' => [
            'id_empleado' => $idEmpleado,
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
    error_log('Error en cargar_pacientes.php: ' . $e->getMessage());
    responderJson(500, [
        'exito' => false,
        'codigo' => 'ERROR_INTERNO',
        'mensaje' => 'Ocurrió un error al consultar tus pacientes.'
    ]);
}
