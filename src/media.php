<?php
require_once __DIR__ . '/lib/auth.php';
auth_require();
$file = $_GET['file'] ?? '';
$directory = realpath(__DIR__ . '/static/qr');
if (!is_string($file) || !preg_match('/^[a-f0-9]{64}\.jpg$/D', $file) || $directory === false) {
    http_response_code(404);
    exit;
}
$path = realpath($directory . '/' . $file);
if ($path === false || dirname($path) !== $directory || !is_file($path)) {
    http_response_code(404);
    exit;
}
header('Content-Type: image/jpeg');
header('Content-Length: ' . filesize($path));
readfile($path);
