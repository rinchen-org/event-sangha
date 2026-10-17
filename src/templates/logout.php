<?php
require_once dirname(__DIR__) . '/lib/auth.php';
auth_require();
auth_post_only();
$_SESSION = [];
$params = session_get_cookie_params();
setcookie(session_name(), '', [
    'expires' => time() - 3600, 'path' => $params['path'],
    'secure' => $params['secure'], 'httponly' => true, 'samesite' => 'Lax',
]);
session_destroy();
header('Location: ' . auth_url('templates/login.php'), true, 303);
exit;
