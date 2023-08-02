<?php

/**
 * @param array<string,string> $context
 */
function send_email(
  string $templateFile,
  array $context,
  string $subject,
  string $from,
  string $to,
  string $reply_to,
  string $cc
): bool {
  // $templateFile = dirname(__DIR__) . '/templates/subscription-email.html';
  $htmlContent = file_get_contents($templateFile);

  foreach ($context as $key => $value) {
    $htmlContent = str_replace($key, $value, $htmlContent);
  }

  if ($to == "") {
    throw new Exception("The argument `to` shouldn't be empty");
  }

  if ($from == "") {
    throw new Exception(" The argument `from` shouldn't be empty");
  }

  if ($reply_to == "") {
    $reply_to = $from;
  }

  // $to = $subscription->person->email;
  // $subject = 'Centro Sakya Rinchen Ling - Confirmación Inscripción al Retiro';
  $headers = "From: $from" . "\r\n";
  $headers .= "Reply-To: $reply_to" . "\r\n";
  $headers .= 'Content-Type: text/html; charset=utf-8' . "\r\n";

  if ($cc) {
      $headers .= 'Cc: ' . $cc . "\r\n";
  }

  // Send the email
  return mail($to, $subject, $htmlContent, $headers);
}

?>
