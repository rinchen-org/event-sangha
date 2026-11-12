<?php
require_once __DIR__ . '/auth.php';
auth_block_direct(__FILE__);
require_once __DIR__ . '/event_registration.php';
require_once __DIR__ . '/dotenv.php';
require_once __DIR__ . '/qr_assets.php';

/** Explicit event mail only. Review is read-only; claims are durable before any transport call. */
class EventEmail {
    private SQLite3 $db;
    private EventRegistration $registrations;
    public const SUBJECT = 'Centro Sakya Rinchen Ling - Confirmación Inscripción al Retiro';

    public function __construct(SQLite3 $db) {
        $this->db = $db;
        $this->registrations = new EventRegistration($db);
    }

    /** @param mixed $value
     * @return array<int, int> */
    public static function selectedIds($value): array {
        if (!is_string($value) || strlen($value) > 100000 || $value === '') {
            throw new DomainException('Selecciona al menos un participante del evento.');
        }
        $ids = array_values(array_unique(array_map([EventRegistration::class, 'id'], explode(',', $value))));
        sort($ids);
        return $ids;
    }

    public static function template(): string {
        $template = file_get_contents(dirname(__DIR__) . '/templates/subscription-email.html');
        if ($template === false || strpos($template, '<QR>') === false) {
            throw new RuntimeException('No se pudo leer la plantilla de correo.');
        }
        return str_replace('{{HOST_ADDRESS}}', auth_escape(app_base_url()), $template);
    }

    /** @return array{from: string, reply: string, base: string} */
    private function config(): array {
        $config = load_env(false);
        if (($config['SEND_EMAIL'] ?? '1') === '0') {
            throw new DomainException('El envío está deshabilitado (SEND_EMAIL=0). La importación no necesita habilitarlo.');
        }
        $from = trim($config['EMAIL_FROM'] ?? '');
        $reply = trim($config['EMAIL_REPLY_TO'] ?? '') ?: $from;
        foreach ([$from, $reply] as $address) {
            if (preg_match('/[\r\n\x00]/', $address) || !filter_var($address, FILTER_VALIDATE_EMAIL)) {
                throw new DomainException('Configura EMAIL_FROM y EMAIL_REPLY_TO con direcciones válidas, sin nombre de presentación.');
            }
        }
        // A targeted send must not silently copy production EMAIL_CC or other recipients.
        return ['from' => $from, 'reply' => $reply, 'base' => app_base_url()];
    }

    /** No URL is fetched. Existing private QR images are embedded for recipients without a staff login.
     * @return array{data: string, mime: string} */
    private function image(string $url): array {
        return qr_inline_image($url);
    }

    /** @return array<int, array<string, mixed>> */
    public function history(int $eventId): array {
        $exists = $this->registrations->rows("SELECT name FROM sqlite_master WHERE type='table' AND name='event_email_attempt'");
        return $exists ? $this->registrations->rows('SELECT * FROM event_email_attempt WHERE event_id=:event ORDER BY id DESC', ['event' => $eventId]) : [];
    }

