<?php
require_once dirname(__DIR__, 2) . '/lib/auth.php';
auth_require();
require_once dirname(__DIR__, 2) . '/lib/qr_regeneration.php';

$review = null;
$result = '';
$error = '';
$notice = '';
$token = '';
$history = [];
$subscriptionId = null;
$eventId = null;
try {
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    if ($method === 'POST') {
        $subscriptionId = EventRegistration::id($_POST['subscription_id'] ?? null);
        $eventId = isset($_POST['event_id']) ? EventRegistration::id($_POST['event_id']) : null;
        $action = $_POST['action'] ?? 'review';
        $db = new SQLite3(dirname(__DIR__, 2) . '/db.sqlite',
            $action === 'confirm' ? SQLITE3_OPEN_READWRITE : SQLITE3_OPEN_READONLY);
        try {
            $regenerator = new QrRegeneration($db);
            if ($action === 'confirm' || $action === 'cancel') {
                $pending = $_SESSION['qr_regeneration_review'] ?? null;
                unset($_SESSION['qr_regeneration_review']);
                if (!is_array($pending) || $pending['expires'] < time()
                    || !is_string($_POST['review_token'] ?? null) || !hash_equals($pending['token'], $_POST['review_token'])
                    || $pending['review']['person']['id'] !== $subscriptionId
                    || ($pending['review']['scope']['event']['id'] ?? null) !== $eventId) {
                    throw new DomainException('La revisión caducó, cambió de participante/evento o ya se utilizó. No se reintentó la regeneración.');
                }
                if ($action === 'cancel') {
                    $notice = 'Regeneración cancelada. No se generó ningún QR, no se enviaron correos ni se modificaron registros.';
                } else {
                    $notes = $_POST['notes'] ?? '';
                    if (!is_string($notes)) { throw new DomainException('La nota opcional no es válida.'); }
                    $result = $regenerator->regenerate($pending['review'], $pending['token'], $_SESSION['auth_user'], trim($notes));
                }
            } elseif ($action === 'review') {
                unset($_SESSION['qr_regeneration_review']);
                $review = $regenerator->review($subscriptionId, $eventId);
                $token = bin2hex(random_bytes(24));
                $_SESSION['qr_regeneration_review'] = ['token' => $token, 'expires' => time() + 600, 'review' => $review];
            } else { throw new DomainException('Acción de regeneración no válida.'); }
            $history = $regenerator->history($subscriptionId);
        } finally { $db->close(); }
    }
} catch (DomainException $exception) {
    http_response_code(409);
    $error = $exception->getMessage();
    $review = null;
    $result = '';
} catch (Throwable $exception) {
    http_response_code(503);
    error_log('QR regeneration controller: ' . $exception->getMessage());
    $review = null;
    $result = '';
    $error = 'Fallo técnico. No se confirma la regeneración ni se reintentará automáticamente. Vuelve a la lista y verifica el QR antes de iniciar otra acción. No se enviaron correos.';
}
// Read durable outcomes after an interrupted action too. Never retry to obtain a result.
if ($subscriptionId !== null && $error !== '') {
    try {
        $db = new SQLite3(dirname(__DIR__, 2) . '/db.sqlite', SQLITE3_OPEN_READONLY);
        try { $history = (new QrRegeneration($db))->history($subscriptionId); }
        finally { $db->close(); }
    } catch (Throwable $exception) { /* Keep the original failure; no action is retried. */ }
}
require_once dirname(__DIR__, 2) . '/lib/layout.php';
AppLayout::start('Regenerar QR', 'participantes');
$back = 'templates/index.php#' . ($eventId === null ? 'participantes' : 'eventos/' . $eventId . '/participantes');
$statusLabels = ['pending' => 'Sin resultado confirmado; verificar antes de repetir', 'completed' => 'QR actualizado', 'failed' => 'No se reemplazó el QR'];
?>
<h1>Regenerar QR</h1>
<?php if ($error !== ''): ?><p class="alert alert-danger" role="alert"><?php echo auth_escape($error); ?></p><?php endif; ?>
<?php if ($notice !== ''): ?><p class="alert alert-info" role="status"><?php echo auth_escape($notice); ?></p><?php endif; ?>
<?php if ($review !== null): ?>
<section aria-label="Revisión de regeneración QR">
    <h2><?php echo auth_escape($review['person']['fullname']); ?></h2>
    <p><?php echo auth_escape($review['person']['email'] . ' · ' . $review['person']['phone']); ?></p>
    <?php if ($review['scope'] !== null): ?><p>Desde el evento: <?php echo auth_escape($review['scope']['event']['name']); ?></p><?php endif; ?>
    <p>Dirección del nuevo QR (HOST_ADDRESS): <strong><?php echo auth_escape($review['base']); ?></strong></p>
    <p>Se enviarán el nombre, correo y teléfono al servicio externo api.qrserver.com para generar la imagen.
        Solo se actualizará el QR de esta inscripción; no se enviará ningún correo. El envío de la plantilla sigue siendo una acción separada.</p>
    <p>Este QR es compartido entre los eventos de la persona, no es un QR exclusivo de este evento.
        No cambia el evento configurado para admisión, la activación ni las asistencias. Las imágenes y enlaces anteriores no se borran ni se revocan.</p>
    <?php foreach ($review['warnings'] as $warning): ?><p class="alert alert-warning" role="alert"><?php echo auth_escape($warning['message']); ?></p><?php endforeach; ?>
    <p>No se ha generado nada todavía. Verifica la dirección antes de confirmar.</p>
    <form method="post" action="<?php echo auth_escape(auth_url('templates/subscription/regenerate_qr.php')); ?>">
        <?php auth_csrf_field(); ?>
        <input type="hidden" name="subscription_id" value="<?php echo $subscriptionId; ?>">
        <?php if ($eventId !== null): ?><input type="hidden" name="event_id" value="<?php echo $eventId; ?>"><?php endif; ?>
        <input type="hidden" name="review_token" value="<?php echo auth_escape($token); ?>">
        <label>Nota opcional <textarea name="notes" maxlength="2000"></textarea></label>
        <button name="action" value="confirm" class="btn btn-primary">Confirmar y regenerar QR</button>
        <button name="action" value="cancel" class="btn btn-secondary">Cancelar</button>
    </form>
