<style>
pre.qr {
    line-height: 15px;
}
</style>

<?php

// curl qrcode.show -d https://example.com
$qr_url = "https://qrcode.show";

$page_url = "https://rinchen.org/?name=MyName&email=myemail@mail.com&idnumber=12341234";

$qr_url_with_data = "$qr_url/$page_url";

$ch = curl_init($qr_url_with_data);

// Set cURL options
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

// Execute the request and save the response
$response = curl_exec($ch);

// Check for errors
if (!$response) {
  die('Error: "' . curl_error($ch) . '" - Code: ' . curl_errno($ch));
}

// Decode the received JSON
$received_data = json_decode($response);

print "<pre class='qr'>\n";
print $response;
print "\n</pre";
// print $received_data;

// Close session to clear up resources
curl_close($ch);

?>
