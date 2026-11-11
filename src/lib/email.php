<?php
require_once __DIR__ . '/auth.php';
auth_block_direct(__FILE__);

require_once __DIR__ . "/settings.php";

/**
 * @param array<string,string> $context
 * @param array<string,array{data: string, mime: string}> $inlineImages Trusted, validated local images keyed by content ID.
 */
function send_email(
  string $templateFile,
  array $context,
  string $subject,
  string $from,
  string $to,
  string $reply_to,
  string $cc,
  array $inlineImages = []
): bool {
  if (get_env("SEND_EMAIL") == "0") {
    return false;
  }
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
  $headers .= 'MIME-Version: 1.0' . "\r\n";
  if ($inlineImages) {
    $boundary = 'rinchen-' . bin2hex(random_bytes(24));
    $headers .= 'Content-Type: multipart/related; boundary="' . $boundary . '"' . "\r\n";
    $body = '--' . $boundary . "\r\nContent-Type: text/html; charset=utf-8\r\nContent-Transfer-Encoding: base64\r\n\r\n"
      . chunk_split(base64_encode($htmlContent));
    foreach ($inlineImages as $cid => $image) {
      if (!preg_match('/^[a-z0-9-]+$/D', $cid) || !in_array($image['mime'], ['image/png', 'image/jpeg'], true)) {
        throw new InvalidArgumentException('Invalid inline mail image.');
      }
      $body .= '--' . $boundary . "\r\nContent-Type: " . $image['mime']
        . "\r\nContent-Transfer-Encoding: base64\r\nContent-ID: <" . $cid . ">\r\nContent-Disposition: inline\r\n\r\n"
        . chunk_split(base64_encode($image['data']));
    }
    $htmlContent = $body . '--' . $boundary . "--\r\n";
  } else {
    $headers .= 'Content-Type: text/html; charset=utf-8' . "\r\n";
  }

  if ($cc) {
      $headers .= 'Cc: ' . $cc . "\r\n";
  }

  // Send the email
  return mail($to, $subject, $htmlContent, $headers);
}

?>
