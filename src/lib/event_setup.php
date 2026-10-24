<?php
require_once __DIR__ . '/schema.php';
require_once __DIR__ . '/datetime.php';
auth_block_direct(__FILE__);

class EventSetup
{
    /** @return array<string, string> */
    public static function defaults(): array {
        return ['name' => 'Retiro de noviembre', 'description' => '', 'year' => '2026',
            'morning_start' => '09:00', 'morning_end' => '12:00',
            'afternoon_start' => '14:00', 'afternoon_end' => '18:00', 'event_id' => '', 'qr_event' => '1'];
    }

    /** @param array<string, string> $input
     * @return array<int, array<string, string>>
     */
    public static function schedule(array $input): array {
        if (!preg_match('/^20[0-9]{2}$/D', $input['year'] ?? '') || trim($input['name'] ?? '') === '') {
            throw new DomainException('Indica el nombre del evento y un año entre 2000 y 2099.');
        }
        $times = [];
        foreach (['morning_start', 'morning_end', 'afternoon_start', 'afternoon_end'] as $field) {
            $value = $input[$field] ?? '';
            if (!preg_match('/^(?:[01][0-9]|2[0-3]):[0-5][0-9]$/D', $value)) {
                throw new DomainException('Introduce horarios válidos (HH:MM).');
            }
            $times[] = $value;
        }
        if (!($times[0] < $times[1] && $times[1] <= $times[2] && $times[2] < $times[3])) {
            throw new DomainException('Cada sesión debe terminar después de comenzar y los dos intervalos no deben superponerse.');
        }
        $sessions = [];
        for ($day = 12; $day <= 15; $day++) {
            foreach (['am' => [0, 1, 'Mañana'], 'pm' => [2, 3, 'Tarde']] as $slot => $parts) {
                if ($slot === 'am' && in_array($day, [13, 14], true)) {
                    continue;
                }
                $date = $input['year'] . "-11-$day";
                $start = new DateTime($date . ' ' . $times[$parts[0]], new DateTimeZone('America/La_Paz'));
                $end = new DateTime($date . ' ' . $times[$parts[1]], new DateTimeZone('America/La_Paz'));
                $sessions[] = ['slot' => "11-$day-$slot", 'name' => "$day de noviembre · " . $parts[2],
                    'start' => convert_to_utc0($start)->format('Y-m-d H:i:s'),
                    'end' => convert_to_utc0($end)->format('Y-m-d H:i:s')];
            }
        }
        return $sessions;
    }

