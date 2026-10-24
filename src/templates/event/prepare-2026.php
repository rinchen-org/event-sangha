<?php
require_once dirname(__DIR__, 2) . '/lib/auth.php';
auth_require();
require_once dirname(__DIR__, 2) . '/lib/event_setup.php';

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
if (!in_array($method, ['GET', 'HEAD', 'POST'], true)) {
    header('Allow: GET, HEAD, POST');
    auth_fail(405, 'Método no permitido.');
}
$path = dirname(__DIR__, 2) . '/db.sqlite';
$url = auth_url('scripts/setup.php?mode=prepare2026');
$selection = '';
$state = null;
$error = '';
$success = '';
$db = null;
$token = '';
try {
    if ($method === 'POST') {
        $submitted = $_POST['prepare_token'] ?? '';
        $review = is_string($submitted) ? ($_SESSION['prepare_2026_tokens'][$submitted] ?? null) : null;
        if (!$review || $review['expires'] < time()) {
            throw new DomainException('La revisión caducó o ya se utilizó. Revisa el estado antes de confirmar.');
        }
        unset($_SESSION['prepare_2026_tokens'][$submitted]);
        $selection = $review['selection']; // Never trust POSTed IDs or completion flags.
    } else {
        $selection = $_GET['event_id'] ?? '';
        if (!is_string($selection)) { throw new DomainException('Selecciona un evento válido.'); }
    }
    if (is_file($path)) {
        $db = new SQLite3($path, SQLITE3_OPEN_READONLY);
        $db->enableExceptions(true);
    }
    $state = EventSetup::preparationStatus($db, $selection);
    if ($method === 'POST') {
        if ($state['ready']) {
            $success = 'El evento 2026 y la base ya están preparados. No se modificó ningún dato ni se creó otra copia.';
        } else {
            if ($state['choose'] || !hash_equals($review['fingerprint'], $state['fingerprint'])) {
                throw new DomainException('La configuración cambió desde la revisión. Revisa el estado actual y confirma de nuevo.');
            }
            if ($db !== null) { $db->close(); $db = null; }
            $exists = is_file($path);
            if (!$exists) {
                // Exclusive creation avoids changing permissions on a database
                // created by another request between the review and this step.
                $file = @fopen($path, 'x');
                if ($file !== false) {
                    fclose($file);
                    if (!chmod($path, 0600)) { throw new RuntimeException('Cannot protect new database.'); }
                } elseif (is_file($path)) {
                    $exists = true;
                } else {
                    throw new RuntimeException('Cannot create database.');
                }
            }
            $db = new SQLite3($path, SQLITE3_OPEN_READWRITE);
            $backedUp = false;
            $result = EventSetup::prepare2026($db, $selection, $review['fingerprint'], static function () use ($exists, $path, &$backedUp): void {
                if (!$exists) { return; }
                // Backing up the writer connection inside BEGIN IMMEDIATE can
                // be busy; a second reader snapshots while that lock is held.
                $source = new SQLite3($path, SQLITE3_OPEN_READONLY);
                $source->enableExceptions(true);
                try {
                    EventSetup::backup($source, dirname($path) . '/.backups');
                    $backedUp = true;
                } finally {
                    $source->close();
                }
            });
            $success = $result['changed']
                ? 'Preparación completada. Solo se aplicaron los pasos pendientes; se conservaron los datos existentes y la selección QR.'
                : 'El evento 2026 y la base ya están preparados. No se modificó ningún dato.';
            if ($backedUp) { $success .= ' Se guardó una copia previa en el directorio protegido .backups.'; }
            $state = EventSetup::preparationStatus($db, $selection);
        }
    }
} catch (DomainException $exception) {
    http_response_code(409);
    $success = '';
    $error = $exception->getMessage();
    $state = null; // A stale or incomplete review must be loaded again.
} catch (Throwable $exception) {
    http_response_code(503);
    $success = '';
    error_log('Event preparation 2026: ' . $exception->getMessage());
    $error = 'No se pudo completar la preparación. Revisa PHP sqlite3, permisos y el esquema. No ejecutes las migraciones antiguas.';
    $state = null;
} finally {
    if ($db !== null) { $db->close(); }
}
$_SESSION['prepare_2026_tokens'] = array_filter($_SESSION['prepare_2026_tokens'] ?? [], static function (array $item): bool { return $item['expires'] >= time(); });
$_SESSION['prepare_2026_tokens'] = array_slice($_SESSION['prepare_2026_tokens'], -9, null, true);
if ($state !== null && !$state['choose'] && !$state['ready']) {
    $token = bin2hex(random_bytes(24));
    $_SESSION['prepare_2026_tokens'][$token] = ['selection' => $selection, 'fingerprint' => $state['fingerprint'], 'expires' => time() + 1800];
}
$localTime = static function (string $value): string {
    return (new DateTimeImmutable($value, new DateTimeZone('UTC')))->setTimezone(new DateTimeZone('America/La_Paz'))->format('d/m/Y H:i');
};
?>
<!doctype html>
<html lang="es">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Preparar evento 2026 · Rinchen</title>
  <link rel="stylesheet" href="<?php echo auth_escape(auth_url('static/auth.css')); ?>">
