<?php
require_once dirname(__DIR__) . '/lib/auth.php';
auth_require();
require_once dirname(__DIR__) . '/lib/layout.php';
AppLayout::end();
