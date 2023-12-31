<?php
session_start();

require_once dirname(dirname(__DIR__)) . "/lib/attendance.php";
require_once dirname(dirname(__DIR__)) . "/lib/person.php";
require_once dirname(dirname(__DIR__)) . "/lib/event.php";

// Check if the form is submitted
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Retrieve form data
    $personId = $_POST['person'];
    $eventId = $_POST['event'];
    $eventSessionId = $_POST['event_session'];
    $force = $_POST['force'];

    $person = Person::get(["id" => $personId]);
    $eventSession = EventSession::get(["id" => $eventSessionId]);

    // Log the attendance
    try {
        if ($force == "1") {
            Attendance::log_force($person, $eventSession);
        } else {
            Attendance::log($person, $eventSession);
        }
        $_SESSION['message'] = [
            "type" => "success",
            "text" => "Attendance logged successfully!",
        ];
    } catch (Exception $e) {
        $_SESSION['message'] = [
            "type" => "error",
            "text" => $e->getMessage(),
        ];
    }
}

// Retrieve data for the dropdowns
$subscriptionList = Subscription::list(["active" => 1]);
// this is a temporarily workflow
$eventList = [EventSangha::get(["id" => 1])];
$eventSessionList = EventSession::list(["event_id" => 1]);

include dirname(__DIR__) . "/header.php";
?>

<h1>Attendance Log</h1>

<form method="POST" action="">
    <div class="mb-3">
        <label for="person" class="form-label">Select a Person:</label>
        <select name="person" id="person" class="form-select" required>
            <option value="" disabled selected>Select a Person</option>
            <?php foreach ($subscriptionList as $subscription) { ?>
                <option value="<?php echo $subscription->person->id; ?>"><?php
                    echo $subscription->person->fullname . " &lt;" . $subscription->person->email . "&gt;";
                ?></option>
            <?php } ?>
        </select>
    </div>

    <div class="mb-3">
        <label for="event" class="form-label">Event:</label>
        <select name="event" id="event" class="form-select" required>
            <!--
            <option value="" disabled selected>Select an Event</option>
            -->
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
        <label for="event_session" class="form-label">Force:</label>
        <input type="checkbox" name="force" value="1" />
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
