<?php
include dirname(__DIR__) . "/header.php";
require_once dirname(dirname(__DIR__)) . "/lib/attendance.php";
require_once dirname(dirname(__DIR__)) . "/lib/person.php";
require_once dirname(dirname(__DIR__)) . "/lib/event.php";

// Check if the form is submitted
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Retrieve form data
    $personId = $_POST['person'];
    $eventId = $_POST['event'];
    $eventSessionId = $_POST['event_session'];

    $person = Person::get(["id" => $personId]);
    $eventSession = EventSession::get(["id" => $eventSessionId]);

    // Log the attendance
    try {
        Attendance::log($person, $eventSession);
        echo '<p class="alert alert-success">Attendance logged successfully!</p>';
    } catch (Exception $e) {
        echo '<p class="alert alert-danger">' . $e->getMessage() . '</p>';
    }
}

// Retrieve data for the dropdowns
$personList = Person::list();
$eventList = Event::list();
$eventSessionList = EventSession::list();

?>

<h1>Attendance Log</h1>

<form method="POST" action="">
    <div class="mb-3">
        <label for="person" class="form-label">Select a Person:</label>
        <select name="person" id="person" class="form-select" required>
            <option value="" disabled selected>Select a Person</option>
            <?php foreach ($personList as $person) { ?>
                <option value="<?php echo $person->id; ?>"><?php
                    echo $person->fullname . " &lt;" . $person->email . "&gt;";
                ?></option>
            <?php } ?>
        </select>
    </div>

    <div class="mb-3">
        <label for="event" class="form-label">Event:</label>
        <select name="event" id="event" class="form-select" required>
            <option value="" disabled selected>Select an Event</option>
            <?php foreach ($eventList as $event) { ?>
                <option value="<?php echo $event->id; ?>"><?php echo $event->name; ?></option>
            <?php } ?>
        </select>
    </div>

    <div class="mb-3">
        <label for="event_session" class="form-label">Event Session:</label>
        <select name="event_session" id="event_session" class="form-select" required>
            <option value="" disabled selected>Select an Event Session</option>
            <?php foreach ($eventSessionList as $eventSession) { ?>
                <option value="<?php echo $eventSession->id; ?>"><?php echo $eventSession->name; ?></option>
            <?php } ?>
        </select>
    </div>

    <div class="mb-3">
        <button type="submit" class="btn btn-primary">Log</button>
    </div>
</form>

<a href="../" class="btn btn-warning my-3">Back to the menu</a>
<a href="./list.php" class="btn btn-warning my-3">Back to the List</a>

<?php
include dirname(__DIR__) . "/footer.php";
?>
