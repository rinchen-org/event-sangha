<?php

require_once dirname(__DIR__) . '/lib/settings.php';


function isEnvProduction() {
  // Get the current server's hostname from $_SERVER['HTTP_HOST']
  $currentHostWithPort = $_SERVER['HTTP_HOST'];

  // Remove the port number, if present
  $currentHost = preg_replace('/:.*$/', '', $currentHostWithPort);

  // Check if the current host is in the array of development hosts
  return !in_array(
    $currentHost, ['localhost', '127.0.0.1', '0.0.0.0']
  );
}

$BASE_URL = get_env("HOST_ADDRESS");
$ENV_PROD = isEnvProduction();
?>
