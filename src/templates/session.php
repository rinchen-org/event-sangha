<?php
require_once dirname(__DIR__) . '/lib/auth.php';
require_once dirname(__DIR__) . '/lib/test_mode.php';
// Session bootstrap is always JSON, even without Accept headers.
$_GET['format'] = 'json';
auth_require();
header('Content-Type: application/json; charset=utf-8');
echo json_encode(['success' => true, 'csrfToken' => auth_csrf_token(), 'username' => AUTH_ADMIN_USER, 'testMode' => test_mode_enabled()]);
