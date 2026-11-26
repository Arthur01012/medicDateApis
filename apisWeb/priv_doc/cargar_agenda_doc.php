<?php
declare(strict_types=1);

/**
 * API: Mi agenda del médico
 * Ruta: apisWeb/priv_doc/cargar_agenda_doc.php
 * Método: GET
 * Recibe id de sesión / id_usuario / id_empleado del doctor y devuelve su agenda en JSON
 * para cargarla en pages/private/doctor/mi_agenda.html.
 * Soporta filtros por lapso (hoy, semana, mes, todas), fecha exacta, estado e id_cita (detalle).
 */

require_once __DIR__ . '/../config/api_helper.php';

inicializarApiWeb(['GET']);

try {
    $conexion = conectarBD();
    $entrada = leerEntradaApi();

    $contexto = validarUsuarioPorRol($conexion, ['medico'], $entrada);
    $idEmpleado = (int) $contexto['id_empleado'];

    $idCita = enteroPositivo($entrada['id_cita'] ?? null);

    // =========================================================================
    // MODO DETALLE DE CITA DEL MÉDICO (INCLUYE NOTAS INTERNAS E HISTORIAL)
    // =========================================================================
    if ($idCita !== null) {
        $stmt = $conexion->prepare(
            "SELECT
                c.id_cita,
                DATE(c.inicio) AS fecha,
                TIME_FORMAT(c.inicio, '%H:%i') AS hora,
                TIME_FORMAT(c.fin, '%H:%i') AS hora_fin,
                TIMESTAMPDIFF(MINUTE, c.inicio, c.fin) AS duracion_minutos,
                c.estado,
                c.motivo,
                c.notas_internas,
                c.canal_registro,
                (DATE(c.inicio) <= CURDATE()) AS es_fecha_atendible,
                pa.id_paciente,
                pa.alergias,
                pa.notas_medicas,
                CONCAT_WS(' ', pp.nombres, pp.apellido_paterno, pp.apellido_materno) AS paciente_nombre,
                pp.telefono_principal AS paciente_telefono,
                pp.correo_contacto AS paciente_correo,
                pp.fecha_nacimiento AS paciente_fecha_nacimiento,
                ta.nombre AS tipo_atencion,
                co.nombre AS consultorio
             FROM cita c
             INNER JOIN paciente pa ON pa.id_paciente = c.id_paciente
             INNER JOIN persona pp ON pp.id_persona = pa.id_persona
             INNER JOIN tipo_atencion ta ON ta.id_tipo_atencion = c.id_tipo_atencion
             LEFT JOIN consultorio co ON co.id_consultorio = c.id_consultorio
             WHERE c.id_cita = ? AND c.id_empleado = ?
             LIMIT 1"
        );
        $stmt->bind_param('ii', $idCita, $idEmpleado);
        $stmt->execute();
        $cita = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$cita) {
            responderJson(404, [
                'exito' => false,
                'codigo' => 'CITA_NO_ENCONTRADA',
                'mensaje' => 'No se encontró la cita o no pertenece a tu agenda.'
            ]);
        }

        // Historial de cambios de la cita
        $stmtHist = $conexion->prepare(
            "SELECT
                h.id_historial,
                h.estado_anterior,
                h.estado_nuevo,
                h.accion,
                h.observacion,
                h.fecha_evento,
                CONCAT_WS(' ', p.nombres, p.apellido_paterno) AS usuario_nombre
             FROM historial_cita h
             LEFT JOIN usuario u ON u.id_usuario = h.id_usuario
             LEFT JOIN persona p ON p.id_persona = u.id_persona
             WHERE h.id_cita = ?
             ORDER BY h.fecha_evento DESC"
        );
        $stmtHist->bind_param('i', $idCita);
        $stmtHist->execute();
        $resHist = $stmtHist->get_result();
        $historial = [];
        while ($h = $resHist->fetch_assoc()) {
            $historial[] = [
                'id_historial' => (int) $h['id_historial'],
                'estado_anterior' => $h['estado_anterior'],
                'estado_nuevo' => $h['estado_nuevo'],
                'accion' => $h['accion'],
                'observacion' => $h['observacion'],
                'fecha_evento' => $h['fecha_evento'],
                'usuario' => trim((string) ($h['usuario_nombre'] ?? 'Sistema'))
            ];
        }
        $stmtHist->close();

        $esProgramada = in_array($cita['estado'], ['pendiente', 'confirmada', 'en_progreso'], true);
        $puedeMarcar = $esProgramada && ((int) $cita['es_fecha_atendible'] === 1);
        $puedeEditarNotas = $cita['estado'] !== 'cancelada';

        responderJson(200, [
            'exito' => true,
            'cita' => [
                'id_cita' => (int) $cita['id_cita'],
                'fecha' => $cita['fecha'],
                'hora' => $cita['hora'],
                'hora_fin' => $cita['hora_fin'],
                'duracion_minutos' => (int) $cita['duracion_minutos'],
                'estado' => $cita['estado'],
                'estado_etiqueta' => etiquetaEstadoCita((string) $cita['estado']),
                'motivo' => $cita['motivo'] ?: $cita['tipo_atencion'],
                'notas_internas' => $cita['notas_internas'] ?? '',
                'tipo_atencion' => $cita['tipo_atencion'],
                'consultorio' => $cita['consultorio'] ?? 'Por asignar',
                'canal_registro' => $cita['canal_registro'],
                'puede_editar_notas' => $puedeEditarNotas,
                'puede_marcar_atendida' => $puedeMarcar,
                'paciente' => [
                    'id_paciente' => (int) $cita['id_paciente'],
                    'nombre' => trim((string) $cita['paciente_nombre']),
                    'telefono' => $cita['paciente_telefono'] ?? '—',
                    'correo' => $cita['paciente_correo'] ?? '—',
                    'edad' => calcularEdad($cita['paciente_fecha_nacimiento']),
                    'alergias' => $cita['alergias'] ?? '',
                    'notas_medicas' => $cita['notas_medicas'] ?? ''
                ],
                'historial' => $historial
            ]
        ]);
    }

    // =========================================================================
    // MODO LISTADO DE AGENDA DEL MÉDICO
    // =========================================================================
    $lapso = isset($entrada['lapso']) && trim((string) $entrada['lapso']) !== ''
        ? strtolower(trim((string) $entrada['lapso']))
        : 'hoy';
    $fechaFiltro = isset($entrada['fecha']) && trim((string) $entrada['fecha']) !== ''
        ? trim((string) $entrada['fecha'])
        : null;
    $estadoFiltro = isset($entrada['estado']) && trim((string) $entrada['estado']) !== ''
        ? strtolower(trim((string) $entrada['estado']))
        : null;

    if ($fechaFiltro !== null && !esFechaValida($fechaFiltro)) {
        responderJson(422, [
            'exito' => false,
            'codigo' => 'FECHA_INVALIDA',
            'mensaje' => 'La fecha debe tener el formato YYYY-MM-DD.'
        ]);
    }

    $lapsosValidos = ['hoy', 'semana', 'mes', 'todas'];
    if (!in_array($lapso, $lapsosValidos, true)) {
        responderJson(422, [
            'exito' => false,
            'codigo' => 'LAPSO_INVALIDO',
            'mensaje' => 'Lapso no válido. Usa: hoy, semana, mes o todas.'
        ]);
    }

    $estadosValidos = ['pendiente', 'confirmada', 'en_progreso', 'completada', 'cancelada', 'inasistencia', 'programada', 'atendida'];
    if ($estadoFiltro !== null && !in_array($estadoFiltro, $estadosValidos, true)) {
        responderJson(422, [
            'exito' => false,
            'codigo' => 'ESTADO_INVALIDO',
            'mensaje' => 'El estado seleccionado no es válido.'
        ]);
    }

    $condiciones = ['c.id_empleado = ?'];
    $tipos = 'i';
    $parametros = [$idEmpleado];

    if ($fechaFiltro !== null) {
        $condiciones[] = 'DATE(c.inicio) = ?';
        $tipos .= 's';
        $parametros[] = $fechaFiltro;
    } elseif ($lapso === 'hoy') {
        $condiciones[] = 'DATE(c.inicio) = CURDATE()';
    } elseif ($lapso === 'semana') {
        $condiciones[] = 'DATE(c.inicio) BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 6 DAY)';
    } elseif ($lapso === 'mes') {
        $condiciones[] = 'YEAR(c.inicio) = YEAR(CURDATE()) AND MONTH(c.inicio) = MONTH(CURDATE())';
    }

    if ($estadoFiltro !== null) {
        if ($estadoFiltro === 'programada') {
            $condiciones[] = "c.estado IN ('pendiente', 'confirmada', 'en_progreso')";
        } elseif ($estadoFiltro === 'atendida') {
            $condiciones[] = "c.estado = 'completada'";
        } elseif ($estadoFiltro === 'cancelada') {
            $condiciones[] = "c.estado IN ('cancelada', 'inasistencia')";
        } else {
            $condiciones[] = 'c.estado = ?';
            $tipos .= 's';
            $parametros[] = $estadoFiltro;
        }
    }

    $sql = "SELECT
                c.id_cita,
                DATE(c.inicio) AS fecha,
                TIME_FORMAT(c.inicio, '%H:%i') AS hora,
                TIME_FORMAT(c.fin, '%H:%i') AS hora_fin,
                c.estado,
                c.motivo,
                c.notas_internas,
                (DATE(c.inicio) <= CURDATE()) AS es_fecha_atendible,
                pa.id_paciente,
                CONCAT_WS(' ', pp.nombres, pp.apellido_paterno, pp.apellido_materno) AS paciente_nombre,
                ta.nombre AS tipo_atencion,
                co.nombre AS consultorio
            FROM cita c
            INNER JOIN paciente pa ON pa.id_paciente = c.id_paciente
            INNER JOIN persona pp ON pp.id_persona = pa.id_persona
            INNER JOIN tipo_atencion ta ON ta.id_tipo_atencion = c.id_tipo_atencion
            LEFT JOIN consultorio co ON co.id_consultorio = c.id_consultorio
            WHERE " . implode(' AND ', $condiciones) . "
            ORDER BY c.inicio ASC";

    $stmt = $conexion->prepare($sql);
    $stmt->bind_param($tipos, ...$parametros);
    $stmt->execute();
    $res = $stmt->get_result();

    $citas = [];
    while ($fila = $res->fetch_assoc()) {
        $esProgramada = in_array($fila['estado'], ['pendiente', 'confirmada', 'en_progreso'], true);
        $citas[] = [
            'id_cita' => (int) $fila['id_cita'],
            'fecha' => $fila['fecha'],
            'hora' => $fila['hora'],
            'hora_fin' => $fila['hora_fin'],
            'estado' => $fila['estado'],
            'estado_etiqueta' => etiquetaEstadoCita((string) $fila['estado']),
            'motivo' => $fila['motivo'] ?: $fila['tipo_atencion'],
            'notas_internas' => $fila['notas_internas'] ?? '',
            'id_paciente' => (int) $fila['id_paciente'],
            'paciente' => trim((string) $fila['paciente_nombre']),
            'tipo_atencion' => $fila['tipo_atencion'],
            'consultorio' => $fila['consultorio'] ?? 'Por asignar',
            'puede_marcar_atendida' => $esProgramada && ((int) $fila['es_fecha_atendible'] === 1)
        ];
    }
    $stmt->close();

    responderJson(200, [
        'exito' => true,
        'medico' => [
            'id_empleado' => $idEmpleado,
            'id_usuario' => $contexto['id_usuario'],
            'nombre' => $contexto['nombre_completo']
        ],
        'filtros_aplicados' => [
            'lapso' => $lapso,
            'fecha' => $fechaFiltro,
            'estado' => $estadoFiltro
        ],
        'total_citas' => count($citas),
        'citas' => $citas
    ]);
} catch (RuntimeException $e) {
    responderJson(503, [
        'exito' => false,
        'codigo' => 'ERROR_CONEXION_BD',
        'mensaje' => $e->getMessage()
    ]);
} catch (Throwable $e) {
    error_log('Error en cargar_agenda_doc.php: ' . $e->getMessage());
    responderJson(500, [
        'exito' => false,
        'codigo' => 'ERROR_INTERNO',
        'mensaje' => 'Ocurrió un error al cargar la agenda del médico.'
    ]);
}
