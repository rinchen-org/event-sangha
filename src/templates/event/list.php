<?php
require_once dirname(dirname(__DIR__)) . "/lib/event.php";
include dirname(__DIR__) . "/header.php";

$filter = $_POST["filter"] ?? "all";

if ($filter == "active") {
  $result = Event::list([
    "active" => 1,
  ]);
} else if ($filter == "inactive") {
  $result = Event::list([
    "active" => 0,
  ]);
} else {
  $result = Event::list();
}

?>
<h2>Event List</h2>
<a href="../">&lt;&lt; Back to the menu</a>

<div class="alert alert-secondary input-group" role="alert">
  Filters: <br/>
  <form method="POST" class="mx-1">
    <input type="hidden" name="filter" value="all">
    <input type="submit"
      value="All"
      class="btn btn-success btn-sm"
      <?php if ($filter == "all") { ?>
      style="background:#fafafa!important;color:#888888;"
      disabled="disabled"
      <?php } ?>
    />
  </form>
  <form method="POST" class="mx-1">
    <input type="hidden" name="filter" value="active">
    <input type="submit"
      value="Active"
      class="btn btn-success btn-sm"
      <?php if ($filter == "active") { ?>
      style="background:#fafafa!important;color:#888888;"
      disabled="disabled"
      <?php } ?>
    />
  </form>
  <form method="POST" class="mx-1">
    <input type="hidden" name="filter" value="inactive">
    <input type="submit"
      value="Inactive"
      class="btn btn-success btn-sm"
      <?php if ($filter == "inactive") { ?>
      style="background:#fafafa!important;color:#888888;"
      disabled="disabled"
      <?php } ?>
    />
  </form>
</div>

<?php if ($result): ?>
  <table id="event_list">
    <thead>
      <tr>
        <th>ID</th>
        <th>Title</th>
        <th>Description</th>
        <th>Start Date</th>
        <th>End Date</th>
        <th>Active</th>
        <th>Actions</th>
      </tr>
    </thead>
    <tbody>
      <?php foreach ($result as $event): ?>
        <tr>
          <td><?php echo $event->id; ?></td>
          <td><?php echo $event->title; ?></td>
          <td><?php echo $event->description; ?></td>
          <td><?php echo $event->start_date; ?></td>
          <td><?php echo $event->end_date; ?></td>
          <td>
            <?php if ($event->active == 1): ?>
              <span class="badge bg-success">ACTIVE</span>
            <?php else: ?>
              <span class="badge bg-danger">INACTIVE</span>
            <?php endif; ?>
          </td>
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

<a href="../">&lt;&lt; Back to the menu</a>

<script>
  let table = new DataTable('#event_list');
</script>

<?php include dirname(__DIR__) . "/footer.php"; ?>
