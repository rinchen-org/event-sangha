<?php
require_once dirname(dirname(__DIR__)) . "/lib/event.php";
include dirname(__DIR__) . "/header.php";

$result = EventSession::list();

?>
<h2>Event Session List</h2>
<a href="../" class="btn btn-warning my-3">Back to the menu</a>
<a href="./form.php" class="btn btn-primary my-3">New Session</a>

<?php if ($result): ?>
  <table id="eventsession_list">
    <thead>
      <tr>
        <th>ID</th>
        <th>Event Title</th>
        <th>Event Session Title</th>
        <th>Start Date</th>
        <th>End Date</th>
        <th>Actions</th>
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
                update
              </button>
            </form>
          </td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
<?php else: ?>
  <p>No records found.</p>
<?php endif; ?>

<a href="../" class="btn btn-warning my-3">Back to the menu</a>

<script>
  let table = new DataTable('#eventsession_list');
</script>

<?php include dirname(__DIR__) . "/footer.php"; ?>
