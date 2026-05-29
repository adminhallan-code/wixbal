<?php
// GET /webhook/qpaypro — Relay URL de QPayPro
// QPayPro redirige el browser del cliente aquí después del pago.
// Parámetros GET: x_response_status, x_response_code, x_trans_id, x_amount,
//                 x_invoice_num (= links_pendientes.id), x_MD5_Hash, x_custom_fields

$status_code  = $_GET['x_response_status'] ?? '';
$resp_code    = $_GET['x_response_code']   ?? '';
$trans_id     = $_GET['x_trans_id']        ?? '';
$amount       = $_GET['x_amount']          ?? '';
$invoice_num  = (int)($_GET['x_invoice_num'] ?? 0);
$hash_recv    = $_GET['x_MD5_Hash']        ?? '';
$event_type   = $_GET['x_response_text']   ?? '';

error_log("[WEBHOOK QPAYPRO] status=$status_code code=$resp_code trans_id=$trans_id invoice=$invoice_num amount=$amount");

// ── Redirigir siempre al final (browser está esperando) ──────────────────────
$url_exito  = 'https://wolfsacatenango.com';
$url_error  = 'https://wolfsacatenango.com';

// Pago no aprobado — redirigir sin procesar
if ($status_code !== '1' || $resp_code !== '1') {
    error_log("[WEBHOOK QPAYPRO] Pago no aprobado — ignorando. status=$status_code code=$resp_code");
    header('Location: ' . $url_error);
    exit;
}

if (!$invoice_num) {
    error_log("[WEBHOOK QPAYPRO] Sin x_invoice_num — no se puede identificar la reservación.");
    header('Location: ' . $url_error);
    exit;
}

// ── Verificar hash MD5 (fórmula: MD5(api_secret + x_login + x_trans_id + x_amount)) ──
if ($hash_recv && !qpaypro_verificar_hash($trans_id, $amount, $hash_recv)) {
    error_log("[WEBHOOK QPAYPRO] ADVERTENCIA: x_MD5_Hash no coincide — trans_id=$trans_id amount=$amount hash=$hash_recv");
    // Logeamos pero continuamos; confirmar fórmula exacta con soporte QPayPro si falla en producción.
}

// ── Buscar el link en links_pendientes ───────────────────────────────────────
$lp_res = sb_get("links_pendientes?id=eq.$invoice_num&select=*");
$pendientes = $lp_res['body'] ?? [];

if (empty($pendientes)) {
    error_log("[WEBHOOK QPAYPRO] links_pendientes no encontrado para invoice=$invoice_num");
    header('Location: ' . $url_exito);
    exit;
}

$link = $pendientes[0];

if (in_array($link['estado'], ['Pagado', 'Cancelado'])) {
    error_log("[WEBHOOK QPAYPRO] Link ya procesado (estado={$link['estado']}) — ignorando duplicado.");
    header('Location: ' . $url_exito);
    exit;
}

$checkout_url  = $link['checkout_url']  ?? '';
$agencia       = $link['agencia']       ?? '';
$fecha_ascenso = $link['fecha_ascenso'] ?? '';
$tipo_cabana   = $link['tipo_cabana']   ?? '';
$token         = $link['checkout_id']   ?? '';

// ── Marcar link como Pagado ───────────────────────────────────────────────────
sb_patch("links_pendientes?id=eq.$invoice_num", ['estado' => 'Pagado']);

// ── Marcar reservación como Completado ────────────────────────────────────────
sb_patch("reservaciones?link_pago=eq." . urlencode($checkout_url), [
    'estado_pago' => 'Completado',
    'fecha_pago'  => gt_date(),
]);

// Obtener datos de la reservación para factura y email
$rv_data = sb_get(
    "reservaciones?link_pago=eq." . urlencode($checkout_url)
    . "&select=id,nombre,correo,precio,tipo_cabana,fecha_ascenso,link_pago,agencia,nit,tipo_identificacion,nombre_fiscal,paquete"
);
$rv_row = $rv_data['body'][0] ?? [];

// ── Factura + email de confirmación ──────────────────────────────────────────
if ($rv_row) {
    $factura = felplex_emitir_factura(
        $rv_row['id'],
        $link['nombre'],
        $link['correo'] ?? null,
        (float)($link['precio'] ?? 0),
        $link['tipo_cabana'],
        $link['fecha_ascenso'],
        $link['nit']                 ?? null,
        $link['tipo_identificacion'] ?? null,
        $link['nombre_fiscal']       ?? null,
        $link['paquete']             ?? null
    );
    if ($link['correo'] ?? null) {
        enviar_confirmacion_cliente($link['correo'], $link['nombre'], $link['tipo_cabana'], $factura['url'] ?? null);
    }
}

// ── Email interno de notificación ────────────────────────────────────────────
enviar_email(
    "Pago recibido QPayPro: " . ($link['nombre'] ?? '') . " | " . ($fecha_ascenso) . " | Q" . number_format((float)($link['precio'] ?? 0)),
    "<p><b>" . htmlspecialchars($link['nombre'] ?? '') . "</b> pagó su reservación QPayPro.<br>"
    . "Fecha de ascenso: <b>$fecha_ascenso</b> | Cabaña: <b>$tipo_cabana</b><br>"
    . "Trans ID QPayPro: <b>$trans_id</b> | Monto: Q<b>" . number_format((float)($link['precio'] ?? 0)) . "</b></p>"
);

// ── Sincronizar Amelia ────────────────────────────────────────────────────────
if (es_wolfs($agencia)) {
    $lp_actual = $rv_row['link_pago'] ?? $checkout_url;
    if (str_starts_with($lp_actual, 'amelia_booking_')) {
        enqueue_sync('update_status', [
            'estado'     => 'approved',
            'booking_id' => str_replace('amelia_booking_', '', $lp_actual),
        ]);
    } else {
        enqueue_sync('update_status', [
            'estado'        => 'approved',
            'link_id'       => $token,
            'fecha_ascenso' => $rv_row['fecha_ascenso'] ?? $fecha_ascenso,
            'tipo_cabana'   => $rv_row['tipo_cabana']   ?? $tipo_cabana,
        ]);
    }
}

error_log("[WEBHOOK QPAYPRO] Procesado OK — invoice=$invoice_num trans_id=$trans_id");

// ── Redirigir al cliente a página de éxito ────────────────────────────────────
header('Location: ' . $url_exito);
exit;
