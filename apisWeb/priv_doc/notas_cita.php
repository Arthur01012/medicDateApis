<?php
declare(strict_types=1);

/**
 * API: Guardar notas internas y marcar cita como atendida (Médico)
 * Ruta: apisWeb/priv_doc/notas_cita.php
 * Método: POST
 * Reglas:
 *  - Solo el médico dueño de la cita puede editar notas o marcarla como atendida.
 *  - No se pueden editar notas de citas canceladas.
 *  - Solo se puede marcar como atendida ('completada') desde la fecha de la cita (DATE(inicio) <= CURDATE())
 *    y cuando su estado actual sea 'pendiente', 'confirmada' o 'en_progreso'.
 *  - Toda actualización se registra en historial_cita y auditoria dentro de una transacción.
 */

require_once __DIR__ . '/../config/api_helper.php';

inicializarApiWeb(['POST']);

try {
    $conexion = conectarBD();
    $entrada = leerEntradaApi();

    $contexto = validarUsuarioPorRol($conexion, ['medico'], $entrada);
    $idEmpleado = (int) $contexto['id_empleado'];
    $idUsuario = (int) $contexto['id_usuario'];

    $idCita = enteroPositivo($entrada['id_cita'] ?? null);
    if ($idCita === null) {
        responderJson(422, [
            'exito' => false,
            'codigo' => 'ID_CITA_REQUERIDO',
            'mensaje' => 'Debes indicar el identificador de la cita (id_cita).'
        ]);
    }

    $notas = isset($entrada['notas_internas'])
        ? trim((string) $entrada['notas_internas'])
        : (isset($entrada['notas']) ? trim((string) $entrada['notas']) : '');

    $marcarAtendida = filter_var(
        $entrada['marcar_atendida'] ?? ($entrada['atender'] ?? false),
        FILTER_VALIDATE_BOOLEAN
    );
    if (isset($entrada['accion']) && $entrada['accion'] === 'marcar_atendida') {
        $marcarAtendida = true;
    }

    $conexion->begin_transaction();

    // Bloquear fila de la cita para actualización atómica
    $stmt = $conexion->prepare(
        "SELECT
            id_cita,
            id_empleado,
            estado,
            DATE(inicio) AS fecha_cita,
            (DATE(inicio) <= CURDATE()) AS es_fecha_atendible,
            notas_internas
         FROM cita
         WHERE id_cita = ?
         FOR UPDATE"
    );
    $stmt->bind_param('i', $idCita);
    $stmt->execute();
    $cita = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$cita) {
        $conexion->rollback();
        responderJson(404, [
            'exito' => false,
            'codigo' => 'CITA_NO_ENCONTRADA',
            'mensaje' => 'La cita especificada no existe.'
        ]);
    }

    if ((int) $cita['id_empleado'] !== $idEmpleado) {
        $conexion->rollback();
        responderJson(403, [
            'exito' => false,
            'codigo' => 'CITA_OTRO_MEDICO',
            'mensaje' => 'No puedes modificar una cita asignada a otro médico.'
        ]);
    }

    $estadoAnterior = (string) $cita['estado'];

    if ($estadoAnterior === 'cancelada') {
        $conexion->rollback();
        responderJson(409, [
            'exito' => false,
            'codigo' => 'CITA_CANCELADA',
            'mensaje' => 'No es posible editar notas ni atender una cita cancelada.'
        ]);
    }

    $estadoNuevo = $estadoAnterior;
    $accionHistorial = 'actualizar_notas';
    $observacionHistorial = 'El médico actualizó las notas internas de la consulta.';

    if ($marcarAtendida) {
        if (!in_array($estadoAnterior, ['pendiente', 'confirmada', 'en_progreso'], true)) {
            $conexion->rollback();
            responderJson(409, [
                'exito' => false,
                'codigo' => 'ESTADO_NO_ATENDIBLE',
                'mensaje' => 'Esta cita ya no se encuentra pendiente o programada.'
            ]);
        }

        if ((int) $cita['es_fecha_atendible'] !== 1) {
            $conexion->rollback();
            responderJson(422, [
                'exito' => false,
                'codigo' => 'FECHA_FUTURA',
                'mensaje' => 'Podrás marcar la cita como atendida a partir de la fecha programada (' . $cita['fecha_cita'] . ').'
            ]);
        }

        $estadoNuevo = 'completada';
        $accionHistorial = 'marcar_atendida';
        $observacionHistorial = $notas !== ''
            ? 'Cita marcada como atendida por el médico con notas clínicas.'
            : 'Cita marcada como atendida por el médico.';
    }

    // 1. Actualizar cita
    $stmtUpd = $conexion->prepare(
        "UPDATE cita
         SET notas_internas = ?, estado = ?
         WHERE id_cita = ? AND id_empleado = ?"
    );
    $notasDb = $notas !== '' ? $notas : null;
    $stmtUpd->bind_param('ssii', $notasDb, $estadoNuevo, $idCita, $idEmpleado);
    $stmtUpd->execute();
    $stmtUpd->close();

    // 2. Insertar registro en historial_cita
    $stmtHist = $conexion->prepare(
        "INSERT INTO historial_cita (id_cita, id_usuario, estado_anterior, estado_nuevo, accion, observacion)
         VALUES (?, ?, ?, ?, ?, ?)"
    );
    $stmtHist->bind_param('iissss', $idCita, $idUsuario, $estadoAnterior, $estadoNuevo, $accionHistorial, $observacionHistorial);
    $stmtHist->execute();
    $idHistorial = (int) $conexion->insert_id;
    $stmtHist->close();

    $conexion->commit();

    // 3. Registrar en auditoría
    registrarAuditoria(
        $conexion,
        $idUsuario,
        'mi_agenda',
        $accionHistorial,
        'cita',
        (string) $idCita,
        'correcto',
        $observacionHistorial
    );

    responderJson(200, [
        'exito' => true,
        'mensaje' => $marcarAtendida
            ? 'Cita marcada como atendida y registrada en el historial.'
            : 'Notas de la consulta guardadas correctamente.',
        'cita' => [
            'id_cita' => $idCita,
            'estado_anterior' => $estadoAnterior,
            'estado' => $estadoNuevo,
            'estado_etiqueta' => etiquetaEstadoCita($estadoNuevo),
            'notas_internas' => $notas,
            'id_historial' => $idHistorial
        ]
    ]);
} catch (RuntimeException $e) {
    responderJson(503, [
        'exito' => false,
        'codigo' => 'ERROR_CONEXION_BD',
        'mensaje' => $e->getMessage()
    ]);
} catch (Throwable $e) {
    if (isset($conexion) && $conexion instanceof mysqli) {
        try {
            $conexion->rollback();
        } catch (Throwable $ignored) {
        }
    }
    error_log('Error en notas_cita.php: ' . $e->getMessage());
    responderJson(500, [
        'exito' => false,
        'codigo' => 'ERROR_INTERNO',
        'mensaje' => 'Ocurrió un error al guardar los cambios de la cita.'
    ]);
}
