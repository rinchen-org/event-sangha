<?php
require_once __DIR__ . '/test_mode.php';
require_once __DIR__ . '/schema.php';
require_once __DIR__ . '/qr_admission.php';
auth_block_direct(__FILE__);

/** Private, fixed storage; never accepts a path or a live DB from an HTTP request. */
function test_lab_db(bool $write = false, bool $create = false): SQLite3 {
    $directory = dirname(__DIR__) . '/.test-lab';
    $path = $directory . '/lab.sqlite';
    if (is_link($directory) || is_link($path)) { throw new RuntimeException('El almacenamiento de pruebas no puede usar enlaces.'); }
    if ($create && !is_dir($directory) && !mkdir($directory, 0700)) {
        throw new RuntimeException('No se pudo crear el almacenamiento privado de pruebas.');
    }
    if (!is_dir($directory) || (!$create && !is_file($path))) {
        throw new DomainException('Primero prepara las sesiones de prueba de hoy.');
    }
    if (file_exists($path) && (stat($path)['nlink'] ?? 0) !== 1) { throw new RuntimeException('Archivo de pruebas no válido.'); }
    $old = umask(0077);
    $db = null;
    try {
        $db = new SQLite3($path, $write ? SQLITE3_OPEN_READWRITE | ($create ? SQLITE3_OPEN_CREATE : 0) : SQLITE3_OPEN_READONLY);
        $db->enableExceptions(true);
        $db->busyTimeout(5000);
        $db->exec('PRAGMA foreign_keys=ON');
        if ($create) {
            if (!chmod($directory, 0700) || !chmod($path, 0600)) { throw new RuntimeException('No se pudieron proteger los archivos de prueba.'); }
            TestLab::initialize($db);
        } else { TestLab::verify($db); }
        return $db;
    } catch (Throwable $e) {
        if ($db !== null) { $db->close(); }
        throw $e;
    } finally { umask($old); }
}

class TestLab {
    private SQLite3 $db;
    /** @var Closure(): int */
    private Closure $clock;
    /** @param (Closure(): int)|null $clock */
    public function __construct(SQLite3 $db, ?Closure $clock = null) {
        self::verify($db);
        $this->db = $db;
        $db->enableExceptions(true);
        $db->busyTimeout(5000);
        $db->exec('PRAGMA foreign_keys=ON');
        $this->clock = $clock ?? static function (): int { return time(); };
    }

    public static function verify(SQLite3 $db): void {
        if (!$db->querySingle("SELECT count(*) FROM sqlite_master WHERE type='table' AND name='test_lab_identity'")
            || $db->querySingle('SELECT marker FROM test_lab_identity') !== 'rinchen-qr-lab-v1') {
            throw new RuntimeException('La base no es un laboratorio de pruebas. No se modificó.');
        }
    }

