<?php
require_once dirname(__DIR__, 2) . '/lib/auth.php';
auth_require();
require_once dirname(__DIR__, 2) . '/lib/qr_admission.php';

$review = null;
$error = '';
$notice = '';
$token = '';
$db = null;
$autoSubmit = false;
$attendanceSaved = false;
$showSessionReview = false;
$notes = '';
$isPost = ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST';
$isLab = defined('RINCHEN_QR_LAB');
if ($isLab) {
    require_once dirname(__DIR__, 2) . '/lib/test_lab.php';
    test_mode_require();
}
$scope = $isLab ? 'lab' : 'live';
$reviewBucket = $isLab ? 'lab_qr_reviews' : 'qr_reviews';
$actionPath = $isLab ? 'templates/testing/check-in.php' : 'templates/attendance/log.php';
try {
    // Scans cannot write, even on the automatic path. Only the later protected
    // POST can write to the existing DB; neither request creates/migrates it.
    $db = $isLab ? test_lab_db($isPost) : new SQLite3(dirname(__DIR__, 2) . '/db.sqlite', $isPost ? SQLITE3_OPEN_READWRITE : SQLITE3_OPEN_READONLY);
    $admission = new QrAdmission($db);
    if ($isPost) {
        $submitted = $_POST['review_token'] ?? '';
        $pending = is_string($submitted) ? ($_SESSION[$reviewBucket][$submitted] ?? null) : null;
        if (!$pending || $pending['expires'] < time()) {
            throw new DomainException('La revisión caducó. Vuelve a escanear el QR.');
        }
        unset($_SESSION[$reviewBucket][$submitted]);
        if (($pending['scope'] ?? 'live') !== $scope) { throw new DomainException('Esta revisión pertenece a otro entorno. Vuelve a abrir el QR correcto.'); }
        $personId = $pending['person'];
        $sessionId = $pending['session'];
        if (($pending['event'] ?? null) !== $admission->eventId()) {
            throw new DomainException('El evento QR cambió desde la revisión. Vuelve a escanear el QR.');
        }
        try {
            $decision = $_POST['decision'] ?? '';
            if (!is_string($decision) || !in_array($decision, ['allow', 'deny', 'review', 'automatic'], true)) {
                throw new DomainException('Decisión no válida.');
            }
            $selected = $_POST['selected_session_id'] ?? (string) $sessionId;
            $selectedId = is_string($selected) ? filter_var($selected, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) : false;
            if ($selectedId === false) { throw new DomainException('Selecciona una sesión válida.'); }
            $notes = $_POST['notes'] ?? '';
            if (!is_string($notes) || strlen($notes) > 4000) { $notes = ''; throw new DomainException('La nota no es válida o es demasiado larga.'); }
            if ($decision === 'review' || $selectedId !== $sessionId) {
                if ($decision === 'automatic') { throw new DomainException('La sesión cambió; se requiere revisión del personal.'); }
                // Selection only refreshes the review. A changed dropdown must
                // never authorize a different session with the old fingerprint.
                $admission->review($personId, $selectedId);
                $sessionId = $selectedId;
                $showSessionReview = true;
                $notice = 'Sesión seleccionada. Revisa las advertencias antes de decidir; todavía no se registró asistencia.';
                if ($decision !== 'review') { http_response_code(409); }
            } else {
                $automatic = $decision === 'automatic';
                if ($automatic && empty($pending['automatic'])) {
                    throw new DomainException('Esta revisión requiere una decisión explícita del personal.');
                }
                $admission->decide($personId, $sessionId, $automatic ? 'allow' : $decision,
                    $pending['fingerprint'], $_SESSION['auth_user'], $automatic, $notes);
                $attendanceSaved = $automatic || $decision === 'allow';
                $notice = $automatic ? 'Asistencia registrada automáticamente para la sesión indicada.'
                    : ($decision === 'allow' ? 'Participación permitida. Asistencia registrada.'
                        : 'Participación denegada para esta sesión. No se registró asistencia.');
            }
        } catch (DomainException $exception) {
            http_response_code(409);
            $error = $exception->getMessage();
        }
    } elseif ($isLab) {
        $uuid = $_GET['uuid'] ?? '';
        if (!is_string($uuid)) { throw new DomainException('Identificador de prueba no válido.'); }
        $personId = (int) (new TestLab($db))->attendee($uuid)['person_id'];
        $sessionId = $admission->selection()['recommended_id'];
    } else {
        foreach (['fullname', 'email', 'phone'] as $field) {
            if (!isset($_GET[$field]) || !is_string($_GET[$field]) || $_GET[$field] === '') {
                throw new DomainException('El QR no contiene todos los datos necesarios.');
            }
        }
        $personId = $admission->findPerson($_GET['fullname'], $_GET['email'], $_GET['phone']);
        $sessionId = $admission->selection()['recommended_id'];
    }
    $review = $admission->review($personId, $sessionId);
    $autoSubmit = ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET' && $review['automatic_allowed'];
    // Selection gets its own fresh review, including when a previous session's
    // attendance already exists. POST responses never auto-submit or retry.
    $_SESSION[$reviewBucket] = array_filter($_SESSION[$reviewBucket] ?? [],
        function ($item) { return $item['expires'] >= time(); });
    $_SESSION[$reviewBucket] = array_slice($_SESSION[$reviewBucket], -19, null, true);
    $token = bin2hex(random_bytes(24));
    $_SESSION[$reviewBucket][$token] = ['person' => $personId, 'session' => $sessionId,
        'scope' => $scope,
        'event' => (int) $review['session']['event_id'], 'automatic' => $autoSubmit,
        'fingerprint' => QrAdmission::fingerprint($review), 'expires' => time() + 600];
} catch (DomainException $exception) {
    http_response_code(409);
    $error = $exception->getMessage();
} catch (Throwable $exception) {
    http_response_code(503);
    $review = null;
    $autoSubmit = false;
    $notice = '';
    error_log('QR admission: ' . $exception->getMessage());
    $error = 'No se pudo verificar o guardar la admisión. No autorices la entrada con este resultado. Contacta al administrador.';
} finally {
    if ($db !== null) {
        $db->close();
    }
}
$sessionLabel = static function (array $session): string {
    $zone = new DateTimeZone('America/La_Paz');
    $start = (new DateTimeImmutable('@' . $session['start_timestamp']))->setTimezone($zone);
    $end = (new DateTimeImmutable('@' . $session['end_timestamp']))->setTimezone($zone);
    return $session['name'] . ' · ' . $start->format('d/m/Y H:i') . ' — ' . $end->format('d/m/Y H:i');
};
?>
<!doctype html>
<html lang="es">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Control de acceso · Rinchen</title>
  <link rel="stylesheet" href="<?php echo auth_escape(auth_url('static/auth.css')); ?>">
  <script defer src="<?php echo auth_escape(auth_url('static/auth-navigation.js')); ?>"></script>
  <script defer src="<?php echo auth_escape(auth_url('static/qr-checkin.js?v=2')); ?>"></script>
