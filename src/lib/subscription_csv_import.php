<?php
require_once __DIR__ . '/auth.php';
auth_block_direct(__FILE__);
require_once __DIR__ . '/subscription_csv.php';

/** CSV-only persistence; keep legacy registration and attendance workflows intact. */
class SubscriptionCsvImport {
    private SQLite3 $db;

    function __construct(SQLite3 $db) {
        $this->db = $db;
        $db->enableExceptions(true);
        $db->busyTimeout(5000);
        $db->exec('PRAGMA foreign_keys=ON');
    }

    /**
     * @param array<string, int|string> $values
     * @return array<string, mixed>|false
     */
    private function query(string $sql, array $values = []): array|false {
        $statement = $this->db->prepare($sql);
        if ($statement === false) {
            throw new RuntimeException('No se pudo preparar la operación de importación.');
        }
        foreach ($values as $key => $value) {
            $statement->bindValue(':' . $key, $value, is_int($value) ? SQLITE3_INTEGER : SQLITE3_TEXT);
        }
        $result = $statement->execute();
        if ($result === false) {
            throw new RuntimeException('No se pudo ejecutar la operación de importación.');
        }
        $row = $result->numColumns() > 0 ? $result->fetchArray(SQLITE3_ASSOC) : false;
        $result->finalize();
        $statement->close();
        return $row;
    }

    /**
     * $review must come from the server-side reader, never a client-supplied row/warning list.
     * @param array<string, mixed> $review
     * @return array<string, mixed>
     */
    public function run(array $review, bool $confirmed, string $actor = '', string $notes = ''): array {
        if ($review['warnings'] && !$confirmed) {
            throw new DomainException('Revisa las advertencias y confirma la importación antes de continuar.');
        }
        $report = ['imported' => 0, 'duplicates' => 0, 'errors' => $review['errors'], 'mail_errors' => []];
        foreach ($review['rows'] as $row) {
            $inTransaction = false;
            try {
                $this->db->exec('BEGIN IMMEDIATE');
                $inTransaction = true;
                $identity = ['fullname' => $row['fullname'], 'email' => $row['email'], 'phone' => $row['phone']];
                $matches = $this->query('SELECT count(*) AS total FROM person WHERE fullname=:fullname AND email=:email AND phone=:phone', $identity);
                if ($matches === false) {
                    throw new RuntimeException('No se pudo verificar la identidad de la persona.');
                }
                if ((int) $matches['total'] > 1) {
                    $this->db->exec('ROLLBACK');
                    $inTransaction = false;
                    $report['errors'][] = ['row' => $row['row'], 'message' => 'Hay varias personas con estos mismos datos. No se importó la fila; revisa los registros duplicados antes de generar un QR.'];
                    continue;
                }
                $person = $this->query('SELECT * FROM person WHERE fullname=:fullname AND email=:email AND phone=:phone ORDER BY id LIMIT 1', $identity);
                if ($person && $this->query('SELECT id FROM subscription WHERE person_id=:id LIMIT 1', ['id' => (int) $person['id']])) {
                    $this->db->exec('ROLLBACK');
                    $inTransaction = false;
                    $report['duplicates']++;
                    continue;
                }
                // Generate QR before any person insert: a transport failure must not leave an orphan.
                $qr = generate_qr($row['fullname'], $row['email'], $row['phone']);
                if (!$person) {
                    $this->query('INSERT INTO person (fullname, email, phone, active) VALUES (:fullname, :email, :phone, 1)', $identity);
                    $person = $identity + ['id' => $this->db->lastInsertRowID(), 'active' => 1];
                }
                $now = new DateTime('now', new DateTimeZone('UTC'));
                $this->query('INSERT INTO subscription (person_id, datetime, qr, active) VALUES (:person, :datetime, :qr, 1)', [
                    'person' => (int) $person['id'], 'datetime' => $now->format('Y-m-d H:i:s'), 'qr' => $qr,
                ]);
                $subscriptionId = $this->db->lastInsertRowID();
                // Additive, only on an explicit import POST/CLI action, never on preview/GET.
                $this->db->exec('CREATE TABLE IF NOT EXISTS csv_import_audit (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    person_id INTEGER NOT NULL REFERENCES person(id),
                    subscription_id INTEGER NOT NULL REFERENCES subscription(id),
                    profile TEXT NOT NULL, source_row INTEGER NOT NULL, warnings_json TEXT NOT NULL,
                    staff_confirmed INTEGER NOT NULL, actor TEXT NOT NULL, imported_at TEXT NOT NULL, notes TEXT NOT NULL
                )');
                $this->query('INSERT INTO csv_import_audit
                    (person_id, subscription_id, profile, source_row, warnings_json, staff_confirmed, actor, imported_at, notes)
                    VALUES (:person, :subscription, :profile, :row, :warnings, :confirmed, :actor, :datetime, :notes)', [
                    'person' => (int) $person['id'], 'subscription' => $subscriptionId,
                    'profile' => $review['profile'], 'row' => $row['row'],
                    'warnings' => json_encode($row['warnings'], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
                    'confirmed' => $confirmed ? 1 : 0, 'actor' => $actor,
                    'datetime' => $now->format('Y-m-d H:i:s'), 'notes' => $notes,
                ]);
                $this->db->exec('COMMIT');
                $inTransaction = false;
                $report['imported']++;
            } catch (Throwable $exception) {
                if ($inTransaction) {
                    $this->db->exec('ROLLBACK');
                }
                error_log('CSV import row ' . $row['row'] . ': ' . $exception->getMessage());
                $report['errors'][] = ['row' => $row['row'], 'message' => 'No se guardó la inscripción por un fallo técnico de base de datos o generación del QR. Contacta al administrador.'];
                continue;
            }
            // A mail failure after COMMIT is not an import failure or an invitation to retry the row.
            try {
                $subscription = new Subscription(new Person($row['fullname'], $row['email'], $row['phone'], (int) $person['id'], (int) $person['active']),
                    $qr, convert_from_utc0($now), $subscriptionId, 1);
                Subscription::send_email($subscription);
            } catch (Throwable $exception) {
                error_log('CSV confirmation email row ' . $row['row'] . ': ' . $exception->getMessage());
                $report['mail_errors'][] = ['row' => $row['row'], 'message' => get_env('SEND_EMAIL') === '0'
                    ? 'La inscripción quedó guardada. El envío de correo está deshabilitado (SEND_EMAIL=0); no se envió la confirmación.'
                    : 'La inscripción quedó guardada, pero el transporte de correo no confirmó el envío. No repitas la importación; revisa el correo antes de reenviarlo.'];
            }
        }
        return $report;
    }
}
