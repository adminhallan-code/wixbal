<?php
// amelia_webhook_proxy.php
// Archivo temporal para espiar los webhooks enviados por Amelia.

// 1. Registrar todo lo que recibimos
$log_file = __DIR__ . '/amelia_webhook_proxy.log';
$method = $_SERVER['REQUEST_METHOD'];
$contentType = $_SERVER['CONTENT_TYPE'] ?? '';
$body = file_get_contents('php://input');

$log_entry = "======================\n";
$log_entry .= "DATE: " . date('Y-m-d H:i:s') . "\n";
$log_entry .= "METHOD: " . $method . "\n";
$log_entry .= "CONTENT-TYPE: " . $contentType . "\n";
$log_entry .= "BODY:\n" . $body . "\n";
$log_entry .= "======================\n\n";

file_put_contents($log_file, $log_entry, FILE_APPEND);

// 2. Reenviar todo a Railway exactamente igual
$railway_url = "https://wolfs-reservaciones-production.up.railway.app/webhook/amelia";

$ch = curl_init($railway_url);
curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
$headers = [
    'Content-Type: ' . $contentType,
    'Content-Length: ' . strlen($body)
];
curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

$response = curl_exec($ch);
$http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

// 3. Devolver la misma respuesta al servidor de Amelia (para que no piense que falló)
http_response_code($http_code);
header('Content-Type: application/json');
echo $response;
?>