</section>
<?php elseif ($result !== ''): ?>
<p class="alert alert-success" role="status">QR actualizado. No se envió ningún correo. Puedes enviar la plantilla por separado desde la lista de participantes.</p>
<p><img src="<?php echo auth_escape($result); ?>" alt="Nuevo código QR personal" width="300" height="300"></p>
<p><a href="<?php echo auth_escape($result); ?>">Abrir el nuevo QR</a></p>
<?php elseif ($notice === '' && $error === ''): ?><p>Selecciona «Regenerar QR» junto a un participante en la lista para revisar esta acción.</p><?php endif; ?>
<?php if ($history): ?>
<h2>Últimos intentos de esta inscripción</h2>
<p>La cuenta admin es compartida, no identifica a una persona del equipo. No se reintenta ningún intento automáticamente.</p>
<table class="table"><thead><tr><th>Fecha (UTC)</th><th>Resultado</th></tr></thead><tbody>
<?php foreach ($history as $attempt): ?><tr><td><?php echo auth_escape($attempt['requested_at']); ?></td><td><?php echo auth_escape($statusLabels[$attempt['status']] ?? 'Resultado desconocido'); ?></td></tr><?php endforeach; ?>
</tbody></table>
<?php endif; ?>
<a class="btn btn-secondary my-3" href="<?php echo auth_escape(auth_url($back)); ?>">Volver a participantes</a>
<?php AppLayout::end(); ?>
