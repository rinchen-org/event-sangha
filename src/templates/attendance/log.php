<?php
require_once dirname(dirname(__DIR__)) . '/lib/attendance.php';

include dirname(__DIR__) . "/header.php";


global $BASE_URL;
?>

<?php

function log_attendance(): void {
    // Check if all fields are present
    if (!isset($_GET['fullname'], $_GET['email'], $_GET['phone'])) {
      // Output an error message if any field is missing
      echo '<p style="color:red;">';
      echo "Error: All fields are required!";
      echo '</p>';
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
      print('<div class="alert alert-danger" role="alert">');
      print("Person not found!");
      print('</div>');
      return;
    }

    // event id hard coded for now
    $eventId = 1;
    $eventSession = EventSession::getNextSession($eventId);

    if ($eventSession === null) {
      print('<div class="alert alert-danger" role="alert">');
      print("Event Session not found!");
      print('</div>');
      return;
    }

    $attendance = Attendance::get([
      "person_id" => $person->id,
      "event_session_id" => $eventSession->id
    ]);

    if ($attendance !== null) {
      print('<div class="alert alert-warning" role="alert">');
      print("<strong>Asistencia previamente confirmada</strong>!");
      print('</div>');
      return;
    }

    try {
      Attendance::log($person, $eventSession);
    } catch (Exception $e) {
      print('<div class="alert alert-danger" role="alert">');
      print($e->getMessage());
      print('</div>');
      return;
    }

    // Output a success message
    print('<div class="alert alert-success" role="alert">');
    print("<strong>Asistencia confirmada</strong>!");
    print('</div>');
    return;
}

log_attendance();

include dirname(__DIR__) . "/footer.php";

?>
