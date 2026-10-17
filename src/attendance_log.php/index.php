<?php
require_once dirname(__DIR__) . '/lib/auth.php';
auth_require();





// Redirect to templates/index.php
$params = str_replace("+", "%2B", $_SERVER['QUERY_STRING']);
$url = auth_url('templates/attendance/log.php') . '?' . $params;

header("Location: {$url}");
exit; // Make sure to exit after the redirection
?>