    /** @param array<string, int|string> $params
     * @return array<int, array<string, mixed>>
     */
    private static function sql(SQLite3 $db, string $sql, array $params = []): array {
        $statement = $db->prepare($sql);
        foreach ($params as $key => $value) {
            $statement->bindValue(':' . $key, $value, is_int($value) ? SQLITE3_INTEGER : SQLITE3_TEXT);
        }
        $result = $statement->execute();
        $rows = [];
        if ($result->numColumns() > 0) {
            while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
                $rows[] = $row;
            }
        }
        $result->finalize();
        $statement->close();
        return $rows;
    }

    /** @param array<string, string> $input */
    public static function save(SQLite3 $db, array $input): int {
        $sessions = self::schedule($input);
        $target = $input['event_id'] ?? '';
        if ($target !== '' && !preg_match('/^[1-9][0-9]{0,9}$/D', $target)) {
            throw new DomainException('Identificador de evento no válido.');
        }
        $db->enableExceptions(true);
        $db->busyTimeout(5000);
        $db->exec('BEGIN IMMEDIATE');
        try {
            create_setup_schema($db);
            $key = 'november-' . $input['year'];
            $known = self::sql($db, 'SELECT event_id FROM setup_event WHERE setup_key=:key', ['key' => $key]);
            $id = $target !== '' ? (int) $target : (int) ($known[0]['event_id'] ?? 0);
            if ($known && $id !== (int) $known[0]['event_id']) {
                throw new DomainException('Este noviembre ya está vinculado a otro evento. Selecciona el evento vinculado; no se moverán sus sesiones automáticamente.');
            }
            $values = ['name' => trim($input['name']), 'description' => $input['description'] ?? '',
                'start' => $sessions[0]['start'], 'end' => $sessions[count($sessions) - 1]['end']];
            if ($id) {
                if (!self::sql($db, 'SELECT id FROM event WHERE id=:id', ['id' => $id])) {
                    throw new DomainException('El evento seleccionado ya no existe.');
                }
                self::sql($db, 'UPDATE event SET name=:name, description=:description,
                    start_date=:start, end_date=:end WHERE id=:id', $values + ['id' => $id]);
            } else {
                self::sql($db, 'INSERT INTO event (name, description, start_date, end_date)
                    VALUES (:name, :description, :start, :end)', $values);
                $id = $db->lastInsertRowID();
            }
            self::sql($db, 'INSERT OR REPLACE INTO setup_event (setup_key, event_id) VALUES (:key, :id)',
                ['key' => $key, 'id' => $id]);
            // Remove only obsolete slots owned by this setup, never unrelated sessions.
            // Refuse the entire update if doing so would orphan attendance or decisions.
            foreach (['11-13-am', '11-14-am'] as $obsoleteSlot) {
                $obsolete = self::sql($db, 'SELECT session_id FROM setup_session WHERE event_id=:event AND slot=:slot',
                    ['event' => $id, 'slot' => $obsoleteSlot]);
                if (!$obsolete) {
                    continue;
                }
                $obsoleteId = (int) $obsolete[0]['session_id'];
                if (self::sql($db, 'SELECT id FROM attendance WHERE event_session_id=:id LIMIT 1', ['id' => $obsoleteId])
                    || self::sql($db, 'SELECT id FROM qr_admission_decision WHERE event_session_id=:id LIMIT 1', ['id' => $obsoleteId])) {
                    throw new DomainException('Una sesión de mañana del 13 o 14 ya tiene asistencias o decisiones. No se eliminó ni cambió ningún registro; revisa su historial antes de ajustar el programa.');
                }
                $existing = self::sql($db, 'SELECT event_id FROM event_session WHERE id=:id', ['id' => $obsoleteId]);
                if ($existing && (int) $existing[0]['event_id'] !== $id) {
                    throw new DomainException('Una sesión vinculada cambió de evento. Revisa la configuración.');
                }
                self::sql($db, 'DELETE FROM setup_session WHERE event_id=:event AND slot=:slot',
                    ['event' => $id, 'slot' => $obsoleteSlot]);
                self::sql($db, 'DELETE FROM event_session WHERE id=:id AND event_id=:event',
                    ['id' => $obsoleteId, 'event' => $id]);
            }
            foreach ($sessions as $session) {
                $linked = self::sql($db, 'SELECT session_id FROM setup_session WHERE event_id=:event AND slot=:slot',
                    ['event' => $id, 'slot' => $session['slot']]);
                $sessionId = (int) ($linked[0]['session_id'] ?? 0);
                if (!$sessionId) {
                    // Adopt only an unambiguous existing session at exactly this start time.
                    $matches = self::sql($db, 'SELECT id FROM event_session WHERE event_id=:event AND start_date=:start',
                        ['event' => $id, 'start' => $session['start']]);
                    if (count($matches) > 1) {
                        throw new DomainException('Hay varias sesiones con el mismo horario. Revisa los duplicados antes de continuar.');
                    }
                    $sessionId = (int) ($matches[0]['id'] ?? 0);
                }
                $values = ['event' => $id, 'name' => $session['name'], 'start' => $session['start'], 'end' => $session['end']];
                if ($sessionId) {
                    if (!self::sql($db, 'SELECT id FROM event_session WHERE id=:id AND event_id=:event',
                        ['id' => $sessionId, 'event' => $id])) {
                        throw new DomainException('Una sesión vinculada cambió o ya no existe. Revisa el evento antes de continuar.');
                    }
                    self::sql($db, 'UPDATE event_session SET name=:name, start_date=:start, end_date=:end
                        WHERE id=:id AND event_id=:event', $values + ['id' => $sessionId]);
                } else {
                    self::sql($db, 'INSERT INTO event_session (event_id, name, start_date, end_date)
                        VALUES (:event, :name, :start, :end)', $values);
                    $sessionId = $db->lastInsertRowID();
                }
                self::sql($db, 'INSERT OR REPLACE INTO setup_session (event_id, slot, session_id)
                    VALUES (:event, :slot, :session)', ['event' => $id, 'slot' => $session['slot'], 'session' => $sessionId]);
            }
            if (($input['qr_event'] ?? '') === '1') {
                self::sql($db, "INSERT OR REPLACE INTO app_setting (name, value) VALUES ('qr_event_id', :id)", ['id' => $id]);
            }
            $db->exec('COMMIT');
            return $id;
        } catch (Throwable $error) {
            $db->exec('ROLLBACK');
            throw $error;
        }
    }

    /**
     * Read-only plan for the shortcut. Existing records are never updated or
     * deleted here, including edited schedules and obsolete/extra sessions.
     * @return array<string, mixed>
     */
    public static function preparationStatus(?SQLite3 $db, string $selection = ''): array {
        if ($selection !== '' && $selection !== 'new' && !preg_match('/^[1-9][0-9]{0,9}$/D', $selection)) {
            throw new DomainException('Selecciona un evento válido.');
        }
        $schema = pending_setup_schema($db);
        $has = static function (string $table) use ($db): bool {
            return $db !== null && (bool) $db->querySingle("SELECT 1 FROM sqlite_master WHERE type='table' AND name='" . SQLite3::escapeString($table) . "'");
        };
        $events = $has('event') ? self::sql($db, 'SELECT * FROM event ORDER BY id') : [];
        $known = $has('setup_event') ? self::sql($db, "SELECT event_id FROM setup_event WHERE setup_key='november-2026'") : [];
        $id = $known ? (int) $known[0]['event_id'] : ($selection !== 'new' && $selection !== '' ? (int) $selection : 0);
        if ($known && $selection !== '' && $selection !== 'new' && $id !== (int) $selection) {
            throw new DomainException('Noviembre de 2026 ya está vinculado a otro evento. No se cambiará ese vínculo.');
        }
        $event = null;
        foreach ($events as $item) { if ((int) $item['id'] === $id) { $event = $item; } }
        if (($known || $id) && ($id < 1 || $event === null)) {
            throw new DomainException('El evento vinculado o seleccionado ya no existe. Revisa los datos antes de continuar.');
        }
        if (!$known && $id && $has('setup_event') && self::sql($db, 'SELECT setup_key FROM setup_event WHERE event_id=:id', ['id' => $id])) {
            throw new DomainException('El evento seleccionado pertenece a otra configuración anual. No se reutilizará para 2026.');
        }
        $choose = !$known && !$id && $selection !== 'new' && count($events) > 0;
        $existing = $id && $has('event_session') ? self::sql($db, 'SELECT * FROM event_session WHERE event_id=:id ORDER BY id', ['id' => $id]) : [];
        $links = $has('setup_session') ? self::sql($db, 'SELECT * FROM setup_session ORDER BY event_id, slot') : [];
        $sessions = [];
        $claimed = [];
        foreach ($links as $link) { $claimed[(int) $link['session_id']] = true; }
        foreach (self::schedule(self::defaults()) as $slot) {
            $linked = null;
            foreach ($links as $link) {
                if ((int) $link['event_id'] === $id && $link['slot'] === $slot['slot']) { $linked = (int) $link['session_id']; }
            }
            $matches = array_values(array_filter($existing, static function (array $row) use ($linked, $slot): bool {
                return $linked !== null ? (int) $row['id'] === $linked : $row['start_date'] === $slot['start'];
            }));
            if (($linked !== null && count($matches) !== 1) || count($matches) > 1) {
                throw new DomainException('Una sesión vinculada falta, cambió de evento o tiene coincidencias ambiguas. Revisa la configuración; no se crearán duplicados.');
            }
            $session = $matches[0] ?? null;
            if ($linked === null && $session !== null && isset($claimed[(int) $session['id']])) {
                throw new DomainException('Una sesión ya pertenece a otro vínculo. Revisa la configuración.');
            }
            if ($session !== null) { $claimed[(int) $session['id']] = true; }
            $sessions[] = $slot + ['action' => $linked !== null ? 'skip' : ($session !== null ? 'adopt' : 'create'), 'record' => $session];
        }
        // Missing mappings plus edited/unrecognized sessions cannot be safely
        // interpreted as missing sessions. Ask for explicit historical review.
        if (in_array('create', array_column($sessions, 'action'), true)) {
            foreach ($existing as $session) {
                if (!isset($claimed[(int) $session['id']])) {
                    throw new DomainException('Hay sesiones sin vínculo con horarios distintos de los propuestos. Revisa la configuración avanzada antes de crear otras; no se modificarán ni duplicarán esas sesiones.');
                }
            }
        }
        $qr = $has('app_setting') ? self::sql($db, "SELECT value FROM app_setting WHERE name='qr_event_id'") : [];
        $eventReady = (bool) $known && !array_filter($sessions, static function (array $slot): bool { return $slot['action'] !== 'skip'; });
        $state = ['schema' => $schema, 'events' => $events, 'event' => $event, 'linked' => (bool) $known,
            'choose' => $choose, 'sessions' => $sessions, 'existing' => $existing, 'links' => $links,
            'qr' => $qr[0]['value'] ?? '1', 'event_ready' => $eventReady, 'ready' => !$schema && $eventReady];
        $state['fingerprint'] = hash('sha256', json_encode($state, JSON_THROW_ON_ERROR));
        return $state;
    }

    /**
     * Recheck under a write lock; only run missing additive steps. The callback
     * snapshots from a separate read-only connection while other writers wait.
     * @param callable(): void $beforeWrite
     * @return array{changed: bool, event_id: int}
     */
    public static function prepare2026(SQLite3 $db, string $selection, string $fingerprint, callable $beforeWrite): array {
        $db->enableExceptions(true);
        $db->busyTimeout(5000);
        $db->exec('BEGIN IMMEDIATE');
        try {
            $state = self::preparationStatus($db, $selection);
            if ($state['ready']) {
                $db->exec('COMMIT');
                return ['changed' => false, 'event_id' => (int) $state['event']['id']];
            }
            if ($state['choose'] || !hash_equals($fingerprint, $state['fingerprint'])) {
                throw new DomainException('La configuración cambió desde la revisión. Revisa el estado actual y confirma de nuevo.');
            }
            $beforeWrite();
            foreach ($state['schema'] as $sql) { $db->exec($sql); }
            $id = (int) ($state['event']['id'] ?? 0);
            if (!$id) {
                $schedule = self::schedule(self::defaults());
                self::sql($db, 'INSERT INTO event (name, description, start_date, end_date) VALUES (:name, :description, :start, :end)',
                    ['name' => self::defaults()['name'], 'description' => '', 'start' => $schedule[0]['start'], 'end' => $schedule[count($schedule) - 1]['end']]);
                $id = $db->lastInsertRowID();
            }
            foreach ($state['sessions'] as $slot) {
                if ($slot['action'] === 'skip') { continue; }
                $sessionId = (int) ($slot['record']['id'] ?? 0);
                if ($slot['action'] === 'create') {
                    self::sql($db, 'INSERT INTO event_session (event_id, name, start_date, end_date) VALUES (:event, :name, :start, :end)',
                        ['event' => $id, 'name' => $slot['name'], 'start' => $slot['start'], 'end' => $slot['end']]);
                    $sessionId = $db->lastInsertRowID();
                }
                self::sql($db, 'INSERT INTO setup_session (event_id, slot, session_id) VALUES (:event, :slot, :session)',
                    ['event' => $id, 'slot' => $slot['slot'], 'session' => $sessionId]);
            }
            if (!$state['linked']) {
                self::sql($db, "INSERT INTO setup_event (setup_key, event_id) VALUES ('november-2026', :id)", ['id' => $id]);
            }
            $db->exec('COMMIT');
            return ['changed' => true, 'event_id' => $id];
        } catch (Throwable $error) {
            $db->exec('ROLLBACK');
            throw $error;
        }
    }

    /** Snapshot existing SQLite data, including WAL, to a server-protected directory. */
    public static function backup(SQLite3 $db, string $directory): void {
        if ((!is_dir($directory) && !mkdir($directory, 0700, true)) || is_link($directory)
            || (fileperms($directory) & 0077) !== 0) {
            throw new RuntimeException('A private backup directory is required.');
        }
        $path = $directory . '/setup-' . gmdate('Ymd-His') . '-' . bin2hex(random_bytes(8)) . '.sqlite';
        $backup = new SQLite3($path);
        try {
            chmod($path, 0600);
            if (!$db->backup($backup)) {
                throw new RuntimeException('Database backup failed.');
            }
        } finally {
            $backup->close();
        }
    }
}
