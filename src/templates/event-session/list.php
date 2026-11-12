<?php
require_once dirname(__DIR__, 2) . '/lib/auth.php';
auth_require();
if (($_GET['format'] ?? '') === 'json') {
    require_once dirname(dirname(__DIR__)) . '/lib/frontend.php';
    frontend_list_response('sessions');
    exit;
}
require_once dirname(dirname(__DIR__)) . "/lib/event.php";
include dirname(__DIR__) . "/header.php";

$result = EventSession::list();

?>
<h2>Sesiones</h2>
<a href="../" class="btn btn-warning my-3">Volver a mi comunidad</a>
<a href="./form.php" class="btn btn-primary my-3">Nueva sesión</a>

<?php if ($result): ?>
  <table id="eventsession_list">
    <thead>
      <tr>
        <th>ID</th>
        <th>Evento</th>
        <th>Sesión</th>
        <th>Inicio</th>
        <th>Fin</th>
        <th>Acciones</th>
      </tr>
    </thead>
    <tbody>
      <?php foreach ($result as $session): ?>
        <tr>
          <td><?php echo $session->id; ?></td>
          <td><?php echo $session->event->name; ?></td>
          <td><?php echo $session->name; ?></td>
          <td><?php echo $session->startDate->format('Y-m-d H:i:s'); ?></td>
          <td><?php echo $session->endDate->format('Y-m-d H:i:s'); ?></td>
          <td>
            <form action="./form.php" method="GET">
              <input type="hidden" name="id" value="<?php echo $session->id; ?>" />
              <button type="submit" class="btn btn-success">
                Editar
              </button>
            </form>
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
  let table = new DataTable('#eventsession_list');
</script>

<?php include dirname(__DIR__) . "/footer.php"; ?>
