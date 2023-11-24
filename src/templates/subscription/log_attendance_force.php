<?php
session_start();

require_once dirname(dirname(__DIR__)) . '/lib/attendance.php';

include_once __DIR__ . "/config.php";


global $BASE_URL;
?>

<?php

function log_attendance(int $subscription_id): void {
    $subscription = Subscription::get(["id" => $subscription_id]);

    if ($subscription === null) {
      $_SESSION['message'] = [
        "type" => "error",
        "text" => "Subscription not found!",
      ];
      return;
    }

    $person = $subscription->person;

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
        "text" => "Session not found!",
      ];
      return;
    }

    $attendance = Attendance::get([
      "person_id" => $person->id,
      "event_session_id" => $eventSession->id
    ]);

    if ($attendance !== null) {
      // Output a success message
      $person = $subscription->person;
      $name = $person->fullname;

      $_SESSION['message'] = [
        "type" => "warning",
        "text" => "Asistencia previamente confirmada ($name)!",
      ];
      return;
    }

    try {
      Attendance::log_force($person, $eventSession);
    } catch (Exception $e) {
      $_SESSION['message'] = [
        "type" => "error",
        "text" => $e->getMessage(),
      ];
      return;
    }

    // Output a success message
    $person = $subscription->person;
    $name = $person->fullname;

    $_SESSION['message'] = [
      "type" => "success",
      "text" => "Asistencia confirmada ({$name})",
    ];
    return;
}

log_attendance(intval($_POST["id"]));

include __DIR__ . "/list.php";

?>
