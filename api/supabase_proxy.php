<?php
// GET/POST/PATCH/DELETE /api/sb/{path} — proxy a Supabase
$sb_path = $_route_sb_path ?? '';
if (!$sb_path) json_error('Path requerido', 400);

$qs     = $_SERVER['QUERY_STRING'] ?? '';
$target = SUPABASE_URL . '/rest/v1/' . $sb_path . ($qs ? "?$qs" : '');
$body   = file_get_contents('php://input');
$prefer = $_SERVER['HTTP_PREFER'] ?? 'return=representation';

$headers = [
    'apikey: '        . SUPABASE_KEY,
    'Authorization: Bearer ' . SUPABASE_KEY,
    'Content-Type: application/json',
];
if ($method !== 'GET') {
    $headers[] = "Prefer: $prefer";
}

$ch = curl_init($target);
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_CUSTOMREQUEST  => $method,
    CURLOPT_HTTPHEADER     => $headers,
    CURLOPT_TIMEOUT        => 30,
]);
if ($body && $method !== 'GET') {
    curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
}
$resp_body = curl_exec($ch);
$status    = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

http_response_code($status);
header('Content-Type: application/json; charset=utf-8');
echo $resp_body;
exit;
