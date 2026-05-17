<?php
$secret  = 'WOLFS_DEPLOY_2026';
$header  = $_SERVER['HTTP_X_HUB_SIGNATURE_256'] ?? '';
$payload = file_get_contents('php://input');

if (!hash_equals('sha256=' . hash_hmac('sha256', $payload, $secret), $header)) {
    http_response_code(403); exit;
}

$git_dir   = '/home1/ixcanula/home/ixcanula/public_html/.git';
$work_tree = '/home1/ixcanula/public_html';

$fetch  = shell_exec("git --git-dir=$git_dir --work-tree=$work_tree fetch origin master 2>&1");
$reset  = shell_exec("git --git-dir=$git_dir --work-tree=$work_tree reset --hard origin/master 2>&1");
echo $fetch . $reset;
