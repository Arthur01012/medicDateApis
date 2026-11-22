<?php
declare(strict_types=1);

/**
 * API: Agenda general del asistente (SOLO LECTURA)
 * Ruta: apisWeb/apis_asist/agenda_general.php
 * Método: GET
 * Reglas:
 *  - Muestra citas de todos los médicos.
 *  - Permite filtrar por día (fecha), médico (id_medico / id_empleado), estado y lapso.
 *  - En el detalle de cita NO se exponen notas_internas del médico.
 *  - Solo lectura: rechaza POST/PUT/PATCH/DELETE.
 */

require_once __DIR__ . '/../config/api_helper.php';

inicializarApiWeb(['GET']);

try {
    $conexion = conectarBD();
    $entrada = leerEntradaApi();

    // Validar perfil asistente (o administrador en consulta)
    $contexto = validarUsuarioPorRol($conexion, ['asistente', 'administrador'], $entrada);

    $idCita = enteroPositivo($entrada['id_cita'] ?? null);

    // =========================================================================
    // MODO DETALLE DE CITA (SIN NOTAS INTERNAS DEL MÉDICO)
    // =========================================================================
    if ($idCita !== null) {
        $stmt = $conexion->prepare(
            "SELECT
                c.id_cita,
                DATE(c.inicio) AS fecha,
                TIME_FORMAT(c.inicio, '%H:%i') AS hora_inicio,
                TIME_FORMAT(c.fin, '%H:%i') AS hora_fin,
                TIMESTAMPDIFF(MINUTE, c.inicio, c.fin) AS duracion_minutos,
                c.estado,
                c.canal_registro,
                c.motivo,
                c.costo,
                c.moneda,
                c.motivo_cancelacion,
                c.fecha_cancelacion,
                pa.id_paciente,
                CONCAT_WS(' ', pp.nombres, pp.apellido_paterno, pp.apellido_materno) AS paciente_nombre,
                pp.telefono_principal AS paciente_telefono,
                pp.correo_contacto AS paciente_correo,
                pp.fecha_nacimiento AS paciente_fecha_nacimiento,
                d.id_empleado AS id_medico,
                CONCAT_WS(' ', pd.nombres, pd.apellido_paterno, pd.apellido_materno) AS medico_nombre,
                ta.id_tipo_atencion,
                ta.nombre AS tipo_atencion,
                COALESCE(
                    e.nombre,
                    (SELECT GROUP_CONCAT(es.nombre ORDER BY es.nombre SEPARATOR ', ')
                     FROM doctor_especialidad de
                     INNER JOIN especialidad es ON es.id_especialidad = de.id_especialidad
                     WHERE de.id_empleado = d.id_empleado)
                ) AS especialidad,
                co.codigo AS consultorio_codigo,
                co.nombre AS consultorio_nombre
             FROM cita c
             INNER JOIN paciente pa ON pa.id_paciente = c.id_paciente
             INNER JOIN persona pp ON pp.id_persona = pa.id_persona
             INNER JOIN doctor d ON d.id_empleado = c.id_empleado
             INNER JOIN empleado em ON em.id_empleado = d.id_empleado
             INNER JOIN persona pd ON pd.id_persona = em.id_persona
             INNER JOIN tipo_atencion ta ON ta.id_tipo_atencion = c.id_tipo_atencion
             LEFT JOIN especialidad e ON e.id_especialidad = ta.id_especialidad
             LEFT JOIN consultorio co ON co.id_consultorio = c.id_consultorio
             WHERE c.id_cita = ?
             LIMIT 1"
        );
        $stmt->bind_param('i', $idCita);
        $stmt->execute();
        $cita = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$cita) {
            responderJson(404, [
                'exito' => false,
                'codigo' => 'CITA_NO_ENCONTRADA',
                'mensaje' => 'No se encontró la cita solicitada.'
            ]);
        }

        // Importante: nunca se incluye notas_internas ni notas_medicas/alergias privadas en vista asistente
        responderJson(200, [
            'exito' => true,
            'solo_lectura' => true,
            'cita' => [
                'id_cita' => (int) $cita['id_cita'],
                'fecha' => $cita['fecha'],
                'hora' => $cita['hora_inicio'],
                'hora_fin' => $cita['hora_fin'],
                'duracion_minutos' => (int) $cita['duracion_minutos'],
                'estado' => $cita['estado'],
                'estado_etiqueta' => etiquetaEstadoCita((string) $cita['estado']),
                'canal_registro' => $cita['canal_registro'],
                'motivo' => $cita['motivo'] ?? 'Sin motivo especificado',
                'tipo_atencion' => $cita['tipo_atencion'],
                'especialidad' => $cita['especialidad'] ?? 'Medicina general',
                'consultorio' => $cita['consultorio_nombre'] ?: ($cita['consultorio_codigo'] ?: 'Por asignar'),
                'costo' => $cita['costo'] !== null ? (float) $cita['costo'] : null,
                'moneda' => $cita['moneda'],
                'motivo_cancelacion' => $cita['motivo_cancelacion'],
                'paciente' => [
                    'id_paciente' => (int) $cita['id_paciente'],
                    'nombre' => trim((string) $cita['paciente_nombre']),
                    'telefono' => $cita['paciente_telefono'] ?? '—',
                    'correo' => $cita['paciente_correo'] ?? '—',
                    'edad' => calcularEdad($cita['paciente_fecha_nacimiento'])
                ],
                'medico' => [
                    'id_empleado' => (int) $cita['id_medico'],
                    'nombre' => trim((string) $cita['medico_nombre'])
                ]
            ]
        ]);
    }

    // =========================================================================
    // MODO LISTADO CON FILTROS (DÍA, MÉDICO, ESTADO, LAPSO)
    // =========================================================================
    $fechaFiltro = isset($entrada['fecha']) && trim((string) $entrada['fecha']) !== ''
        ? trim((string) $entrada['fecha'])
        : null;
    $idMedicoFiltro = enteroPositivo($entrada['id_medico'] ?? ($entrada['id_empleado'] ?? null));
    $estadoFiltro = isset($entrada['estado']) && trim((string) $entrada['estado']) !== ''
        ? strtolower(trim((string) $entrada['estado']))
        : null;
    $lapsoFiltro = isset($entrada['lapso']) && trim((string) $entrada['lapso']) !== ''
        ? strtolower(trim((string) $entrada['lapso']))
        : null;

    if ($fechaFiltro !== null && !esFechaValida($fechaFiltro)) {
        responderJson(422, [
            'exito' => false,
            'codigo' => 'FECHA_INVALIDA',
            'mensaje' => 'El filtro de fecha debe tener el formato YYYY-MM-DD.'
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

    $condiciones = ['1 = 1'];
    $tipos = '';
    $parametros = [];

    if ($fechaFiltro !== null) {
        $condiciones[] = 'DATE(c.inicio) = ?';
        $tipos .= 's';
        $parametros[] = $fechaFiltro;
    } elseif ($lapsoFiltro === 'hoy') {
        $condiciones[] = 'DATE(c.inicio) = CURDATE()';
    } elseif ($lapsoFiltro === 'semana') {
        $condiciones[] = 'DATE(c.inicio) BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 6 DAY)';
    } elseif ($lapsoFiltro === 'mes') {
        $condiciones[] = 'YEAR(c.inicio) = YEAR(CURDATE()) AND MONTH(c.inicio) = MONTH(CURDATE())';
    }

    if ($idMedicoFiltro !== null) {
        $condiciones[] = 'c.id_empleado = ?';
        $tipos .= 'i';
        $parametros[] = $idMedicoFiltro;
    }

    if ($estadoFiltro !== null) {
        if ($estadoFiltro === 'programada') {
            $condiciones[] = "c.estado IN ('pendiente', 'confirmada', 'en_progreso')";
        } elseif ($estadoFiltro === 'atendida') {
            $condiciones[] = "c.estado = 'completada'";
        } else {
            $condiciones[] = 'c.estado = ?';
            $tipos .= 's';
            $parametros[] = $estadoFiltro;
        }
    }

    $sqlCitas = "SELECT
                    c.id_cita,
                    DATE(c.inicio) AS fecha,
                    TIME_FORMAT(c.inicio, '%H:%i') AS hora,
                    TIME_FORMAT(c.fin, '%H:%i') AS hora_fin,
                    c.estado,
                    c.motivo,
                    pa.id_paciente,
                    CONCAT_WS(' ', pp.nombres, pp.apellido_paterno, pp.apellido_materno) AS paciente_nombre,
                    d.id_empleado AS id_medico,
                    CONCAT_WS(' ', pd.nombres, pd.apellido_paterno, pd.apellido_materno) AS medico_nombre,
                    ta.nombre AS tipo_atencion,
                    COALESCE(
                        e.nombre,
                        (SELECT GROUP_CONCAT(es.nombre ORDER BY es.nombre SEPARATOR ', ')
                         FROM doctor_especialidad de
                         INNER JOIN especialidad es ON es.id_especialidad = de.id_especialidad
                         WHERE de.id_empleado = d.id_empleado)
                    ) AS especialidad,
                    co.nombre AS consultorio
                 FROM cita c
                 INNER JOIN paciente pa ON pa.id_paciente = c.id_paciente
                 INNER JOIN persona pp ON pp.id_persona = pa.id_persona
                 INNER JOIN doctor d ON d.id_empleado = c.id_empleado
                 INNER JOIN empleado em ON em.id_empleado = d.id_empleado
                 INNER JOIN persona pd ON pd.id_persona = em.id_persona
                 INNER JOIN tipo_atencion ta ON ta.id_tipo_atencion = c.id_tipo_atencion
                 LEFT JOIN especialidad e ON e.id_especialidad = ta.id_especialidad
                 LEFT JOIN consultorio co ON co.id_consultorio = c.id_consultorio
                 WHERE " . implode(' AND ', $condiciones) . "
                 ORDER BY c.inicio ASC";

    $stmt = $conexion->prepare($sqlCitas);
    if ($tipos !== '') {
        $stmt->bind_param($tipos, ...$parametros);
    }
    $stmt->execute();
    $resCitas = $stmt->get_result();

    $citas = [];
    while ($fila = $resCitas->fetch_assoc()) {
        $citas[] = [
            'id_cita' => (int) $fila['id_cita'],
            'fecha' => $fila['fecha'],
            'hora' => $fila['hora'],
            'hora_fin' => $fila['hora_fin'],
            'estado' => $fila['estado'],
            'estado_etiqueta' => etiquetaEstadoCita((string) $fila['estado']),
            'motivo' => $fila['motivo'] ?? '',
            'id_paciente' => (int) $fila['id_paciente'],
            'paciente' => trim((string) $fila['paciente_nombre']),
            'id_medico' => (int) $fila['id_medico'],
            'medico' => trim((string) $fila['medico_nombre']),
            'especialidad' => $fila['especialidad'] ?? 'Medicina general',
            'tipo_atencion' => $fila['tipo_atencion'],
            'consultorio' => $fila['consultorio'] ?? 'Por asignar'
        ];
    }
    $stmt->close();

    // Resumen general para tarjetas superiores
    $resResumen = $conexion->query(
        "SELECT
            (SELECT COUNT(*) FROM cita WHERE DATE(inicio) = CURDATE() AND estado <> 'cancelada') AS citas_hoy,
            (SELECT COUNT(*) FROM cita WHERE estado IN ('pendiente', 'confirmada', 'en_progreso')) AS programadas,
            (SELECT COUNT(*) FROM paciente WHERE estado = 'activo') AS total_pacientes,
            (SELECT COUNT(*) FROM doctor d INNER JOIN empleado em ON em.id_empleado = d.id_empleado WHERE em.estado = 'activo') AS total_medicos"
    );
    $resumenFila = $resResumen->fetch_assoc() ?: [];

    // Catálogo de médicos para el filtro desplegable
    $resMedicos = $conexion->query(
        "SELECT
            d.id_empleado,
            CONCAT_WS(' ', p.nombres, p.apellido_paterno, p.apellido_materno) AS nombre
         FROM doctor d
         INNER JOIN empleado em ON em.id_empleado = d.id_empleado
         INNER JOIN persona p ON p.id_persona = em.id_persona
         WHERE em.estado = 'activo'
         ORDER BY p.nombres ASC, p.apellido_paterno ASC"
    );
    $medicos = [];
    while ($m = $resMedicos->fetch_assoc()) {
        $medicos[] = [
            'id_empleado' => (int) $m['id_empleado'],
            'nombre' => trim((string) $m['nombre'])
        ];
    }

    responderJson(200, [
        'exito' => true,
        'solo_lectura' => true,
        'usuario' => [
            'id_usuario' => $contexto['id_usuario'],
            'nombre' => $contexto['nombre_completo'],
            'roles' => $contexto['roles']
        ],
        'resumen' => [
            'citas_hoy' => (int) ($resumenFila['citas_hoy'] ?? 0),
            'programadas' => (int) ($resumenFila['programadas'] ?? 0),
            'pacientes' => (int) ($resumenFila['total_pacientes'] ?? 0),
            'medicos' => (int) ($resumenFila['total_medicos'] ?? 0)
        ],
        'filtros_aplicados' => [
            'fecha' => $fechaFiltro,
            'id_medico' => $idMedicoFiltro,
            'estado' => $estadoFiltro,
            'lapso' => $lapsoFiltro
        ],
        'medicos' => $medicos,
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
    error_log('Error en agenda_general.php: ' . $e->getMessage());
    responderJson(500, [
        'exito' => false,
        'codigo' => 'ERROR_INTERNO',
        'mensaje' => 'Ocurrió un error al consultar la agenda general.'
    ]);
}
