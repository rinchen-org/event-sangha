<?php
require_once __DIR__ . '/auth.php';
auth_block_direct(__FILE__);
require_once __DIR__ . '/schema.php';

/** Event membership is not an admission gate or a change to global activation/QR policy. */
class EventRegistration {
    private SQLite3 $db;

    public function __construct(SQLite3 $db) {
        $this->db = $db;
        $db->enableExceptions(true);
        $db->busyTimeout(5000);
        $db->exec('PRAGMA foreign_keys=ON');
    }

    /** @param mixed $value */
    public static function id($value): int {
        if ((!is_string($value) && !is_int($value))
            || !preg_match('/^[1-9][0-9]*$/D', (string) $value)
            || filter_var($value, FILTER_VALIDATE_INT) === false) {
            throw new DomainException('El identificador no es válido.');
        }
        return (int) $value;
    }

    /** @param array<string, int|string> $values
     * @return array<int, array<string, mixed>> */
    public function rows(string $sql, array $values = []): array {
        $statement = $this->db->prepare($sql);
        if ($statement === false) { throw new RuntimeException('No se pudo preparar la consulta.'); }
        foreach ($values as $key => $value) {
            $statement->bindValue(':' . $key, $value, is_int($value) ? SQLITE3_INTEGER : SQLITE3_TEXT);
        }
        $result = $statement->execute();
        if ($result === false) { throw new RuntimeException('No se pudo ejecutar la consulta.'); }
        $rows = [];
        if ($result->numColumns() > 0) {
            while ($row = $result->fetchArray(SQLITE3_ASSOC)) { $rows[] = $row; }
        }
        $result->finalize();
        $statement->close();
        return $rows;
    }

    public function hasSchema(): bool {
        $objects = $this->rows("SELECT type FROM sqlite_master WHERE name='event_registration'");
        if ($objects && $objects[0]['type'] !== 'table') { throw new RuntimeException('Esquema de inscripciones incompatible.'); }
        return $objects !== [];
    }

    /** @return array<string, mixed> */
    public function event(int $eventId): array {
        $rows = $this->rows('SELECT id, name, description, start_date, end_date FROM event WHERE id=:id', ['id' => $eventId]);
        if (!$rows) { throw new DomainException('El evento no existe. Vuelve a la lista de eventos.'); }
        return $rows[0];
    }

