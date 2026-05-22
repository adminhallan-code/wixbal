<?php
// POST /api/factura_reintentar  { id: reservacion_id }
$data   = get_body();
$res_id = (int)($data['id'] ?? 0);
if (!$res_id) json_error('id requerido', 400);

$rv_res = sb_get("reservaciones?id=eq.$res_id&select=id,nombre,correo,precio,tipo_cabana,paquete,fecha_ascenso,nit,tipo_identificacion,nombre_fiscal,factura_uuid");
$rv = $rv_res['body'][0] ?? null;
if (!$rv) json_error('Reservación no encontrada', 404);

if (!empty($rv['factura_uuid'])) {
    json_response(['ok' => true, 'ya_tenia' => true, 'uuid' => $rv['factura_uuid'],
                   'url' => "https://app.felplex.com/pdf/{$rv['factura_uuid']}"]);
}

// Construir payload FELplex (misma lógica que felplex_emitir_factura + soporte paquete 4x4)
$key    = FELPLEX_API_KEY;
$total  = round((float)$rv['precio'], 2);
$iva    = round($total * 12 / 112, 2);
$gt_now = gmdate('Y-m-d\TH:i:s', time() + GT_OFFSET * 3600);

$tipo_id = strtoupper($rv['tipo_identificacion'] ?? 'CF');
if ($tipo_id === 'CF' || !$rv['nit']) {
    $to_cf = 1; $receptor = null;
} elseif ($tipo_id === 'NIT') {
    $to_cf = 0;
    $receptor = ['tax_code_type' => 'NIT', 'tax_code' => $rv['nit'],
                 'tax_name' => $rv['nombre_fiscal'] ?: $rv['nombre'],
                 'address'  => ['street' => 'Ciudad', 'city' => 'Guatemala', 'state' => 'GU', 'zip' => '01001', 'country' => 'GT']];
} elseif ($tipo_id === 'CUI') {
    $to_cf = 0;
    $receptor = ['tax_code_type' => 'DPI', 'tax_code' => $rv['nit'],
                 'tax_name' => $rv['nombre_fiscal'] ?: $rv['nombre'],
                 'address'  => ['street' => 'Ciudad', 'city' => 'Guatemala', 'state' => 'GU', 'zip' => '01001', 'country' => 'GT']];
} else {
    $to_cf = 0;
    $receptor = ['tax_code_type' => 'EXT', 'tax_code' => $rv['nit'],
                 'tax_name' => $rv['nombre_fiscal'] ?: $rv['nombre'],
                 'address'  => ['street' => 'Ciudad', 'city' => 'Guatemala', 'state' => 'GU', 'zip' => '01001', 'country' => 'GT']];
}

$descs = [
    'Privada'  => 'Cabaña Privada Exclusiva: Ascenso al Volcán Acatenango con servicio todo incluido: traslado de ida y vuelta, alimentación durante el recorrido, alojamiento en cabaña privada, acompañamiento de guías certificados e ingreso a los parques nacionales correspondientes.',
    'Mixta'    => 'Cabaña Mixta Compartida: Ascenso al Volcán Acatenango con servicio todo incluido: traslado de ida y vuelta, alimentación durante el recorrido, alojamiento en cabaña compartida, acompañamiento de guías certificados e ingreso a los parques nacionales correspondientes.',
    'Familiar' => 'Cabaña Familiar VIP+: Ascenso al Volcán Acatenango con servicio todo incluido: traslado de ida y vuelta, alimentación durante el recorrido, alojamiento en cabaña familiar, acompañamiento de guías certificados e ingreso a los parques nacionales correspondientes.',
];
$desc = $descs[$rv['tipo_cabana']] ?? "Ascenso Volcán Acatenango — Cabaña {$rv['tipo_cabana']}";
if (strtolower($rv['paquete'] ?? '') === '4x4') {
    $desc .= ' Incluye servicio de transporte 4x4.';
}

$payload = [
    'type' => 'FACT', 'currency' => 'GTQ', 'datetime_issue' => $gt_now,
    'items' => [['qty' => 1, 'type' => 'S', 'price' => $total, 'description' => $desc,
                 'without_iva' => 0, 'discount' => 0, 'is_discount_percentage' => 0]],
    'total' => $total, 'total_tax' => $iva, 'to_cf' => $to_cf,
    'emails' => $rv['correo'] ? [['email' => $rv['correo']]] : [],
];
if ($receptor) $payload['to'] = $receptor;

$ch = curl_init(FELPLEX_BASE . "/api/entity/" . FELPLEX_EMPRESA . "/invoices/await");
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true,
    CURLOPT_POSTFIELDS     => json_encode($payload),
    CURLOPT_HTTPHEADER     => ["X-Authorization: $key", "Accept: application/json", "Content-Type: application/json"],
    CURLOPT_TIMEOUT        => 30,
]);
$body   = curl_exec($ch);
$status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);
$fdata = json_decode($body, true) ?? [];

if (!empty($fdata['valid'])) {
    $uuid = $fdata['uuid'];
    $url  = $fdata['invoice_url'];
    $sat  = $fdata['sat']['authorization'] ?? '';
    sb_patch("reservaciones?id=eq.$res_id", [
        'factura_uuid'             => $uuid,
        'factura_url'              => $url,
        'factura_sat_autorizacion' => $sat,
    ]);
    json_response(['ok' => true, 'uuid' => $uuid, 'url' => $url]);
}

json_response([
    'ok'          => false,
    'http_status' => $status,
    'error_codes' => $fdata['error_codes'] ?? [],
    'messages'    => $fdata['messages'] ?? ($fdata['message'] ?? null),
    'felplex_raw' => $fdata,
]);
