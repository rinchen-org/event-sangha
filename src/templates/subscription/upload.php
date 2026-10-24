<?php
require_once dirname(__DIR__, 2) . '/lib/auth.php';
auth_require();
require_once dirname(__DIR__, 2) . '/lib/subscription.php';

$review = null;
$report = null;
$error = '';
$notice = '';
$token = '';
$eventId = null;
$event = null;
if (isset($_SESSION['csv_import_review']) && $_SESSION['csv_import_review']['expires'] < time()) {
    unset($_SESSION['csv_import_review']);
}
try {
    $requestedEvent = $_POST['event_id'] ?? ($_GET['event_id'] ?? null);
    if ($requestedEvent !== null) {
        $eventId = EventRegistration::id($requestedEvent);
        $db = new SQLite3(dirname(__DIR__, 2) . '/db.sqlite', SQLITE3_OPEN_READONLY);
        try { $event = (new EventRegistration($db))->event($eventId); }
        finally { $db->close(); }
    }
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
            if (($pending['event_id'] ?? null) !== $eventId) {
                throw new DomainException('El evento no coincide con la revisión. Vuelve a revisar el CSV para el evento correcto.');
            }
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
                    if ($eventId !== null) {
                        $review = (new EventRegistration($db))->scopeCsv($review, $eventId);
                        if ($review !== ($pending['scoped_review'] ?? null)) {
                            throw new DomainException('Los datos del evento, las inscripciones o las advertencias cambiaron. Vuelve a revisar el CSV.');
                        }
                    }
                    $report = (new SubscriptionCsvImport($db))->run($review, true, $_SESSION['auth_user'], trim($notes), $eventId);
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
            if ($eventId !== null) {
                $db = new SQLite3(dirname(__DIR__, 2) . '/db.sqlite', SQLITE3_OPEN_READONLY);
                try { $review = (new EventRegistration($db))->scopeCsv($review, $eventId); }
                finally { $db->close(); }
            }
            if ($review['rows']) {
                $contents = file_get_contents($upload['tmp_name']);
                if ($contents === false) {
                    throw new RuntimeException('No se pudo conservar el CSV para confirmación.');
                }
                $token = bin2hex(random_bytes(24));
                $_SESSION['csv_import_review'] = ['token' => $token, 'expires' => time() + 600,
                    'contents' => $contents, 'event_id' => $eventId,
                    'scoped_review' => $eventId === null ? null : $review];
            }
        } else {
            throw new DomainException('Acción de importación no válida.');
        }
    }
} catch (DomainException $exception) {
    http_response_code(409);
    $error = $exception->getMessage();
    $review = null;
} catch (Throwable $exception) {
    http_response_code(503);
    error_log('CSV upload: ' . $exception->getMessage());
    $review = null;
    $error = 'No se pudo completar la importación por un fallo técnico. Revisa el resultado antes de volver a intentarlo; no se reenviarán correos automáticamente.';
}

include dirname(__DIR__) . '/header.php';
?>
<h2>Importar participantes desde CSV</h2>
<?php if ($event !== null): ?>
<h3>Evento: <?php echo auth_escape($event['name']); ?></h3>
<p>Las filas confirmadas se vincularán únicamente a este evento. Las personas con una inscripción general existente conservarán su QR y su estado; no recibirán otro correo. Una persona puede estar inscrita en varios eventos.</p>
<?php else: ?>
<p class="alert alert-info">Importación general: no se asignará ningún evento. Para inscribir participantes en un evento, abre Eventos → el evento → Importar CSV.</p>
<?php endif; ?>
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
            <div class="alert alert-warning" role="alert"><h4>Advertencias de la importación</h4>
                <p>El personal decide: revisa cada advertencia. Puedes cancelar o continuar de todos modos con estas filas.</p>
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
                <?php if ($eventId !== null): ?><input type="hidden" name="event_id" value="<?php echo $eventId; ?>"><?php endif; ?>
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
            <?php if ($eventId !== null): ?>Inscripciones existentes vinculadas al evento: <?php echo (int) $report['linked']; ?>.<?php endif; ?>
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
    <?php if ($eventId !== null): ?><input type="hidden" name="event_id" value="<?php echo $eventId; ?>"><?php endif; ?>
    <p>Formatos admitidos: Google Forms histórico y Participantes 2026. Se detectan por sus columnas, no por el nombre del archivo. Las filas vacías se omiten. UTF-8, máximo 5 MB.</p>
    <label for="csv-file">Archivo CSV</label>
    <input id="csv-file" type="file" name="csv_file" accept=".csv,text/csv" required>
    <button class="btn btn-primary" type="submit">Revisar CSV</button>
</form>
<a href="<?php echo auth_escape(auth_url('templates/index.php' . ($eventId === null ? '#participantes' : '#eventos/' . $eventId . '/participantes'))); ?>" class="btn btn-warning my-3"><?php echo $eventId === null ? 'Volver a participantes' : 'Volver al evento'; ?></a>
<?php include dirname(__DIR__) . '/footer.php'; ?>
