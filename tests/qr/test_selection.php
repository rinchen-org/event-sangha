<?php
// Deterministic QR policy/transaction checks: in-memory SQLite, no HTTP or app data.
require_once dirname(__DIR__, 2) . '/src/lib/qr_admission.php';
require_once dirname(__DIR__, 2) . '/src/lib/schema.php';

class QrTestClock { public int $now; }

function qr_check(bool $condition, string $message): void {
    if (!$condition) { throw new RuntimeException($message); }
}

/** @param callable(): mixed $action
 * @param class-string<Throwable> $exception
 */
function qr_reject(callable $action, string $message, string $exception = DomainException::class): void {
    try { $action(); } catch (Throwable $error) {
        if (!$error instanceof $exception) { throw $error; }
        return;
    }
    throw new RuntimeException($message);
}

/** @return array<string, mixed> */
function qr_audit(SQLite3 $db): array {
    return json_decode($db->querySingle('SELECT warnings_json FROM qr_admission_decision ORDER BY id DESC LIMIT 1'), true, 512, JSON_THROW_ON_ERROR);
}

/** @var array<string, callable(SQLite3, QrAdmission, QrTestClock): void> $tests */
$tests = [
    'clear active session records automatic admission once with truthful audit' => static function (SQLite3 $db, QrAdmission $qr, QrTestClock $clock): void {
        $review = $qr->review(1, $qr->selection()['recommended_id']);
        qr_check($review['automatic_allowed'] && !$review['warnings'], 'Clear session must be eligible.');
        $hash = QrAdmission::fingerprint($review);
        $qr->decide(1, 1, 'allow', $hash, 'admin', true);
        $audit = qr_audit($db);
        qr_check($audit['mode'] === 'automatic' && $audit['staff_confirmed'] === false, 'Automatic write falsely claims human confirmation.');
        qr_check($audit['version'] === 2 && $audit['warnings'] === [], 'Wrong audit format.');
        qr_check($db->querySingle('SELECT log_time FROM attendance') === '2026-11-12 13:30:00', 'Audit/attendance time must be UTC.');
        qr_reject(static function () use ($qr, $hash): void { $qr->decide(1, 1, 'allow', $hash, 'admin', true); }, 'Duplicate automatic attendance.');
        qr_check((int) $db->querySingle('SELECT count(*) FROM attendance') === 1, 'Duplicate attendance created.');
        qr_check((int) $db->querySingle('SELECT count(*) FROM qr_admission_decision') === 1, 'Duplicate audit created.');
        qr_check(!$qr->review(1, 1)['automatic_allowed'], 'Rescan must not auto-submit.');
    },
    'suggestions cover before/during/between/after periods and boundary ties' => static function (SQLite3 $db, QrAdmission $qr, QrTestClock $clock): void {
        foreach ([['2026-11-12 12:00:00', 1], ['2026-11-12 13:00:00', 1],
            ['2026-11-12 16:00:00', 1], ['2026-11-12 16:30:00', 1],
            ['2026-11-12 17:00:00', 2], ['2026-11-12 17:30:00', 2],
            ['2026-11-12 18:00:00', 2], ['2026-11-13 23:00:00', 3]] as [$time, $id]) {
            $clock->now = (new DateTimeImmutable($time, new DateTimeZone('UTC')))->getTimestamp();
            qr_check($qr->selection()['recommended_id'] === $id, 'Wrong nearest interval at ' . $time);
        }
        qr_check(count($qr->selection()['sessions']) === 3, 'Other-event sessions leaked into choices.');
        qr_check((int) $db->querySingle('SELECT count(*) FROM attendance') === 0, 'Suggestion wrote attendance.');
    },
    'outside times only warn and can be explicitly allowed' => static function (SQLite3 $db, QrAdmission $qr, QrTestClock $clock): void {
        $clock->now = strtotime('2026-11-12 12:00:00 UTC');
        $review = $qr->review(1, 1);
        qr_check(!$review['automatic_allowed'], 'Outside interval cannot auto-admit.');
        qr_check(array_column($review['warnings'], 'code') === ['outside_session_time'], 'Missing time advisory.');
        $hash = QrAdmission::fingerprint($review);
        qr_reject(static function () use ($qr, $hash): void { $qr->decide(1, 1, 'allow', $hash, 'admin', true); }, 'Forged auto outside interval succeeded.');
        $qr->decide(1, 1, 'allow', $hash, 'admin', false, ' Entrada anticipada ');
        $audit = qr_audit($db);
        qr_check($audit['staff_confirmed'] && $audit['notes'] === 'Entrada anticipada', 'Staff confirmation/notes missing.');
        qr_check($audit['warnings'][0]['code'] === 'outside_session_time', 'Time warning not audited.');
    },
    'missing previous sessions require review but do not block confirmed admission' => static function (SQLite3 $db, QrAdmission $qr, QrTestClock $clock): void {
        $clock->now = strtotime('2026-11-12 19:00:00 UTC');
        $review = $qr->review(1, 2);
        qr_check(array_column($review['missing'], 'id') === [1], 'Missing session 1 was exempted.');
        $hash = QrAdmission::fingerprint($review);
        qr_reject(static function () use ($qr, $hash): void { $qr->decide(1, 2, 'allow', $hash, 'admin', true); }, 'Automatic bypass of missing attendance.');
        $qr->decide(1, 2, 'allow', $hash, 'admin');
        qr_check(qr_audit($db)['missing_sessions'][0]['id'] === 1, 'Missing-session snapshot lost.');
    },
    'a prior denial is never silently overridden' => static function (SQLite3 $db, QrAdmission $qr, QrTestClock $clock): void {
        $qr->decide(1, 1, 'deny', QrAdmission::fingerprint($qr->review(1, 1)), 'admin');
        $review = $qr->review(1, 1);
        qr_check(!$review['automatic_allowed'], 'Denied person is automatically eligible.');
        $hash = QrAdmission::fingerprint($review);
        qr_reject(static function () use ($qr, $hash): void { $qr->decide(1, 1, 'allow', $hash, 'admin', true); }, 'Automatic override of denial.');
        $qr->decide(1, 1, 'allow', $hash, 'admin');
        qr_check(qr_audit($db)['warnings'][0]['code'] === 'existing_denial', 'Denial override not audited.');
        qr_check((int) $db->querySingle('SELECT count(*) FROM attendance') === 1, 'Explicit override did not record attendance.');
    },
    'overlapping periods require review even without missing attendance' => static function (SQLite3 $db, QrAdmission $qr, QrTestClock $clock): void {
        $db->exec("INSERT INTO event_session VALUES (5, 1, 'Solapada', '2026-11-12 13:00:00', '2026-11-12 15:00:00')");
        $review = $qr->review(1, 1);
        qr_check(!$review['missing'] && !$review['automatic_allowed'], 'Overlap was automatically admitted.');
        qr_check($review['warnings'][0]['code'] === 'overlapping_sessions', 'Missing overlap warning.');
        $qr->decide(1, 1, 'allow', QrAdmission::fingerprint($review), 'admin');
    },
    'clock progression within an interval does not cause repeated confirmation' => static function (SQLite3 $db, QrAdmission $qr, QrTestClock $clock): void {
        $hash = QrAdmission::fingerprint($qr->review(1, 1));
        $clock->now += 10;
        qr_check($hash === QrAdmission::fingerprint($qr->review(1, 1)), 'Every second invalidates review.');
        $qr->decide(1, 1, 'allow', $hash, 'admin', true);
    },
    'crossing interval boundaries requires a refreshed manual review' => static function (SQLite3 $db, QrAdmission $qr, QrTestClock $clock): void {
        $hash = QrAdmission::fingerprint($qr->review(1, 1));
        $clock->now = strtotime('2026-11-12 16:00:01 UTC');
        qr_reject(static function () use ($qr, $hash): void { $qr->decide(1, 1, 'allow', $hash, 'admin', true); }, 'Late automatic admission succeeded.');
        $qr->decide(1, 1, 'allow', QrAdmission::fingerprint($qr->review(1, 1)), 'admin');
    },
    'staff can select a valid non-recommended period but not another event' => static function (SQLite3 $db, QrAdmission $qr, QrTestClock $clock): void {
        qr_reject(static function () use ($qr): void { $qr->review(1, 4); }, 'Other event accepted.');
        qr_reject(static function () use ($qr): void { $qr->review(1, 999); }, 'Missing session accepted.');
        qr_reject(static function () use ($qr): void { $qr->review(999, 1); }, 'Missing person accepted.');
        $qr->decide(1, 3, 'allow', QrAdmission::fingerprint($qr->review(1, 3)), 'admin');
        qr_check((int) $db->querySingle('SELECT event_session_id FROM attendance') === 3, 'Selected session replaced with suggested session.');
    },
    'configured event changes invalidate old reviews' => static function (SQLite3 $db, QrAdmission $qr, QrTestClock $clock): void {
        $hash = QrAdmission::fingerprint($qr->review(1, 1));
        $db->exec("INSERT INTO app_setting VALUES ('qr_event_id', '2')");
        qr_reject(static function () use ($qr, $hash): void { $qr->decide(1, 1, 'allow', $hash, 'admin', true); }, 'Stale event accepted.');
        qr_check($qr->selection()['recommended_id'] === 4, 'Configured event ignored.');
    },
    'concurrent decisions and changed warnings invalidate a review' => static function (SQLite3 $db, QrAdmission $qr, QrTestClock $clock): void {
        $hash = QrAdmission::fingerprint($qr->review(1, 1));
        $qr->decide(1, 1, 'deny', $hash, 'admin');
        qr_reject(static function () use ($qr, $hash): void { $qr->decide(1, 1, 'allow', $hash, 'admin', true); }, 'Concurrent denial lost.');
        qr_check((int) $db->querySingle('SELECT count(*) FROM attendance') === 0, 'Concurrency failure wrote attendance.');
    },
    'audit failures roll back automatic attendance' => static function (SQLite3 $db, QrAdmission $qr, QrTestClock $clock): void {
        $db->exec("CREATE TRIGGER fail_audit BEFORE INSERT ON qr_admission_decision BEGIN SELECT RAISE(ABORT, 'fake failure'); END");
        $hash = QrAdmission::fingerprint($qr->review(1, 1));
        qr_reject(static function () use ($qr, $hash): void { $qr->decide(1, 1, 'allow', $hash, 'admin', true); }, 'Audit failure ignored.', Throwable::class);
        qr_check((int) $db->querySingle('SELECT count(*) FROM attendance') === 0, 'Failed transaction left attendance.');
    },
    'invalid schedule data and missing migrations fail closed without creating schema' => static function (SQLite3 $db, QrAdmission $qr, QrTestClock $clock): void {
        $db->exec('DROP TABLE qr_admission_decision');
        qr_reject(static function () use ($qr): void { $qr->review(1, 1); }, 'Missing audit table accepted.', Throwable::class);
        qr_check((int) $db->querySingle("SELECT count(*) FROM sqlite_master WHERE name='qr_admission_decision'") === 0, 'Scan migrated the database.');
        $db->exec("UPDATE event_session SET end_date='not-a-date' WHERE id=2");
        qr_reject(static function () use ($qr): void { $qr->selection(); }, 'Invalid schedule was treated as an overridable warning.');
    },
    'legacy audit snapshots remain intact and manual fallback is correctly attributed' => static function (SQLite3 $db, QrAdmission $qr, QrTestClock $clock): void {
        $db->exec("INSERT INTO qr_admission_decision VALUES (1, 1, 1, 'deny', '[]', 'admin', '2026-11-12 13:00:00')");
        $qr->decide(1, 1, 'allow', QrAdmission::fingerprint($qr->review(1, 1)), 'admin');
        qr_check($db->querySingle('SELECT warnings_json FROM qr_admission_decision WHERE id=1') === '[]', 'Historical audit was rewritten.');
        qr_check(qr_audit($db)['mode'] === 'staff' && qr_audit($db)['staff_confirmed'], 'Human action attributed to automation.');
    },
];

