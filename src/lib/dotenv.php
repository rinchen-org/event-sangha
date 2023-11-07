<?php

/**
 * @return array<string,string>
 */
function load_env(): array {
  $envFilePath = dirname(__DIR__) . '/.env';

  // Check if the .env file exists
  if (!file_exists($envFilePath)) {
    // If the file doesn't exist, create it and write the default content
    file_put_contents($envFilePath, "");
    // $message = '.env file created successfully with default content.';
    // error_log($message, E_WARNING);
  } else {
    // $message = '.env file already exists.';
    // error_log($message, E_WARNING);
  }

  // Load the content of the .env file into an array
  $envContent = file(
    $envFilePath,
    FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES
  );

  // Initialize an empty array to store the environment variables
  $envVariables = ["" => ""];

  // Loop through each line in the .env file
  foreach ($envContent as $line) {
      // Split the line into key and value using the '=' character
      list($key, $value) = explode('=', $line, 2);
      // Store the key-value pair in the $envVariables array
      $envVariables[$key] = $value;
  }
  return $envVariables;
}
?>
