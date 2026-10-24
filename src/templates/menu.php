<?php
// Compatibility for old bookmarks. The legacy menu itself has been retired.
require_once dirname(__DIR__) . '/lib/auth.php';
auth_require();
header('Location: ' . auth_url('templates/index.php'), true, 303);
exit;
