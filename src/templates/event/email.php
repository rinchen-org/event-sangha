<?php
require_once dirname(__DIR__, 2) . '/lib/auth.php';
auth_require();
require_once dirname(__DIR__, 2) . '/lib/event_email.php';

$event = null;
$review = null;
$results = null;
$history = [];
$error = '';
$notice = '';
$token = '';
$previewHtml = '';
$statusLabels = ['pending' => 'Reservado; resultado sin confirmar', 'accepted' => 'Aceptado por el transporte (entrega no confirmada)',
    'unconfirmed' => 'Envío sin confirmar; revisar antes de repetir'];
try {
    $eventId = EventRegistration::id($_POST['event_id'] ?? ($_GET['event_id'] ?? null));
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    $action = $_POST['action'] ?? 'review';
    $db = new SQLite3(dirname(__DIR__, 2) . '/db.sqlite',
        $method === 'POST' && $action === 'confirm' ? SQLITE3_OPEN_READWRITE : SQLITE3_OPEN_READONLY);
    try {
        $mailer = new EventEmail($db);
        $event = (new EventRegistration($db))->event($eventId);
        if ($method === 'POST') {
            if ($action === 'confirm' || $action === 'cancel') {
                $pending = $_SESSION['event_email_review'] ?? null;
                unset($_SESSION['event_email_review']);
                if (!is_array($pending) || $pending['expires'] < time()
                    || !is_string($_POST['review_token'] ?? null) || !hash_equals($pending['token'], $_POST['review_token'])
                    || $pending['review']['event']['id'] !== $eventId) {
                    throw new DomainException('La revisión caducó, cambió de evento o ya se utilizó. Revisa nuevamente; no se reintentó el envío.');
                }
                if ($action === 'cancel') {
                    $notice = 'Envío cancelado. No se enviaron correos ni se modificaron registros.';
                } else {
                    $notes = $_POST['notes'] ?? '';
                    if (!is_string($notes)) { throw new DomainException('La nota opcional no es válida.'); }
                    $results = $mailer->send($pending['review'], $pending['token'], $_SESSION['auth_user'], trim($notes));
                }
            } elseif ($action === 'review') {
                unset($_SESSION['event_email_review']);
                $mode = $_POST['mode'] ?? '';
                if (!is_string($mode)) { throw new DomainException('La selección no es válida.'); }
                $ids = $mode === 'selected' ? EventEmail::selectedIds($_POST['subscription_ids'] ?? null) : [];
                $review = $mailer->review($eventId, $mode, $ids);
                $token = bin2hex(random_bytes(24));
                $_SESSION['event_email_review'] = ['token' => $token, 'expires' => time() + 600, 'review' => $review];
                $previewHtml = '<meta http-equiv="Content-Security-Policy" content="default-src \'none\'; style-src \'unsafe-inline\'">'
                    . str_replace('<QR>', '<p>[QR personal de cada destinatario, incluido en el correo]</p>', EventEmail::template());
            } else { throw new DomainException('Acción de correo no válida.'); }
        }
    } finally { $db->close(); }
} catch (DomainException $exception) {
    http_response_code(409);
    $error = $exception->getMessage();
    $review = null;
} catch (Throwable $exception) {
    http_response_code(503);
    error_log('Event email controller: ' . $exception->getMessage());
    $review = null;
    $error = 'Fallo técnico. El envío no se confirma ni se reintentará automáticamente. Revisa el historial antes de iniciar otro envío.';
}
// Include durable claims even when transport/outcome persistence was interrupted.
if ($event !== null) {
    try {
        $db = new SQLite3(dirname(__DIR__, 2) . '/db.sqlite', SQLITE3_OPEN_READONLY);
        try { $history = (new EventEmail($db))->history((int) $event['id']); }
        finally { $db->close(); }
    } catch (Throwable $exception) {
        http_response_code(503);
        $error = 'No se pudo leer el historial de correos. No repitas un envío sin verificar sus resultados.';
        $review = null;
    }
}
include dirname(__DIR__) . '/header.php';
?>
<h1>Enviar plantilla a participantes</h1>
<?php if ($error !== ''): ?><p class="alert alert-danger" role="alert"><?php echo auth_escape($error); ?></p><?php endif; ?>
<?php if ($notice !== ''): ?><p class="alert alert-info" role="status"><?php echo auth_escape($notice); ?></p><?php endif; ?>
<?php if ($event !== null): ?>
<h2><?php echo auth_escape($event['name']); ?></h2>
<p>Importar no envía correos. Esta acción separada envía un mensaje individual a cada destinatario, sin CC ni otros destinatarios ocultos.
El QR privado se incluye en el mensaje; no requiere que el participante inicie sesión.</p>
<?php if ($review !== null): ?>
<section aria-label="Revisión del envío">
    <h3>Revisar <?php echo count($review['recipients']); ?> destinatario(s) antes de enviar</h3>
    <p>Asunto: <?php echo auth_escape(EventEmail::SUBJECT); ?><br>Remitente: <?php echo auth_escape($review['config']['from']); ?></p>
    <p class="alert alert-warning">La plantilla actual contiene información del encuentro de Ngöndro del 12 al 15 de noviembre de 2026.
        Revisa que corresponda a este evento. No se adapta automáticamente a otros eventos.</p>
    <iframe title="Vista previa de la plantilla" sandbox="" srcdoc="<?php echo auth_escape($previewHtml); ?>" style="width:100%;height:450px;border:1px solid #ddd"></iframe>
    <ul><?php foreach ($review['recipients'] as $recipient): ?>
        <li><?php echo auth_escape($recipient['fullname'] . ' · ' . $recipient['email']); ?>
            <?php foreach ($recipient['warnings'] as $warning): ?><p class="alert alert-warning" role="alert"><?php echo auth_escape($warning['message']); ?></p><?php endforeach; ?>
        </li>
    <?php endforeach; ?></ul>
    <p>No se ha enviado nada. Puedes cancelar o confirmar. La aceptación del transporte no garantiza la entrega; nunca se reintenta automáticamente.</p>
    <form method="post" action="<?php echo auth_escape(auth_url('templates/event/email.php')); ?>">
        <?php auth_csrf_field(); ?>
        <input type="hidden" name="event_id" value="<?php echo (int) $event['id']; ?>">
        <input type="hidden" name="review_token" value="<?php echo auth_escape($token); ?>">
        <label>Nota opcional <textarea name="notes" maxlength="2000"></textarea></label>
        <button name="action" value="confirm" class="btn btn-primary">Confirmar y enviar</button>
        <button name="action" value="cancel" class="btn btn-secondary">Cancelar envío</button>
    </form>