    /** @param array<int, int> $ids
     * @return array<string, mixed> */
    public function review(int $eventId, string $mode, array $ids = []): array {
        if (!in_array($mode, ['selected', 'all'], true)) { throw new DomainException('Selecciona destinatarios o todos los participantes del evento.'); }
        $event = $this->registrations->event($eventId);
        $rows = $this->registrations->hasSchema() ? $this->registrations->rows('SELECT s.id, s.person_id, s.qr,
            s.active, p.active AS person_active, p.fullname, p.email FROM event_registration r
            JOIN subscription s ON s.id=r.subscription_id AND s.person_id=r.person_id
            JOIN person p ON p.id=r.person_id WHERE r.event_id=:event ORDER BY s.id', ['event' => $eventId]) : [];
        if ($mode === 'selected') {
            if (!$ids || array_diff($ids, array_column($rows, 'id'))) { throw new DomainException('Los destinatarios deben pertenecer a este evento. Vuelve a seleccionar la lista.'); }
            $rows = array_values(array_filter($rows, static fn(array $row): bool => in_array($row['id'], $ids, true)));
        }
        if (!$rows) { throw new DomainException('No hay participantes seleccionados en este evento.'); }
        $history = $this->history($eventId);
        foreach ($rows as &$row) {
            EventRegistration::id($row['id']);
            EventRegistration::id($row['person_id']);
            if (preg_match('/[\r\n\x00]/', $row['email']) || !filter_var($row['email'], FILTER_VALIDATE_EMAIL)) {
                throw new DomainException('El correo de ' . $row['fullname'] . ' no es válido. Corrige los datos antes de enviar.');
            }
            $row['qr_hash'] = hash('sha256', $this->image($row['qr'])['data']);
            $row['previous'] = array_values(array_filter($history, static fn(array $attempt): bool => $attempt['subscription_id'] === $row['id']));
            $row['warnings'] = [];
            if (qr_base_changed($row['qr'])) {
                $row['warnings'][] = ['code' => 'qr_base_changed', 'message' => QR_BASE_WARNING];
            }
            if ($row['active'] !== 1 || $row['person_active'] !== 1) {
                $row['warnings'][] = ['code' => 'inactive_registration', 'message' => 'La persona o su inscripción general está inactiva. Puedes cancelar o enviar de todos modos; su estado no cambiará.'];
            }
            if ($row['previous']) {
                $row['warnings'][] = ['code' => 'previous_email_attempt', 'message' => 'Ya hay intentos de envío para esta persona en el evento. Revisa el historial y sus resultados antes de enviar otra vez. Puedes cancelar o continuar de todos modos.'];
            }
        }
        unset($row);
        return ['event' => $event, 'mode' => $mode, 'recipients' => $rows, 'config' => $this->config(),
            'template_hash' => hash('sha256', self::template())];
    }

    /** @param array<string, mixed> $review Server-held review only.
     * @return array<int, array<string, mixed>> */
    public function send(array $review, string $batch, string $actor, string $notes = '', ?callable $sender = null): array {
        if (!preg_match('/^[a-f0-9]{48}$/D', $batch) || $actor === '' || strlen($notes) > 2000 || !preg_match('//u', $notes)) {
            throw new DomainException('La confirmación o la nota no es válida.');
        }
        $eventId = (int) $review['event']['id'];
        $this->db->exec('BEGIN IMMEDIATE');
        try {
            $current = $this->review($eventId, $review['mode'], array_column($review['recipients'], 'id'));
            if ($review !== $current) { throw new DomainException('Cambiaron los destinatarios, el evento, la plantilla, la configuración o los intentos previos. Revisa nuevamente antes de enviar.'); }
            $this->db->exec("CREATE TABLE IF NOT EXISTS event_email_attempt (
                id INTEGER PRIMARY KEY AUTOINCREMENT, batch TEXT NOT NULL,
                event_id INTEGER NOT NULL REFERENCES event(id), person_id INTEGER NOT NULL REFERENCES person(id),
                subscription_id INTEGER NOT NULL, fullname TEXT NOT NULL, email TEXT NOT NULL,
                template_hash TEXT NOT NULL, warnings_json TEXT NOT NULL, staff_confirmed INTEGER NOT NULL,
                actor TEXT NOT NULL, notes TEXT NOT NULL, attempted_at TEXT NOT NULL, completed_at TEXT,
                status TEXT NOT NULL CHECK(status IN ('pending','accepted','unconfirmed')),
                UNIQUE(batch, subscription_id),
                FOREIGN KEY (subscription_id, person_id) REFERENCES subscription(id, person_id),
                FOREIGN KEY (event_id, person_id) REFERENCES event_registration(event_id, person_id)
            )");
            foreach ($current['recipients'] as $row) {
                $this->registrations->rows('INSERT INTO event_email_attempt
                    (batch,event_id,person_id,subscription_id,fullname,email,template_hash,warnings_json,staff_confirmed,actor,notes,attempted_at,status)
                    VALUES (:batch,:event,:person,:subscription,:fullname,:email,:template,:warnings,1,:actor,:notes,:time,\'pending\')', [
                    'batch' => $batch, 'event' => $eventId, 'person' => $row['person_id'], 'subscription' => $row['id'],
                    'fullname' => $row['fullname'], 'email' => $row['email'], 'template' => $current['template_hash'],
                    'warnings' => json_encode($row['warnings'], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
                    'actor' => $actor, 'notes' => $notes, 'time' => gmdate('Y-m-d H:i:s'),
                ]);
            }
            $this->db->exec('COMMIT');
        } catch (Throwable $error) { $this->db->exec('ROLLBACK'); throw $error; }

        // No automatic retries, including a crash after a claim or transport acceptance.
        require_once __DIR__ . '/email.php';
        $sender = $sender ?? 'send_email';
        foreach ($current['recipients'] as $row) {
            $status = 'unconfirmed';
            try {
                $image = $this->image($row['qr']);
                if (hash('sha256', $image['data']) !== $row['qr_hash'] || $this->config() !== $current['config']
                    || hash('sha256', self::template()) !== $current['template_hash']) {
                    throw new RuntimeException('Mail configuration/template/QR changed after claim.');
                }
                set_error_handler(static function (int $severity, string $message, string $file, int $line): bool {
                    throw new ErrorException($message, 0, $severity, $file, $line);
                });
                try {
                    $accepted = $sender(dirname(__DIR__) . '/templates/subscription-email.html',
                        ['<QR>' => '<img src="cid:participant-qr" alt="Tu código QR personal" />',
                            '{{HOST_ADDRESS}}' => auth_escape($current['config']['base'])], self::SUBJECT,
                        $current['config']['from'], $row['email'], $current['config']['reply'], '', ['participant-qr' => $image]);
                } finally { restore_error_handler(); }
                if ($accepted) { $status = 'accepted'; }
            } catch (Throwable $error) { error_log('Event email: ' . $error->getMessage()); }
            // If persistence fails, stop: pending claims remain visible and must not be retried blindly.
            $this->registrations->rows('UPDATE event_email_attempt SET status=:status, completed_at=:time
                WHERE batch=:batch AND subscription_id=:subscription AND status=\'pending\'', [
                'status' => $status, 'time' => gmdate('Y-m-d H:i:s'), 'batch' => $batch, 'subscription' => $row['id'],
            ]);
            if ($this->db->changes() !== 1) { throw new RuntimeException('No se pudo registrar el resultado del correo.'); }
        }
        return $this->registrations->rows('SELECT * FROM event_email_attempt WHERE batch=:batch ORDER BY id', ['batch' => $batch]);
    }
}
