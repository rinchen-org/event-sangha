<?php

/**
 * Read-only data for the React views. Called from each existing list route so
 * deployments retain their existing route-level access controls.
 * This never creates/migrates a database or calls QR/email services.
 *
 * @param string $resource A fixed internal resource name, never a SQL fragment.
 */
function frontend_list_response(string $resource): void {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: private, no-store');
    header('X-Content-Type-Options: nosniff');

    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
        http_response_code(405);
        header('Allow: GET');
        echo json_encode(['success' => false, 'message' => 'Method not allowed']);
        return;
    }

    $queries = [
        'events' => 'SELECT id, name, description, start_date, end_date FROM event ORDER BY start_date DESC',
        'sessions' => 'SELECT s.id, s.event_id, e.name AS event_name, s.name, s.start_date, s.end_date
            FROM event_session s JOIN event e ON e.id = s.event_id ORDER BY s.start_date',
        'subscriptions' => 'SELECT s.id, s.person_id, p.fullname, p.email, p.phone, s.active, s.datetime
            FROM subscription s JOIN person p ON p.id = s.person_id ORDER BY p.fullname',
        'attendance' => 'SELECT a.id, a.person_id, p.fullname, e.name AS event_name,
            s.name AS session_name, a.event_session_id, a.log_time
            FROM attendance a JOIN person p ON p.id = a.person_id
            JOIN event_session s ON s.id = a.event_session_id
            JOIN event e ON e.id = s.event_id ORDER BY a.log_time DESC',
    ];

    try {
        $databasePath = dirname(__DIR__) . '/db.sqlite';
        if (!isset($queries[$resource]) || !is_file($databasePath)) {
            throw new RuntimeException('Frontend database is unavailable.');
        }
        $db = new SQLite3($databasePath, SQLITE3_OPEN_READONLY);
        $db->enableExceptions(true);
        $result = $db->query($queries[$resource]);
        if ($result === false) {
            throw new RuntimeException('Frontend query failed.');
        }
        $rows = [];
        while (($row = $result->fetchArray(SQLITE3_ASSOC)) !== false) {
            $rows[] = $row;
        }
        $result->finalize();
        $db->close();
        echo json_encode(['success' => true, 'data' => $rows], JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE);
    } catch (Throwable $error) {
        error_log('Rinchen frontend: ' . $error->getMessage());
        http_response_code(503);
        echo json_encode(['success' => false, 'message' => 'No se pudieron cargar los datos.']);
    }
}
