<?php
include "functions.php";
?>
<link rel="stylesheet" href="./style.css">
<?php

$fullname = $_GET['fullname'];
$email = $_GET['email'];
$phone = $_GET['phone'];

$response = generate_qr($fullname, $email, $phone);

print "<pre class='qr'>\n";
print $response;
print "\n</pre";


?>
