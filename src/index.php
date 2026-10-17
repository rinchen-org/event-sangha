<?php
require_once __DIR__ . '/lib/auth.php';
auth_require();
// Redirect to templates/index.php
header("Location: templates/index.php");
exit; // Make sure to exit after the redirection
?>
