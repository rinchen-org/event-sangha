<?php

function generate_qr($fullname, $email, $phone) {
  // curl qrcode.show -d https://example.com
  $qr_url = "https://qrcode.show";

  $fullname_clean = urlencode($fullname);
  $email_clean = urlencode($email);
  $phone_clean = urlencode($phone);
  $endpoint = "https://rinchen.org/event-retiro/attendance_log.php";
  //$endpoint = "http://localhost:9000/attendance_log.php";

  $page_url = "$endpoint/?fullname=$fullname_clean&email=$email_clean&phone=$phone_clean";

  $qr_url_with_data = "$qr_url/$page_url";

  $ch = curl_init($qr_url_with_data);

  // Set cURL options
  curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

  // Execute the request and save the response
  $response = curl_exec($ch);

  // Close session to clear up resources
  curl_close($ch);

  // Check for errors
  if (!$response) {
    die(
      'Error: "' . curl_error($ch) .
      '" - Code: ' . curl_errno($ch) .
      ' URL: ' . $qr_url_with_data
    );
  }

  return $response;
}

?>
