<?php
require_once __DIR__ . '/auth.php';
auth_block_direct(__FILE__);
require_once __DIR__ . '/event_registration.php';
require_once __DIR__ . '/qr_assets.php';

/** Explicit repair of one existing subscription. Never creates people or sends mail. */
final class QrRegeneration {
    private SQLite3 $db;
    private EventRegistration $records;

    public function __construct(SQLite3 $db) {
        $this->db = $db;
        $this->records = new EventRegistration($db);
    }

    /** @return array<int, array<string, mixed>> */
    public function history(int $subscriptionId): array {
        $exists = $this->records->rows("SELECT name FROM sqlite_master WHERE type='table' AND name='qr_regeneration'");
        return $exists ? $this->records->rows('SELECT * FROM qr_regeneration WHERE subscription_id=:id
            ORDER BY rowid DESC LIMIT 10', ['id' => $subscriptionId]) : [];
    }

    /** Read-only, including for a missing/broken old image.
     * @return array<string, mixed> */
    public function review(int $subscriptionId, ?int $eventId = null): array {
        return $this->snapshot($subscriptionId, $eventId);
    }

    /** @return array<string, mixed> */
    private function snapshot(int $subscriptionId, ?int $eventId, string $ignoreAction = ''): array {
        EventRegistration::id($subscriptionId);
        $rows = $this->records->rows('SELECT s.id, s.person_id, s.qr, s.active, p.active AS person_active,
            p.fullname, p.email, p.phone FROM subscription s JOIN person p ON p.id=s.person_id
            WHERE s.id=:id', ['id' => $subscriptionId]);
        if (!$rows) { throw new DomainException('La inscripción no existe. Vuelve a la lista.'); }
        $person = $rows[0];
        EventRegistration::id($person['person_id']);
        foreach (['fullname', 'email', 'phone'] as $field) {
            if (!is_string($person[$field]) || strlen($person[$field]) > 4096 || !preg_match('//u', $person[$field])) {
                throw new DomainException('Corrige los datos de identidad antes de regenerar el QR.');
            }
        }
        if (trim($person['fullname']) === '' || !filter_var($person['email'], FILTER_VALIDATE_EMAIL)) {
            throw new DomainException('El nombre y el correo deben ser válidos para regenerar el QR.');
        }
        // Preserve exact legacy identity matching, including a deliberately empty phone.
        $matches = $this->records->rows('SELECT id FROM person WHERE fullname=:name AND email=:email AND phone=:phone',
            ['name' => $person['fullname'], 'email' => $person['email'], 'phone' => $person['phone']]);
        if (count($matches) !== 1) { throw new DomainException('La identidad es ambigua. Revisa las personas duplicadas antes de generar el QR.'); }
        $scope = null;
        if ($eventId !== null) {
            EventRegistration::id($eventId);
            $event = $this->records->event($eventId);
            $membership = $this->records->hasSchema() ? $this->records->rows('SELECT * FROM event_registration
                WHERE event_id=:event AND subscription_id=:subscription AND person_id=:person',
                ['event' => $eventId, 'subscription' => $subscriptionId, 'person' => $person['person_id']]) : [];
            if (!$membership) { throw new DomainException('La inscripción no pertenece a este evento. Vuelve a su lista de participantes.'); }
            $scope = ['event' => $event, 'membership' => $membership[0]];
        }
        $base = app_base_url();
        $target = $base . '/attendance_log.php/?fullname=' . urlencode($person['fullname'])
            . '&email=' . urlencode($person['email']) . '&phone=' . urlencode($person['phone']);
        $previous = null;
        foreach ($this->history($subscriptionId) as $attempt) {
            if ($attempt['action'] !== $ignoreAction) { $previous = $attempt; break; }
        }
        $warnings = [];
        if ($person['active'] !== 1 || $person['person_active'] !== 1) {
            $warnings[] = ['code' => 'inactive_registration', 'message' => 'La persona o su inscripción general está inactiva. Puedes cancelar o regenerar de todos modos; su estado no cambiará.'];
        }
        if (is_string($person['qr']) && qr_base_changed($person['qr'])) {
            $warnings[] = ['code' => 'qr_base_changed', 'message' => 'La dirección guardada del QR difiere de HOST_ADDRESS. El nuevo código usará la dirección configurada actual. No se ha decodificado el código anterior. Puedes cancelar o continuar.'];
        }
        if ($previous !== null && $previous['status'] === 'pending') {
            $warnings[] = ['code' => 'unfinished_regeneration', 'message' => 'Hay un intento sin resultado confirmado. Verifica el historial antes de iniciar otro. Puedes cancelar o continuar de todos modos; no se reintenta automáticamente.'];
        }
        return ['person' => $person, 'scope' => $scope, 'base' => $base, 'target' => $target,
            'previous' => $previous, 'warnings' => $warnings];
    }

    /** Bounded fixed-origin transport. Never fetch a URL supplied by the browser or old QR metadata. */
    public static function fetch(string $target): string {
        $handle = curl_init('https://api.qrserver.com/v1/create-qr-code/?size=300x300&format=png&data=' . urlencode($target));
        if ($handle === false) { throw new RuntimeException('No se pudo iniciar el servicio QR.'); }
        $data = '';
        try {
            curl_setopt_array($handle, [CURLOPT_CONNECTTIMEOUT => 5, CURLOPT_TIMEOUT => 20,
                CURLOPT_FOLLOWLOCATION => false, CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
                CURLOPT_WRITEFUNCTION => static function ($handle, string $chunk) use (&$data): int {
                    if (strlen($data) + strlen($chunk) > 2097152) { return 0; }
                    $data .= $chunk;
                    return strlen($chunk);
                }]);
            if (curl_exec($handle) === false || curl_getinfo($handle, CURLINFO_HTTP_CODE) !== 200) {
                throw new RuntimeException('El servicio QR no devolvió una respuesta válida. No se reintentó.');
            }
        } finally { curl_close($handle); }
        return $data;
    }

    /** @param array<string, mixed> $review Server-held, not request data.
     * @param callable(string): string|null $fetch Injectable only in server-side tests. */
    public function regenerate(array $review, string $action, string $actor, string $notes = '', ?callable $fetch = null): string {
        if (!preg_match('/^[a-f0-9]{48}$/D', $action) || $actor === '' || strlen($notes) > 2000 || !preg_match('//u', $notes)) {
            throw new DomainException('La confirmación o la nota no es válida.');
        }
        $id = (int) $review['person']['id'];
        $eventId = $review['scope'] === null ? null : (int) $review['scope']['event']['id'];
        $this->db->exec('BEGIN IMMEDIATE');
        try {
            if ($this->snapshot($id, $eventId) !== $review) { throw new DomainException('Cambiaron la identidad, el QR, el evento, los intentos o HOST_ADDRESS. Revisa nuevamente antes de regenerar.'); }
            $this->db->exec('CREATE UNIQUE INDEX IF NOT EXISTS subscription_id_person_unique ON subscription(id, person_id)');
            $this->db->exec("CREATE TABLE IF NOT EXISTS qr_regeneration (
                action TEXT PRIMARY KEY, subscription_id INTEGER NOT NULL, person_id INTEGER NOT NULL REFERENCES person(id),
                event_id INTEGER REFERENCES event(id), old_qr TEXT, new_qr TEXT, base_url TEXT NOT NULL, target_hash TEXT NOT NULL,
                warnings_json TEXT NOT NULL, staff_confirmed INTEGER NOT NULL CHECK(staff_confirmed=1),
                actor TEXT NOT NULL, notes TEXT NOT NULL, requested_at TEXT NOT NULL, completed_at TEXT,
                status TEXT NOT NULL CHECK(status IN ('pending','completed','failed')),
                FOREIGN KEY (subscription_id, person_id) REFERENCES subscription(id, person_id)
            )");
            $this->records->rows('INSERT INTO qr_regeneration
                (action,subscription_id,person_id,event_id,old_qr,base_url,target_hash,warnings_json,staff_confirmed,actor,notes,requested_at,status)
                VALUES (:action,:subscription,:person,NULLIF(:event,\'\'),:old,:base,:target,:warnings,1,:actor,:notes,:time,\'pending\')',
                ['action' => $action, 'subscription' => $id, 'person' => $review['person']['person_id'],
                    // The query helper binds text/integers; NULLIF keeps a global action unscoped.
                    'event' => $eventId ?? '', 'old' => $review['person']['qr'] ?? '', 'base' => $review['base'],
                    'target' => hash('sha256', $review['target']), 'warnings' => json_encode($review['warnings'], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
                    'actor' => $actor, 'notes' => $notes, 'time' => gmdate('Y-m-d H:i:s')]);
            $this->db->exec('COMMIT');
        } catch (Throwable $error) { $this->db->exec('ROLLBACK'); throw $error; }

        // Claim is durable before networking. Never automatically repeat an interrupted attempt.
        $published = null;
        try {
            $bytes = ($fetch ?? [self::class, 'fetch'])($review['target']);
            $info = strlen($bytes) <= 2097152 ? @getimagesizefromstring($bytes) : false;
            if ($info === false || $info[0] !== 300 || $info[1] !== 300 || $info[2] !== IMAGETYPE_PNG
                || substr($bytes, -12) !== "\x00\x00\x00\x00IEND\xAE\x42\x60\x82") {
                throw new RuntimeException('El servicio QR no devolvió una imagen PNG válida de 300 × 300.');
            }
            $this->db->exec('BEGIN IMMEDIATE');
            try {
                if ($this->snapshot($id, $eventId, $action) !== $review) {
                    throw new DomainException('Los datos cambiaron durante la generación. No se reemplazó el QR; revisa nuevamente.');
                }
                $filename = bin2hex(random_bytes(32)) . '.jpg';
                $url = $review['base'] . '/static/qr/' . $filename;
                $published = $this->publish($filename, $bytes);
                $this->updateOne('UPDATE subscription SET qr=:qr WHERE id=:id AND person_id=:person',
                    ['qr' => $url, 'id' => $id, 'person' => $review['person']['person_id']]);
                $this->updateOne('UPDATE qr_regeneration SET new_qr=:qr, status=\'completed\', completed_at=:time
                    WHERE action=:action AND status=\'pending\'', ['qr' => $url, 'time' => gmdate('Y-m-d H:i:s'), 'action' => $action]);
                $this->db->exec('COMMIT');
            } catch (Throwable $error) {
                // Only remove our newly published file after a confirmed rollback, never an old QR.
                if ($this->db->exec('ROLLBACK') && $published !== null) { @unlink($published); }
                throw $error;
            }
            return $url;
        } catch (Throwable $error) {
            $this->records->rows('UPDATE qr_regeneration SET status=\'failed\', completed_at=:time
                WHERE action=:action AND status=\'pending\'', ['action' => $action, 'time' => gmdate('Y-m-d H:i:s')]);
            throw $error;
        }
    }

    /** Each write must change exactly one row; check on the same connection immediately.
     * @param array<string, int|string> $values */
    private function updateOne(string $sql, array $values): void {
        $this->records->rows($sql, $values);
        if ($this->db->changes() !== 1) { throw new RuntimeException('No se pudo guardar el QR o su auditoría.'); }
    }

    /** Publish complete bytes under a new exclusive name; never overwrite historical images. */
    private function publish(string $filename, string $bytes): string {
        $directory = dirname(__DIR__) . '/static/qr';
        if (is_link(dirname($directory)) || is_link($directory)
            || (!is_dir($directory) && !@mkdir($directory, 0700)) || !is_writable($directory)) {
            throw new RuntimeException('No se puede escribir en el directorio QR.');
        }
        $temporary = $directory . '/.regenerate-' . bin2hex(random_bytes(24));
        $path = $directory . '/' . $filename;
        $file = @fopen($temporary, 'x+b');
        if ($file === false) { throw new RuntimeException('No se pudo crear el archivo QR temporal.'); }
        try {
            if (!@chmod($temporary, 0600) || @fwrite($file, $bytes) !== strlen($bytes) || !@fflush($file)) {
                throw new RuntimeException('No se pudo guardar la imagen QR completa.');
            }
            // link() is atomic and fails if the destination already exists (unlike rename()).
            if (!@link($temporary, $path)) { throw new RuntimeException('No se pudo publicar la imagen QR.'); }
            if (!@unlink($temporary)) {
                @unlink($path);
                throw new RuntimeException('No se pudo finalizar la imagen QR.');
            }
            return $path;
        } finally {
            fclose($file);
            if (is_file($temporary)) { @unlink($temporary); }
        }
    }
}
