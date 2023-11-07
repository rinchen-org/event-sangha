<?php

function getBaseUrlFromEndpoint(string $endpoint) {
  $protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https://' : 'http://';
  $host = $_SERVER['HTTP_HOST'];
  $directory = dirname($_SERVER['SCRIPT_NAME']);

  // Remove trailing slash if present
  $directory = rtrim($directory, '/');

  $baseUrl = $protocol . $host . $directory;

  // Append a trailing slash to the base URL
  if ($baseUrl[strlen($baseUrl) - 1] !== '/') {
      $baseUrl .= '/';
  }

  return $baseUrl;
}

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

$BASE_URL = getBaseUrlFromEndpoint($_SERVER['REQUEST_URI']);
$ENV_PROD = isEnvProduction();
?>
