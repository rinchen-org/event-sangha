<?php
require_once dirname(__DIR__, 2) . '/lib/auth.php';
auth_require();
if (!in_array($_SERVER['REQUEST_METHOD'] ?? 'GET', ['GET', 'HEAD'], true)) {
    header('Allow: GET, HEAD');
    auth_fail(405, 'La vista de impresión es de solo lectura.');
}
require_once dirname(__DIR__, 2) . '/lib/event_registration.php';
require_once dirname(__DIR__, 2) . '/lib/frontend.php';
require_once dirname(__DIR__, 2) . '/lib/layout.php';

$scope = $_GET['scope'] ?? 'event';
if (!is_string($scope) || !in_array($scope, ['event', 'general'], true)
    || ($scope === 'general' && array_key_exists('event_id', $_GET))) {
    auth_fail(400, 'Selecciona un evento o la vista general para imprimir; no mezcles ambos contextos.');
}
$eventId = null;
if ($scope === 'event') {
    try { $eventId = EventRegistration::id($_GET['event_id'] ?? null); }
    catch (DomainException $exception) { auth_fail(400, 'Selecciona un evento válido antes de imprimir.'); }
}
$event = null;
$records = [];
$error = '';
try {
    $db = new SQLite3(dirname(__DIR__, 2) . '/db.sqlite', SQLITE3_OPEN_READONLY);
    try {
        $registrations = new EventRegistration($db);
        if ($eventId !== null) {
            $event = $registrations->event($eventId);
            $records = $registrations->roster($eventId);
        } else {
            $records = $registrations->rows('SELECT s.id, s.person_id, p.fullname, p.email, p.phone,
                s.active, s.qr FROM subscription s JOIN person p ON p.id=s.person_id ORDER BY p.fullname');
        }
    } finally { $db->close(); }
    $records = array_map('frontend_subscription_record', $records);
} catch (DomainException $exception) {
    http_response_code(404);
    $error = $exception->getMessage();
} catch (Throwable $exception) {
    http_response_code(503);
    error_log('Participant print: ' . $exception->getMessage());
    $error = 'No se pudo cargar la lista completa por un fallo técnico. No se generaron QR ni se modificaron registros.';
}
$title = $event === null ? 'Inscripciones generales (histórico)' : $event['name'];
$back = 'templates/index.php#participantes/' . ($eventId === null ? 'todos' : $eventId);
AppLayout::start('Lista de participantes', 'participantes', compact: true,
    styles: ['static/participants-print.css'], scripts: ['static/participants-print.js']);
?>
<section class="participant-print">
<?php if ($error !== ''): ?>
    <h1>No se pudo preparar la impresión</h1>
    <p class="alert alert-danger" role="alert"><?php echo auth_escape($error); ?></p>
    <a href="<?php echo auth_escape(auth_url($back)); ?>">Volver a participantes</a>
<?php else: ?>
    <header class="print-heading">
        <img class="print-logo" src="<?php echo auth_escape(auth_url('static/images/logo-head-square.png')); ?>" alt="Sakya Rinchen Ling" width="52" height="52">
        <div><p>Sakya Rinchen Ling · La Paz, Bolivia</p><h1>Lista completa de participantes</h1><h2><?php echo auth_escape($title); ?></h2>
            <p><?php echo count($records); ?> participantes · Generado el <?php echo (new DateTimeImmutable('now', new DateTimeZone('America/La_Paz')))->format('d/m/Y H:i'); ?> (Bolivia)</p></div>
    </header>
    <p class="print-context"><?php echo $eventId === null ? 'Vista histórica general: no representa la lista de un evento.' : 'Solo incluye las inscripciones vinculadas explícitamente a este evento.'; ?>
        Incluye estados activos e inactivos, sin filtros ni paginación. El estado es general, no una decisión de admisión.</p>
    <p class="print-context">Uso interno del equipo. Imprimir no genera QR, no envía correos ni cambia el evento configurado para admisión. Los códigos conservan su destino original; revisa las advertencias antes de usarlos.</p>
    <div class="print-actions">
        <button id="participants-print-button" type="button" disabled hidden>Imprimir / Guardar PDF</button>
        <a href="<?php echo auth_escape(auth_url($back)); ?>">Volver a participantes</a>
        <p id="participants-print-status" role="status">Verifica que los códigos estén cargados antes de imprimir.</p>
        <p>También puedes usar el menú Imprimir del navegador (Ctrl+P o ⌘P).</p>
    </div>
    <?php if (!$records): ?><p>No hay participantes registrados en esta lista.</p><?php else: ?>
    <table class="participant-print-table">
        <thead><tr><th scope="col">N.º</th><th scope="col">Participante</th><th scope="col">Contacto</th><th scope="col">Estado general</th><th scope="col">Código QR</th></tr></thead>
        <tbody>
        <?php foreach ($records as $index => $record): $qr = $record['qr_preview']; ?>
        <tr data-participant-row>
            <td><?php echo $index + 1; ?></td>
            <th scope="row"><?php echo auth_escape($record['fullname'] ?? 'Nombre no disponible'); ?><small>Inscripción #<?php echo (int) $record['id']; ?></small></th>
            <td><?php echo auth_escape($record['email'] ?? ''); ?><small><?php echo auth_escape($record['phone'] ?? ''); ?></small></td>
            <td><?php echo $record['active'] === 1 ? 'Activa' : ($record['active'] === 0 ? 'Inactiva' : 'Sin datos'); ?></td>
            <td class="print-qr">
                <?php if ($qr['url'] !== null): ?>
                    <img data-participant-qr src="<?php echo auth_escape($qr['url']); ?>" alt="Código QR de <?php echo auth_escape($record['fullname'] ?? 'participante'); ?>" width="180" height="180" loading="eager">
                    <span data-qr-fallback class="print-qr-error" hidden>QR no disponible en esta vista. Revisa la imagen o tu sesión antes de imprimir.</span>
                <?php else: ?><span data-qr-missing class="print-qr-error"><?php echo auth_escape($qr['error'] ?? 'QR no disponible.'); ?></span><?php endif; ?>
                <?php foreach ($qr['warnings'] as $warning): ?><small class="print-qr-warning"><?php echo auth_escape($warning['message']); ?></small><?php endforeach; ?>
            </td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <?php endif; ?>
<?php endif; ?>
</section>
<?php AppLayout::end(); ?>
