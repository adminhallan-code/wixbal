<?php
// POST /api/reservaciones/{id}/reprogramar  — reprogramacion directa sin cobro
$res_id = $_route_res_id ?? 0;
$data   = get_body();
$nueva_fecha = $data['nueva_fecha'] ?? '';

if (!$res_id)                    json_error('ID inválido', 400);
if (!fecha_valida($nueva_fecha)) json_error('Fecha inválida');

$res = sb_get("reservaciones?id=eq.$res_id&select=link_pago,agencia,tipo_cabana,fecha_ascenso");
$rows = $res['body'] ?? [];
if (empty($rows)) json_error('Reservación no encontrada', 404);

$rv        = $rows[0];
$link_pago = $rv['link_pago'] ?? '';
$agencia   = $rv['agencia']   ?? '';

// Actualizar Supabase
sb_patch("reservaciones?id=eq.$res_id", ['fecha_ascenso' => $nueva_fecha]);

// Sincronizar Amelia
if ($link_pago && es_wolfs($agencia)) {
    if (str_starts_with($link_pago, 'amelia_booking_')) {
        enqueue_sync('move_booking', [
            'booking_id'  => str_replace('amelia_booking_', '', $link_pago),
            'nueva_fecha' => $nueva_fecha,
        ]);
    } elseif (str_starts_with($link_pago, 'amelia_app_')) {
        enqueue_sync('reschedule', [
            'nueva_fecha'    => $nueva_fecha,
            'appointment_id' => str_replace('amelia_app_', '', $link_pago),
        ]);
    } else {
        // Fallback por checkout_url
        $lk = sb_get("links_pendientes?checkout_url=eq." . urlencode($link_pago) . "&select=checkout_id");
        $checkout_id = $lk['body'][0]['checkout_id'] ?? $link_pago;
        enqueue_sync('reschedule', ['nueva_fecha' => $nueva_fecha, 'link_id' => $checkout_id]);
    }
}

json_response(['success' => true]);