    /** Explicit setup only, never GET/scan; refuse to adopt any populated unknown database. */
    public static function initialize(SQLite3 $db): void {
        $db->exec('BEGIN IMMEDIATE');
        try {
            if ($db->querySingle("SELECT count(*) FROM sqlite_master WHERE name NOT LIKE 'sqlite_%'")) {
                self::verify($db);
                $db->exec('COMMIT');
                return;
            }
            create_setup_schema($db);
            $db->exec("CREATE TABLE test_lab_identity (marker TEXT NOT NULL);
                INSERT INTO test_lab_identity VALUES ('rinchen-qr-lab-v1');
                CREATE TABLE test_lab_day (day TEXT PRIMARY KEY, start TEXT NOT NULL, end TEXT NOT NULL);
                CREATE TABLE test_lab_batch (id TEXT PRIMARY KEY, created_at TEXT NOT NULL, profile TEXT NOT NULL, recipient TEXT NOT NULL);
                CREATE TABLE test_lab_attendee (uuid TEXT PRIMARY KEY, person_id INTEGER NOT NULL UNIQUE REFERENCES person(id),
                    subscription_id INTEGER NOT NULL UNIQUE REFERENCES subscription(id), batch_id TEXT NOT NULL REFERENCES test_lab_batch(id),
                    qr_png BLOB, qr_target TEXT, mail_state TEXT NOT NULL DEFAULT 'none');
                CREATE TABLE test_lab_audit (id INTEGER PRIMARY KEY, action TEXT NOT NULL, detail_json TEXT NOT NULL,
                    actor TEXT NOT NULL, created_at TEXT NOT NULL)");
            $db->exec('COMMIT');
        } catch (Throwable $e) { $db->exec('ROLLBACK'); throw $e; }
    }

    /** @param array<string, int|string> $params
     * @return array<int, array<string, mixed>> */
    public function rows(string $sql, array $params = []): array {
        $statement = $this->db->prepare($sql);
        foreach ($params as $key => $value) { $statement->bindValue(':' . $key, $value, is_int($value) ? SQLITE3_INTEGER : SQLITE3_TEXT); }
        $result = $statement->execute();
        $rows = [];
        if ($result->numColumns() > 0) { while ($row = $result->fetchArray(SQLITE3_ASSOC)) { $rows[] = $row; } }
        $result->finalize(); $statement->close();
        return $rows;
    }

    /** @param array<string, mixed> $detail */
    private function audit(string $action, array $detail, string $actor): void {
        if ($actor === '') { throw new DomainException('Se requiere una cuenta autenticada.'); }
        $this->rows('INSERT INTO test_lab_audit (action, detail_json, actor, created_at) VALUES (:action,:detail,:actor,:now)',
            ['action' => $action, 'detail' => json_encode($detail, JSON_THROW_ON_ERROR), 'actor' => $actor, 'now' => gmdate('Y-m-d H:i:s', ($this->clock)())]);
    }

    public static function today(?int $now = null): string {
        return (new DateTimeImmutable('@' . ($now ?? time())))->setTimezone(new DateTimeZone('America/La_Paz'))->format('Y-m-d');
    }

    public function prepareDay(string $day, string $start, string $end, string $actor): int {
        if ($day !== self::today(($this->clock)())) { throw new DomainException('La fecha cambió. Recarga la página y prepara el día actual.'); }
        foreach ([$start, $end] as $time) {
            if (!preg_match('/^(?:[01][0-9]|2[0-3]):[0-5][0-9]$/D', $time)) { throw new DomainException('Usa horas válidas HH:MM.'); }
        }
        $zone = new DateTimeZone('America/La_Paz');
        $first = new DateTimeImmutable("$day $start", $zone);
        $last = new DateTimeImmutable("$day $end", $zone);
        if ($last->getTimestamp() - $first->getTimestamp() < 3000) { throw new DomainException('El intervalo debe permitir al menos una sesión de 50 minutos, sin cruzar medianoche.'); }
        $this->db->exec('BEGIN IMMEDIATE');
        try {
            $known = $this->rows('SELECT * FROM test_lab_day WHERE day=:day', ['day' => $day]);
            if ($known && $known[0]['start'] === $start && $known[0]['end'] === $end) {
                $this->db->exec('COMMIT'); return 0;
            }
            $event = $this->rows("SELECT value FROM app_setting WHERE name='qr_event_id'");
            if (!$event) {
                $this->rows('INSERT INTO event (name, description, start_date, end_date) VALUES (:name,:description,:start,:end)',
                    ['name' => 'Laboratorio QR · SOLO PRUEBAS', 'description' => 'Datos sintéticos, aislados de producción.',
                        'start' => gmdate('Y-m-d H:i:s', $first->getTimestamp()), 'end' => gmdate('Y-m-d H:i:s', $last->getTimestamp())]);
                $eventId = $this->db->lastInsertRowID();
                $this->rows("INSERT INTO app_setting VALUES ('qr_event_id', :id)", ['id' => (string) $eventId]);
            } else {
                $eventId = (int) $event[0]['value'];
                if (!$this->rows('SELECT id FROM event WHERE id=:id', ['id' => $eventId])) { throw new RuntimeException('El evento de pruebas ya no existe.'); }
                $this->rows('UPDATE event SET start_date=min(start_date,:start), end_date=max(end_date,:end) WHERE id=:id',
                    ['start' => gmdate('Y-m-d H:i:s', $first->getTimestamp()), 'end' => gmdate('Y-m-d H:i:s', $last->getTimestamp()), 'id' => $eventId]);
            }
            $count = 0;
            for ($cursor = $first; $cursor->modify('+50 minutes') <= $last; $cursor = $cursor->modify('+60 minutes')) {
                $sessionStart = gmdate('Y-m-d H:i:s', $cursor->getTimestamp());
                $sessionEnd = gmdate('Y-m-d H:i:s', $cursor->getTimestamp() + 3000);
                $existing = $this->rows('SELECT end_date FROM event_session WHERE event_id=:event AND start_date=:start',
                    ['event' => $eventId, 'start' => $sessionStart]);
                if ($existing) {
                    if (count($existing) !== 1 || $existing[0]['end_date'] !== $sessionEnd) { throw new RuntimeException('Una sesión de prueba tiene una configuración incompatible. No se reemplazó su historial.'); }
                    continue;
                }
                $this->rows('INSERT INTO event_session (event_id,name,start_date,end_date) VALUES (:event,:name,:start,:end)',
                    ['event' => $eventId, 'name' => 'PRUEBA ' . $day . ' · ' . $cursor->format('H:i'),
                        'start' => $sessionStart, 'end' => $sessionEnd]);
                $count++;
            }
            $this->rows('INSERT INTO test_lab_day VALUES (:day,:start,:end) ON CONFLICT(day) DO UPDATE SET start=excluded.start,end=excluded.end', ['day' => $day, 'start' => $start, 'end' => $end]);
            $this->audit('prepare_day', ['day' => $day, 'start' => $start, 'end' => $end, 'sessions' => $count], $actor);
            $this->db->exec('COMMIT'); return $count;
        } catch (Throwable $e) { $this->db->exec('ROLLBACK'); throw $e; }
    }

    /** @return array<int, string> */
    public static function recipients(string $raw): array {
        if ($raw === '' || preg_match('/[\r\n\x00]/', $raw)) { throw new DomainException('Configura TEST_EMAIL_TO con correos válidos.'); }
        $result = [];
        foreach (explode(',', $raw) as $email) {
            $email = trim($email);
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) { throw new DomainException('TEST_EMAIL_TO contiene un correo no válido.'); }
            $result[strtolower($email)] = $email;
        }
        return array_values($result);
    }

