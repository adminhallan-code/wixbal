<?php
// POST /api/reservaciones/{id}/reprogramar  — reprogramacion directa sin cobro
$res_id = $_route_res_id ?? 0;
$data   = get_body();
$nueva_fecha    = $data['nueva_fecha']    ?? '';
$reprogramado_por = $data['reprogramado_por'] ?? '';

if (!$res_id)                    json_error('ID inválido', 400);
if (!fecha_valida($nueva_fecha)) json_error('Fecha inválida');

$res = sb_get("reservaciones?id=eq.$res_id&select=link_pago,agencia,tipo_cabana,fecha_ascenso,nombre,paquete");
$rows = $res['body'] ?? [];
if (empty($rows)) json_error('Reservación no encontrada', 404);

$rv           = $rows[0];
$link_pago    = $rv['link_pago']    ?? '';
$agencia      = $rv['agencia']      ?? '';
$fecha_vieja  = $rv['fecha_ascenso'] ?? '';
$nombre_rv    = $rv['nombre']       ?? '—';
$tipo_cabana  = $rv['tipo_cabana']  ?? '—';
$paquete_rv   = $rv['paquete']      ?? '—';

// Actualizar Supabase
sb_patch("reservaciones?id=eq.$res_id", ['fecha_ascenso' => $nueva_fecha]);

// ── Notificación Telegram ────────────────────────────────────────────────────
// Grupo reservaciones: siempre
telegram_notif_res(
    "🔄 <b>Reprogramación de reservación</b>\n" .
    "━━━━━━━━━━━━━━━━━━━\n" .
    "👤 Nombre: <b>" . htmlspecialchars($nombre_rv) . "</b>\n" .
    "🏕 Cabaña: $tipo_cabana\n" .
    "📦 Paquete: $paquete_rv\n" .
    "📅 Fecha anterior: $fecha_vieja\n" .
    "📅 Nueva fecha: <b>$nueva_fecha</b>" .
    ($reprogramado_por ? "\n🧑‍💼 Reprogramado por: " . htmlspecialchars($reprogramado_por) : '')
);
// Grupo cuadros: solo si afecta mañana
$manana_gt = gmdate('Y-m-d', time() + (-6 * 3600) + 86400);
if ($fecha_vieja === $manana_gt) {
    telegram_notify(
        "🔄 <b>Reprogramación — sale del cuadro de mañana</b>\n" .
        "👤 Nombre: " . htmlspecialchars($nombre_rv) . "\n" .
        "🏕 $tipo_cabana · $paquete_rv\n" .
        "📅 Nueva fecha: $nueva_fecha" .
        ($reprogramado_por ? "\n🧑‍💼 Reprogramado por: " . htmlspecialchars($reprogramado_por) : '')
    );
    enviar_cuadro_telegram($manana_gt);
} elseif ($nueva_fecha === $manana_gt) {
    telegram_notify(
        "🔄 <b>Reprogramación — entra al cuadro de mañana</b>\n" .
        "👤 Nombre: " . htmlspecialchars($nombre_rv) . "\n" .
        "🏕 $tipo_cabana · $paquete_rv" .
        ($reprogramado_por ? "\n🧑‍💼 Reprogramado por: " . htmlspecialchars($reprogramado_por) : '')
    );
    enviar_cuadro_telegram($manana_gt);
}

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
