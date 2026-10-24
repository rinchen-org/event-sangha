<?php
require_once __DIR__ . '/auth.php';
auth_block_direct(__FILE__);

/**
 * Staff-operated QR admission. No mail, QR generation, or legacy force-all bypass.
 * All writes and the warning snapshot are checked under one SQLite write lock.
 */
class QrAdmission
{
    private SQLite3 $db;
    /** @var Closure(): int */
    private Closure $clock;

    /** @param (Closure(): int)|null $clock */
    public function __construct(SQLite3 $db, ?Closure $clock = null) {
        $this->db = $db;
        $this->clock = $clock ?? static function (): int { return time(); };
        $db->enableExceptions(true);
        $db->busyTimeout(5000);
    }

    /** @param array<string, int|string> $params
     * @return array<int, array<string, mixed>>
     */
    private function rows(string $sql, array $params = []): array {
        $statement = $this->db->prepare($sql);
        foreach ($params as $key => $value) {
            $statement->bindValue(':' . $key, $value, is_int($value) ? SQLITE3_INTEGER : SQLITE3_TEXT);
        }
        $result = $statement->execute();
        $rows = [];
        // Fetch only SELECT results: fetching a result-less SQLite3 statement
        // can execute the statement again and duplicate INSERTs.
        if ($result->numColumns() > 0) {
            while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
                $rows[] = $row;
            }
        }
        $result->finalize();
        $statement->close();
        return $rows;
    }

    public function findPerson(string $fullname, string $email, string $phone): int {
        $people = $this->rows(
            'SELECT id FROM person WHERE fullname = :name AND email = :email AND phone = :phone',
            ['name' => $fullname, 'email' => $email, 'phone' => $phone]
        );
        if (count($people) !== 1) {
            throw new DomainException('No se pudo identificar a una única persona con este QR.');
        }
        return (int) $people[0]['id'];
    }

    public function eventId(): int {
        // Older deployments keep event 1 until staff select an event in web setup.
        $eventId = 1;
        if ($this->rows("SELECT name FROM sqlite_master WHERE type='table' AND name='app_setting'")) {
            $setting = $this->rows("SELECT value FROM app_setting WHERE name='qr_event_id'");
            if ($setting) {
                if (!preg_match('/^[0-9]+$/D', $setting[0]['value']) || (int) $setting[0]['value'] < 1) {
                    throw new DomainException('La selección del evento QR no es válida.');
                }
                $eventId = (int) $setting[0]['value'];
            }
        }
        return $eventId;
    }

    /**
     * Times suggest a session; they never make an otherwise valid session
     * unavailable. Equal distances prefer the upcoming interval, then start/id.
     * @return array<string, mixed>
     */
    public function selection(?int $now = null): array {
        $now = $now ?? ($this->clock)();
        $eventId = $this->eventId();
        $sessions = $this->rows("SELECT *, CAST(strftime('%s', start_date) AS INTEGER) AS start_timestamp,
            CAST(strftime('%s', end_date) AS INTEGER) AS end_timestamp
            FROM event_session WHERE event_id=:event ORDER BY datetime(start_date), id", ['event' => $eventId]);
        if (!$sessions) {
            throw new DomainException('No hay sesiones configuradas para este evento. Configura al menos una antes de registrar asistencia.');
        }
        $active = [];
        $recommended = 0;
        $closest = PHP_INT_MAX;
        $upcoming = false;
        foreach ($sessions as $session) {
            if ($session['start_timestamp'] === null || $session['end_timestamp'] === null
                || $session['end_timestamp'] < $session['start_timestamp'] || (int) $session['id'] < 1) {
                throw new DomainException('Una sesión tiene datos de fecha o identificador no válidos. Revisa la configuración.');
            }
            $inside = $now >= $session['start_timestamp'] && $now <= $session['end_timestamp'];
            if ($inside) { $active[] = (int) $session['id']; }
            $distance = $inside ? 0 : min(abs($now - $session['start_timestamp']), abs($now - $session['end_timestamp']));
            $future = $session['start_timestamp'] > $now;
            if ($distance < $closest || ($distance === $closest && $future && !$upcoming)) {
                $recommended = (int) $session['id'];
                $closest = $distance;
                $upcoming = $future;
            }
        }
        return ['event_id' => $eventId, 'sessions' => $sessions, 'active_ids' => $active, 'recommended_id' => $recommended];
    }

    /** @return array<string, mixed> */
    public function review(int $personId, int $sessionId): array {
        $now = ($this->clock)();
        $selection = $this->selection($now);
        $person = $this->rows('SELECT id, fullname FROM person WHERE id = :id', ['id' => $personId]);
        $session = null;
        foreach ($selection['sessions'] as $item) { if ((int) $item['id'] === $sessionId) { $session = $item; } }
        if ($personId < 1 || !$person || $session === null) {
            throw new DomainException('La persona o la sesión ya no están disponibles. Vuelve a escanear el QR.');
        }
        // Every missed previous session is advisory, including the formerly exempt ID 1.
        $missing = $this->rows('SELECT s.id, s.name FROM event_session s
            WHERE s.event_id = :event AND datetime(s.start_date) < datetime(:start)
            AND NOT EXISTS (SELECT 1 FROM attendance a
                WHERE a.person_id = :person AND a.event_session_id = s.id)
            ORDER BY s.start_date, s.id',
            ['event' => (int) $session['event_id'], 'start' => $session['start_date'], 'person' => $personId]);
        $attendance = $this->rows('SELECT id FROM attendance
            WHERE person_id = :person AND event_session_id = :session ORDER BY id',
            ['person' => $personId, 'session' => $sessionId]);
        $decision = $this->rows('SELECT id, decision, actor, decided_at FROM qr_admission_decision
            WHERE person_id = :person AND event_session_id = :session ORDER BY id DESC LIMIT 1',
            ['person' => $personId, 'session' => $sessionId]);
        $warnings = [];
        if (!in_array($sessionId, $selection['active_ids'], true)) {
            $warnings[] = ['code' => 'outside_session_time',
                'message' => 'La hora actual está fuera del horario orientativo de esta sesión. Puedes registrar la asistencia igualmente; confirma la sesión seleccionada.'];
        }
        if (count($selection['active_ids']) > 1) {
            $warnings[] = ['code' => 'overlapping_sessions', 'session_ids' => $selection['active_ids'],
                'message' => 'Varios períodos coinciden con la hora actual. Selecciona y confirma la sesión correspondiente.'];
        }
        if ($missing) {
            $warnings[] = ['code' => 'missing_previous_sessions', 'sessions' => $missing,
                'message' => 'Faltan asistencias anteriores. Esta advertencia no bloquea el ingreso; el personal decide.'];
        }
        if (($decision[0]['decision'] ?? '') === 'deny') {
            $warnings[] = ['code' => 'existing_denial', 'decision_id' => (int) $decision[0]['id'],
                'message' => 'Existe una denegación para esta sesión. Solo una decisión explícita del personal puede cambiarla.'];
        }
        return ['person' => $person[0], 'session' => $session, 'missing' => $missing,
            'attendance' => $attendance, 'decision' => $decision[0] ?? null, 'warnings' => $warnings,
            'sessions' => $selection['sessions'], 'recommended_id' => $selection['recommended_id'],
            'automatic_allowed' => !$attendance && !$warnings && count($selection['active_ids']) === 1,
            'evaluated_at' => gmdate('Y-m-d H:i:s', $now)];
    }

    /** @param array<string, mixed> $review */
    public static function fingerprint(array $review): string {
        // Passage of time alone must not invalidate a review every second.
        // A changed warning (e.g. crossing an interval boundary) still does.
        unset($review['evaluated_at'], $review['sessions'], $review['recommended_id']);
        return hash('sha256', json_encode($review, JSON_THROW_ON_ERROR));
    }

    /** The expected fingerprint comes from the server-side session, never the browser. */
    public function decide(int $personId, int $sessionId, string $decision, string $expected, string $actor, bool $automatic = false, string $notes = ''): void {
        if (!in_array($decision, ['allow', 'deny'], true) || $actor === '' || ($automatic && $decision !== 'allow')) {
            throw new DomainException('Decisión no válida.');
        }
        $notes = trim($notes);
        if (strlen($notes) > 4000) { throw new DomainException('La nota es demasiado larga.'); }
        $this->db->exec('BEGIN IMMEDIATE');
        try {
            $review = $this->review($personId, $sessionId);
            if (!hash_equals($expected, self::fingerprint($review))) {
                throw new DomainException('La información cambió. Revisa el estado actualizado antes de decidir.');
            }
            if ($review['attendance']) {
                throw new DomainException('La asistencia ya está registrada. No se creará otro registro ni se eliminará.');
            }
            if ($automatic && !$review['automatic_allowed']) {
                throw new DomainException('Esta sesión requiere revisión del personal. No se registró asistencia automáticamente.');
            }
            $now = $review['evaluated_at'];
            if ($decision === 'allow') {
                $this->rows('INSERT INTO attendance (person_id, event_session_id, log_time)
                    VALUES (:person, :session, :time)',
                    ['person' => $personId, 'session' => $sessionId, 'time' => $now]);
            }
            $this->rows('INSERT INTO qr_admission_decision
                (person_id, event_session_id, decision, warnings_json, actor, decided_at)
                VALUES (:person, :session, :decision, :warnings, :actor, :time)',
                ['person' => $personId, 'session' => $sessionId, 'decision' => $decision,
                    // Versioned JSON preserves old list-only snapshots without
                    // a schema migration, and distinguishes automatic admission
                    // from a human confirmation by the shared staff account.
                    'warnings' => json_encode(['version' => 2, 'warnings' => $review['warnings'],
                        'missing_sessions' => $review['missing'], 'session' => $review['session'],
                        'mode' => $automatic ? 'automatic' : 'staff', 'staff_confirmed' => !$automatic,
                        'notes' => $notes], JSON_THROW_ON_ERROR),
                    'actor' => $actor, 'time' => $now]);
            $this->db->exec('COMMIT');
        } catch (Throwable $error) {
            $this->db->exec('ROLLBACK');
            throw $error;
        }
    }
}
