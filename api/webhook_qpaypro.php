<?php
// GET /webhook/qpaypro — Relay URL de QPayPro
// QPayPro redirige el browser del cliente aquí después del pago (x_relay_url).
// Parámetros GET: x_response_status, x_response_code, x_trans_id, x_amount,
//                 x_invoice_num (= links_pendientes.id), x_MD5_Hash

$url_exito = 'https://wolfsacatenango.com';

// Leer parámetros — QPayPro los manda como GET
$p = $_GET;
// Fallback a POST o JSON body por si acaso
if (empty($p)) {
    $raw = file_get_contents('php://input');
    $p   = json_decode($raw, true) ?: $_POST ?: [];
}

error_log('[WEBHOOK QPAYPRO] method=' . $_SERVER['REQUEST_METHOD'] . ' params=' . json_encode($p));

$status_code = (string)($p['x_response_status'] ?? '');
$resp_code   = (string)($p['x_response_code']   ?? '');
$trans_id    = (string)($p['x_trans_id']        ?? '');
$amount      = (string)($p['x_amount']          ?? '');
$hash_recv   = (string)($p['x_MD5_Hash']        ?? '');

// QPayPro ignora nuestro x_invoice_num y le asigna el suyo propio.
// Nuestro lp_id (links_pendientes.id) viene de vuelta en el campo link_id
// que se envió dentro de custom_fields al registrar la transacción.
$invoice_num = (int)($p['link_id'] ?? $p['x_invoice_num'] ?? 0);
error_log("[WEBHOOK QPAYPRO] invoice resuelto: link_id={$p['link_id']} x_invoice_num={$p['x_invoice_num']} → usando invoice_num=$invoice_num");

error_log("[WEBHOOK QPAYPRO] status=$status_code code=$resp_code trans_id=$trans_id invoice=$invoice_num amount=$amount");

// ── Verificar que el pago fue aprobado ───────────────────────────────────────
if ($status_code !== '1' || $resp_code !== '1') {
    error_log("[WEBHOOK QPAYPRO] Pago no aprobado — status=$status_code code=$resp_code");
    header('Location: ' . $url_exito);
    exit;
}

if (!$invoice_num) {
    error_log("[WEBHOOK QPAYPRO] Sin x_invoice_num — no se puede identificar la reservación.");
    header('Location: ' . $url_exito);
    exit;
}

// ── Verificar hash MD5 ────────────────────────────────────────────────────────
if ($hash_recv) {
    if (!qpaypro_verificar_hash($trans_id, $amount, $hash_recv)) {
        // Loguear todas las combinaciones posibles para identificar la fórmula correcta
        $intentos = [
            'SECRET+LOGIN+TRANS+AMOUNT' => md5(QPAYPRO_SECRET . QPAYPRO_LOGIN . $trans_id . $amount),
            'KEY+LOGIN+TRANS+AMOUNT'    => md5(QPAYPRO_KEY    . QPAYPRO_LOGIN . $trans_id . $amount),
            'LOGIN+TRANS+AMOUNT+SECRET' => md5(QPAYPRO_LOGIN  . $trans_id . $amount . QPAYPRO_SECRET),
            'TRANS+AMOUNT+SECRET'       => md5($trans_id . $amount . QPAYPRO_SECRET),
            'TRANS+AMOUNT+KEY'          => md5($trans_id . $amount . QPAYPRO_KEY),
        ];
        $match = 'ninguna';
        foreach ($intentos as $formula => $hash_calculado) {
            if (hash_equals($hash_calculado, strtolower($hash_recv))) {
                $match = $formula;
                break;
            }
        }
        error_log("[WEBHOOK QPAYPRO] MD5 no coincide — formula_match=$match hash_recibido=$hash_recv");
    } else {
        error_log("[WEBHOOK QPAYPRO] MD5 OK — trans_id=$trans_id");
    }
}

// ── Buscar el link en links_pendientes ───────────────────────────────────────
$lp_res     = sb_get("links_pendientes?id=eq.$invoice_num&select=*");
$pendientes = $lp_res['body'] ?? [];

error_log("[WEBHOOK QPAYPRO] SB links_pendientes — query=id=eq.$invoice_num http={$lp_res['status']} registros=" . count($pendientes) . " raw=" . json_encode($lp_res['body']));

if (empty($pendientes)) {
    error_log("[WEBHOOK QPAYPRO] CRITICO: links_pendientes no encontrado — invoice=$invoice_num trans_id=$trans_id amount=$amount.");
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
error_log("[WEBHOOK QPAYPRO] rv_row: checkout_url=$checkout_url sb_http={$rv_data['status']} encontrado=" . (!empty($rv_row) ? 'SI' : 'NO') . " correo=" . ($rv_row['correo'] ?? 'null'));

// ── Factura + email de confirmación al cliente ────────────────────────────────
// Usar $rv_row si existe, sino usar datos de $link como fallback
$factura = null;
if ($rv_row) {
    $factura = felplex_emitir_factura(
        $rv_row['id'],
        $link['nombre'],
        $link['correo']              ?? null,
        (float)($link['precio']     ?? 0),
        $link['tipo_cabana'],
        $link['fecha_ascenso'],
        $link['nit']                 ?? null,
        $link['tipo_identificacion'] ?? null,
        $link['nombre_fiscal']       ?? null,
        $link['paquete']             ?? null
    );
    error_log("[WEBHOOK QPAYPRO] Factura: " . ($factura ? "OK uuid={$factura['uuid']}" : 'FALLIDA o sin API key'));
} else {
    error_log("[WEBHOOK QPAYPRO] AVISO: rv_row vacío — factura omitida, email igual se intenta");
}

// Enviar email de confirmación al cliente (usa $link, no depende de $rv_row)
$correo_cliente = $link['correo'] ?? null;
if ($correo_cliente) {
    enviar_confirmacion_cliente($correo_cliente, $link['nombre'], $link['tipo_cabana'], $factura['url'] ?? null);
    error_log("[WEBHOOK QPAYPRO] Email confirmación enviado a $correo_cliente");
} else {
    error_log("[WEBHOOK QPAYPRO] Sin correo en el link — email de confirmación omitido");
}

// ── Email interno de notificación ────────────────────────────────────────────
enviar_email(
    "Pago recibido QPayPro: " . ($link['nombre'] ?? '') . " | $fecha_ascenso | Q" . number_format((float)($link['precio'] ?? 0)),
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

// Redirigir al cliente a wolfsacatenango.com
header('Location: ' . $url_exito);
exit;