    /** Read-only, including on installations that have not added the membership table yet.
     * @return array<string, mixed> */
    public function detail(int $eventId): array {
        $event = $this->event($eventId);
        $subscriptions = $this->hasSchema() ? $this->rows('SELECT s.id, s.person_id, p.fullname, p.email, p.phone,
            s.active, s.datetime, r.registered_at FROM event_registration r
            JOIN subscription s ON s.id=r.subscription_id AND s.person_id=r.person_id
            JOIN person p ON p.id=r.person_id WHERE r.event_id=:id ORDER BY p.fullname', ['id' => $eventId]) : [];
        $sessions = $this->rows('SELECT s.id, s.event_id, e.name AS event_name, s.name, s.start_date, s.end_date
            FROM event_session s JOIN event e ON e.id=s.event_id WHERE s.event_id=:id ORDER BY s.start_date', ['id' => $eventId]);
        // Historical attendance is shown even if the person has not been explicitly linked yet.
        $attendance = $this->rows('SELECT a.id, a.person_id, p.fullname, e.name AS event_name, e.id AS event_id,
            s.name AS session_name, a.event_session_id, a.log_time FROM attendance a
            JOIN person p ON p.id=a.person_id JOIN event_session s ON s.id=a.event_session_id
            JOIN event e ON e.id=s.event_id WHERE e.id=:id ORDER BY a.log_time DESC', ['id' => $eventId]);
        return compact('event', 'subscriptions', 'sessions', 'attendance');
    }

    /** @return array<int, array<string, mixed>> */
    public function candidates(): array {
        return $this->rows('SELECT s.id, s.person_id, p.fullname, p.email, p.phone, s.active
            FROM subscription s JOIN person p ON p.id=s.person_id ORDER BY p.fullname, s.id');
    }

    /** @param array<string, mixed> $person
     * @param array<string, mixed>|null $subscription
     * @return array<int, array<string, string>> */
    private function warnings(array $person, ?array $subscription): array {
        $warnings = [];
        if ((int) $person['active'] !== 1 || ($subscription !== null && (int) $subscription['active'] !== 1)) {
            $warnings[] = ['code' => 'inactive_registration', 'message' => 'La persona o su inscripción general está inactiva. Puedes cancelar o vincularla de todos modos; su estado general no cambiará.'];
        }
        return $warnings;
    }

    /** @return array<string, mixed> */
    public function reviewExisting(int $eventId, int $subscriptionId): array {
        $subscription = $this->rows('SELECT * FROM subscription WHERE id=:id', ['id' => $subscriptionId])[0] ?? null;
        if ($subscription === null) { throw new DomainException('La inscripción no existe.'); }
        $person = $this->rows('SELECT * FROM person WHERE id=:id', ['id' => (int) $subscription['person_id']])[0] ?? null;
        if ($person === null) { throw new DomainException('La inscripción no tiene una persona válida.'); }
        return ['event' => $this->event($eventId), 'person' => $person, 'subscription' => $subscription,
            'warnings' => $this->warnings($person, $subscription)];
    }

    /** Exact identity only: never guess between multiple people/subscriptions.
     * @param array<string, mixed> $row
     * @return array<string, mixed> */
    public function reviewIdentity(int $eventId, array $row): array {
        $people = $this->rows('SELECT * FROM person WHERE fullname=:fullname AND email=:email AND phone=:phone',
            ['fullname' => $row['fullname'], 'email' => $row['email'], 'phone' => $row['phone']]);
        if (count($people) > 1) { throw new DomainException('Hay varias personas con estos mismos datos. Revisa los duplicados antes de importar.'); }
        $person = $people[0] ?? null;
        $subscriptions = $person === null ? [] : $this->rows('SELECT * FROM subscription WHERE person_id=:id', ['id' => (int) $person['id']]);
        if (count($subscriptions) > 1) { throw new DomainException('Hay varias inscripciones para esta persona. Selecciona explícitamente una mediante Vincular inscripción existente.'); }
        $subscription = $subscriptions[0] ?? null;
        return ['event' => $this->event($eventId), 'person' => $person, 'subscription' => $subscription,
            'warnings' => $person === null ? [] : $this->warnings($person, $subscription)];
    }

    /** Add server-evaluated event context/warnings before staff confirmation; never writes.
     * @param array<string, mixed> $review
     * @return array<string, mixed> */
    public function scopeCsv(array $review, int $eventId): array {
        $review['event'] = $this->event($eventId);
        $rows = [];
        foreach ($review['rows'] as $row) {
            try {
                $row['registration_review'] = $this->reviewIdentity($eventId, $row);
                foreach ($row['registration_review']['warnings'] as $warning) {
                    $row['warnings'][] = $warning;
                    $review['warnings'][] = ['row' => $row['row'], 'message' => $warning['message']];
                }
                $rows[] = $row;
            } catch (DomainException $error) {
                $review['errors'][] = ['row' => $row['row'], 'message' => $error->getMessage()];
            }
        }
        $review['rows'] = $rows;
        return $review;
    }

    public function registeredSubscription(int $eventId, int $personId): ?int {
        if (!$this->hasSchema()) { return null; }
        $rows = $this->rows('SELECT subscription_id FROM event_registration WHERE event_id=:event AND person_id=:person',
            ['event' => $eventId, 'person' => $personId]);
        return $rows ? (int) $rows[0]['subscription_id'] : null;
    }

    /** Caller holds BEGIN IMMEDIATE. The database enforces event/person/subscription integrity.
     * @param array<int, array<string, mixed>> $warnings */
    public function insert(int $eventId, int $subscriptionId, int $personId, string $actor, string $source,
        array $warnings, string $notes = '', ?int $sourceRow = null): void {
        create_event_registration_schema($this->db);
        $this->rows('INSERT INTO event_registration
            (event_id, person_id, subscription_id, registered_at, actor, source, source_row, warnings_json, staff_confirmed, notes)
            VALUES (:event, :person, :subscription, :time, :actor, :source, :row, :warnings, 1, :notes)', [
                'event' => $eventId, 'person' => $personId, 'subscription' => $subscriptionId,
                'time' => gmdate('Y-m-d H:i:s'), 'actor' => $actor, 'source' => $source, 'row' => $sourceRow ?? 0,
                'warnings' => json_encode($warnings, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE), 'notes' => $notes,
            ]);
    }

    /** @param array<string, mixed> $review */
    public function confirmExisting(array $review, string $actor, string $notes): bool {
        $this->db->exec('BEGIN IMMEDIATE');
        try {
            $eventId = (int) $review['event']['id'];
            $subscriptionId = (int) $review['subscription']['id'];
            $current = $this->reviewExisting($eventId, $subscriptionId);
            if ($current !== $review) { throw new DomainException('Los datos o las advertencias cambiaron. Revisa nuevamente antes de confirmar.'); }
            $existing = $this->registeredSubscription($eventId, (int) $current['person']['id']);
            if ($existing !== null && $existing !== $subscriptionId) {
                throw new DomainException('La persona ya está vinculada al evento mediante otra inscripción. No se modificó ese vínculo.');
            }
            if ($existing === null) {
                $this->insert($eventId, $subscriptionId, (int) $current['person']['id'], $actor, 'staff', $current['warnings'], $notes);
            }
            $this->db->exec('COMMIT');
            return $existing === null;
        } catch (Throwable $error) {
            $this->db->exec('ROLLBACK');
            throw $error;
        }
    }
}
