<?php
require_once dirname(__DIR__) . "/event/lib/settings.php";


function test_settings(): void {
  $envFilePath = dirname(__DIR__) . '/event/.env';

  $address = "http://localhost:8000";
  file_put_contents($envFilePath, "HOST_ADDRESS=$address\n");
  assert($address == get_env("HOST_ADDRESS"));

  $address = "http://localhost:8001";
  file_put_contents($envFilePath, "HOST_ADDRESS=$address\n");
  assert($address == get_env("HOST_ADDRESS")); // @phpstan-ignore-line
}

test_settings();
