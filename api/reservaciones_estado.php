<?php
// POST /api/reservaciones/{id}/estado
$res_id = $_route_res_id ?? 0;
$data   = get_body();
$estado = $data['estado_pago'] ?? '';

$validos = ['Completado', 'Pendiente', 'Cancelado', 'Rechazado'];
if (!$res_id)                    json_error('ID inválido', 400);
if (!in_array($estado, $validos)) json_error("estado_pago invalido. Validos: " . implode(', ', $validos));

$res = sb_get("reservaciones?id=eq.$res_id&select=link_pago,agencia,estado_pago,fecha_pago,tipo_cabana,fecha_ascenso");
$rows = $res['body'] ?? [];
if (empty($rows)) json_error('Reservación no encontrada', 404);

$rv        = $rows[0];
$link_pago = $rv['link_pago'] ?? '';
$agencia   = $rv['agencia']   ?? '';

$patch = ['estado_pago' => $estado];
if ($estado === 'Completado' && !$rv['fecha_pago']) {
    $patch['fecha_pago'] = gt_date();
}
sb_patch("reservaciones?id=eq.$res_id", $patch);

// Sincronizar Amelia
if ($link_pago && es_wolfs($agencia)) {
    $estado_amelia = match($estado) {
        'Completado' => 'approved',
        'Cancelado', 'Rechazado' => 'canceled',
        default => 'pending',
    };

    if (str_starts_with($link_pago, 'amelia_app_')) {
        enqueue_sync('update_status', ['estado' => $estado_amelia, 'appointment_id' => str_replace('amelia_app_', '', $link_pago)]);
    } elseif (str_starts_with($link_pago, 'amelia_booking_')) {
        enqueue_sync('update_status', [
            'estado'        => $estado_amelia,
            'fecha_ascenso' => $rv['fecha_ascenso'] ?? '',
            'tipo_cabana'   => $rv['tipo_cabana']   ?? '',
        ]);
    }
}

json_response(['success' => true]);