</head>
<body>
<main class="auth-card">
  <?php if ($isLab): ?><p class="auth-warning">SOLO PRUEBAS · Esta asistencia se guarda en el laboratorio, nunca en el evento real.</p><?php endif; ?>
  <p class="eyebrow">SOLO PERSONAL · CONTROL DE ACCESO</p>
  <h1>Registro de asistencia</h1>
  <?php if ($error !== ''): ?><p class="auth-error" role="alert"><?php echo auth_escape($error); ?></p><?php endif; ?>
  <?php if ($notice !== '' && !$attendanceSaved): ?><p role="status"><?php echo auth_escape($notice); ?></p><?php endif; ?>
  <?php if ($review !== null): ?>
    <h2><?php echo auth_escape($review['person']['fullname']); ?></h2>
    <p>Sesión: <strong><?php echo auth_escape($sessionLabel($review['session'])); ?></strong></p>
    <?php if ($review['attendance'] && !$showSessionReview): ?>
      <p id="qr-result" class="qr-result" role="status"><strong><?php echo $attendanceSaved ? auth_escape($notice) : 'Asistencia ya registrada.'; ?></strong>
        <?php if (!$attendanceSaved): ?>No se creará un registro duplicado.<?php endif; ?>
      </p>
      <?php if (count($review['sessions']) > 1 && array_intersect(['outside_session_time', 'overlapping_sessions'], array_column($review['warnings'], 'code'))): ?>
        <details class="qr-secondary" id="qr-other-session"><summary>Otra sesión</summary>
          <p>La asistencia indicada ya está registrada. Si corresponde otro período, puedes elegirlo sin modificar este registro.</p>
          <form method="post" action="<?php echo auth_escape(auth_url($actionPath)); ?>">
            <?php auth_csrf_field(); ?>
            <input type="hidden" name="review_token" value="<?php echo auth_escape($token); ?>">
            <button class="auth-deny" name="decision" value="review" type="submit">Elegir otra sesión</button>
          </form>
        </details>
      <?php endif; ?>
    <?php elseif ($autoSubmit): ?>
      <p id="qr-auto-status" role="status">Registrando asistencia automáticamente…</p>
      <form id="qr-checkin-form" method="post" action="<?php echo auth_escape(auth_url($actionPath)); ?>" data-auto-checkin="1">
        <?php auth_csrf_field(); ?>
        <input type="hidden" name="review_token" value="<?php echo auth_escape($token); ?>">
        <input type="hidden" name="selected_session_id" value="<?php echo (int) $review['session']['id']; ?>">
        <button class="qr-auto-submit" type="submit" name="decision" value="automatic" data-auto-submit hidden>Registrar automáticamente</button>
        <noscript><p>JavaScript está desactivado. Abre la opción siguiente para registrar la asistencia manualmente.</p></noscript>
        <details class="qr-secondary" id="qr-auto-fallback"><summary>¿El registro no continúa?</summary>
          <p>Si no aparece un resultado, puedes enviar este registro manualmente. No se duplicará una asistencia ya guardada.</p>
          <button class="auth-deny" type="submit" name="decision" value="allow">Registrar asistencia</button>
        </details>
      </form>
    <?php else: ?>
    <p>Hora de Bolivia. Los horarios son orientativos, no una restricción de ingreso.</p>
    <form id="qr-checkin-form" method="post" action="<?php echo auth_escape(auth_url($actionPath)); ?>">
      <?php auth_csrf_field(); ?>
      <input type="hidden" name="review_token" value="<?php echo auth_escape($token); ?>">
      <label for="qr-session">Sesión para registrar la asistencia</label>
      <select id="qr-session" name="selected_session_id">
        <?php foreach ($review['sessions'] as $session): ?>
          <option value="<?php echo (int) $session['id']; ?>" <?php echo (int) $session['id'] === (int) $review['session']['id'] ? 'selected' : ''; ?>><?php echo auth_escape($sessionLabel($session)); ?></option>
        <?php endforeach; ?>
      </select>
      <button class="auth-deny" type="submit" name="decision" value="review" data-review-session formnovalidate>Revisar sesión seleccionada</button>
      <p>Cambiar la selección no registra asistencia. Fuera de los horarios se propone la sesión más cercana; puedes elegir otra y confirmar.</p>
    <?php if ($review['attendance']): ?>
      <p role="status"><strong>Asistencia ya registrada.</strong> No se creará un registro duplicado.</p>
    <?php else: ?>
      <?php if (($review['decision']['decision'] ?? '') === 'deny'): ?>
        <p class="auth-error" role="alert"><strong>Participación denegada para esta sesión.</strong>
          No permitir el ingreso salvo que el personal revise y cambie esta decisión.</p>
      <?php endif; ?>
      <?php if ($review['warnings']): ?>
        <section class="auth-warning" aria-label="Advertencias">
          <h2>Revisar antes de registrar</h2>
          <?php foreach ($review['warnings'] as $warning): ?>
            <p><?php echo auth_escape($warning['message']); ?></p>
          <?php endforeach; ?>
          <?php if ($review['missing']): ?><h3>Faltan asistencias anteriores</h3>
          <ul><?php foreach ($review['missing'] as $missing): ?>
            <li><?php echo auth_escape($missing['name']); ?></li>
          <?php endforeach; ?></ul><?php endif; ?>
          <p>Esta advertencia no bloquea el ingreso automáticamente. El personal decide si permite o deniega la participación.</p>
        </section>
      <?php else: ?><p>No faltan asistencias a sesiones anteriores.</p><?php endif; ?>
        <label for="qr-notes">Nota opcional</label>
        <textarea id="qr-notes" class="auth-notes" name="notes" rows="2" maxlength="1000"><?php echo auth_escape($notes); ?></textarea>
        <button type="submit" name="decision" value="allow"><?php echo ($review['decision']['decision'] ?? '') === 'deny' ? 'Cambiar decisión y permitir participación' : 'Permitir participación'; ?></button>
        <button class="auth-deny" type="submit" name="decision" value="deny">Denegar participación</button>
    <?php endif; ?>
    </form>
    <?php endif; ?>
  <?php endif; ?>
  <a href="<?php echo auth_escape(auth_url($isLab ? 'templates/testing/index.php' : 'templates/index.php')); ?>">Volver</a>
  <footer class="auth-footnote">Desarrollado por <a href="https://scistitch.com/" target="_blank" rel="noopener noreferrer">SciStitch</a></footer>
</main>
</body>
</html>
