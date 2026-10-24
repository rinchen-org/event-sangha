<?php
require_once dirname(__DIR__, 2) . '/lib/auth.php';
auth_require();
require_once dirname(__DIR__, 2) . '/lib/test_lab.php';
test_mode_require();

/** Tokens bind each single-use action to its target; never use QR/live review tokens. */
function lab_form_token(string $action, string $target): string {
    $_SESSION['lab_actions'] = array_filter($_SESSION['lab_actions'] ?? [], static function ($item) { return $item['expires'] >= time(); });
    $_SESSION['lab_actions'] = array_slice($_SESSION['lab_actions'], -255, null, true);
    $token = bin2hex(random_bytes(24));
    $_SESSION['lab_actions'][$token] = ['action' => $action, 'target' => $target, 'expires' => time() + 900];
    return $token;
}
function lab_form_fields(string $action, string $target): void {
    auth_csrf_field();
    echo '<input type="hidden" name="action" value="' . auth_escape($action) . '">';
    echo '<input type="hidden" name="action_token" value="' . lab_form_token($action, $target) . '">';
}
function lab_input(string $name, string $default = ''): string {
    $value = $_POST[$name] ?? $default;
    if (!is_string($value)) { throw new DomainException('El formulario contiene un valor no válido.'); }
    return $value;
}

$config = load_env(false);
$db = null; $lab = null; $error = ''; $notice = ''; $recipientError = ''; $recipients = [];
$today = TestLab::today(); $days = []; $batches = []; $people = []; $sessions = [];
$batch = is_string($_GET['batch'] ?? null) ? $_GET['batch'] : '';
$uuid = is_string($_GET['uuid'] ?? null) ? $_GET['uuid'] : '';
try { $recipients = TestLab::recipients($config['TEST_EMAIL_TO'] ?? ''); }
catch (DomainException $e) { $recipientError = $e->getMessage(); }

