<?php
include dirname(__DIR__) . "/header.php";
require_once dirname(dirname(__DIR__)) . "/lib/event.php";

$eventList = EventSangha::list();
$eventSession = null;

// Check if the form is submitted
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  // Retrieve form data
  $eventId = $_POST['event_id'];
  $name = $_POST['name'];
  $startDate = new DateTime($_POST['start_datetime']);
  $endDate = new DateTime($_POST['end_datetime']);
  $eventSessionId = $_POST['event_session_id'];

  try {
    $event_selected = EventSangha::get(["id" => $eventId]);

    // Check if event_session_id is provided for an update
    if ($eventSessionId) {
      $eventSession = EventSession::get(["id" => $eventSessionId]);
      $eventSession->event = $event_selected;
      $eventSession->name = $name;
      $eventSession->startDate = $startDate;
      $eventSession->endDate = $endDate;
      $eventSession->save();
    } else {
      // Create a new event session
      $eventSession = new EventSession(
          $event_selected, $name, $startDate, $endDate
      );
      $eventSession->save();
    }
  } catch (Exception $e) {
    print("<div><p>" . $e->getMessage() . "</p></div>");
    echo '<div><p><a href="./form.php" class="btn btn-warning my-3">Back to the form</a></p></div>';
  }

  if ($eventSession) {
?>
    <div><strong>Success!</strong></div>
    <div>
        <a href="./list.php" class="btn btn-warning my-3">Back to the list</a>
    </div>
<?php
  }
}

// Check if the form is submitted
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
  $eventSessionId = $_GET['id'];

  if ($eventSessionId) {
    // Fetch the existing event session for editing
    $eventSession = EventSession::get(["id" => $eventSessionId]);
  }
?>
  <h1>Event Session Form</h1>

  <form method="POST" action="">
    <div class="mb-3">
      <label for="event_id" class="form-label">Event</label>
      <select class="form-select" name="event_id" id="event_id" required>
      <?php foreach ($eventList as $event) { ?>
        <option value="<?php echo $event->id; ?>"><?php echo $event->name; ?></option>
      <?php } ?>
      </select>
    </div>

    <div class="mb-3">
      <label for="name" class="form-label">Name</label>
      <input
        type="text"
        class="form-control"
        name="name"
        id="name"
        required
        value="<?php echo $eventSession ? $eventSession->name : ''; ?>">
    </div>

    <div class="mb-3">
      <label for="start_datetime" class="form-label">Start Date and Time</label>
      <input
        type="datetime-local"
        class="form-control"
        name="start_datetime"
        id="start_datetime"
        required
        value="<?php echo $eventSession ? $eventSession->startDate->format('Y-m-d\TH:i:s') : ''; ?>">
    </div>

    <div class="mb-3">
      <label for="end_datetime" class="form-label">End Date and Time</label>
      <input
        type="datetime-local"
        class="form-control"
        name="end_datetime"
        id="end_datetime"
        required
        value="<?php echo $eventSession ? $eventSession->endDate->format('Y-m-d\TH:i:s') : ''; ?>">
    </div>

    <input type="hidden" name="event_session_id" value="<?php echo $eventSessionId; ?>">
    <button type="submit" class="btn btn-primary"><?php echo $eventSessionId ? 'Update' : 'Create'; ?></button>
  </form>

  <a href="../" class="btn btn-warning my-3">Back to the menu</a>
  <a href="./list.php" class="btn btn-warning my-3">Back to the list</a>
<?php
}
include dirname(__DIR__) . "/footer.php";
?>