</head>
<body>
<main class="auth-card">
  <p class="eyebrow">SOLO PERSONAL · CONFIGURACIÓN</p>
  <h1>Preparar evento 2026</h1>
  <p>Esta revisión no modifica la base. La confirmación completa solo lo que falta, sin sobrescribir nombres, horarios, asistencias ni decisiones.</p>
  <?php if ($error !== ''): ?><p class="auth-error" role="alert"><?php echo auth_escape($error); ?></p><a href="<?php echo auth_escape($url); ?>">Revisar de nuevo</a><?php endif; ?>
  <?php if ($success !== ''): ?><p role="status"><?php echo auth_escape($success); ?></p><?php endif; ?>
  <?php if ($state !== null): ?>
    <h2>Base de datos</h2>
    <?php if (!$state['schema']): ?><p>Esquema listo: se omiten las migraciones ya aplicadas.</p>
    <?php else: ?><p>Pasos pendientes (solo cambios aditivos):</p><ul><?php foreach ($state['schema'] as $label => $sql): ?><li><?php echo auth_escape($label); ?></li><?php endforeach; ?></ul><?php endif; ?>
    <h2>Evento y sesiones de 2026</h2>
    <?php if ($state['choose']): ?>
      <p>Ya existen eventos, pero ninguno está vinculado a esta preparación. Selecciona uno explícitamente o confirma que deseas uno nuevo; no se elegirá por su nombre o por el año.</p>
      <form method="get" action="<?php echo auth_escape(auth_url('scripts/setup.php')); ?>">
        <input type="hidden" name="mode" value="prepare2026">
        <label for="event_id">Evento para noviembre de 2026</label>
        <select id="event_id" name="event_id" required>
          <option value="">Selecciona una opción</option>
          <?php foreach ($state['events'] as $event): ?><option value="<?php echo (int) $event['id']; ?>"><?php echo auth_escape('#' . $event['id'] . ' · ' . $event['name']); ?></option><?php endforeach; ?>
          <option value="new">Crear un evento nuevo, sin reutilizar los existentes</option>
        </select>
        <button type="submit">Revisar preparación</button>
      </form>
    <?php else: ?>
      <p><?php echo $state['event_ready'] ? 'Evento 2026 ya preparado: se conserva sin cambios.' : ($state['event'] !== null ? 'Se conservará el evento seleccionado y se completarán sus vínculos o sesiones pendientes.' : 'Se creará el evento «Retiro de noviembre» de 2026.'); ?></p>
      <?php if ($state['event'] !== null): ?><p><?php echo auth_escape('#' . $state['event']['id'] . ' · ' . $state['event']['name']); ?></p><?php endif; ?>
      <ul><?php foreach ($state['sessions'] as $slot): ?>
        <li><?php echo auth_escape(($slot['record']['name'] ?? $slot['name']) . ': ' . $localTime($slot['record']['start_date'] ?? $slot['start']) . ' — ' . $localTime($slot['record']['end_date'] ?? $slot['end'])); ?>
          <strong><?php echo ['skip' => 'Conservar (ya preparada)', 'adopt' => 'Vincular sin modificar', 'create' => 'Crear sesión pendiente'][$slot['action']]; ?></strong></li>
      <?php endforeach; ?></ul>
      <p>Horarios de Bolivia (America/La_Paz). Los horarios de las sesiones nuevas son propuestas; para cambiarlos utiliza la configuración avanzada. Las sesiones adicionales o antiguas no se eliminan.</p>
      <p>La selección QR no cambia: evento #<?php echo auth_escape($state['qr']); ?> (se usa #1 cuando no hay selección guardada). Para elegir otro evento QR, utiliza la configuración avanzada.</p>
      <?php if ($state['ready']): ?><p role="status">Todo está listo. No hay pasos pendientes ni cambios que aplicar.</p>
      <?php else: ?>
        <p>Antes de escribir se volverá a verificar el estado y se guardará una copia privada si la base ya existe. No se crean participantes, no se envían correos ni se generan QR.</p>
        <form method="post" action="<?php echo auth_escape($url); ?>">
          <?php auth_csrf_field(); ?>
          <input type="hidden" name="prepare_token" value="<?php echo auth_escape($token); ?>">
          <button type="submit">Confirmar y completar lo pendiente</button>
        </form>
      <?php endif; ?>
    <?php endif; ?>
  <?php endif; ?>
  <a href="<?php echo auth_escape(auth_url('templates/index.php')); ?>">Cancelar y volver</a><br>
  <a href="<?php echo auth_escape(auth_url('scripts/setup.php')); ?>">Configuración avanzada (permite actualizar horarios)</a>
</main>
</body>
</html>