try {
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
        $token = lab_input('action_token');
        $pending = $_SESSION['lab_actions'][$token] ?? null;
        unset($_SESSION['lab_actions'][$token]);
        if (!$pending || $pending['expires'] < time() || $pending['action'] !== lab_input('action')) {
            throw new DomainException('El formulario caducó o ya se utilizó. Revisa los datos antes de repetir una acción.');
        }
        $action = $pending['action'];
        $db = test_lab_db(true, $action === 'prepare');
        $lab = new TestLab($db);
        if ($action === 'prepare') {
            $count = $lab->prepareDay($pending['target'], lab_input('start'), lab_input('end'), $_SESSION['auth_user']);
            $notice = $count ? "$count sesiones de prueba creadas. No se modificó ningún evento real." : 'La agenda de hoy ya estaba preparada; no se duplicó ninguna sesión.';
        } elseif ($action === 'generate') {
            if ($pending['target'] !== $today) { throw new DomainException('La fecha cambió; recarga la página.'); }
            $count = filter_var(lab_input('count'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 100]]);
            if ($count === false) { throw new DomainException('La cantidad debe ser un entero entre 1 y 100.'); }
            $lab->generate($count, lab_input('recipient'), $recipients, lab_input('profile'), $token, $_SESSION['auth_user']);
            $batch = $token; $uuid = '';
            $notice = "$count participantes de prueba creados. No se envió correo ni se contactó al servicio QR.";
        } elseif ($action === 'qr') {
            require_once dirname(__DIR__, 2) . '/lib/test_lab_qr.php';
            $uuid = $pending['target']; $batch = '';
            $lab->generateQr($uuid, $config['HOST_ADDRESS'] ?? '', 'test_lab_fetch_qr', $_SESSION['auth_user']);
            $notice = 'Imagen QR de prueba disponible. Escanéala desde un dispositivo con acceso a esta instalación.';
        } elseif ($action === 'mail') {
            if (time() - ($_SESSION['lab_mail_last'] ?? 0) < 60) { throw new DomainException('Espera un minuto entre solicitudes de envío. No se reintentó ningún correo.'); }
            $_SESSION['lab_mail_last'] = time();
            require_once dirname(__DIR__, 2) . '/lib/email.php';
            $batch = $pending['target']; $uuid = '';
            $result = $lab->sendBatch($batch, $config, static function (string $to, string $url, string $from, string $reply, string $id): bool {
                return send_email(dirname(__DIR__) . '/test-lab-email.html', ['{{url}}' => auth_escape($url), '{{uuid}}' => auth_escape($id)],
                    'Rinchen · Participante de PRUEBA', $from, $to, $reply, '');
            }, $_SESSION['auth_user']);
            $notice = "Correos aceptados por el transporte: {$result['accepted']}; sin confirmación: {$result['unconfirmed']}; ya intentados, omitidos: {$result['skipped']}. Esto no confirma la entrega. No se reenvían intentos anteriores.";
            if ($result['unconfirmed'] > 0) {
                http_response_code(502);
                $error = 'No se pudo confirmar uno o más envíos. Los participantes siguen guardados; no repitas su generación ni se reintentará el correo automáticamente.';
            }
        } else { throw new DomainException('Acción no válida.'); }
    }
    if ($db === null && is_file(dirname(__DIR__, 2) . '/.test-lab/lab.sqlite')) {
        $db = test_lab_db(); $lab = new TestLab($db);
    }
} catch (DomainException $e) { http_response_code(409); $error = $e->getMessage(); }
catch (Throwable $e) {
    http_response_code(503); $notice = '';
    error_log('Test lab: ' . $e->getMessage());
    $error = 'No se pudo completar la operación de pruebas. Revisa los registros guardados antes de repetirla. Un fallo de imagen/correo no elimina participantes. No se reintenta automáticamente.';
}
try {
    if ($lab !== null) {
        $days = $lab->rows('SELECT * FROM test_lab_day WHERE day=:day', ['day' => $today]);
        $batches = $lab->rows('SELECT b.*, count(a.uuid) AS total FROM test_lab_batch b JOIN test_lab_attendee a ON a.batch_id=b.id GROUP BY b.id ORDER BY b.created_at DESC,b.rowid DESC LIMIT 20');
        $sessions = $lab->rows('SELECT name,start_date,end_date FROM event_session WHERE date(start_date, \'-4 hours\')=:day ORDER BY start_date', ['day' => $today]);
        if ($uuid !== '') { $people = [$lab->attendee($uuid)]; }
        else {
            if ($batch === '' && $batches) { $batch = $batches[0]['id']; }
            $people = $lab->rows('SELECT a.uuid,a.person_id,a.mail_state,a.qr_target,(a.qr_png IS NOT NULL) AS has_qr,p.fullname,p.email
                FROM test_lab_attendee a JOIN person p ON p.id=a.person_id WHERE a.batch_id=:batch ORDER BY p.id LIMIT 100', ['batch' => $batch]);
        }
    }
} catch (Throwable $e) { http_response_code(503); $error = 'No se pudieron leer los datos de prueba.'; $people = []; }
finally { if ($db !== null) { $db->close(); } }
?>
<!doctype html>
<html lang="es"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>Laboratorio QR · Rinchen</title>
<link rel="stylesheet" href="<?php echo auth_escape(auth_url('static/auth.css')); ?>">
<link rel="stylesheet" href="<?php echo auth_escape(auth_url('static/test-lab.css')); ?>">
<script defer src="<?php echo auth_escape(auth_url('static/auth-navigation.js')); ?>"></script>
</head><body><main class="auth-card lab-card">
<p class="eyebrow">RINCHEN · SOLO PRUEBAS</p><h1>Laboratorio QR</h1>
<p class="auth-warning">Base de pruebas independiente. Ninguna acción de esta pantalla cambia participantes, asistencias ni el evento QR real. TEST_ENV=0 desactiva estas herramientas sin borrar su historial.</p>
<?php if ($error !== ''): ?><p class="auth-error" role="alert"><?php echo auth_escape($error); ?></p><?php endif; ?>
<?php if ($notice !== ''): ?><p role="status"><?php echo auth_escape($notice); ?></p><?php endif; ?>
<section><h2>1. Preparar el día</h2><p>Fecha: <?php echo auth_escape($today); ?> · Bolivia. Un único evento de pruebas, sesiones de 50 minutos y descansos de 10 minutos. Solo se crean intervalos completos; repetir las mismas horas no duplica sesiones ni borra historial. Cambiar el rango añade las sesiones que faltan: conserva las anteriores y puede producir solapamientos, que se mostrarán como advertencias al escanear.</p>
<form method="post" action="<?php echo auth_escape(auth_url('templates/testing/index.php')); ?>">
<?php lab_form_fields('prepare', $today); ?>
<label for="start">Desde</label><input id="start" name="start" type="time" required value="<?php echo auth_escape($days[0]['start'] ?? '09:00'); ?>">
<label for="end">Hasta</label><input id="end" name="end" type="time" required value="<?php echo auth_escape($days[0]['end'] ?? '18:00'); ?>">
<button type="submit">Preparar sesiones de hoy</button></form>
<?php if ($sessions): ?><details><summary>Ver agenda de hoy (<?php echo count($sessions); ?> sesiones)</summary><ul>
<?php foreach ($sessions as $session): ?><li><?php echo auth_escape($session['name']); ?></li><?php endforeach; ?></ul></details><?php endif; ?></section>
<section><h2>2. Generar participantes</h2>
<?php if ($recipientError !== ''): ?><p class="auth-error"><?php echo auth_escape($recipientError); ?></p><?php endif; ?>
<form method="post" action="<?php echo auth_escape(auth_url('templates/testing/index.php')); ?>">
<?php lab_form_fields('generate', $today); ?>
<label for="count">Cantidad de registros (1–100)</label><input id="count" name="count" type="number" min="1" max="100" step="1" value="10" required>
<label for="recipient">Correo base de TEST_EMAIL_TO</label><select id="recipient" name="recipient" required>
<?php foreach ($recipients as $recipient): ?><option><?php echo auth_escape($recipient); ?></option><?php endforeach; ?></select>
<p>Se añade +UUID al correo base; el mismo UUID aparece en el nombre. Comprueba que tu proveedor acepta estos alias. Generar registros no envía correo.</p>
<label for="profile">Escenario</label><select id="profile" name="profile"><option value="ready">Asistencias anteriores preparadas</option><option value="missing">Sin asistencias anteriores (probar advertencias)</option></select>
<p>El primer escenario crea asistencias sintéticas anteriores a la sesión sugerida en este momento. No registra la sesión sugerida ni omite reglas de admisión. Más adelante pueden aparecer nuevas advertencias.</p>
<button type="submit" <?php echo !$days || !$recipients ? 'disabled' : ''; ?>>Generar participantes de prueba</button></form></section>
<section><h2>3. Probar QR</h2>
<p>«Abrir check-in» prueba el mismo flujo de asistencia, solo en esta base. Las imágenes se generan al pulsar el botón correspondiente: el servicio externo de QR recibe un enlace con UUID, no nombres ni correos. Necesita Internet. HOST_ADDRESS debe apuntar a esta instalación y ser accesible desde el dispositivo que escanea.</p>
<?php if ($batches): ?><form method="get" action="<?php echo auth_escape(auth_url('templates/testing/index.php')); ?>"><label for="batch">Lotes recientes</label><select id="batch" name="batch">
<?php foreach ($batches as $item): ?><option value="<?php echo auth_escape($item['id']); ?>" <?php echo $item['id'] === $batch ? 'selected' : ''; ?>><?php echo auth_escape($item['created_at'] . ' UTC · ' . $item['total'] . ' registros · ' . $item['profile']); ?></option><?php endforeach; ?></select><button type="submit">Ver lote</button></form><?php endif; ?>
<?php foreach ($people as $person): ?>
<article class="lab-person"><h3><?php echo auth_escape($person['fullname']); ?></h3><p><?php echo auth_escape($person['email']); ?></p>
<a href="<?php echo auth_escape(auth_url('templates/testing/check-in.php?uuid=' . $person['uuid'])); ?>">Abrir check-in</a>
<?php if ((!empty($person['has_qr']) || !empty($person['qr_png'])) && $person['qr_target'] === rtrim($config['HOST_ADDRESS'] ?? '', '/') . '/templates/testing/check-in.php?uuid=' . $person['uuid']): ?>
<img class="lab-qr" width="300" height="300" loading="lazy" src="<?php echo auth_escape(auth_url('templates/testing/qr.php?uuid=' . $person['uuid'])); ?>" alt="QR de prueba para <?php echo auth_escape($person['fullname']); ?>">
<?php endif; ?>
<form method="post" action="<?php echo auth_escape(auth_url('templates/testing/index.php')); ?>"><?php lab_form_fields('qr', $person['uuid']); ?><button class="auth-deny" type="submit">Generar / mostrar QR</button></form>
<p>Estado de correo: <?php echo auth_escape(['none' => 'no intentado', 'attempted' => 'intentado, resultado pendiente o desconocido', 'accepted' => 'aceptado por el transporte (entrega no verificada)', 'unconfirmed' => 'sin confirmación de envío'][$person['mail_state']] ?? 'desconocido'); ?></p></article>
<?php endforeach; ?>
<?php if ($people && $batch !== ''): ?>
<form method="post" action="<?php echo auth_escape(auth_url('templates/testing/index.php')); ?>"><?php lab_form_fields('mail', $batch); ?>
<p>Opcional: enviar un correo por participante del lote a sus alias configurados, con enlace a esta pantalla (requiere sesión del personal). No se añade EMAIL_CC. Los intentos anteriores nunca se reenvían, incluso si su resultado es desconocido.</p>
<button class="auth-deny" type="submit">Enviar correos de prueba de este lote</button></form><?php endif; ?>
</section><a href="<?php echo auth_escape(auth_url('templates/index.php')); ?>">Volver sin otra acción</a>
</main></body></html>
