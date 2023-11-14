<?php
require_once __DIR__ . "/settings.php";


function generate_qr(string $fullname, string $email, string $phone): string {
  // curl qrcode.show -d https://example.com
  $qr_url = "https://api.qrserver.com/v1/create-qr-code/?size=300x300&data";

  $fullname_clean = urlencode($fullname);
  $email_clean = urlencode($email);
  $phone_clean = urlencode($phone);

  $root_url = get_env("HOST_ADDRESS");

  $endpoint = "$root_url/attendance_log.php";

  // todo: it shouldn't have this / after the $endpoint
  //       this works on apache but not on nginx
  //       as a workaround we needed to create a folder called attendance_log.php
  //       and we created a file called index.php
  $page_url = "$endpoint/?fullname=$fullname&email=$email&phone=$phone";

  $qr_url_with_data = "$qr_url=" . urlencode($page_url);

  $ch = curl_init($qr_url_with_data);

  // Set cURL options
  curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

  // Execute the request and save the response
  $response = curl_exec($ch);

  // Close session to clear up resources
  curl_close($ch);

  // Check for errors
  if (!$response) {
    throw new Exception(
      'Error: "' . curl_error($ch) .
      '" - Code: ' . curl_errno($ch) .
      ' URL: ' . $qr_url_with_data
    );
  }

  $qr_filename = hash('sha256', $page_url) . ".jpg";
  $qr_url = "$root_url/static/qr/$qr_filename";

  file_put_contents(dirname(__DIR__) . "/static/qr/$qr_filename", $response);

  return $qr_url;
}

?>
