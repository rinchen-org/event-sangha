<?php
require_once dirname(__DIR__) . '/lib/auth.php';
auth_require();
auth_post_only();
$json = auth_json_request();
$result = ['success' => false, 'message' => ''];
try {
    if (($_SESSION['test_email_after'] ?? 0) > time()) {
        http_response_code(429);
        header('Retry-After: ' . ($_SESSION['test_email_after'] - time()));
        $result['message'] = 'Espera un minuto entre pruebas para evitar envíos duplicados.';
    } else {
        // Set before attempting delivery: a failed/ambiguous response must not trigger retries.
        $_SESSION['test_email_after'] = time() + 60;
        require_once dirname(__DIR__) . '/lib/test_email.php';
        $result = send_test_email();
    }
} catch (DomainException $error) {
    http_response_code(422);
    $result['message'] = $error->getMessage();
} catch (Throwable $error) {
    http_response_code(502);
    error_log('Rinchen test email: ' . $error->getMessage());
    $result['message'] = 'No se pudo enviar el correo de prueba. Revisa la configuración de mail()/sendmail del servidor y los registros de PHP. La entrega no está confirmada.';
}
if ($json) {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($result);
    exit;
}
?>
<!doctype html>
<html lang="es">
<head>
  <meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Test email sender · Rinchen</title>
  <link rel="stylesheet" href="<?php echo auth_escape(auth_url('static/auth.css')); ?>">
</head>
<body>
<main class="auth-card">
  <h1>Test email sender</h1>
  <p role="<?php echo $result['success'] ? 'status' : 'alert'; ?>" class="<?php echo $result['success'] ? '' : 'auth-error'; ?>"><?php echo auth_escape($result['message']); ?></p>
  <a href="<?php echo auth_escape(auth_url('templates/index.php')); ?>">Volver al inicio</a>
  <footer class="auth-footnote">Desarrollado por <a href="https://scistitch.com/" target="_blank" rel="noopener noreferrer">SciStitch</a></footer>
</main>
</body>
</html>
