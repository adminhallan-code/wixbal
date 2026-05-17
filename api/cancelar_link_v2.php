<?php
// POST /api/links/{link_id}/cancelar
$link_id = $_route_link_id ?? 0;
if (!$link_id) json_error('ID inválido', 400);

$res = sb_get("links_pendientes?id=eq.$link_id&select=*");
$rows = $res['body'] ?? [];
if (empty($rows)) json_error('Link no encontrado', 404);

$link       = $rows[0];
$checkout_id  = $link['checkout_id']  ?? '';
$checkout_url = $link['checkout_url'] ?? '';
$agencia      = $link['agencia']      ?? '';

// Marcar como Cancelado
sb_patch("links_pendientes?id=eq.$link_id", ['estado' => 'Cancelado']);

// Cancelar la reservación con ese link_pago
if ($checkout_url) {
    sb_patch("reservaciones?link_pago=eq." . urlencode($checkout_url), ['estado_pago' => 'Cancelado']);
}

// Sync Amelia
if ($checkout_id && es_wolfs($agencia)) {
    enqueue_sync('update_status', ['estado' => 'canceled', 'link_id' => $checkout_id]);
}

json_response(['success' => true]);
