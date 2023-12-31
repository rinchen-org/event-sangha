<?php
session_start();

require_once dirname(dirname(__DIR__)) . '/lib/attendance.php';

global $BASE_URL;
?>

<?php

function log_attendance(): void {
    // Check if all fields are present
    if (!isset($_GET['fullname'], $_GET['email'], $_GET['phone'])) {
      // Output an error message if any field is missing
      $_SESSION['message'] = [
        "type" => "error",
        "text" => "All fields are required!",
      ];
      return;
    }

    // Get the values from GET parameters
    $fullname = $_GET['fullname'];
    $email = $_GET['email'];
    $phone = $_GET['phone'];

    $person = Person::get([
      "fullname" => $fullname,
      "email" => $email,
      "phone" => $phone,
    ]);

    if ($person === null) {
      $_SESSION['message'] = [
        "type" => "error",
        "text" => "Person not found!",
      ];
      return;
    }

    // event id hard coded for now
    $eventId = 1;
    $eventSession = EventSession::getNextSession($eventId);

    if ($eventSession === null) {
      $_SESSION['message'] = [
        "type" => "error",
        "text" => "Event Session not found!",
      ];
      return;
    }

    $attendance = Attendance::get([
      "person_id" => $person->id,
      "event_session_id" => $eventSession->id
    ]);

    if ($attendance !== null) {
      $_SESSION['message'] = [
        "type" => "warning",
        "text" => "Asistencia previamente confirmada ({$fullname})",
      ];
      return;
    }

    try {
      Attendance::log($person, $eventSession);
    } catch (Exception $e) {
      $_SESSION['message'] = [
        "type" => "error",
        "text" => $e->getMessage(),
      ];
      return;
    }

    // Output a success message
    $_SESSION['message'] = [
      "type" => "success",
      "text" => "Asistencia confirmada ({$fullname})</strong>!",
    ];
    return;
}

log_attendance();

include dirname(__DIR__) . "/header.php";

include dirname(__DIR__) . "/footer.php";

?>
