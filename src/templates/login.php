<?php
require_once dirname(__DIR__) . '/lib/auth.php';
auth_start();
$next = auth_safe_next($_POST['next'] ?? ($_GET['next'] ?? ''));
if (auth_logged_in()) {
    header('Location: ' . auth_url($next), true, 303);
    exit;
}
$error = '';
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
if (!in_array($method, ['GET', 'HEAD', 'POST'], true)) {
    header('Allow: GET, HEAD, POST');
    auth_fail(405, 'Método no permitido.');
}
if ($method === 'POST') {
    if (!auth_csrf_valid()) {
        http_response_code(403);
        $error = 'El formulario caducó. Inténtalo de nuevo.';
    } else {
        $username = is_string($_POST['username'] ?? null) ? $_POST['username'] : '';
        $password = is_string($_POST['password'] ?? null) ? $_POST['password'] : '';
        try {
            if (auth_attempt_login($username, $password)) {
                session_regenerate_id(true);
                $_SESSION = ['auth_user' => AUTH_ADMIN_USER, 'auth_created' => time(), 'auth_seen' => time()];
                auth_csrf_token();
                header('Location: ' . auth_url($next), true, 303);
                exit;
            }
            if (http_response_code() !== 429) {
                http_response_code(401);
            }
            $error = http_response_code() === 429
                ? 'Demasiados intentos. Espera 15 minutos antes de volver a intentarlo.'
                : 'Usuario o contraseña incorrectos.';
        } catch (Throwable $exception) {
            error_log('Rinchen login: ' . $exception->getMessage());
            http_response_code(503);
            $error = 'No pudimos iniciar la sesión. Inténtalo más tarde.';
        }
    }
}
?>
<!doctype html>
<html lang="es">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="theme-color" content="#79364b">
  <title>Iniciar sesión · Rinchen</title>
  <link rel="stylesheet" href="<?php echo auth_escape(auth_url('static/auth.css')); ?>">
  <script defer src="<?php echo auth_escape(auth_url('static/auth-password.js')); ?>"></script>
</head>
<body>
  <main class="auth-card">
    <img class="auth-logo" src="<?php echo auth_escape(auth_url('static/images/logo-head-square.png')); ?>" alt="Sakya Rinchen Ling">
    <p class="eyebrow">NUESTRA COMUNIDAD</p>
    <h1>Un espacio para<br><em>volver a conectar.</em></h1>
    <p>Inicia sesión para gestionar nuestros encuentros.</p>
    <?php if ($error !== ''): ?><p class="auth-error" role="alert"><?php echo auth_escape($error); ?></p><?php endif; ?>
    <form method="post" action="<?php echo auth_escape(auth_url('templates/login.php')); ?>">
      <?php auth_csrf_field(); ?>
      <input type="hidden" name="next" value="<?php echo auth_escape($next); ?>">
      <label for="username">Usuario</label>
      <input id="username" name="username" autocomplete="username" required maxlength="100" autocapitalize="none" spellcheck="false">
      <label for="password">Contraseña</label>
      <input id="password" name="password" type="password" autocomplete="current-password" autocapitalize="none" spellcheck="false" required maxlength="200">
      <button id="toggle-password" class="auth-password-toggle" type="button" aria-controls="password" hidden>Mostrar contraseña</button>
      <button type="submit">Iniciar sesión <span aria-hidden="true">→</span></button>
    </form>
    <footer class="auth-footnote">Sakya Rinchen Ling · Bolivia<br>Desarrollado por <a href="https://scistitch.com/" target="_blank" rel="noopener noreferrer">SciStitch</a></footer>
  </main>
</body>
</html>
