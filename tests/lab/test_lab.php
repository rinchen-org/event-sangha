<?php
// In-memory SQLite + injected clock/QR/mail. Never reads working data or calls a service.
require_once dirname(__DIR__, 2) . '/src/lib/test_lab.php';

function lab_check(bool $value, string $message): void {
    if (!$value) { throw new RuntimeException($message); }
}
/** @param callable(): mixed $action */
function lab_reject(callable $action): void {
    try { $action(); } catch (Throwable $e) { return; }
    throw new RuntimeException('Expected rejection.');
}
function lab_png(): string {
    $chunk = static function (string $type, string $data): string {
        return pack('N', strlen($data)) . $type . $data . pack('N', crc32($type . $data));
    };
    return "\x89PNG\r\n\x1a\n" . $chunk('IHDR', pack('NNCCCCC', 300, 300, 8, 2, 0, 0, 0))
        . $chunk('IDAT', gzcompress(str_repeat("\x00" . str_repeat("\xff", 900), 300))) . $chunk('IEND', '');
}
class LabClock { public int $now; }
/** @return array<string, string> */
function lab_mail_config(): array {
    return ['SEND_EMAIL' => '1', 'TEST_EMAIL_TO' => 'staff@example.test', 'HOST_ADDRESS' => 'https://example.test/event-retiro',
        'EMAIL_FROM' => 'sender@example.test', 'EMAIL_REPLY_TO' => 'reply@example.test', 'EMAIL_CC' => 'never@example.test'];
}
/** @var array<string, callable(SQLite3, TestLab, LabClock): void> $tests */
$tests = [
    'private database marker refuses to adopt live or unknown schema' => static function ($db, $lab, $clock): void {
        $other = new SQLite3(':memory:'); $other->enableExceptions(true);
        $other->exec('CREATE TABLE person (id INTEGER)');
        lab_reject(static function () use ($other): void { TestLab::initialize($other); });
        lab_check((int) $other->querySingle('SELECT count(*) FROM sqlite_master') === 1, 'Unknown DB changed.');
        $other->close();
        TestLab::initialize($db);
        lab_check((int) $db->querySingle('SELECT count(*) FROM person') === 0, 'Initialize seeded users.');
    },
    'day setup is idempotent, UTC converted, 50 minute sessions and 10 minute breaks' => static function ($db, $lab, $clock): void {
        lab_check($lab->prepareDay('2026-10-24', '09:00', '12:00', 'admin') === 3, 'Wrong session count.');
        $rows = $lab->rows('SELECT * FROM event_session ORDER BY id');
        lab_check($rows[0]['start_date'] === '2026-10-24 13:00:00' && $rows[0]['end_date'] === '2026-10-24 13:50:00', 'Wrong Bolivia/UTC conversion.');
        lab_check($rows[1]['start_date'] === '2026-10-24 14:00:00', 'Wrong break duration.');
        lab_check($lab->prepareDay('2026-10-24', '09:00', '12:00', 'admin') === 0, 'Repeated setup duplicated sessions.');
        lab_check($lab->prepareDay('2026-10-24', '10:00', '14:00', 'admin') === 2, 'Extending a day did not add only missing sessions.');
        lab_check($lab->rows('SELECT * FROM event_session ORDER BY id LIMIT 3') === $rows, 'Changed range rewrote old sessions.');
        $clock->now += 86400;
        $lab->prepareDay('2026-10-25', '09:00', '10:50', 'admin');
        lab_check((int) $db->querySingle('SELECT count(*) FROM event') === 1 && (int) $db->querySingle('SELECT count(*) FROM event_session') === 7, 'Permanent event/history not retained.');
        lab_check((int) $db->querySingle('SELECT count(*) FROM person') === 0, 'Schedule generated users.');
    },
    'invalid hours, stale day and a technical setup failure do not leave an event' => static function ($db, $lab, $clock): void {
        foreach ([['2026-10-23','09:00','12:00'], ['2026-10-24','24:00','25:00'], ['2026-10-24','23:00','01:00'], ['2026-10-24','09:00','09:49']] as $args) {
            lab_reject(static function () use ($lab, $args): void { $lab->prepareDay(...array_merge($args, ['admin'])); });
        }
        $db->exec("CREATE TRIGGER fail_session BEFORE INSERT ON event_session BEGIN SELECT RAISE(ABORT, 'fake failure'); END");
        lab_reject(static function () use ($lab): void { $lab->prepareDay('2026-10-24', '09:00', '12:00', 'admin'); });
        lab_check((int) $db->querySingle('SELECT count(*) FROM event') === 0, 'Failed setup left an event.');
    },
    'UUID attendees have matching aliases, subscriptions and replay-proof batches' => static function ($db, $lab, $clock): void {
        $lab->prepareDay('2026-10-24', '09:00', '12:00', 'admin');
        $batch = str_repeat('a', 48);
        $lab->generate(10, 'staff@example.test', ['staff@example.test'], 'missing', $batch, 'admin');
        lab_reject(static function () use ($lab, $batch): void { $lab->generate(10, 'staff@example.test', ['staff@example.test'], 'missing', $batch, 'admin'); });
        $lab->generate(10, 'staff@example.test', ['staff@example.test'], 'missing', str_repeat('b',48), 'admin');
        lab_check((int) $db->querySingle('SELECT count(DISTINCT uuid) FROM test_lab_attendee') === 20, 'UUID collision/duplicate batch.');
        lab_check((int) $db->querySingle('SELECT count(*) FROM subscription') === 20, 'Missing subscriptions.');
        foreach ($lab->rows('SELECT uuid FROM test_lab_attendee') as $item) {
            $row = $lab->attendee($item['uuid']);
            lab_check(strpos($row['fullname'], $row['uuid']) !== false && $row['email'] === 'staff+' . $row['uuid'] . '@example.test', 'Alias and name do not share UUID.');
            lab_check($row['qr_png'] === null && $row['mail_state'] === 'none', 'Generation has QR/mail side effects.');
        }
        lab_check((int) $db->querySingle('SELECT count(*) FROM attendance') === 0, 'Missing profile fabricated attendance.');
    },
    'profiles exercise actual automatic and warning admission, without bypassing rules' => static function ($db, $lab, $clock): void {
        $lab->prepareDay('2026-10-24', '09:00', '12:00', 'admin');
        foreach (['ready','missing'] as $i => $profile) { $lab->generate(1, 'staff@example.test', ['staff@example.test'], $profile, str_repeat((string) $i,48), 'admin'); }
        $qr = new QrAdmission($db, static function () use ($clock): int { return $clock->now; });
        $ready = $qr->review(1, 2); $missing = $qr->review(2, 2);
        lab_check($ready['automatic_allowed'] && !$missing['automatic_allowed'], 'Profiles do not exercise real policy.');
        lab_check(array_column($missing['warnings'], 'code') === ['missing_previous_sessions'], 'Missing warning lost.');
        $qr->decide(1, 2, 'allow', QrAdmission::fingerprint($ready), 'admin', true);
        lab_reject(static function () use ($qr, $ready): void { $qr->decide(1, 2, 'allow', QrAdmission::fingerprint($ready), 'admin', true); });
        $clock->now = strtotime('2026-10-24 14:55:00 UTC');
        $outside = $qr->review(2, $qr->selection()['recommended_id']);
        lab_check(in_array('outside_session_time', array_column($outside['warnings'], 'code'), true), 'Break does not require staff confirmation.');
        $qr->decide(2, 3, 'allow', QrAdmission::fingerprint($outside), 'admin');
    },
    'generation validates inputs and rolls back on failed audit' => static function ($db, $lab, $clock): void {
        lab_reject(static function () use ($lab): void { $lab->generate(1, 'staff@example.test', ['staff@example.test'], 'ready', str_repeat('a',48), 'admin'); });
        $lab->prepareDay('2026-10-24', '09:00', '12:00', 'admin');
        foreach ([0,-1,101] as $count) { lab_reject(static function () use ($lab, $count): void { $lab->generate($count, 'staff@example.test', ['staff@example.test'], 'ready', str_repeat('a',48), 'admin'); }); }
        lab_reject(static function () use ($lab): void { $lab->generate(1, 'forged@example.test', ['staff@example.test'], 'ready', str_repeat('a',48), 'admin'); });
        $db->exec("CREATE TRIGGER fail_audit BEFORE INSERT ON test_lab_audit BEGIN SELECT RAISE(ABORT, 'fake failure'); END");
        lab_reject(static function () use ($lab): void { $lab->generate(2, 'staff@example.test', ['staff@example.test'], 'ready', str_repeat('a',48), 'admin'); });
        foreach (['person','subscription','attendance','test_lab_batch','test_lab_attendee'] as $table) { lab_check((int) $db->querySingle("SELECT count(*) FROM $table") === 0, 'Generation not atomic: ' . $table); }
    },
    'email configuration validation and plus aliases never accept arbitrary recipients' => static function ($db, $lab, $clock): void {
        lab_check(TestLab::recipients(' staff@example.test,staff@example.test,second@example.test ') === ['staff@example.test','second@example.test'], 'Recipient normalization failed.');
        foreach (['', 'bad', 'staff@example.test,', "staff@example.test\rBcc:injected@example.test"] as $value) { lab_reject(static function () use ($value): void { TestLab::recipients($value); }); }
        lab_reject(static function (): void { TestLab::alias(str_repeat('a', 40) . '@example.test', TestLab::uuid()); });
        foreach (['', 'javascript:bad', 'https://user:pass@example.test', 'https://example.test/?query=1'] as $url) { lab_reject(static function () use ($url): void { TestLab::baseUrl($url); }); }
    },
    'QR generation is explicit, opaque, cached and validated; transport failures keep people' => static function ($db, $lab, $clock): void {
        $lab->prepareDay('2026-10-24','09:00','12:00','admin');
        $lab->generate(1,'staff@example.test',['staff@example.test'],'ready',str_repeat('a',48),'admin');
        $uuid = $lab->rows('SELECT uuid FROM test_lab_attendee')[0]['uuid'];
        lab_reject(static function () use ($lab, $uuid): void { $lab->generateQr($uuid, 'https://example.test', static function (): string { return '<html>not PNG</html>'; }, 'admin'); });
        lab_check($lab->attendee($uuid)['qr_png'] === null, 'Invalid QR saved.');
        $lab->generateQr($uuid, 'https://example.test/event-retiro', static function ($url) use ($uuid): string {
            lab_check($url === 'https://example.test/event-retiro/templates/testing/check-in.php?uuid=' . $uuid, 'Wrong/private-data QR target.');
            return lab_png();
        }, 'admin');
        $lab->generateQr($uuid, 'https://example.test/event-retiro', static function (): string { throw new RuntimeException('Cached QR must not contact service.'); }, 'admin');
        lab_check($lab->attendee($uuid)['qr_png'] === lab_png(), 'PNG not persisted in private DB.');
        lab_check((int) $db->querySingle('SELECT count(*) FROM person') === 1, 'QR failure duplicated/deleted attendee.');
    },
    'mail is opt-in, restricted to configured aliases and never automatically retried' => static function ($db, $lab, $clock): void {
        $lab->prepareDay('2026-10-24','09:00','12:00','admin');
        $batch = str_repeat('a',48);
        $lab->generate(2,'staff@example.test',['staff@example.test'],'missing',$batch,'admin');
        $never = static function (): bool { throw new RuntimeException('Mail must not be invoked.'); };
        lab_reject(static function () use ($lab, $batch, $never): void { $lab->sendBatch($batch, array_replace(lab_mail_config(), ['SEND_EMAIL'=>'0']), $never, 'admin'); });
        lab_reject(static function () use ($lab, $batch, $never): void { $lab->sendBatch($batch, array_replace(lab_mail_config(), ['TEST_EMAIL_TO'=>'changed@example.test']), $never, 'admin'); });
        lab_check($db->querySingle('SELECT mail_state FROM test_lab_attendee LIMIT 1') === 'none', 'Disabled mail claimed an attempt.');
        $calls = 0;
        $result = $lab->sendBatch($batch, lab_mail_config(), static function ($to, $url, $from, $reply, $uuid) use (&$calls): bool {
            $calls++;
            lab_check($to === 'staff+' . $uuid . '@example.test' && $from === 'sender@example.test' && $reply === 'reply@example.test', 'Mail headers/recipient changed.');
            lab_check($url === 'https://example.test/event-retiro/templates/testing/index.php?uuid=' . $uuid, 'Wrong email destination.');
            if ($calls === 2) { throw new RuntimeException('Unknown transport outcome.'); }
            return true;
        }, 'admin');
        lab_check($result === ['accepted'=>1,'unconfirmed'=>1,'skipped'=>0], 'Ambiguous failure reported as delivery.');
        lab_check($lab->sendBatch($batch, lab_mail_config(), $never, 'admin')['skipped'] === 2, 'Mail was retried.');
        lab_check((int) $db->querySingle("SELECT count(*) FROM test_lab_audit WHERE action='email_attempt'") === 2, 'Missing durable attempt audit.');
    },
];
foreach ($tests as $name => $test) {
    $db = new SQLite3(':memory:'); $db->enableExceptions(true); $db->exec('PRAGMA foreign_keys=ON');
    try {
        TestLab::initialize($db);
        $clock = new LabClock(); $clock->now = strtotime('2026-10-24 14:20:00 UTC');
        $lab = new TestLab($db, static function () use ($clock): int { return $clock->now; });
        $test($db, $lab, $clock); echo "PASS: $name\n";
    } finally { $db->close(); }
}
echo count($tests) . " isolated laboratory checks passed.\n";
