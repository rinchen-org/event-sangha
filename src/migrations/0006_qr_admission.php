<?php
// Run this additive migration alone on existing databases; do not rerun old migrations.
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require_once dirname(__DIR__) . '/lib/db.php';
require_once dirname(__DIR__) . '/lib/schema.php';

function migrate_0006(): void {
    $db = get_db();
    $db->enableExceptions(true);
    create_qr_admission_schema($db);
    $db->close();
}
if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    migrate_0006();
}
