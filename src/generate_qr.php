<?php
require_once __DIR__ . '/lib/auth.php';
auth_require();
auth_confirm_action('Generar código QR');
include __DIR__ . "/lib/settings.php";
include __DIR__ . "/lib/qr.php";

global $BASE_URL;
?>
<link rel="stylesheet" href="<?php echo $BASE_URL; ?>/static/style.css">
<?php

$fullname = $_GET['fullname'];
$email = $_GET['email'];
$phone = $_GET['phone'];

$response = generate_qr($fullname, $email, $phone);

print "<pre class='qr'>\n";
print $response;
print "\n</pre";


?>
