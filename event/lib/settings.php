<?php
require_once __DIR__ . "/dotenv.php";


function get_env(string $key): string {
  $env = load_env();

  $env_default = [
    'HOST_ADDRESS' => 'https://rinchen.org/event-retiro',
  ];

  return $env[$key] ?? $env_default[$key] ?? "";
}
