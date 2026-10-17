<?php
require_once dirname(__DIR__, 2) . '/lib/auth.php';
auth_require();
require_once dirname(__DIR__, 2) . '/lib/qr_admission.php';

$review = null;
$error = '';
$notice = '';
$token = '';
$db = null;
try {
    // Read/write existing DB only: never create a database or schema during a scan.
    $db = new SQLite3(dirname(__DIR__, 2) . '/db.sqlite', SQLITE3_OPEN_READWRITE);
    $admission = new QrAdmission($db);
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
        $submitted = $_POST['review_token'] ?? '';
        $pending = is_string($submitted) ? ($_SESSION['qr_reviews'][$submitted] ?? null) : null;
        if (!$pending || $pending['expires'] < time()) {
            throw new DomainException('La revisión caducó. Vuelve a escanear el QR.');
        }
        unset($_SESSION['qr_reviews'][$submitted]);
        $personId = $pending['person'];
        $sessionId = $pending['session'];
        try {
            $decision = $_POST['decision'] ?? '';
            if (!is_string($decision)) {
                throw new DomainException('Decisión no válida.');
            }
            $admission->decide($personId, $sessionId, $decision,
                $pending['fingerprint'], $_SESSION['auth_user']);
            $notice = $_POST['decision'] === 'allow'
                ? 'Participación permitida. Asistencia registrada.'
                : 'Participación denegada para esta sesión. No se registró asistencia.';
        } catch (DomainException $exception) {
            http_response_code(409);
            $error = $exception->getMessage();
        }
    } else {
        foreach (['fullname', 'email', 'phone'] as $field) {
            if (!isset($_GET[$field]) || !is_string($_GET[$field]) || $_GET[$field] === '') {
                throw new DomainException('El QR no contiene todos los datos necesarios.');
            }
        }
        $personId = $admission->findPerson($_GET['fullname'], $_GET['email'], $_GET['phone']);
        $sessionId = $admission->currentSession();
    }
    $review = $admission->review($personId, $sessionId);
    if (!$review['attendance']) {
        // Bind review and one-use decision to this staff session; support multiple scanner tabs.
        $_SESSION['qr_reviews'] = array_filter($_SESSION['qr_reviews'] ?? [],
            function ($item) { return $item['expires'] >= time(); });
        $_SESSION['qr_reviews'] = array_slice($_SESSION['qr_reviews'], -19, null, true);
        $token = bin2hex(random_bytes(24));
        $_SESSION['qr_reviews'][$token] = ['person' => $personId, 'session' => $sessionId,
            'fingerprint' => QrAdmission::fingerprint($review), 'expires' => time() + 600];
    }
} catch (DomainException $exception) {
    http_response_code(409);
    $error = $exception->getMessage();
} catch (Throwable $exception) {
    http_response_code(503);
    error_log('QR admission: ' . $exception->getMessage());
    $error = 'No se pudo verificar o guardar la admisión. No autorices la entrada con este resultado. Contacta al administrador.';
} finally {
    if ($db !== null) {
        $db->close();
    }
}
?>
<!doctype html>
<html lang="es">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Control de acceso · Rinchen</title>
  <link rel="stylesheet" href="<?php echo auth_escape(auth_url('static/auth.css')); ?>">
  <script defer src="<?php echo auth_escape(auth_url('static/auth-navigation.js')); ?>"></script>
</head>
<body>
<main class="auth-card">
  <p class="eyebrow">SOLO PERSONAL · CONTROL DE ACCESO</p>
  <h1>Decisión de admisión</h1>
  <?php if ($error !== ''): ?><p class="auth-error" role="alert"><?php echo auth_escape($error); ?></p><?php endif; ?>
  <?php if ($notice !== ''): ?><p role="status"><?php echo auth_escape($notice); ?></p><?php endif; ?>
  <?php if ($review !== null): ?>
    <h2><?php echo auth_escape($review['person']['fullname']); ?></h2>
    <p>Sesión: <strong><?php echo auth_escape($review['session']['name']); ?></strong></p>
    <?php if ($review['attendance']): ?>
      <p role="status"><strong>Asistencia ya registrada.</strong> No se creará un registro duplicado.</p>
    <?php else: ?>
      <?php if (($review['decision']['decision'] ?? '') === 'deny'): ?>
        <p class="auth-error" role="alert"><strong>Participación denegada para esta sesión.</strong>
          No permitir el ingreso salvo que el personal revise y cambie esta decisión.</p>
      <?php endif; ?>
      <?php if ($review['missing']): ?>
        <section class="auth-warning" aria-label="Advertencias">
          <h2>Faltan asistencias anteriores</h2>
          <ul><?php foreach ($review['missing'] as $missing): ?>
            <li><?php echo auth_escape($missing['name']); ?></li>
          <?php endforeach; ?></ul>
          <p>Esta advertencia no bloquea el ingreso automáticamente. El personal decide si permite o deniega la participación.</p>
        </section>
      <?php else: ?><p>No faltan asistencias a sesiones anteriores.</p><?php endif; ?>
      <form method="post" action="<?php echo auth_escape(auth_url('templates/attendance/log.php')); ?>">
        <?php auth_csrf_field(); ?>
        <input type="hidden" name="review_token" value="<?php echo auth_escape($token); ?>">
        <button type="submit" name="decision" value="allow"><?php echo ($review['decision']['decision'] ?? '') === 'deny' ? 'Cambiar decisión y permitir participación' : 'Permitir participación'; ?></button>
        <button class="auth-deny" type="submit" name="decision" value="deny">Denegar participación</button>
      </form>
    <?php endif; ?>
  <?php endif; ?>
  <a href="<?php echo auth_escape(auth_url('templates/index.php')); ?>">Volver sin otra decisión</a>
  <footer class="auth-footnote">Desarrollado por <a href="https://scistitch.com/" target="_blank" rel="noopener noreferrer">SciStitch</a></footer>
</main>
</body>
</html>