    public static function uuid(): string {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
        $hex = bin2hex($bytes);
        return substr($hex, 0, 8) . '-' . substr($hex, 8, 4) . '-' . substr($hex, 12, 4) . '-' . substr($hex, 16, 4) . '-' . substr($hex, 20);
    }

    public static function alias(string $recipient, string $uuid): string {
        $at = strrpos($recipient, '@');
        if ($at === false) { throw new DomainException('Correo de prueba no válido.'); }
        $local = substr($recipient, 0, $at) . '+' . $uuid;
        $email = $local . substr($recipient, $at);
        if (strlen($local) > 64 || !filter_var($email, FILTER_VALIDATE_EMAIL)) { throw new DomainException('El correo es demasiado largo o no permite formar un alias +UUID válido.'); }
        return $email;
    }

    /** @param array<int, string> $allowed */
    public function generate(int $count, string $recipient, array $allowed, string $profile, string $batch, string $actor): void {
        if ($count < 1 || $count > 100 || !in_array($profile, ['ready', 'missing'], true)
            || !in_array($recipient, $allowed, true) || !preg_match('/^[a-f0-9]{48}$/D', $batch)) { throw new DomainException('Revisa la cantidad (1–100), el correo configurado y el perfil.'); }
        $this->db->exec('BEGIN IMMEDIATE');
        try {
            if ($this->rows('SELECT id FROM test_lab_batch WHERE id=:id', ['id' => $batch])) { throw new DomainException('Este lote ya se generó. No se duplicará.'); }
            if (!$this->rows('SELECT day FROM test_lab_day WHERE day=:day', ['day' => self::today(($this->clock)())])) { throw new DomainException('Prepara primero las sesiones de hoy.'); }
            $selection = (new QrAdmission($this->db, $this->clock))->selection();
            $target = $this->rows('SELECT start_date FROM event_session WHERE id=:id', ['id' => $selection['recommended_id']])[0]['start_date'];
            $previous = $profile === 'ready' ? $this->rows('SELECT id FROM event_session WHERE event_id=:event AND datetime(start_date)<datetime(:start)',
                ['event' => $selection['event_id'], 'start' => $target]) : [];
            $now = gmdate('Y-m-d H:i:s', ($this->clock)());
            $this->rows('INSERT INTO test_lab_batch VALUES (:id,:now,:profile,:recipient)', ['id' => $batch, 'now' => $now, 'profile' => $profile, 'recipient' => $recipient]);
            for ($i = 0; $i < $count; $i++) {
                $uuid = self::uuid();
                $this->rows('INSERT INTO person (fullname,email,phone,active) VALUES (:name,:email,:phone,1)',
                    ['name' => 'Asistente de prueba ' . $uuid, 'email' => self::alias($recipient, $uuid), 'phone' => '000' . sprintf('%012d', random_int(0, 999999999999))]);
                $person = $this->db->lastInsertRowID();
                $this->rows('INSERT INTO subscription (person_id,datetime,qr,active) VALUES (:person,:now,:qr,1)',
                    ['person' => $person, 'now' => $now, 'qr' => auth_url('templates/testing/qr.php?uuid=' . $uuid)]);
                $subscription = $this->db->lastInsertRowID();
                $this->rows('INSERT INTO test_lab_attendee (uuid,person_id,subscription_id,batch_id) VALUES (:uuid,:person,:subscription,:batch)',
                    ['uuid' => $uuid, 'person' => $person, 'subscription' => $subscription, 'batch' => $batch]);
                foreach ($previous as $session) {
                    $this->rows('INSERT INTO attendance (person_id,event_session_id,log_time) VALUES (:person,:session,:now)',
                        ['person' => $person, 'session' => (int) $session['id'], 'now' => $now]);
                }
            }
            $this->audit('generate_batch', ['batch' => $batch, 'count' => $count, 'profile' => $profile,
                'seeded_previous_session_ids' => array_column($previous, 'id')], $actor);
            $this->db->exec('COMMIT');
        } catch (Throwable $e) { $this->db->exec('ROLLBACK'); throw $e; }
    }

