<?php
// POST /api/reschedule-pendientes/{id}/marcar-pagado
$resc_id = $_route_resc_id ?? 0;
if (!$resc_id) json_error('ID inválido', 400);

$body      = get_body();
$marcado_por = $body['marcado_por'] ?? 'Admin';

$res = sb_get("reschedule_pendientes?id=eq.$resc_id&select=*");
$rows = $res['body'] ?? [];
if (empty($rows)) json_error('Reprogramación no encontrada', 404);

$resc          = $rows[0];
$reservacion_id = $resc['reservacion_id'] ?? null;
$fecha_nueva    = $resc['fecha_nueva']    ?? '';
$fecha_original = $resc['fecha_original'] ?? '';
$nombre         = $resc['nombre']         ?? '';
$agencia        = $resc['agencia']        ?? '';

// Marcar como pagado
sb_patch("reschedule_pendientes?id=eq.$resc_id", ['estado' => 'Pagado']);

// Actualizar fecha en la reservacion
if ($reservacion_id && $fecha_nueva) {
    sb_patch("reservaciones?id=eq.$reservacion_id", ['fecha_ascenso' => $fecha_nueva]);
}

// Resolver recordatorios
if ($reservacion_id) {
    sb_patch(
        "reschedule_recordatorios?reservacion_id=eq.$reservacion_id&estado=eq.Activo",
        ['estado' => 'Resuelto']
    );
}

// Sincronizar Amelia (la fecha ya fue movida en solicitar_reprogramacion,
// pero si el admin marcó pagado sin haber solicitado antes, hay que moverla ahora)
if ($reservacion_id && $fecha_nueva && es_wolfs($agencia)) {
    $rv = sb_get("reservaciones?id=eq.$reservacion_id&select=link_pago,fecha_ascenso");
    $lp = $rv['body'][0]['link_pago'] ?? '';
    if (str_starts_with($lp, 'amelia_booking_')) {
        enqueue_sync('move_booking', [
            'booking_id'  => str_replace('amelia_booking_', '', $lp),
            'nueva_fecha' => $fecha_nueva,
        ]);
    }
}

enviar_email(
    "Reprogramacion pagada (efectivo): $nombre -> $fecha_nueva",
    "<p><b>$nombre</b> pago su reprogramacion en efectivo.<br>"
    . "Fecha anterior: $fecha_original<br>Nueva fecha: <b>$fecha_nueva</b><br>"
    . "Confirmado por: $marcado_por</p>"
);

json_response(['pagado' => true]);
