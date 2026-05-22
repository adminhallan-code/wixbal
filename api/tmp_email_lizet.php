<?php
// Envío único de confirmación a Lizet Valentina Díaz (res 9881) sin bloque de factura
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../helpers.php';

if (($_GET['secret'] ?? '') !== 'WOLFS_CRON_2026') { http_response_code(403); exit; }

$correo     = 'lizlizvaletina@gmail.com';
$nombre     = 'Lizet Valentina Díaz';
$tipo_cabana = 'Mixta';

$html = html_confirmacion_reserva($nombre, $tipo_cabana, null, true); // true = omitir nota factura

$ch = curl_init('https://api.resend.com/emails');
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST           => true,
    CURLOPT_POSTFIELDS     => json_encode([
        'from'    => FROM_EMAIL,
        'to'      => [$correo],
        'subject' => '✅ Reserva Confirmada — Wolfs Acatenango',
        'html'    => $html,
    ]),
    CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . RESEND_API_KEY, 'Content-Type: application/json'],
    CURLOPT_TIMEOUT    => 10,
]);
$body   = curl_exec($ch);
$status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

$data = json_decode($body, true) ?? [];
json_response([
    'enviado'     => $status >= 200 && $status < 300,
    'http_status' => $status,
    'correo'      => $correo,
    'resend_id'   => $data['id'] ?? null,
    'error'       => $data['message'] ?? null,
]);
