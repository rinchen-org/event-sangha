<?php
require_once __DIR__ . '/schema.php';
require_once __DIR__ . '/datetime.php';
auth_block_direct(__FILE__);

class EventSetup
{
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