    /** @return array<string, mixed> */
    public function attendee(string $uuid): array {
        if (!preg_match('/^[a-f0-9]{8}-[a-f0-9]{4}-4[a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}$/D', $uuid)) { throw new DomainException('Identificador de prueba no válido.'); }
        $rows = $this->rows('SELECT a.*,p.fullname,p.email,p.phone,b.recipient FROM test_lab_attendee a JOIN person p ON p.id=a.person_id
            JOIN test_lab_batch b ON b.id=a.batch_id WHERE a.uuid=:uuid', ['uuid' => $uuid]);
        if (!$rows) { throw new DomainException('No se encontró este participante de prueba.'); }
        return $rows[0];
    }

    public static function baseUrl(string $base): string {
        $parts = parse_url($base);
        if (!filter_var($base, FILTER_VALIDATE_URL) || !$parts || !in_array($parts['scheme'] ?? '', ['http', 'https'], true)
            || isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])
            || preg_match('/[\s\x00-\x1f]/', $base)) { throw new DomainException('Configura HOST_ADDRESS con la URL completa de esta instalación, sin consulta ni credenciales.'); }
        return rtrim($base, '/');
    }

    public static function checkinUrl(string $base, string $uuid): string {
        return self::baseUrl($base) . '/templates/testing/check-in.php?uuid=' . rawurlencode($uuid);
    }

    /** @param callable(string): string $fetch */
    public function generateQr(string $uuid, string $base, callable $fetch, string $actor): void {
        $row = $this->attendee($uuid);
        $url = self::checkinUrl($base, $uuid);
        if ($row['qr_png'] !== null && $row['qr_target'] === $url) { return; }
        $image = $fetch($url);
        $info = @getimagesizefromstring($image);
        if (strlen($image) > 262144 || !$info || $info[2] !== IMAGETYPE_PNG || $info[0] !== 300 || $info[1] !== 300) {
            throw new RuntimeException('El servicio no devolvió una imagen PNG válida de 300×300. No se guardó el QR.');
        }
        $this->db->exec('BEGIN IMMEDIATE');
        try {
            $statement = $this->db->prepare('UPDATE test_lab_attendee SET qr_png=:image,qr_target=:target WHERE uuid=:uuid');
            $statement->bindValue(':image', $image, SQLITE3_BLOB);
            $statement->bindValue(':target', $url, SQLITE3_TEXT);
            $statement->bindValue(':uuid', $uuid, SQLITE3_TEXT);
            $statement->execute()->finalize(); $statement->close();
            $this->audit('generate_qr', ['uuid' => $uuid], $actor);
            $this->db->exec('COMMIT');
        } catch (Throwable $e) { $this->db->exec('ROLLBACK'); throw $e; }
    }

    /** @param array<string, string> $config
     * @param callable(string, string, string, string, string): bool $send
     * @return array{accepted: int, unconfirmed: int, skipped: int} */
    public function sendBatch(string $batch, array $config, callable $send, string $actor): array {
        if (($config['SEND_EMAIL'] ?? '1') === '0') { throw new DomainException('SEND_EMAIL=0: el envío está desactivado. No se intentó enviar.'); }
        $allowed = self::recipients($config['TEST_EMAIL_TO'] ?? '');
        $base = self::baseUrl($config['HOST_ADDRESS'] ?? '');
        $from = trim($config['EMAIL_FROM'] ?? '');
        $reply = trim($config['EMAIL_REPLY_TO'] ?? '') ?: $from;
        foreach ([$from, $reply] as $email) {
            if (preg_match('/[\r\n\x00]/', $email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) { throw new DomainException('Revisa EMAIL_FROM y EMAIL_REPLY_TO antes de enviar.'); }
        }
        $rows = $this->rows('SELECT a.uuid,a.mail_state,p.email,b.recipient FROM test_lab_attendee a JOIN person p ON p.id=a.person_id
            JOIN test_lab_batch b ON b.id=a.batch_id WHERE b.id=:batch', ['batch' => $batch]);
        if (!$rows) { throw new DomainException('Lote no válido.'); }
        foreach ($rows as $row) {
            if (!in_array($row['recipient'], $allowed, true) || $row['email'] !== self::alias($row['recipient'], $row['uuid'])) {
                throw new DomainException('Los correos de este lote ya no coinciden con TEST_EMAIL_TO. No se envió nada.');
            }
        }
        $result = ['accepted' => 0, 'unconfirmed' => 0, 'skipped' => 0];
        foreach ($rows as $row) {
            $this->db->exec('BEGIN IMMEDIATE');
            try {
                $this->rows("UPDATE test_lab_attendee SET mail_state='attempted' WHERE uuid=:uuid AND mail_state='none'", ['uuid' => $row['uuid']]);
                $claimed = $this->db->changes() === 1;
                if ($claimed) { $this->audit('email_attempt', ['uuid' => $row['uuid']], $actor); }
                $this->db->exec('COMMIT');
            } catch (Throwable $e) { $this->db->exec('ROLLBACK'); throw $e; }
            if (!$claimed) { $result['skipped']++; continue; }
            // Claim durably BEFORE mail: a timeout/crash must never cause an automatic resend.
            try {
                $accepted = $send($row['email'], $base . '/templates/testing/index.php?uuid=' . rawurlencode($row['uuid']), $from, $reply, $row['uuid']);
            } catch (Throwable $e) { $accepted = false; error_log('Test lab email transport failed.'); }
            $this->rows('UPDATE test_lab_attendee SET mail_state=:state WHERE uuid=:uuid',
                ['state' => $accepted ? 'accepted' : 'unconfirmed', 'uuid' => $row['uuid']]);
            $this->audit('email_result', ['uuid' => $row['uuid'], 'accepted' => $accepted], $actor);
            $result[$accepted ? 'accepted' : 'unconfirmed']++;
        }
        return $result;
    }
}