foreach ($tests as $name => $test) {
    $db = new SQLite3(':memory:');
    $db->enableExceptions(true);
    try {
        create_setup_schema($db);
        $db->exec("INSERT INTO person VALUES (1, 'Synthetic', 'test@example.test', '123', 1);
            INSERT INTO event VALUES (1, 'Retiro', '', '2026-11-12', '2026-11-13'), (2, 'Otro', '', '2026-11-12', '2026-11-13');
            INSERT INTO event_session VALUES
                (1, 1, 'Mañana', '2026-11-12 13:00:00', '2026-11-12 16:00:00'),
                (2, 1, 'Tarde', '2026-11-12 18:00:00', '2026-11-12 22:00:00'),
                (3, 1, 'Día siguiente', '2026-11-13 18:00:00', '2026-11-13 22:00:00'),
                (4, 2, 'Otro evento', '2026-11-12 13:00:00', '2026-11-12 16:00:00')");
        $clock = new QrTestClock();
        $clock->now = strtotime('2026-11-12 13:30:00 UTC');
        $qr = new QrAdmission($db, static function () use ($clock): int { return $clock->now; });
        $test($db, $qr, $clock);
        echo "PASS: $name\n";
    } finally { $db->close(); }
}
echo count($tests) . " isolated QR checks passed.\n";
