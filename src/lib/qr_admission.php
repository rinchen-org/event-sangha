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

    public function __construct(SQLite3 $db) {
        $this->db = $db;
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

    public function currentSession(): int {
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
        $sessions = $this->rows("SELECT id FROM event_session
            WHERE event_id = :event AND datetime(:now) >= datetime(start_date)
            AND datetime(:now) <= datetime(end_date) ORDER BY start_date, id LIMIT 1",
            ['event' => $eventId, 'now' => gmdate('Y-m-d H:i:s')]);
        if (!$sessions) {
            throw new DomainException('No hay una sesión en curso para este evento.');
        }
        return (int) $sessions[0]['id'];
    }

    /** @return array<string, mixed> */
    public function review(int $personId, int $sessionId): array {
        $person = $this->rows('SELECT id, fullname FROM person WHERE id = :id', ['id' => $personId]);
        $session = $this->rows('SELECT * FROM event_session WHERE id = :id', ['id' => $sessionId]);
        if (!$person || !$session || $sessionId !== $this->currentSession()) {
            throw new DomainException('La persona o la sesión ya no están disponibles. Vuelve a escanear el QR.');
        }
        $session = $session[0];
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
        return ['person' => $person[0], 'session' => $session, 'missing' => $missing,
            'attendance' => $attendance, 'decision' => $decision[0] ?? null];
    }

    /** @param array<string, mixed> $review */
    public static function fingerprint(array $review): string {
        return hash('sha256', json_encode($review, JSON_THROW_ON_ERROR));
    }

    /** The expected fingerprint comes from the server-side session, never the browser. */
    public function decide(int $personId, int $sessionId, string $decision, string $expected, string $actor): void {
        if (!in_array($decision, ['allow', 'deny'], true) || $actor === '') {
            throw new DomainException('Decisión no válida.');
        }
        $this->db->exec('BEGIN IMMEDIATE');
        try {
            $review = $this->review($personId, $sessionId);
            if (!hash_equals($expected, self::fingerprint($review))) {
                throw new DomainException('La información cambió. Revisa el estado actualizado antes de decidir.');
            }
            if ($review['attendance']) {
                throw new DomainException('La asistencia ya está registrada. No se creará otro registro ni se eliminará.');
            }
            $now = gmdate('Y-m-d H:i:s');
            if ($decision === 'allow') {
                $this->rows('INSERT INTO attendance (person_id, event_session_id, log_time)
                    VALUES (:person, :session, :time)',
                    ['person' => $personId, 'session' => $sessionId, 'time' => $now]);
            }
            $this->rows('INSERT INTO qr_admission_decision
                (person_id, event_session_id, decision, warnings_json, actor, decided_at)
                VALUES (:person, :session, :decision, :warnings, :actor, :time)',
                ['person' => $personId, 'session' => $sessionId, 'decision' => $decision,
                    'warnings' => json_encode($review['missing'], JSON_THROW_ON_ERROR),
                    'actor' => $actor, 'time' => $now]);
            $this->db->exec('COMMIT');
        } catch (Throwable $error) {
            $this->db->exec('ROLLBACK');
            throw $error;
        }
    }
}
