<?php

date_default_timezone_set("America/La_Paz");

require_once __DIR__ . "/dotenv.php";


function get_env(string $key, string $default = ""): string {
  $env = load_env();

  $env_default = [
    'HOST_ADDRESS' => 'https://rinchen.org/event-retiro',
    'SEND_EMAIL' => "1",
  ];

  return $env[$key] ?? $env_default[$key] ?? $default;
}

$BASE_URL = get_env("HOST_ADDRESS");
