<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../helpers.php';

if (($_GET['secret'] ?? '') !== 'WOLFS_CRON_2026') { http_response_code(403); exit; }

// María Ordóñez Hernández (9868) — G29075089 pasaporte mexicano
$rv_res = sb_get("reservaciones?id=eq.9868&select=id,nombre,correo,precio,tipo_cabana,fecha_ascenso,factura_uuid");
$rv = $rv_res['body'][0] ?? null;

if (!$rv) { json_response(['error' => 'no encontrada']); }
if (!empty($rv['factura_uuid'])) { json_response(['resultado' => 'ya tiene factura', 'uuid' => $rv['factura_uuid']]); }

$key    = FELPLEX_API_KEY;
$total  = round((float)$rv['precio'], 2);
$iva    = round((float)$rv['precio'] * 12 / 112, 2);
$gt_now = gmdate('Y-m-d\TH:i:s', time() + GT_OFFSET * 3600);
$desc   = 'Cabaña Privada Exclusiva: Ascenso al Volcán Acatenango con servicio todo incluido.';

// Probar con PASAPORTE
$tipo_a_probar = $_GET['tipo'] ?? 'PASAPORTE';
$nit_a_probar  = $_GET['nit']  ?? 'G29075089';

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
    'to'             => [
        'tax_code_type' => $tipo_a_probar,
        'tax_code'      => $nit_a_probar,
        'tax_name'      => 'María Ordóñez Hernández',
        'address'       => ['street' => 'Ciudad', 'city' => 'Guatemala', 'state' => 'GU', 'zip' => '01001', 'country' => 'GT'],
    ],
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
    sb_patch("reservaciones?id=eq.9868", [
        'factura_uuid' => $uuid, 'factura_url' => $url, 'factura_sat_autorizacion' => $sat,
        'nit' => $nit_a_probar, 'tipo_identificacion' => $tipo_a_probar, 'nombre_fiscal' => 'María Ordóñez Hernández',
    ]);
    enviar_confirmacion_cliente($rv['correo'], $rv['nombre'], $rv['tipo_cabana'], $url);
    json_response(['resultado' => 'ok', 'factura_url' => $url, 'uuid' => $uuid]);
}

json_response(['resultado' => 'error', 'http_status' => $status, 'felplex_response' => $data, 'tipo_usado' => $tipo_a_probar, 'nit_usado' => $nit_a_probar]);
