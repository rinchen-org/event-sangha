<?php
require_once dirname(__DIR__, 2) . '/lib/auth.php';
auth_require();
require_once dirname(__DIR__, 2) . '/lib/event_registration.php';
header('Content-Type: application/json; charset=utf-8');
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    header('Allow: GET');
    auth_fail(405, 'Método no permitido.');
}
try {
    $eventId = EventRegistration::id($_GET['event_id'] ?? null);
    $db = new SQLite3(dirname(__DIR__, 2) . '/db.sqlite', SQLITE3_OPEN_READONLY);
    try {
        $data = (new EventRegistration($db))->detail($eventId);
    } finally { $db->close(); }
    echo json_encode(['success' => true, 'data' => $data], JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE);
} catch (DomainException $error) {
    http_response_code(404);
    echo json_encode(['success' => false, 'message' => $error->getMessage()]);
} catch (Throwable $error) {
    error_log('Event detail: ' . $error->getMessage());
    http_response_code(503);
    echo json_encode(['success' => false, 'message' => 'No se pudieron cargar los datos del evento.']);
}
