<?php
require_once dirname(__DIR__, 2) . '/lib/auth.php';
auth_require();
require_once dirname(__DIR__, 2) . '/lib/test_lab.php';
test_mode_require();
// Context can only be selected by this protected server route, never a query flag.
define('RINCHEN_QR_LAB', true);
require dirname(__DIR__) . '/attendance/log.php';
