<?php
// Additive only. Back up first; never run the historical migration bundle.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once dirname(__DIR__) . '/lib/schema.php';

function migrate_0007(): void {
    $db = new SQLite3(dirname(__DIR__) . '/db.sqlite', SQLITE3_OPEN_READWRITE);
    $db->enableExceptions(true);
    $db->busyTimeout(5000);
    $db->exec('PRAGMA foreign_keys=ON');
    $db->exec('BEGIN IMMEDIATE');
    try {
        create_event_registration_schema($db);
        $db->exec('COMMIT');
    } catch (Throwable $error) {
        $db->exec('ROLLBACK');
        throw $error;
    } finally { $db->close(); }
}
if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) { migrate_0007(); }
