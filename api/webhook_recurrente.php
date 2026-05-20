<?php
// POST /webhook/recurrente
$body = get_body();
error_log('[WEBHOOK RECURRENTE] Payload completo: ' . json_encode($body));

// Extraer checkout_id y status del evento
$checkout    = $body['data']['object'] ?? $body['checkout'] ?? $body;
$checkout_id = $checkout['id']     ?? $body['id'] ?? '';
$status      = $checkout['status'] ?? $body['status'] ?? '';
$event_type  = $body['type']       ?? '';

// Si el checkout_id no empieza con 'ch_', intentar extraerlo de la URL o de otros campos
if ($checkout_id && !str_starts_with($checkout_id, 'ch_')) {
    $possible_url = $checkout['checkout_url'] ?? $checkout['url'] ?? $body['data']['object']['checkout_url'] ?? '';
    if ($possible_url && preg_match('/ch_[a-z0-9]+/', $possible_url, $m)) {
        $checkout_id = $m[0];
    } elseif (!empty($checkout['checkout_id'])) {
        $checkout_id = $checkout['checkout_id'];
    }
}
error_log("[WEBHOOK RECURRENTE] checkout_id=$checkout_id status=$status event_type=$event_type");

if (str_contains($event_type, 'checkout') && str_contains($event_type, 'created')) {
    json_response(['received' => true]);
}
if (!in_array($status, ['paid', 'complete', 'completed']) && !str_contains($event_type, 'paid')) {
    json_response(['received' => true]);
}
if (!$checkout_id) {
    json_response(['received' => true]);
}

// ── Reprogramación con tarjeta ─────────────────────────────────────────────
$resc_res = sb_get("reschedule_pendientes?checkout_id=eq.$checkout_id&select=*");
$reschedules = $resc_res['body'] ?? [];

if ($reschedules) {
    $resc = $reschedules[0];
    if ($resc['estado'] === 'Cancelado') {
        json_response(['received' => true, 'ignorado' => 'reschedule cancelado']);
    }

    sb_patch("reschedule_pendientes?id=eq." . $resc['id'], ['estado' => 'Pagado']);
    sb_patch("reservaciones?id=eq." . $resc['reservacion_id'], ['fecha_ascenso' => $resc['fecha_nueva']]);

    // Resolver recordatorios
    sb_patch(
        "reschedule_recordatorios?reservacion_id=eq." . $resc['reservacion_id'] . "&estado=eq.Activo",
        ['estado' => 'Resuelto']
    );

    // Sincronizar Amelia — move_booking si hay amelia_booking_*, sino reschedule legacy
    if (es_wolfs($resc['agencia'] ?? '')) {
        $rv = sb_get("reservaciones?id=eq." . $resc['reservacion_id'] . "&select=link_pago");
        $lp = $rv['body'][0]['link_pago'] ?? '';
        if (str_starts_with($lp, 'amelia_booking_')) {
            enqueue_sync('move_booking', [
                'booking_id'  => str_replace('amelia_booking_', '', $lp),
                'nueva_fecha' => $resc['fecha_nueva'],
            ]);
        } else {
            enqueue_sync('reschedule', ['nueva_fecha' => $resc['fecha_nueva'], 'link_id' => $checkout_id]);
        }
    }

    enviar_email(
        "Reprogramacion pagada: " . ($resc['nombre'] ?? '') . " -> " . ($resc['fecha_nueva'] ?? ''),
        "<p><b>" . htmlspecialchars($resc['nombre'] ?? '') . "</b> reprogramo del "
        . htmlspecialchars($resc['fecha_original'] ?? '') . " al "
        . htmlspecialchars($resc['fecha_nueva'] ?? '')
        . " — Q" . number_format((float)($resc['precio'] ?? 0)) . " pagados.</p>"
    );

    json_response(['received' => true, 'procesado' => true, 'tipo' => 'reprogramacion']);
}

// ── Pago normal de reservación ────────────────────────────────────────────────
$lp_res = sb_get("links_pendientes?checkout_id=eq.$checkout_id&select=*");
$pendientes = $lp_res['body'] ?? [];

if (empty($pendientes)) {
    // Fallback por checkout_url
    $lp_res2 = sb_get("links_pendientes?checkout_url=like.*{$checkout_id}*&select=*");
    $pendientes = $lp_res2['body'] ?? [];
}

if (empty($pendientes)) {
    // Fallback directo en reservaciones
    $rv_res = sb_get("reservaciones?link_pago=like.*{$checkout_id}*&select=id,estado_pago,link_pago,nombre,correo,tipo_cabana,precio,fecha_ascenso,agencia&limit=5");
    $rv_rows = $rv_res['body'] ?? [];
    foreach ($rv_rows as $rv) {
        if ($rv['estado_pago'] === 'Completado') continue;
        sb_patch("reservaciones?id=eq." . $rv['id'], [
            'estado_pago' => 'Completado',
            'fecha_pago'  => gt_date(),
        ]);
        // Sincronizar links_pendientes por checkout_url
        sb_patch("links_pendientes?checkout_url=like.*{$checkout_id}*&estado=eq.Esperando%20pago", ['estado' => 'Pagado']);
        if (es_wolfs($rv['agencia'] ?? '')) {
            $lp = $rv['link_pago'] ?? '';
            if (str_starts_with($lp, 'amelia_booking_')) {
                enqueue_sync('update_status', ['estado' => 'approved', 'booking_id' => str_replace('amelia_booking_', '', $lp)]);
            } else {
                enqueue_sync('update_status', [
                    'estado'        => 'approved',
                    'link_id'       => $checkout_id,
                    'fecha_ascenso' => $rv['fecha_ascenso'] ?? '',
                    'tipo_cabana'   => $rv['tipo_cabana']   ?? '',
                ]);
            }
        }
    }
    json_response(['received' => true]);
}

$link = $pendientes[0];
if (in_array($link['estado'], ['Pagado', 'Cancelado'])) {
    json_response(['received' => true, 'ignorado' => 'link ya procesado']);
}

$checkout_url  = $link['checkout_url']  ?? '';
$agencia       = $link['agencia']       ?? '';
$fecha_ascenso = $link['fecha_ascenso'] ?? '';
$tipo_cabana   = $link['tipo_cabana']   ?? '';

// Marcar link como Pagado
sb_patch("links_pendientes?checkout_id=eq.$checkout_id", [
    'estado'         => 'Pagado',
    'fecha_pago_real'=> gt_date(),
]);

// Actualizar reservación a Completado
sb_patch("reservaciones?link_pago=eq." . urlencode($checkout_url), [
    'estado_pago' => 'Completado',
    'fecha_pago'  => gt_date(),
]);

// Sincronizar Amelia
if (es_wolfs($agencia)) {
    $cli_fecha  = $fecha_ascenso;
    $cli_cabana = $tipo_cabana;
    $rv2 = sb_get("reservaciones?link_pago=eq." . urlencode($checkout_url) . "&select=link_pago,fecha_ascenso,tipo_cabana");
    $rv2_row = $rv2['body'][0] ?? [];
    if ($rv2_row) {
        $cli_fecha  = $rv2_row['fecha_ascenso'] ?? $cli_fecha;
        $cli_cabana = $rv2_row['tipo_cabana']   ?? $cli_cabana;
    }
    enqueue_sync('update_status', [
        'estado'        => 'approved',
        'link_id'       => $checkout_id,
        'fecha_ascenso' => $cli_fecha,
        'tipo_cabana'   => $cli_cabana,
    ]);
}

json_response(['received' => true, 'procesado' => true, 'tipo' => 'pago_normal']);
