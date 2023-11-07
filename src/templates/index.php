<?php
include __DIR__ . "/header.php";
?>
<h1>Evento Retiro con Su Santidad Sakya Trizin 42 y Venerable Lama Rinchen Gyaltsen</h1>

<div class="container">
  <strong>Menu Suscripciones</strong>
  <div class="list-group">
    <a class="list-group-item list-group-item-action"
      href="<?php $BASE_URL?>/templates/subscription/form.php">Suscripción manual</a>
    <a class="list-group-item list-group-item-action"
      href="<?php $BASE_URL?>/templates/subscription/list.php">Lista de suscripciones</a>
    <a class="list-group-item list-group-item-action"
      href="<?php $BASE_URL?>/templates/subscription/upload.php">Upload archivo de suscripciones</a>
  </div>
</div>
<div class="container">
  <strong>Menu Asistencia</strong>
  <div class="list-group">
    <a class="list-group-item list-group-item-action"
      href="<?php $BASE_URL?>/templates/attendance/form.php">Registro manual de asistencia</a>
    <a class="list-group-item list-group-item-action"
      href="<?php $BASE_URL?>/templates/attendance/list.php">Lista de asistencia</a>
  </div>
</div>

<div class="container">
  <strong>Events</strong>
  <div class="list-group">
    <a class="list-group-item list-group-item-action"
      href="<?php $BASE_URL?>/templates/event/list.php">Event List</a>
    <a class="list-group-item list-group-item-action"
      href="<?php $BASE_URL?>/templates/event/form.php">Event Form</a>
  </div>
</div>

<div class="container">
  <strong>Events' Session</strong>
  <div class="list-group">
    <a class="list-group-item list-group-item-action"
      href="<?php $BASE_URL?>/templates/event-session/list.php">Event Sessions List</a>
    <a class="list-group-item list-group-item-action"
      href="<?php $BASE_URL?>/templates/event-session/form.php">Event Sessions Form</a>
  </div>
</div>

<?php
include __DIR__ . "/footer.php";
?>
