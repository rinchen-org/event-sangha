<?php
require_once dirname(__DIR__, 2) . '/lib/auth.php';
auth_require();
require_once dirname(__DIR__, 2) . '/lib/subscription.php';

$review = null;
$report = null;
$error = '';
$notice = '';
$token = '';
if (isset($_SESSION['csv_import_review']) && $_SESSION['csv_import_review']['expires'] < time()) {
    unset($_SESSION['csv_import_review']);
}
try {
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
        $action = $_POST['action'] ?? 'review';
        if ($action === 'confirm' || $action === 'cancel') {
            $pending = $_SESSION['csv_import_review'] ?? null;
            $submitted = $_POST['import_token'] ?? null;
            if (!$pending || !is_string($submitted) || !hash_equals($pending['token'], $submitted)) {
                throw new DomainException('La revisión caducó o ya se utilizó. Vuelve a seleccionar el CSV.');
            }
            // Consume before doing any work: never repeat writes or ambiguous mail attempts on refresh.
            unset($_SESSION['csv_import_review']);
            if ($action === 'cancel') {
                $notice = 'Importación cancelada. No se guardaron inscripciones ni se enviaron correos.';
            } else {
                $notes = $_POST['notes'] ?? '';
                if (!is_string($notes) || strlen($notes) > 2000 || !preg_match('//u', $notes)) {
                    throw new DomainException('La nota opcional debe ser texto UTF-8 de hasta 2000 bytes.');
                }
                // Re-evaluate the original server-held CSV. Never accept rows/warnings from the browser.
                $review = SubscriptionCsv::readContents($pending['contents']);
                $db = new SQLite3(dirname(__DIR__, 2) . '/db.sqlite', SQLITE3_OPEN_READWRITE);
                try {
                    $report = (new SubscriptionCsvImport($db))->run($review, true, $_SESSION['auth_user'], trim($notes));
                } finally {
                    $db->close();
                }
                $review = null;
            }
        } elseif ($action === 'review') {
            unset($_SESSION['csv_import_review']);
            $upload = $_FILES['csv_file'] ?? null;
            if (!$upload || ($upload['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK
                || !is_string($upload['tmp_name']) || !is_uploaded_file($upload['tmp_name'])) {
                throw new DomainException('No se pudo recibir el CSV. Selecciona un archivo de hasta 5 MB y revisa el límite de subida del servidor.');
            }
            $review = SubscriptionCsv::read($upload['tmp_name']);
            if ($review['rows']) {
                $contents = file_get_contents($upload['tmp_name']);
                if ($contents === false) {
                    throw new RuntimeException('No se pudo conservar el CSV para confirmación.');
                }
                $token = bin2hex(random_bytes(24));
                $_SESSION['csv_import_review'] = ['token' => $token, 'expires' => time() + 600,
                    'contents' => $contents];
            }
        } else {
            throw new DomainException('Acción de importación no válida.');
        }
    }
} catch (DomainException $exception) {
    http_response_code(409);
    $error = $exception->getMessage();
} catch (Throwable $exception) {
    http_response_code(503);
    error_log('CSV upload: ' . $exception->getMessage());
    $review = null;
    $error = 'No se pudo completar la importación por un fallo técnico. Revisa el resultado antes de volver a intentarlo; no se reenviarán correos automáticamente.';
}

include dirname(__DIR__) . '/header.php';
?>
<h2>Importar participantes desde CSV</h2>
<?php if ($error !== ''): ?><p class="alert alert-danger" role="alert"><?php echo auth_escape($error); ?></p><?php endif; ?>
<?php if ($notice !== ''): ?><p class="alert alert-info" role="status"><?php echo auth_escape($notice); ?></p><?php endif; ?>

<?php if ($review !== null && $report === null): ?>
    <section aria-label="Revisión de importación">
        <h3>Revisar antes de importar</h3>
        <p>Formato: <strong><?php echo auth_escape($review['label']); ?></strong>.
            <?php echo count($review['rows']); ?> fila(s) con datos válidos;
            <?php echo count($review['errors']); ?> fila(s) con errores;
            <?php echo (int) $review['blank_rows']; ?> fila(s) vacía(s) omitida(s).</p>
        <p>No se ha guardado nada ni se han generado QR o enviado correos.
            Al confirmar se importarán las filas válidas; las filas con errores se omitirán.
            Las inscripciones existentes con el mismo nombre, correo y teléfono no se duplicarán ni recibirán otro correo.</p>
        <?php if ($review['warnings']): ?>
            <div class="alert alert-warning" role="alert"><h4>Advertencias del registro histórico</h4>
                <p>El personal decide: puedes cancelar o continuar de todos modos con estas filas. No se supone que el aporte esté confirmado.</p>
                <ul><?php foreach ($review['warnings'] as $warning): ?>
                    <li>Fila <?php echo (int) $warning['row']; ?>: <?php echo auth_escape($warning['message']); ?></li>
                <?php endforeach; ?></ul>
            </div>
        <?php endif; ?>
        <?php if ($review['errors']): ?>
            <div class="alert alert-danger" role="alert"><h4>Filas que no se importarán</h4><ul>
                <?php foreach ($review['errors'] as $issue): ?>
                    <li>Fila <?php echo (int) $issue['row']; ?>: <?php echo auth_escape($issue['message']); ?></li>
                <?php endforeach; ?>
            </ul></div>
        <?php endif; ?>
        <?php if ($review['rows']): ?>
            <div class="table-responsive"><table class="table table-striped">
                <thead><tr><th>Fila</th><th>Nombre</th><th>Correo</th><th>Celular</th></tr></thead>
                <tbody><?php foreach ($review['rows'] as $row): ?>
                    <tr><td><?php echo (int) $row['row']; ?></td><td><?php echo auth_escape($row['fullname']); ?></td>
                        <td><?php echo auth_escape($row['email']); ?></td><td><?php echo auth_escape($row['phone']); ?></td></tr>
                <?php endforeach; ?></tbody>
            </table></div>
            <p>Se generarán los QR y se intentará enviar el correo de confirmación a los participantes importados.
                La aceptación del transporte no confirma la entrega en su bandeja de entrada.</p>
            <form method="post" action="<?php echo auth_escape(auth_url('templates/subscription/upload.php')); ?>">
                <?php auth_csrf_field(); ?>
                <input type="hidden" name="import_token" value="<?php echo auth_escape($token); ?>">
                <label for="import-notes">Nota opcional</label>
                <textarea class="form-control mb-3" id="import-notes" name="notes" maxlength="2000" rows="2"></textarea>
                <button class="btn btn-success" type="submit" name="action" value="confirm"><?php echo $review['warnings'] ? 'Continuar de todos modos e importar' : 'Confirmar e importar'; ?></button>
                <button class="btn btn-secondary" type="submit" name="action" value="cancel">Cancelar importación</button>
            </form>
        <?php endif; ?>
    </section>
<?php endif; ?>

<?php if ($report !== null): ?>
    <section aria-label="Resultado de importación">
        <p role="status">Inscripciones guardadas: <?php echo (int) $report['imported']; ?>.
            Duplicados omitidos: <?php echo (int) $report['duplicates']; ?>.
            Filas no importadas por errores: <?php echo count($report['errors']); ?>.</p>
        <?php foreach (array_merge($report['errors'], $report['mail_errors']) as $issue): ?>
            <p class="alert alert-danger" role="alert">Fila <?php echo (int) $issue['row']; ?>: <?php echo auth_escape($issue['message']); ?></p>
        <?php endforeach; ?>
        <p>La aceptación del transporte de correo no garantiza la entrega. No se realizan reintentos automáticos.</p>
    </section>
<?php endif; ?>

<form method="post" enctype="multipart/form-data" class="my-3" action="<?php echo auth_escape(auth_url('templates/subscription/upload.php')); ?>">
    <?php auth_csrf_field(); ?>
    <input type="hidden" name="action" value="review">
    <p>Formatos admitidos: Google Forms histórico y Participantes 2026. Se detectan por sus columnas, no por el nombre del archivo. Las filas vacías se omiten. UTF-8, máximo 5 MB.</p>
    <label for="csv-file">Archivo CSV</label>
    <input id="csv-file" type="file" name="csv_file" accept=".csv,text/csv" required>
    <button class="btn btn-primary" type="submit">Revisar CSV</button>
</form>
<a href="<?php echo auth_escape(auth_url('templates/index.php')); ?>" class="btn btn-warning my-3">Volver al menú</a>
<?php include dirname(__DIR__) . '/footer.php'; ?>
