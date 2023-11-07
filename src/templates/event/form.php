<?php
include dirname(__DIR__) . "/header.php";
require_once dirname(dirname(__DIR__)) . "/lib/event.php";

// Initialize variables
$event = null;
$isNewEvent = true;

// Check if an event ID is provided for updating
if (isset($_GET['id'])) {
    $event = Event::get(['id' => $_GET['id']]);
    if ($event) {
        $isNewEvent = false;
    }
}

// Check if the form is submitted
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Retrieve form data
    $name = $_POST['name'];
    $description = $_POST['description'];
    $startDateStr = $_POST['start_date'];
    $endDateStr = $_POST['end_date'];

    // Convert the string dates to DateTime objects
    $startDate = new DateTime($startDateStr);
    $endDate = new DateTime($endDateStr);

    // Create or update the event
    if ($isNewEvent) {
        $event = new Event($name, $description, $startDate, $endDate);
        $event->save();
    } else {
        $event->name = $name;
        $event->description = $description;
        $event->start_date = $startDate;
        $event->end_date = $endDate;
        $event->update();
    }

    if ($event) {
        echo '<div class="alert alert-success" role="alert">';
        echo $isNewEvent ? 'Event created successfully!' : 'Event updated successfully!';
        echo '</div>';
        echo '<a href="' . $BASE_URL . 'event/list.php" class="btn btn-primary">Back to Event List</a>';
    }
}

// If it's a new event or an existing one, show the form
if ($isNewEvent || $event) {
?>

<div class="mt-5">
    <h1><?php echo $isNewEvent ? 'Create New Event' : 'Update Event'; ?></h1>

    <form method="POST" action="">
        <div class="mb-3">
            <label for="name" class="form-label">Event Name:</label>
            <input type="text" class="form-control" name="name" id="name" required value="<?php echo $event ? $event->name : ''; ?>">
        </div>

        <div class="mb-3">
            <label for="description" class="form-label">Description:</label>
            <textarea class="form-control" name="description" id="description" rows="4"><?php echo $event ? $event->description : ''; ?></textarea>
        </div>

        <div class="mb-3">
            <label for="start_date" class="form-label">Start Date:</label>
            <input type="date" class="form-control" name="start_date" id="start_date" required value="<?php echo $event ? $event->start_date : ''; ?>">
        </div>

        <div class="mb-3">
            <label for="end_date" class="form-label">End Date:</label>
            <input type="date" class="form-control" name="end_date" id="end_date" required value="<?php echo $event ? $event->end_date : ''; ?>">
        </div>

        <button type="submit" class="btn btn-primary"><?php echo $isNewEvent ? 'Create Event' : 'Update Event'; ?></button>
    </form>

    <a href="../" class="btn btn-warning my-3">Back to the menu</a>
    <a href="./list.php" class="btn btn-warning my-3">Back to Event List</a>

</div>

<?php
}

include dirname(__DIR__) . "/footer.php";
?>
