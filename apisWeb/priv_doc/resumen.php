<?php
declare(strict_types=1);

/**
 * API: Resumen del médico
 * Ruta: apisWeb/priv_doc/resumen.php
 * Método: GET
 * Reglas:
 *  - Solo citas del médico autenticado (c.id_empleado = id_empleado del usuario).
 *  - Retorna perfil del médico, citas de hoy, próxima cita, pendientes y totales del mes,
 *    gráfica de próximos 7 días y agenda de hoy.
 */

require_once __DIR__ . '/../config/api_helper.php';

inicializarApiWeb(['GET']);

try {
    $conexion = conectarBD();
    $entrada = leerEntradaApi();

    $contexto = validarUsuarioPorRol($conexion, ['medico'], $entrada);
    $idEmpleado = (int) $contexto['id_empleado'];

    // 1. Perfil y especialidades del médico
    $stmtPerfil = $conexion->prepare(
        "SELECT
            d.id_empleado,
            d.cedula_profesional,
            d.descripcion_publica,
            CONCAT_WS(' ', p.nombres, p.apellido_paterno, p.apellido_materno) AS nombre_completo,
            (SELECT GROUP_CONCAT(es.nombre ORDER BY es.nombre SEPARATOR ', ')
             FROM doctor_especialidad de
             INNER JOIN especialidad es ON es.id_especialidad = de.id_especialidad
             WHERE de.id_empleado = d.id_empleado) AS especialidades
         FROM doctor d
         INNER JOIN empleado em ON em.id_empleado = d.id_empleado
         INNER JOIN persona p ON p.id_persona = em.id_persona
         WHERE d.id_empleado = ?
         LIMIT 1"
    );
    $stmtPerfil->bind_param('i', $idEmpleado);
    $stmtPerfil->execute();
    $perfil = $stmtPerfil->get_result()->fetch_assoc();
    $stmtPerfil->close();

    // Horarios de atención del médico
    $stmtHor = $conexion->prepare(
        "SELECT DISTINCT
            dia_semana,
            TIME_FORMAT(hora_inicio, '%H:%i') AS hora_inicio,
            TIME_FORMAT(hora_fin, '%H:%i') AS hora_fin
         FROM horario_atencion
         WHERE id_empleado = ? AND activo = 1
         ORDER BY dia_semana ASC, hora_inicio ASC"
    );
    $stmtHor->bind_param('i', $idEmpleado);
    $stmtHor->execute();
    $resHor = $stmtHor->get_result();

    $diasArr = [];
    $rangos = [];
    while ($h = $resHor->fetch_assoc()) {
        $dNombre = nombreDiaCorto((int) $h['dia_semana']);
        if ($dNombre !== '' && !in_array($dNombre, $diasArr, true)) {
            $diasArr[] = $dNombre;
        }
        $rango = $h['hora_inicio'] . ' a ' . $h['hora_fin'];
        if (!in_array($rango, $rangos, true)) {
            $rangos[] = $rango;
        }
    }
    $stmtHor->close();

    // 2. Métricas del médico (solo sus propias citas)
    $stmtMetricas = $conexion->prepare(
        "SELECT
            SUM(CASE WHEN DATE(inicio) = CURDATE() AND estado <> 'cancelada' THEN 1 ELSE 0 END) AS citas_hoy,
            SUM(CASE WHEN DATE(inicio) = CURDATE() AND estado IN ('pendiente', 'confirmada', 'en_progreso') THEN 1 ELSE 0 END) AS por_atender_hoy,
            SUM(CASE WHEN DATE(inicio) = CURDATE() AND estado = 'completada' THEN 1 ELSE 0 END) AS atendidas_hoy,
            SUM(CASE WHEN DATE(inicio) BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 6 DAY)
                      AND estado IN ('pendiente', 'confirmada', 'en_progreso') THEN 1 ELSE 0 END) AS proximos_7_dias,
            SUM(CASE WHEN YEAR(inicio) = YEAR(CURDATE()) AND MONTH(inicio) = MONTH(CURDATE())
                      AND estado <> 'cancelada' THEN 1 ELSE 0 END) AS total_mes,
            SUM(CASE WHEN YEAR(inicio) = YEAR(CURDATE()) AND MONTH(inicio) = MONTH(CURDATE())
                      AND estado IN ('pendiente', 'confirmada', 'en_progreso') THEN 1 ELSE 0 END) AS pendientes_mes,
            SUM(CASE WHEN YEAR(inicio) = YEAR(CURDATE()) AND MONTH(inicio) = MONTH(CURDATE())
                      AND estado = 'completada' THEN 1 ELSE 0 END) AS atendidas_mes,
            COUNT(DISTINCT id_paciente) AS total_pacientes
         FROM cita
         WHERE id_empleado = ?"
    );
    $stmtMetricas->bind_param('i', $idEmpleado);
    $stmtMetricas->execute();
    $metricas = $stmtMetricas->get_result()->fetch_assoc() ?: [];
    $stmtMetricas->close();

    // 3. Próxima cita por atender (desde hoy en adelante)
    $stmtSig = $conexion->prepare(
        "SELECT
            c.id_cita,
            DATE(c.inicio) AS fecha,
            TIME_FORMAT(c.inicio, '%H:%i') AS hora,
            c.estado,
            c.motivo,
            pa.id_paciente,
            CONCAT_WS(' ', pp.nombres, pp.apellido_paterno, pp.apellido_materno) AS paciente_nombre,
            ta.nombre AS tipo_atencion
         FROM cita c
         INNER JOIN paciente pa ON pa.id_paciente = c.id_paciente
         INNER JOIN persona pp ON pp.id_persona = pa.id_persona
         INNER JOIN tipo_atencion ta ON ta.id_tipo_atencion = c.id_tipo_atencion
         WHERE c.id_empleado = ?
           AND c.estado IN ('pendiente', 'confirmada', 'en_progreso')
           AND DATE(c.inicio) >= CURDATE()
         ORDER BY c.inicio ASC
         LIMIT 1"
    );
    $stmtSig->bind_param('i', $idEmpleado);
    $stmtSig->execute();
    $sigFila = $stmtSig->get_result()->fetch_assoc();
    $stmtSig->close();

    $siguienteCita = null;
    if ($sigFila) {
        $siguienteCita = [
            'id_cita' => (int) $sigFila['id_cita'],
            'fecha' => $sigFila['fecha'],
            'hora' => $sigFila['hora'],
            'estado' => $sigFila['estado'],
            'estado_etiqueta' => etiquetaEstadoCita((string) $sigFila['estado']),
            'motivo' => $sigFila['motivo'] ?: $sigFila['tipo_atencion'],
            'id_paciente' => (int) $sigFila['id_paciente'],
            'paciente' => trim((string) $sigFila['paciente_nombre']),
            'tipo_atencion' => $sigFila['tipo_atencion']
        ];
    }

    // 4. Gráfica de los próximos 7 días
    $stmtGraf = $conexion->prepare(
        "SELECT DATE(inicio) AS fecha, COUNT(*) AS total
         FROM cita
         WHERE id_empleado = ?
           AND estado <> 'cancelada'
           AND DATE(inicio) BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 6 DAY)
         GROUP BY DATE(inicio)"
    );
    $stmtGraf->bind_param('i', $idEmpleado);
    $stmtGraf->execute();
    $resGraf = $stmtGraf->get_result();

    $conteoPorFecha = [];
    while ($g = $resGraf->fetch_assoc()) {
        $conteoPorFecha[$g['fecha']] = (int) $g['total'];
    }
    $stmtGraf->close();

    $grafica7Dias = [];
    $hoyObj = new DateTimeImmutable('today');
    for ($i = 0; $i < 7; $i++) {
        $diaObj = $hoyObj->modify("+{$i} days");
        $iso = $diaObj->format('Y-m-d');
        $grafica7Dias[] = [
            'fecha' => $iso,
            'dia' => nombreDiaCorto((int) $diaObj->format('N')),
            'es_hoy' => $i === 0,
            'total' => $conteoPorFecha[$iso] ?? 0
        ];
    }

    // 5. Agenda de hoy
    $stmtHoy = $conexion->prepare(
        "SELECT
            c.id_cita,
            DATE(c.inicio) AS fecha,
            TIME_FORMAT(c.inicio, '%H:%i') AS hora,
            c.estado,
            c.motivo,
            c.notas_internas,
            pa.id_paciente,
            CONCAT_WS(' ', pp.nombres, pp.apellido_paterno, pp.apellido_materno) AS paciente_nombre,
            ta.nombre AS tipo_atencion
         FROM cita c
         INNER JOIN paciente pa ON pa.id_paciente = c.id_paciente
         INNER JOIN persona pp ON pp.id_persona = pa.id_persona
         INNER JOIN tipo_atencion ta ON ta.id_tipo_atencion = c.id_tipo_atencion
         WHERE c.id_empleado = ?
           AND DATE(c.inicio) = CURDATE()
           AND c.estado <> 'cancelada'
         ORDER BY c.inicio ASC"
    );
    $stmtHoy->bind_param('i', $idEmpleado);
    $stmtHoy->execute();
    $resHoy = $stmtHoy->get_result();

    $agendaHoy = [];
    while ($c = $resHoy->fetch_assoc()) {
        $puedeMarcar = in_array($c['estado'], ['pendiente', 'confirmada', 'en_progreso'], true);
        $agendaHoy[] = [
            'id_cita' => (int) $c['id_cita'],
            'fecha' => $c['fecha'],
            'hora' => $c['hora'],
            'estado' => $c['estado'],
            'estado_etiqueta' => etiquetaEstadoCita((string) $c['estado']),
            'motivo' => $c['motivo'] ?: $c['tipo_atencion'],
            'notas_internas' => $c['notas_internas'] ?? '',
            'id_paciente' => (int) $c['id_paciente'],
            'paciente' => trim((string) $c['paciente_nombre']),
            'tipo_atencion' => $c['tipo_atencion'],
            'puede_marcar_atendida' => $puedeMarcar
        ];
    }
    $stmtHoy->close();

    responderJson(200, [
        'exito' => true,
        'medico' => [
            'id_empleado' => $idEmpleado,
            'id_usuario' => $contexto['id_usuario'],
            'nombre' => trim((string) ($perfil['nombre_completo'] ?? $contexto['nombre_completo'])),
            'cedula_profesional' => $perfil['cedula_profesional'] ?? '',
            'especialidades' => $perfil['especialidades'] ?? 'Medicina general',
            'dias_atencion' => implode(', ', $diasArr) ?: 'Sin horario registrado',
            'horario' => implode(' · ', $rangos) ?: 'Por definir'
        ],
        'resumen' => [
            'citas_hoy' => (int) ($metricas['citas_hoy'] ?? 0),
            'por_atender_hoy' => (int) ($metricas['por_atender_hoy'] ?? 0),
            'atendidas_hoy' => (int) ($metricas['atendidas_hoy'] ?? 0),
            'proximos_7_dias' => (int) ($metricas['proximos_7_dias'] ?? 0),
            'total_mes' => (int) ($metricas['total_mes'] ?? 0),
            'pendientes_mes' => (int) ($metricas['pendientes_mes'] ?? 0),
            'atendidas_mes' => (int) ($metricas['atendidas_mes'] ?? 0),
            'pacientes' => (int) ($metricas['total_pacientes'] ?? 0)
        ],
        'siguiente_cita' => $siguienteCita,
        'grafica_7_dias' => $grafica7Dias,
        'agenda_hoy' => $agendaHoy
    ]);
} catch (RuntimeException $e) {
    responderJson(503, [
        'exito' => false,
        'codigo' => 'ERROR_CONEXION_BD',
        'mensaje' => $e->getMessage()
    ]);
} catch (Throwable $e) {
    error_log('Error en resumen.php: ' . $e->getMessage());
    responderJson(500, [
        'exito' => false,
        'codigo' => 'ERROR_INTERNO',
        'mensaje' => 'Ocurrió un error al cargar el resumen del médico.'
    ]);
}
