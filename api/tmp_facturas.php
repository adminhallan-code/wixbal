<?php
// Endpoint temporal — genera facturas y envía emails para reservaciones específicas
// ELIMINAR después de usar

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../helpers.php';

$secret = $_GET['secret'] ?? '';
if ($secret !== 'WOLFS_CRON_2026') {
    http_response_code(403); exit;
}

// Datos de identificacion para facturas que fallaron por limite CF
$ids_extra = [
    9851 => ['nit' => 'N03954942', 'tipo_identificacion' => 'EXT', 'nombre_fiscal' => 'Hernán Gerardo Rodriguez Rivera'],
    9854 => ['nit' => 'AT414546',  'tipo_identificacion' => 'EXT', 'nombre_fiscal' => 'María Camila Narváez Gordillo'],
];

$ids = [9851, 9854, 9855]; // Hernán, María Camila, Ermin
$res = sb_get("reservaciones?id=in.(" . implode(',', $ids) . ")&select=id,nombre,correo,precio,tipo_cabana,fecha_ascenso,nit,tipo_identificacion,nombre_fiscal,factura_uuid");
$rows = $res['body'] ?? [];
// Mezclar datos extra de identificacion
foreach ($rows as &$rv) {
    if (isset($ids_extra[$rv['id']])) {
        $rv = array_merge($rv, $ids_extra[$rv['id']]);
    }
}
unset($rv);

$resultados = [];
foreach ($rows as $rv) {
    if (!empty($rv['factura_uuid'])) {
        $resultados[] = ['id' => $rv['id'], 'nombre' => $rv['nombre'], 'resultado' => 'ya tiene factura'];
        continue;
    }
    // Llamar FELplex directamente para ver la respuesta completa
    $key   = FELPLEX_API_KEY ?: '';
    $precio = (float)($rv['precio'] ?? 0);
    $total = round($precio, 2);
    $iva   = round($precio * 12 / 112, 2);
    $descs = [
        'Privada'  => 'Cabaña Privada Exclusiva: Ascenso al Volcán Acatenango con servicio todo incluido.',
        'Mixta'    => 'Cabaña Mixta Compartida: Ascenso al Volcán Acatenango con servicio todo incluido.',
        'Familiar' => 'Cabaña Familiar VIP+: Ascenso al Volcán Acatenango con servicio todo incluido.',
    ];
    $desc = $descs[$rv['tipo_cabana']] ?? "Ascenso Volcán Acatenango — Cabaña {$rv['tipo_cabana']}";
    $gt_now = gmdate('Y-m-d\TH:i:s', time() + GT_OFFSET * 3600);
    $nit              = $rv['nit'] ?? null;
    $tipo_id          = strtoupper($rv['tipo_identificacion'] ?? 'CF');
    $nombre_fiscal    = $rv['nombre_fiscal'] ?? $rv['nombre'];
    $to_cf            = (!$nit || $tipo_id === 'CF') ? 1 : 0;
    $tax_code_type    = match($tipo_id) {
        'NIT' => 'NIT', 'CUI' => 'DPI', default => 'EXT'
    };
    $receptor = $to_cf ? null : [
        'tax_code_type' => $tax_code_type,
        'tax_code'      => $nit,
        'tax_name'      => $nombre_fiscal,
        'address'       => ['street' => 'Ciudad', 'city' => 'Guatemala', 'state' => 'GU', 'zip' => '01001', 'country' => 'GT'],
    ];

    $payload = [
        'type' => 'FACT', 'currency' => 'GTQ', 'datetime_issue' => $gt_now,
        'items' => [['qty' => 1, 'type' => 'S', 'price' => $total, 'description' => $desc,
                     'without_iva' => 0, 'discount' => 0, 'is_discount_percentage' => 0]],
        'total' => $total, 'total_tax' => $iva, 'to_cf' => $to_cf,
        'emails' => $rv['correo'] ? [['email' => $rv['correo']]] : [],
    ];
    if ($receptor) $payload['to'] = $receptor;
    $ch = curl_init('https://app.felplex.com/api/entity/7107/invoices/await');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode($payload),
        CURLOPT_HTTPHEADER     => ["X-Authorization: $key", "Accept: application/json", "Content-Type: application/json"],
        CURLOPT_TIMEOUT        => 30,
    ]);
    $fel_body   = curl_exec($ch);
    $fel_status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    $fel_data = json_decode($fel_body, true) ?? [];

    $factura_url = null;
    if ($fel_status >= 200 && $fel_status < 300 && !empty($fel_data['valid'])) {
        $factura_url = $fel_data['invoice_url'] ?? null;
        $uuid = $fel_data['uuid'] ?? '';
        sb_patch("reservaciones?id=eq.{$rv['id']}", ['factura_uuid' => $uuid, 'factura_url' => $factura_url]);
    }
    if ($rv['correo'] ?? null) {
        enviar_confirmacion_cliente($rv['correo'], $rv['nombre'], $rv['tipo_cabana'], $factura_url);
    }
    $resultados[] = [
        'id'            => $rv['id'],
        'nombre'        => $rv['nombre'],
        'felplex_status'=> $fel_status,
        'felplex_resp'  => $fel_data,
        'factura_url'   => $factura_url,
        'email_enviado' => !empty($rv['correo']),
        'resultado'     => $factura_url ? 'ok' : 'error_felplex',
    ];
}

json_response(['resultados' => $resultados]);