</section>
<?php elseif ($results !== null): ?>
<p role="status">Proceso finalizado: <?php echo count($results); ?> destinatario(s). Revisa el resultado individual a continuación. La entrega no está confirmada.</p>
<?php else: ?><p>Selecciona participantes o «Enviar a todos» en la lista del evento para revisar un nuevo envío.</p><?php endif; ?>
<h3>Historial de intentos de este evento</h3>
<p>Solo incluye los envíos iniciados desde esta pantalla. Los correos históricos o reenviados desde la lista general no aparecen aquí.</p>
<p>Las reservas sin resultado pueden corresponder a una interrupción antes o después del envío. Verifica antes de repetir. La cuenta admin es compartida, no identifica a una persona del equipo.</p>
<div class="table-responsive"><table class="table table-striped"><thead><tr><th>Participante</th><th>Correo</th><th>Fecha (UTC)</th><th>Resultado</th></tr></thead><tbody>
<?php foreach ($history as $attempt): ?><tr><td><?php echo auth_escape($attempt['fullname']); ?></td><td><?php echo auth_escape($attempt['email']); ?></td>
<td><?php echo auth_escape($attempt['attempted_at']); ?></td><td><?php echo auth_escape($statusLabels[$attempt['status']] ?? 'Resultado desconocido'); ?></td></tr><?php endforeach; ?>
</tbody></table></div>
<a class="btn btn-secondary my-3" href="<?php echo auth_escape(auth_url('templates/index.php#participantes/' . (int) $event['id'])); ?>">Volver a participantes</a>
<?php endif; ?>
<?php include dirname(__DIR__) . '/footer.php'; ?>
