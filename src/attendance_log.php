<?php

require_once "./lib/settings.php";

global $BASE_URL;

// Redirect to templates/index.php
$params = str_replace("+", "%2B", $_SERVER['QUERY_STRING']);
$url = "{$BASE_URL}/templates/attendance/log.php?{$params}";

header("Location: {$url}");
exit; // Make sure to exit after the redirection
?>
