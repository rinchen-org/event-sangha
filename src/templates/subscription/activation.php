<?php
require_once dirname(__DIR__, 2) . '/lib/auth.php';
auth_require();
auth_post_only();
require_once dirname(dirname(__DIR__)) . "/lib/subscription.php";


try {
  $id = EventRegistration::id($_POST['id'] ?? null);
  $requestedActive = $_POST['active'] ?? null;
  if (!in_array($requestedActive, ['0', '1'], true)) {
    throw new DomainException('El estado no es válido.');
  }
  $active = (int) $requestedActive;
  $db = new SQLite3(dirname(__DIR__, 2) . '/db.sqlite', SQLITE3_OPEN_READWRITE);
  try { Subscription::setActive($db, $id, $active); }
  finally { $db->close(); }
} catch (DomainException $exception) {
  auth_fail(409, $exception->getMessage());
  exit;
} catch (Throwable $exception) {
  error_log('Subscription activation: ' . $exception->getMessage());
  auth_fail(503, 'No se pudo confirmar el cambio de estado. Revisa la inscripción antes de volver a intentarlo.');
  exit;
}

if ($active == 1){
  $message = "Registro habilitado.";
} else {
  $message = "Registro inhabilitado.";
}

$_SESSION['message'] = [
  "type" => "success",
  "text" => $message,
];

header('Location: ' . auth_url('templates/index.php#participantes/todos'));
exit();
