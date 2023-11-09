<?php
require_once dirname(dirname(__DIR__)) . "/lib/event.php";
include dirname(__DIR__) . "/header.php";

$result = EventSangha::list();

?>
<h2>Event List</h2>
<a href="../" class="btn btn-warning my-3">Back to the menu</a>
<a href="./form.php" class="btn btn-primary my-3">New Event</a>

<?php if ($result): ?>
  <table id="event_list">
    <thead>
      <tr>
        <th>ID</th>
        <th>Title</th>
        <th>Description</th>
        <th>Start Date</th>
        <th>End Date</th>
        <th>Actions</th>
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
  <p>No records found.</p>
<?php endif; ?>

<a href="../" class="btn btn-warning my-3">Back to the menu</a>

<script>
  let table = new DataTable('#event_list');
</script>

<?php include dirname(__DIR__) . "/footer.php"; ?>
