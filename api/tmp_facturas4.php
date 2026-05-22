<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../helpers.php';

if (($_GET['secret'] ?? '') !== 'WOLFS_CRON_2026') { http_response_code(403); exit; }

// Domenico Bee (9879) — EXT PA0805074, paquete 4x4, Cabaña Familiar, Q9,984
$rv_res = sb_get("reservaciones?id=eq.9879&select=id,nombre,correo,precio,tipo_cabana,paquete,fecha_ascenso,factura_uuid");
$rv = $rv_res['body'][0] ?? null;

if (!$rv) { json_response(['error' => 'reservacion no encontrada']); }
if (!empty($rv['factura_uuid'])) {
    json_response(['resultado' => 'ya tiene factura', 'uuid' => $rv['factura_uuid']]);
}

$key   = FELPLEX_API_KEY;
$total = round((float)$rv['precio'], 2);
$iva   = round((float)$rv['precio'] * 12 / 112, 2);
$gt_now = gmdate('Y-m-d\TH:i:s', time() + GT_OFFSET * 3600);

// Descripción con 4x4
$descs = [
    'Privada'  => 'Cabaña Privada Exclusiva: Ascenso al Volcán Acatenango con servicio todo incluido: traslado de ida y vuelta, alimentación durante el recorrido, alojamiento en cabaña privada, acompañamiento de guías certificados e ingreso a los parques nacionales correspondientes.',
    'Mixta'    => 'Cabaña Mixta Compartida: Ascenso al Volcán Acatenango con servicio todo incluido: traslado de ida y vuelta, alimentación durante el recorrido, alojamiento en cabaña compartida, acompañamiento de guías certificados e ingreso a los parques nacionales correspondientes.',
    'Familiar' => 'Cabaña Familiar VIP+: Ascenso al Volcán Acatenango con servicio todo incluido: traslado de ida y vuelta, alimentación durante el recorrido, alojamiento en cabaña familiar, acompañamiento de guías certificados e ingreso a los parques nacionales correspondientes.',
];
$desc = ($descs[$rv['tipo_cabana']] ?? "Ascenso Volcán Acatenango — Cabaña {$rv['tipo_cabana']}")
      . ' Incluye servicio de transporte 4x4.';

$nit_a_probar  = $_GET['nit']  ?? 'PA0805074';
$tipo_a_probar = $_GET['tipo'] ?? 'EXT';

$receptor = [
    'tax_code_type' => $tipo_a_probar,
    'tax_code'      => $nit_a_probar,
    'tax_name'      => 'Domenico Bee',
    'address'       => ['street' => 'Ciudad', 'city' => 'Guatemala', 'state' => 'GU', 'zip' => '01001', 'country' => 'GT'],
];

$payload = [
    'type'           => 'FACT',
    'currency'       => 'GTQ',
    'datetime_issue' => $gt_now,
    'items'          => [['qty' => 1, 'type' => 'S', 'price' => $total, 'description' => $desc,
                          'without_iva' => 0, 'discount' => 0, 'is_discount_percentage' => 0]],
    'total'          => $total,
    'total_tax'      => $iva,
    'to_cf'          => 0,
    'emails'         => $rv['correo'] ? [['email' => $rv['correo']]] : [],
    'to'             => $receptor,
];

$ch = curl_init(FELPLEX_BASE . "/api/entity/" . FELPLEX_EMPRESA . "/invoices/await");
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST           => true,
    CURLOPT_POSTFIELDS     => json_encode($payload),
    CURLOPT_HTTPHEADER     => ["X-Authorization: $key", "Accept: application/json", "Content-Type: application/json"],
    CURLOPT_TIMEOUT        => 30,
]);
$body   = curl_exec($ch);
$status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

$data = json_decode($body, true) ?? [];

if (!empty($data['valid'])) {
    $uuid = $data['uuid'];
    $url  = $data['invoice_url'];
    $sat  = $data['sat']['authorization'] ?? '';
    sb_patch("reservaciones?id=eq.9879", [
        'factura_uuid'            => $uuid,
        'factura_url'             => $url,
        'factura_sat_autorizacion'=> $sat,
        'nit'                     => $nit_a_probar,
        'tipo_identificacion'     => $tipo_a_probar,
        'nombre_fiscal'           => 'Domenico Bee',
    ]);
    enviar_confirmacion_cliente($rv['correo'], $rv['nombre'], $rv['tipo_cabana'], $url);
    json_response(['resultado' => 'ok', 'factura_url' => $url, 'uuid' => $uuid, 'tipo' => $tipo_a_probar, 'nit' => $nit_a_probar]);
}

json_response([
    'resultado'       => 'error',
    'http_status'     => $status,
    'error_codes'     => $data['error_codes'] ?? [],
    'error_messages'  => $data['messages'] ?? ($data['message'] ?? null),
    'tipo_usado'      => $tipo_a_probar,
    'nit_usado'       => $nit_a_probar,
]);
