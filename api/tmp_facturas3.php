<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../helpers.php';

if (($_GET['secret'] ?? '') !== 'WOLFS_CRON_2026') { http_response_code(403); exit; }

// María Ordóñez Hernández (9868) — G29075089 EXT
// Jorge Pantoja Cantú     (9871) — N-23073754 EXT

$casos = [
    ['id' => 9868, 'nit' => 'G29075089',  'tipo' => 'EXT', 'nombre_fiscal' => 'María Ordóñez Hernández'],
    ['id' => 9871, 'nit' => 'N-23073754', 'tipo' => 'EXT', 'nombre_fiscal' => 'Jorge Pantoja Cantú'],
];

$resultados = [];
foreach ($casos as $caso) {
    $rv_res = sb_get("reservaciones?id=eq.{$caso['id']}&select=id,nombre,correo,precio,tipo_cabana,fecha_ascenso,factura_uuid");
    $rv = $rv_res['body'][0] ?? null;
    if (!$rv) { $resultados[] = ['id' => $caso['id'], 'resultado' => 'no encontrado']; continue; }
    if (!empty($rv['factura_uuid'])) { $resultados[] = ['id' => $caso['id'], 'nombre' => $rv['nombre'], 'resultado' => 'ya tiene factura', 'factura_uuid' => $rv['factura_uuid']]; continue; }

    // Llamada directa a FELplex con debug
    $key   = FELPLEX_API_KEY ?: '';
    $total = round((float)$rv['precio'], 2);
    $iva   = round((float)$rv['precio'] * 12 / 112, 2);
    $descs = [
        'Privada'  => 'Cabaña Privada Exclusiva: Ascenso al Volcán Acatenango con servicio todo incluido.',
        'Mixta'    => 'Cabaña Mixta Compartida: Ascenso al Volcán Acatenango con servicio todo incluido.',
        'Familiar' => 'Cabaña Familiar VIP+: Ascenso al Volcán Acatenango con servicio todo incluido.',
    ];
    $desc   = $descs[$rv['tipo_cabana']] ?? "Ascenso Volcán Acatenango — Cabaña {$rv['tipo_cabana']}";
    $gt_now = gmdate('Y-m-d\TH:i:s', time() + GT_OFFSET * 3600);

    $payload = [
        'type' => 'FACT', 'currency' => 'GTQ', 'datetime_issue' => $gt_now,
        'items' => [['qty' => 1, 'type' => 'S', 'price' => $total, 'description' => $desc,
                     'without_iva' => 0, 'discount' => 0, 'is_discount_percentage' => 0]],
        'total' => $total, 'total_tax' => $iva, 'to_cf' => 0,
        'emails' => $rv['correo'] ? [['email' => $rv['correo']]] : [],
        'to' => [
            'tax_code_type' => 'EXT',
            'tax_code'      => $caso['nit'],
            'tax_name'      => $caso['nombre_fiscal'],
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
        $uuid = $data['uuid'] ?? '';
        $url  = $data['invoice_url'] ?? '';
        $sat  = $data['sat']['authorization'] ?? '';
        sb_patch("reservaciones?id=eq.{$caso['id']}",
            ['factura_uuid' => $uuid, 'factura_url' => $url, 'factura_sat_autorizacion' => $sat,
             'nit' => $caso['nit'], 'tipo_identificacion' => $caso['tipo'], 'nombre_fiscal' => $caso['nombre_fiscal']]);
        if ($rv['correo']) enviar_confirmacion_cliente($rv['correo'], $rv['nombre'], $rv['tipo_cabana'], $url);
        $resultados[] = ['id' => $caso['id'], 'nombre' => $rv['nombre'], 'resultado' => 'ok', 'factura_url' => $url, 'uuid' => $uuid];
    } else {
        $resultados[] = [
            'id'             => $caso['id'],
            'nombre'         => $rv['nombre'],
            'resultado'      => 'error_felplex',
            'http_status'    => $status,
            'felplex_body'   => $data,
            'payload_enviado'=> $payload,
        ];
    }
}
json_response(['resultados' => $resultados]);
