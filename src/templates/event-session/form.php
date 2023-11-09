<?php
include dirname(__DIR__) . "/header.php";
require_once dirname(dirname(__DIR__)) . "/lib/event.php";

$eventList = EventSangha::list();

// Check if the form is submitted
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Retrieve form data
    $eventId = $_POST['event_id'];
    $name = $_POST['name'];
    $startDateTime = new DateTime($_POST['start_datetime']);
    $endDateTime = new DateTime($_POST['end_datetime']);

    $eventSession = null;
    try {
        $event_selected = EventSangha::get(["id" => $eventId]);
        $eventSession = new EventSession(
            $event_selected, $name, $startDateTime, $endDateTime
        );
        $eventSession->save();
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
            <input type="text" class="form-control" name="name" id="name" required>
        </div>

        <div class="mb-3">
            <label for="start_datetime" class="form-label">Start Date and Time</label>
            <input type="datetime-local" class="form-control" name="start_datetime" id="start_datetime" required>
        </div>

        <div class="mb-3">
            <label for="end_datetime" class="form-label">End Date and Time</label>
            <input type="datetime-local" class="form-control" name="end_datetime" id="end_datetime" required>
        </div>

        <button type="submit" class="btn btn-primary">Submit</button>
    </form>

    <a href="../" class="btn btn-warning my-3">Back to the menu</a>
    <a href="./list.php" class="btn btn-warning my-3">Back to the list</a>
<?php
}
include dirname(__DIR__) . "/footer.php";
?>
