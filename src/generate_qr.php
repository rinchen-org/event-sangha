<?php
require_once __DIR__ . '/lib/auth.php';
auth_require();
auth_confirm_action('Generar código QR');
require_once __DIR__ . '/lib/layout.php';
require_once __DIR__ . '/lib/qr.php';

$response = null;
$error = '';
try {
    foreach (['fullname', 'email', 'phone'] as $field) {
        if (!isset($_GET[$field]) || !is_string($_GET[$field])) {
            throw new DomainException('El enlace no contiene los datos necesarios para generar el QR.');
        }
    }
    $response = generate_qr($_GET['fullname'], $_GET['email'], $_GET['phone']);
} catch (DomainException $exception) {
    http_response_code(409);
    $error = $exception->getMessage();
} catch (Throwable $exception) {
    http_response_code(503);
    error_log('QR generation: ' . $exception->getMessage());
    $error = 'No se pudo confirmar la generación del QR. Revisa el archivo antes de volver a intentarlo; no se reintenta automáticamente.';
}
AppLayout::start('Generar código QR', compact: true);
?>
<h1>Código QR</h1>
<?php if ($error !== ''): ?><p class="auth-error" role="alert"><?php echo auth_escape($error); ?></p><?php endif; ?>
<?php if ($response !== null): ?>
<p role="status">Código QR generado. Este archivo está protegido y solo puede abrirlo el personal con sesión.</p>
<pre class="qr"><?php echo auth_escape($response); ?></pre>
<?php endif; ?>
<a href="<?php echo auth_escape(auth_url('templates/index.php')); ?>">Volver a mi comunidad</a>
<?php AppLayout::end(); ?>
