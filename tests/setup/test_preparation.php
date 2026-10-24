<?php
// Disposable SQLite coverage without HTTP sockets, Composer, mail, or QR services.
require_once dirname(__DIR__, 2) . '/src/lib/event_setup.php';

function preparation_check(bool $condition, string $message): void {
    if (!$condition) { throw new RuntimeException($message); }
}

/** @param callable(): mixed $operation */
function preparation_fails(callable $operation, string $message): void {
    try { $operation(); } catch (Throwable $error) { return; }
    throw new RuntimeException($message);
}

/** @return array{changed: bool, event_id: int} */
function preparation_apply(SQLite3 $db, string $selection = ''): array {
    $state = EventSetup::preparationStatus($db, $selection);
    return EventSetup::prepare2026($db, $selection, $state['fingerprint'], static function (): void {});
}

/** @var array<string, callable(SQLite3, string): void> $tests */
$tests = [
    'fresh setup, UTC schedule, repeat no-op and no side-effect data' => static function (SQLite3 $db, string $path): void {
        $initial = EventSetup::preparationStatus($db);
        preparation_check(count($initial['schema']) === 10, 'Missing initial schema steps.');
        preparation_check((int) $db->querySingle('SELECT count(*) FROM sqlite_master') === 0, 'Review initialized the real schema.');
        preparation_check(preparation_apply($db)['changed'], 'Initial preparation did not run.');
        preparation_check((int) $db->querySingle('SELECT count(*) FROM event_session') === 6, 'Expected six sessions.');
        preparation_check($db->querySingle('SELECT start_date FROM event_session WHERE id=1') === '2026-11-12 13:00:00', 'Wrong Bolivia conversion.');
        foreach (['person', 'subscription', 'attendance', 'qr_admission_decision', 'app_setting'] as $table) {
            preparation_check((int) $db->querySingle("SELECT count(*) FROM $table") === 0, 'Unexpected data in ' . $table);
        }
        $before = file_get_contents($path);
        $result = EventSetup::prepare2026($db, '', $initial['fingerprint'], static function (): void { throw new RuntimeException('No-op must not back up.'); });
        preparation_check(!$result['changed'] && file_get_contents($path) === $before, 'Repeat changed the database.');
    },
    'existing edits, history, extra sessions and QR are untouched' => static function (SQLite3 $db, string $path): void {
        EventSetup::save($db, array_replace(EventSetup::defaults(), ['name' => 'Edited', 'morning_start' => '08:00']));
        $db->exec("INSERT INTO person VALUES (1, 'Synthetic', 'test@example.test', '123', 1);
            INSERT INTO attendance VALUES (1, 1, 1, '2026-11-12 12:05:00');
            INSERT INTO qr_admission_decision VALUES (1, 1, 1, 'allow', '[]', 'admin', '2026-11-12 12:05:00');
            INSERT INTO event_session VALUES (30, 1, 'Extra', '2026-11-13 13:00:00', '2026-11-13 16:00:00');
            UPDATE app_setting SET value='20' WHERE name='qr_event_id';");
        $before = file_get_contents($path);
        preparation_check(!preparation_apply($db)['changed'], 'Already prepared event changed.');
        preparation_check(file_get_contents($path) === $before, 'Edited/history data changed.');
    },
    'only missing schema executes and private backup includes WAL data' => static function (SQLite3 $db, string $path): void {
        $db->exec('PRAGMA journal_mode=WAL');
        preparation_apply($db);
        $db->exec("UPDATE event SET name='Edited'; DROP TABLE qr_admission_decision;
            ALTER TABLE subscription DROP COLUMN active;
            CREATE TRIGGER no_updates BEFORE UPDATE ON event BEGIN SELECT RAISE(ABORT, 'must skip'); END;");
        $state = EventSetup::preparationStatus($db);
        preparation_check($state['event_ready'] && count($state['schema']) === 3, 'Expected missing table, index and column.');
        EventSetup::prepare2026($db, '', $state['fingerprint'], static function () use ($path): void {
            $reader = new SQLite3($path, SQLITE3_OPEN_READONLY);
            try { EventSetup::backup($reader, dirname($path) . '/backups'); } finally { $reader->close(); }
        });
        $files = glob(dirname($path) . '/backups/*.sqlite');
        preparation_check($files !== false && count($files) === 1, 'Missing snapshot.');
        $backup = new SQLite3($files[0], SQLITE3_OPEN_READONLY);
        preparation_check($backup->querySingle('SELECT name FROM event') === 'Edited', 'WAL data missing from backup.');
        preparation_check((int) $backup->querySingle("SELECT count(*) FROM sqlite_master WHERE name='qr_admission_decision'") === 0, 'Backup was taken after migration.');
        preparation_check((fileperms($files[0]) & 0777) === 0600, 'Snapshot is not private.');
        $backup->close();
        preparation_check(EventSetup::preparationStatus($db)['ready'], 'Schema repair not complete.');
    },
    'partial sessions are adopted or inserted without overwriting others' => static function (SQLite3 $db, string $path): void {
        preparation_apply($db);
        $db->exec("DELETE FROM setup_session WHERE slot IN ('11-12-am','11-15-pm');
            DELETE FROM event_session WHERE id=6; UPDATE event_session SET name='Edited' WHERE id=1;
            CREATE TRIGGER no_updates BEFORE UPDATE ON event_session BEGIN SELECT RAISE(ABORT, 'must skip'); END;");
        preparation_apply($db);
        preparation_check($db->querySingle('SELECT name FROM event_session WHERE id=1') === 'Edited', 'Adoption overwrote session.');
        preparation_check((int) $db->querySingle('SELECT count(*) FROM event_session') === 6, 'Wrong session count.');
        preparation_check(EventSetup::preparationStatus($db)['ready'], 'Partial preparation not completed.');
    },
    'unlinked events require selection and preserve an adopted event' => static function (SQLite3 $db, string $path): void {
        EventSetup::save($db, array_replace(EventSetup::defaults(), ['name' => 'Manual']));
        $db->exec('DELETE FROM setup_event; DELETE FROM setup_session');
        preparation_check(EventSetup::preparationStatus($db)['choose'], 'Existing event was silently selected.');
        preparation_fails(static function () use ($db): void { preparation_apply($db); }, 'Unselected event was prepared.');
        preparation_apply($db, '1');
        preparation_check($db->querySingle('SELECT name FROM event') === 'Manual', 'Adoption overwrote event name.');
        preparation_check((int) $db->querySingle('SELECT count(*) FROM event') === 1, 'Duplicate event.');
    },
    'stale review and technical failure roll back all new schema/data' => static function (SQLite3 $db, string $path): void {
        preparation_apply($db);
        $db->exec("DELETE FROM setup_session WHERE slot='11-15-pm'; DELETE FROM event_session WHERE id=6; DROP TABLE qr_admission_decision;");
        $old = EventSetup::preparationStatus($db);
        $db->exec("UPDATE event SET name='Changed after review'");
        preparation_fails(static function () use ($db, $old): void {
            EventSetup::prepare2026($db, '', $old['fingerprint'], static function (): void {});
        }, 'Stale review accepted.');
        $db->exec("CREATE TRIGGER fail_insert BEFORE INSERT ON event_session BEGIN SELECT RAISE(ABORT, 'test failure'); END");
        preparation_fails(static function () use ($db): void { preparation_apply($db); }, 'Write failure accepted.');
        preparation_check((int) $db->querySingle("SELECT count(*) FROM sqlite_master WHERE name='qr_admission_decision'") === 0, 'Schema was not rolled back.');
        preparation_check((int) $db->querySingle('SELECT count(*) FROM event_session') === 5, 'Unexpected session after failure.');
    },
    'incompatible schema and broken mappings fail closed' => static function (SQLite3 $db, string $path): void {
        $db->exec('CREATE TABLE attendance (id INTEGER PRIMARY KEY, person_id INTEGER, datetime TEXT)');
        preparation_fails(static function () use ($db): void { EventSetup::preparationStatus($db); }, 'Old attendance schema was accepted.');
        $db->exec('DROP TABLE attendance');
        preparation_apply($db);
        $db->exec("UPDATE setup_session SET session_id=999 WHERE slot='11-12-am'");
        preparation_fails(static function () use ($db): void { EventSetup::preparationStatus($db); }, 'Broken mapping was accepted.');
    },
    'unmapped edited sessions and another year cannot be guessed' => static function (SQLite3 $db, string $path): void {
        EventSetup::save($db, array_replace(EventSetup::defaults(), ['morning_start' => '08:00']));
        $db->exec('DELETE FROM setup_session WHERE session_id=1');
        preparation_fails(static function () use ($db): void { preparation_apply($db); }, 'Edited unmapped session was duplicated.');
        $db->exec("INSERT INTO setup_session VALUES (1, '11-12-am', 1); UPDATE setup_event SET setup_key='november-2027'");
        preparation_fails(static function () use ($db): void { preparation_apply($db, '1'); }, 'Another year was reused.');
        preparation_apply($db, 'new');
        preparation_check((int) $db->querySingle('SELECT count(*) FROM event') === 2, 'Explicit new event not created.');
    },
    'backup failure prevents every pending write' => static function (SQLite3 $db, string $path): void {
        preparation_apply($db);
        $db->exec('DROP INDEX qr_admission_lookup');
        $state = EventSetup::preparationStatus($db);
        $before = file_get_contents($path);
        preparation_fails(static function () use ($db, $state): void {
            EventSetup::prepare2026($db, '', $state['fingerprint'], static function (): void { throw new RuntimeException('Backup unavailable.'); });
        }, 'Backup failure was ignored.');
        preparation_check(file_get_contents($path) === $before, 'Database changed after backup failure.');
    },
    'ambiguous matches and falsely completed schema are rejected' => static function (SQLite3 $db, string $path): void {
        preparation_apply($db);
        $db->exec("DELETE FROM setup_session WHERE slot='11-12-am';
            INSERT INTO event_session (event_id, name, start_date, end_date) SELECT event_id, name, start_date, end_date FROM event_session WHERE id=1");
        preparation_fails(static function () use ($db): void { EventSetup::preparationStatus($db); }, 'Ambiguous session was adopted.');
        $db->exec("DELETE FROM event_session WHERE id=7; DROP TABLE qr_admission_decision;
            CREATE TABLE qr_admission_decision (id INTEGER PRIMARY KEY)");
        preparation_fails(static function () use ($db): void { EventSetup::preparationStatus($db); }, 'Table name alone counted as completed schema.');
    },
];

foreach ($tests as $name => $test) {
    $directory = sys_get_temp_dir() . '/rinchen-preparation-' . bin2hex(random_bytes(8));
    mkdir($directory, 0700);
    $path = $directory . '/test.sqlite';
    $db = new SQLite3($path);
    $db->enableExceptions(true);
    try {
        $test($db, $path);
        echo "PASS: $name\n";
    } finally {
        $db->close();
        foreach (glob($directory . '/backups/*') ?: [] as $file) { unlink($file); }
        if (is_dir($directory . '/backups')) { rmdir($directory . '/backups'); }
        foreach (glob($directory . '/*') ?: [] as $file) { unlink($file); }
        rmdir($directory);
    }
}
echo count($tests) . " isolated SQLite checks passed.\n";
