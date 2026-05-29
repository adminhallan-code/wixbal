<?php
// POST /cancelar-link/{id}
$link_id = $_route_link_id ?? 0;
if (!$link_id) json_error('Link ID inválido');

$res = sb_get(
    "links_pendientes?id=eq.$link_id"
    . "&select=checkout_url,checkout_id,product_id,estado,agencia,fecha_ascenso,tipo_cabana,nombre"
);
$rows = $res['body'] ?? [];
if (empty($rows)) json_error('Link no encontrado', 404);

$row = $rows[0];
if ($row['estado'] !== 'Esperando pago') {
    json_error("Solo se pueden cancelar links en estado 'Esperando pago'");
}

$checkout_url  = $row['checkout_url']  ?? '';
$checkout_id   = $row['checkout_id']   ?? '';
$product_id    = $row['product_id']    ?? '';
$agencia       = $row['agencia']       ?? '';
$fecha_ascenso = $row['fecha_ascenso'] ?? '';
$tipo_cabana   = $row['tipo_cabana']   ?? '';
$nombre        = $row['nombre']        ?? '';

// QPayPro: los tokens no tienen API de expiración — solo marcamos en BD.
$checkout_expirado = true;
$producto_borrado  = true;

// Marcar link como Cancelado
sb_patch("links_pendientes?id=eq.$link_id", ['estado' => 'Cancelado']);

// Cancelar en Supabase — intento A: por checkout_url (antes de create_manual)
if ($checkout_url) {
    sb_patch(
        "reservaciones?link_pago=eq." . urlencode($checkout_url),
        ['estado_pago' => 'Cancelado']
    );
}

// Cancelar en Supabase — intento B: por fecha+tipo+nombre (cuando link_pago ya es amelia_booking_*)
$amelia_booking_id = null;
if ($fecha_ascenso && $tipo_cabana && $nombre) {
    $rv = sb_get(
        "reservaciones?fecha_ascenso=eq.$fecha_ascenso&tipo_cabana=eq." . urlencode($tipo_cabana)
        . "&nombre=eq." . urlencode($nombre)
        . "&estado_pago=neq.Cancelado&link_pago=like.amelia_booking_*&select=id,link_pago"
    );
    foreach ($rv['body'] ?? [] as $r) {
        sb_patch("reservaciones?id=eq." . $r['id'], ['estado_pago' => 'Cancelado']);
        if (str_starts_with($r['link_pago'], 'amelia_booking_')) {
            $amelia_booking_id = str_replace('amelia_booking_', '', $r['link_pago']);
        }
    }
}

// Cancelar en Amelia
if (es_wolfs($agencia)) {
    if ($amelia_booking_id) {
        enqueue_sync('cancel_booking', ['booking_id' => $amelia_booking_id]);
    } else {
        enqueue_sync('update_status', [
            'estado'        => 'canceled',
            'link_id'       => $checkout_id,
            'fecha_ascenso' => $fecha_ascenso,
            'tipo_cabana'   => $tipo_cabana,
        ]);
    }
}

json_response([
    'cancelado'          => true,
    'checkout_expirado'  => $checkout_expirado,
    'producto_borrado'   => $producto_borrado,
]);
