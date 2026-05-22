<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../helpers.php';

if (($_GET['secret'] ?? '') !== 'WOLFS_CRON_2026') { http_response_code(403); exit; }

// María Ordóñez Hernández (9868) — G29075089 PASAPORTE guatemalteco
// Jorge Pantoja Cantú     (9871) — N-23073754 EXT

$casos = [
    ['id' => 9868, 'nit' => 'G29075089',  'tipo' => 'PASAPORTE', 'nombre_fiscal' => 'María Ordóñez Hernández'],
    ['id' => 9871, 'nit' => 'N-23073754', 'tipo' => 'EXT',       'nombre_fiscal' => 'Jorge Pantoja Cantú'],
];

$resultados = [];
foreach ($casos as $caso) {
    $rv_res = sb_get("reservaciones?id=eq.{$caso['id']}&select=id,nombre,correo,precio,tipo_cabana,fecha_ascenso,factura_uuid");
    $rv = $rv_res['body'][0] ?? null;
    if (!$rv) { $resultados[] = ['id' => $caso['id'], 'resultado' => 'no encontrado']; continue; }
    if (!empty($rv['factura_uuid'])) {
        $resultados[] = ['id' => $caso['id'], 'nombre' => $rv['nombre'], 'resultado' => 'ya tiene factura', 'factura_uuid' => $rv['factura_uuid']];
        continue;
    }

    // Guardar identificación en la reservación
    sb_patch("reservaciones?id=eq.{$caso['id']}", [
        'nit'                 => $caso['nit'],
        'tipo_identificacion' => $caso['tipo'],
        'nombre_fiscal'       => $caso['nombre_fiscal'],
    ]);

    $factura = felplex_emitir_factura(
        $rv['id'], $rv['nombre'], $rv['correo'] ?? null,
        (float)($rv['precio'] ?? 0), $rv['tipo_cabana'], $rv['fecha_ascenso'],
        $caso['nit'], $caso['tipo'], $caso['nombre_fiscal']
    );

    if ($factura && ($rv['correo'] ?? null)) {
        enviar_confirmacion_cliente($rv['correo'], $rv['nombre'], $rv['tipo_cabana'], $factura['url'] ?? null);
    }

    $resultados[] = [
        'id'         => $caso['id'],
        'nombre'     => $rv['nombre'],
        'resultado'  => $factura ? 'ok' : 'error_felplex',
        'factura_url'=> $factura['url'] ?? null,
    ];
}
json_response(['resultados' => $resultados]);
