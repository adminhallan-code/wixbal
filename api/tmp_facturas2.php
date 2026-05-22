<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../helpers.php';

if (($_GET['secret'] ?? '') !== 'WOLFS_CRON_2026') { http_response_code(403); exit; }

$ids = [9865, 9868, 9871, 9873];
$res = sb_get("reservaciones?id=in.(" . implode(',', $ids) . ")&select=id,nombre,correo,precio,tipo_cabana,fecha_ascenso,nit,tipo_identificacion,nombre_fiscal,factura_uuid");
$rows = $res['body'] ?? [];

$resultados = [];
foreach ($rows as $rv) {
    if (!empty($rv['factura_uuid'])) { $resultados[] = ['id' => $rv['id'], 'nombre' => $rv['nombre'], 'resultado' => 'ya tiene factura']; continue; }
    $factura = felplex_emitir_factura(
        $rv['id'], $rv['nombre'], $rv['correo'] ?? null,
        (float)($rv['precio'] ?? 0), $rv['tipo_cabana'], $rv['fecha_ascenso'],
        $rv['nit'] ?? null, $rv['tipo_identificacion'] ?? null, $rv['nombre_fiscal'] ?? null
    );
    if ($rv['correo'] ?? null) enviar_confirmacion_cliente($rv['correo'], $rv['nombre'], $rv['tipo_cabana'], $factura['url'] ?? null);
    $resultados[] = ['id' => $rv['id'], 'nombre' => $rv['nombre'], 'factura_url' => $factura['url'] ?? null, 'resultado' => $factura ? 'ok' : 'error_felplex_cf_limit'];
}
json_response(['resultados' => $resultados]);
