<?php
$to = "ivan.ogasawara+rinchen@gmail.com";
$subject = 'Subscription Confirmation';
$headers = 'From: sender@example.com' . "\r\n";
$headers .= 'Reply-To: sender@example.com' . "\r\n";
$headers .= 'Content-Type: text/html; charset=utf-8' . "\r\n";

$htmlContent = "<h1>Title</h1><p>Email sent.</p>";

// Send the email
if (mail($to, $subject, $htmlContent, $headers)) {
    echo 'Email sent successfully.';
} else {
    echo 'Email could not be sent.';
}

?>
