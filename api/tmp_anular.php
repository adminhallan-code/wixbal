<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../helpers.php';
if (($_GET['secret'] ?? '') !== 'WOLFS_CRON_2026') { http_response_code(403); exit; }

$uuid     = 'EA9F28C3-644F-40EE-9021-992F17A04D64';
$res_id   = 9873;
$key      = FELPLEX_API_KEY ?: '';

// Intentar anular en FELplex
$ch = curl_init("https://app.felplex.com/api/entity/7107/invoices/$uuid");
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_CUSTOMREQUEST  => 'DELETE',
    CURLOPT_HTTPHEADER     => ["X-Authorization: $key", "Accept: application/json"],
    CURLOPT_TIMEOUT        => 20,
]);
$body   = curl_exec($ch);
$status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);
$fel_resp = json_decode($body, true) ?? [];

// Revertir reservación a Pendiente y limpiar factura
sb_patch("reservaciones?id=eq.$res_id", [
    'estado_pago'              => 'Pendiente',
    'fecha_pago'               => null,
    'factura_uuid'             => null,
    'factura_url'              => null,
    'factura_sat_autorizacion' => null,
]);

json_response([
    'felplex_status' => $status,
    'felplex_resp'   => $fel_resp,
    'reservacion'    => 'revertida a Pendiente',
]);
