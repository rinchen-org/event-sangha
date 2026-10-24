<?php
require_once dirname(__DIR__, 2) . '/lib/auth.php';
auth_require();
require_once dirname(__DIR__, 2) . '/lib/event_registration.php';
$event = null;
$review = null;
$candidates = [];
$error = '';
$notice = '';
$token = '';
try {
    $eventId = EventRegistration::id($_POST['event_id'] ?? ($_GET['event_id'] ?? null));
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    $action = $_POST['action'] ?? 'review';
    $db = new SQLite3(dirname(__DIR__, 2) . '/db.sqlite',
        $method === 'POST' && $action === 'confirm' ? SQLITE3_OPEN_READWRITE : SQLITE3_OPEN_READONLY);
    try {
        $registrations = new EventRegistration($db);
        $event = $registrations->event($eventId);
        $candidates = $registrations->candidates();
        if ($method === 'POST') {
            if ($action === 'confirm' || $action === 'cancel') {
                $pending = $_SESSION['event_registration_review'] ?? null;
                unset($_SESSION['event_registration_review']);
                if (!is_array($pending) || $pending['expires'] < time()
                    || !is_string($_POST['review_token'] ?? null) || !hash_equals($pending['token'], $_POST['review_token'])
                    || $pending['review']['event']['id'] !== $eventId) {
                    throw new DomainException('La revisión caducó, cambió de evento o ya se utilizó. Revisa nuevamente.');
                }
                if ($action === 'cancel') {
                    $notice = 'Vinculación cancelada. No se modificaron registros.';
                } else {
                    $notes = $_POST['notes'] ?? '';
                    if (!is_string($notes) || strlen($notes) > 2000 || !preg_match('//u', $notes)) {
                        throw new DomainException('La nota opcional debe ser texto UTF-8 de hasta 2000 bytes.');
                    }
                    $saved = $registrations->confirmExisting($pending['review'], $_SESSION['auth_user'], trim($notes));
                    $notice = $saved ? 'Inscripción vinculada al evento. No se generó otro QR ni se enviaron correos.'
                        : 'La persona ya estaba inscrita en este evento. No se duplicó el registro.';
                }
            } elseif ($action === 'review') {
                unset($_SESSION['event_registration_review']);
                $review = $registrations->reviewExisting($eventId, EventRegistration::id($_POST['subscription_id'] ?? null));
                $token = bin2hex(random_bytes(24));
                $_SESSION['event_registration_review'] = ['token' => $token, 'expires' => time() + 600, 'review' => $review];
            } else { throw new DomainException('Acción no válida.'); }
        }
    } finally { $db->close(); }
} catch (DomainException $exception) {
    http_response_code(409);
    $error = $exception->getMessage();
} catch (Throwable $exception) {
    http_response_code(503);
    error_log('Event registration: ' . $exception->getMessage());
    $error = 'No se pudo verificar o guardar el vínculo por un fallo técnico. No se confirma ningún cambio.';
}
include dirname(__DIR__) . '/header.php';
?>
<h1>Vincular inscripción existente</h1>
<?php if ($error !== ''): ?><p class="alert alert-danger" role="alert"><?php echo auth_escape($error); ?></p><?php endif; ?>
<?php if ($notice !== ''): ?><p class="alert alert-info" role="status"><?php echo auth_escape($notice); ?></p><?php endif; ?>
<?php if ($event !== null): ?>
<h2><?php echo auth_escape($event['name']); ?></h2>
<p>Este vínculo solo añade a la persona a la lista del evento. No cambia su estado general, su QR ni las reglas de admisión.</p>
<?php if ($review !== null && $error === ''): ?>
<section aria-label="Revisión del vínculo">
    <h3><?php echo auth_escape($review['person']['fullname']); ?></h3>
    <p><?php echo auth_escape($review['person']['email']); ?></p>
    <?php foreach ($review['warnings'] as $warning): ?><p class="alert alert-warning" role="alert"><?php echo auth_escape($warning['message']); ?></p><?php endforeach; ?>
    <form method="post" action="<?php echo auth_escape(auth_url('templates/event/register.php')); ?>">
        <?php auth_csrf_field(); ?>
        <input type="hidden" name="event_id" value="<?php echo (int) $event['id']; ?>">
        <input type="hidden" name="review_token" value="<?php echo auth_escape($token); ?>">
        <label>Nota opcional <textarea name="notes" maxlength="2000"></textarea></label>
        <button name="action" value="confirm" class="btn btn-primary"><?php echo $review['warnings'] ? 'Continuar de todos modos y vincular' : 'Confirmar vínculo'; ?></button>
        <button name="action" value="cancel" class="btn btn-secondary">Cancelar</button>
    </form>
</section>
<?php else: ?>
<form method="post" action="<?php echo auth_escape(auth_url('templates/event/register.php')); ?>">
    <?php auth_csrf_field(); ?>
    <input type="hidden" name="event_id" value="<?php echo (int) $event['id']; ?>">
    <label for="existing-subscription">Inscripción general</label>
    <select id="existing-subscription" name="subscription_id" required class="form-select">
        <option value="">Selecciona una inscripción</option>
        <?php foreach ($candidates as $candidate): ?><option value="<?php echo (int) $candidate['id']; ?>"><?php echo auth_escape($candidate['fullname'] . ' · ' . $candidate['email'] . ' · #' . $candidate['id']); ?></option><?php endforeach; ?>
    </select>
    <button name="action" value="review" class="btn btn-primary my-3">Revisar vínculo</button>
</form>
<?php endif; ?>
<a class="btn btn-secondary my-3" href="<?php echo auth_escape(auth_url('templates/index.php#eventos/' . (int) $event['id'] . '/participantes')); ?>">Volver al evento</a>
<?php endif; ?>
<?php include dirname(__DIR__) . '/footer.php'; ?>
