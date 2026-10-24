<?php
require_once dirname(__DIR__) . '/lib/auth.php';
auth_require();
require_once dirname(__DIR__) . '/lib/event_setup.php';

if (($_GET['mode'] ?? '') === 'prepare2026') {
    require dirname(__DIR__) . '/templates/event/prepare-2026.php';
    return;
}

$values = EventSetup::defaults();
$error = '';
$success = '';
$events = [];
$db = null;
$path = dirname(__DIR__) . '/db.sqlite';
try {
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
        foreach ($values as $key => $default) {
            if (isset($_POST[$key]) && !is_string($_POST[$key])) {
                throw new DomainException('Formulario no válido.');
            }
            $values[$key] = $_POST[$key] ?? '';
        }
        EventSetup::schedule($values); // Validate before opening/creating any database.
        $token = $_POST['setup_token'] ?? '';
        if (!is_string($token) || !isset($_SESSION['setup_tokens'][$token])
            || $_SESSION['setup_tokens'][$token] < time()) {
            throw new DomainException('El formulario caducó o ya se utilizó. Revisa los datos y vuelve a guardar.');
        }
        unset($_SESSION['setup_tokens'][$token]);
        $exists = is_file($path);
        $db = new SQLite3($path);
        $db->enableExceptions(true);
        $db->busyTimeout(5000);
        if ($exists) {
            EventSetup::backup($db, dirname(__DIR__) . '/.backups');
        } else {
            chmod($path, 0600);
        }
        $id = EventSetup::save($db, $values);
        $values['event_id'] = (string) $id;
        $success = "Evento #$id guardado con seis sesiones: mañana y tarde los días 12 y 15, solo tarde los días 13 y 14 de noviembre de " . $values['year'] . '.';
        if ($values['qr_event'] === '1') {
            $success .= ' Seleccionado para el control de acceso QR.';
        }
        if ($exists) {
            $success .= ' Se guardó una copia previa en el directorio protegido .backups del servidor.';
        }
    }
    // GET never creates or migrates the database.
    if ($db === null && is_file($path)) {
        $db = new SQLite3($path, SQLITE3_OPEN_READONLY);
        $db->enableExceptions(true);
    }
    if ($db !== null && $db->querySingle("SELECT count(*) FROM sqlite_master WHERE type='table' AND name='event'")) {
        $result = $db->query('SELECT id, name FROM event ORDER BY id DESC');
        while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
            $events[] = $row;
        }
        $result->finalize();
    }
} catch (DomainException $exception) {
    http_response_code(409);
    $error = $exception->getMessage();
} catch (Throwable $exception) {
    http_response_code(503);
    error_log('Event setup: ' . $exception->getMessage());
    $error = 'No se pudo completar la configuración. Verifica PHP sqlite3, permisos de escritura y el esquema de la base. No vuelvas a ejecutar las migraciones antiguas.';
} finally {
    if ($db !== null) {
        $db->close();
    }
}
$_SESSION['setup_tokens'] = array_filter($_SESSION['setup_tokens'] ?? [], function ($expires) { return $expires >= time(); });
$_SESSION['setup_tokens'] = array_slice($_SESSION['setup_tokens'], -9, null, true);
$token = bin2hex(random_bytes(24));
$_SESSION['setup_tokens'][$token] = time() + 1800;
?>
<!doctype html>
<html lang="es">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Configurar evento · Rinchen</title>
  <link rel="stylesheet" href="<?php echo auth_escape(auth_url('static/auth.css')); ?>">
</head>
<body>
<main class="auth-card">
  <p class="eyebrow">SOLO PERSONAL · CONFIGURACIÓN</p>
  <h1>Evento de noviembre</h1>
  <p>12 y 15 de noviembre: mañana y tarde. 13 y 14 de noviembre: solo tarde.
    Todos los horarios son de Bolivia (America/La_Paz).</p>
  <p>El año y los horarios siguientes son valores propuestos: revísalos antes de guardar.</p>
  <?php if ($error): ?><p class="auth-error" role="alert"><?php echo auth_escape($error); ?></p><?php endif; ?>
  <?php if ($success): ?><p role="status"><?php echo auth_escape($success); ?></p><?php endif; ?>
  <form method="post" action="<?php echo auth_escape(auth_url('scripts/setup.php')); ?>">
    <?php auth_csrf_field(); ?>
    <input type="hidden" name="setup_token" value="<?php echo auth_escape($token); ?>">
    <label for="event_id">Evento a crear o actualizar</label>
    <select id="event_id" name="event_id">
      <option value="">Crear o actualizar el evento de noviembre de este año</option>
      <?php foreach ($events as $event): ?>
        <option value="<?php echo (int) $event['id']; ?>" <?php echo $values['event_id'] === (string) $event['id'] ? 'selected' : ''; ?>><?php echo auth_escape('#' . $event['id'] . ' · ' . $event['name']); ?></option>
      <?php endforeach; ?>
    </select>
    <p>Repetir la configuración actualiza las seis sesiones vinculadas sin duplicarlas.
      Las sesiones de mañana del 13 y 14 creadas por la versión anterior se eliminan solo si no tienen asistencias ni decisiones. Al seleccionar otro evento se actualizarán su nombre y fechas; se conservan sus demás sesiones y registros.</p>
    <?php foreach (['name' => 'Nombre del evento', 'description' => 'Descripción', 'year' => 'Año',
        'morning_start' => 'Mañana: inicio', 'morning_end' => 'Mañana: fin',
        'afternoon_start' => 'Tarde: inicio', 'afternoon_end' => 'Tarde: fin'] as $key => $label): ?>
      <label for="<?php echo $key; ?>"><?php echo $label; ?></label>
      <input id="<?php echo $key; ?>" name="<?php echo $key; ?>" type="<?php echo strpos($key, '_') !== false ? 'time' : ($key === 'year' ? 'number' : 'text'); ?>"
        value="<?php echo auth_escape($values[$key]); ?>" <?php echo $key !== 'description' ? 'required' : ''; ?> <?php echo $key === 'year' ? 'min="2000" max="2099"' : ''; ?>>
    <?php endforeach; ?>
    <label for="qr_event">Evento utilizado al escanear QR</label>
    <select id="qr_event" name="qr_event">
      <option value="1" <?php echo $values['qr_event'] === '1' ? 'selected' : ''; ?>>Usar este evento para QR</option>
      <option value="0" <?php echo $values['qr_event'] !== '1' ? 'selected' : ''; ?>>No cambiar la selección actual</option>
    </select>
    <p>No se crean participantes, no se envían correos y no se generan imágenes QR.
      Se crea la base si no existe. Si existe, se realiza una copia protegida antes de guardar.</p>
    <button type="submit">Guardar evento y seis sesiones</button>
  </form>
  <a href="<?php echo auth_escape(auth_url('templates/index.php')); ?>">Volver sin guardar</a>
  <footer class="auth-footnote">Desarrollado por <a href="https://scistitch.com/" target="_blank" rel="noopener noreferrer">SciStitch</a></footer>
</main>
</body>
</html>
