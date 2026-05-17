<?php
$secret  = 'WOLFS_DEPLOY_2026';
$header  = $_SERVER['HTTP_X_HUB_SIGNATURE_256'] ?? '';
$payload = file_get_contents('php://input');

if (!hash_equals('sha256=' . hash_hmac('sha256', $payload, $secret), $header)) {
    http_response_code(403); exit;
}

$output = shell_exec('cd /home/wixbalco/public_html && git pull origin master 2>&1');
echo $output;