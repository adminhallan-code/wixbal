<?php
// POST /api/reschedule-pendientes/{id}/cancelar
$resc_id = $_route_resc_id ?? 0;
if (!$resc_id) json_error('ID inválido', 400);

$res = sb_get("reschedule_pendientes?id=eq.$resc_id&select=*");
$rows = $res['body'] ?? [];
if (empty($rows)) json_error('Reprogramación no encontrada', 404);

$resc = $rows[0];
// QPayPro: no hay API de expiración de token — solo marcamos Cancelado en BD.

sb_patch("reschedule_pendientes?id=eq.$resc_id", ['estado' => 'Cancelado']);

// Revertir Amelia a la fecha original
$agencia_resc   = $resc['agencia']       ?? '';
$reservacion_id = $resc['reservacion_id'] ?? 0;
$fecha_original = $resc['fecha_original'] ?? '';

if (es_wolfs($agencia_resc) && $reservacion_id && $fecha_original) {
    $rv_res  = sb_get("reservaciones?id=eq.$reservacion_id&select=link_pago");
    $rv_rows = $rv_res['body'] ?? [];
    $link_pago_rv = $rv_rows[0]['link_pago'] ?? '';

    if ($link_pago_rv) {
        $rev_payload = ['nueva_fecha' => $fecha_original];
        if (str_starts_with($link_pago_rv, 'amelia_app_')) {
            $rev_payload['appointment_id'] = str_replace('amelia_app_', '', $link_pago_rv);
        } elseif (str_starts_with($link_pago_rv, 'amelia_booking_')) {
            $rev_payload['booking_id'] = str_replace('amelia_booking_', '', $link_pago_rv);
            enqueue_sync('move_booking', $rev_payload);
            json_response(['cancelado' => true]);
        } else {
            $lk_res  = sb_get("links_pendientes?checkout_url=eq." . urlencode($link_pago_rv) . "&select=checkout_id");
            $lk_rows = $lk_res['body'] ?? [];
            $rev_payload['link_id'] = $lk_rows[0]['checkout_id'] ?? $link_pago_rv;
        }
        enqueue_sync('reschedule', $rev_payload);
    }
}

json_response(['cancelado' => true]);
