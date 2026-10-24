<?php
require_once dirname(__DIR__) . '/lib/auth.php';
auth_require();
include __DIR__ . "/header.php";
?>
<h1>Evento Retiro con Su Santidad Sakya Trizin 42 y Venerable Lama Rinchen Gyaltsen</h1>

<div class="container">
  <p>Revisar la base y completar solo los pasos pendientes del evento de noviembre de 2026.</p>
  <a class="btn btn-outline-primary" href="<?php echo auth_escape(auth_url('scripts/setup.php?mode=prepare2026')); ?>">Preparar evento 2026</a>
</div>

<div class="container">
  <form method="post" action="<?php echo auth_escape(auth_url('templates/test-email.php')); ?>">
    <?php auth_csrf_field(); ?>
    <p>Enviar una prueba a las direcciones configuradas en TEST_EMAIL_TO.</p>
    <button class="btn btn-outline-primary" type="submit">Test email sender</button>
  </form>
</div>

<div class="container">
  <strong>Menu Suscripciones</strong>
  <div class="list-group">
    <a class="list-group-item list-group-item-action"
      href="./subscription/form.php">Suscripción manual</a>
    <a class="list-group-item list-group-item-action"
      href="./subscription/list.php">Lista de suscripciones</a>
    <a class="list-group-item list-group-item-action"
      href="./subscription/upload.php">Upload archivo de suscripciones</a>
  </div>
</div>
<div class="container">
  <strong>Menu Asistencia</strong>
  <div class="list-group">
    <a class="list-group-item list-group-item-action"
      href="./attendance/form.php">Registro manual de asistencia</a>
    <a class="list-group-item list-group-item-action"
      href="./attendance/list.php">Lista de asistencia</a>
    <a class="list-group-item list-group-item-action"
      href="./attendance/report.php">Informe</a>
  </div>
</div>

<div class="container">
  <strong>Events</strong>
  <div class="list-group">
    <a class="list-group-item list-group-item-action"
      href="./event/list.php">Event List</a>
    <a class="list-group-item list-group-item-action"
      href="./event/form.php">Event Form</a>
  </div>
</div>

<div class="container">
  <strong>Events' Session</strong>
  <div class="list-group">
    <a class="list-group-item list-group-item-action"
      href="./event-session/list.php">Event Sessions List</a>
    <a class="list-group-item list-group-item-action"
      href="./event-session/form.php">Event Sessions Form</a>
  </div>
</div>

<?php
include __DIR__ . "/footer.php";
?>
