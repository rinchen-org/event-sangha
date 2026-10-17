<?php
// Schema changes and seed data are CLI-only, never browser endpoints.
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . "/0001.php";
require_once __DIR__ . "/0002.php";
require_once __DIR__ . "/0003.php";
require_once __DIR__ . "/0004.php";


require_once __DIR__ . "/0006_qr_admission.php";

function migrate_all(): void {
  migrate_0001();
  migrate_0002();
  migrate_0003();
  migrate_0006();
  migrate_0004();
}

migrate_all();
