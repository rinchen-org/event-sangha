<?php
require_once dirname(__DIR__, 2) . '/lib/auth.php';
auth_require();
if (($_GET['format'] ?? '') === 'json') {
    require_once dirname(dirname(__DIR__)) . '/lib/frontend.php';
    frontend_list_response('events');
    exit;
}
require_once dirname(dirname(__DIR__)) . "/lib/event.php";
include dirname(__DIR__) . "/header.php";

$result = EventSangha::list();

?>
<h2>Eventos</h2>
<a href="../" class="btn btn-warning my-3">Volver a mi comunidad</a>
<a href="./form.php" class="btn btn-primary my-3">Nuevo evento</a>

<?php if ($result): ?>
  <table id="event_list">
    <thead>
      <tr>
        <th>ID</th>
        <th>Nombre</th>
        <th>Descripción</th>
        <th>Inicio</th>
        <th>Fin</th>
        <th>Acciones</th>
      </tr>
    </thead>
    <tbody>
      <?php foreach ($result as $event): ?>
        <tr>
          <td><?php echo $event->id; ?></td>
          <td><?php echo $event->name; ?></td>
          <td><?php echo $event->description; ?></td>
          <td><?php echo $event->startDate->format('Y-m-d H:i:s'); ?></td>
          <td><?php echo $event->endDate->format('Y-m-d H:i:s'); ?></td>
          <td>
            <!-- Add action buttons here as needed -->
          </td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
<?php else: ?>
  <p>No hay registros en esta vista.</p>
<?php endif; ?>

<a href="../" class="btn btn-warning my-3">Volver a mi comunidad</a>

<script>
  let table = new DataTable('#event_list');
</script>

<?php include dirname(__DIR__) . "/footer.php"; ?>
