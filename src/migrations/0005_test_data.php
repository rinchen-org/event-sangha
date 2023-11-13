<?php

require_once dirname(__DIR__) . "/lib/db.php";
require_once dirname(__DIR__) . "/lib/settings.php";
require_once dirname(__DIR__) . "/lib/datetime.php";
require_once dirname(__DIR__) . "/lib/qr.php";


function create_person_test(): void {
  $db = get_db();

  $personId = 1;
  $fullname = "Person Test 1";
  $email = get_env("EMAIL_TO");
  $phone = "+59100000000";
  $active = 1;

  $query = "INSERT INTO person (id, fullname, email, phone, active) values(
    {$personId},
    '{$fullname}',
    '{$email}',
    '{$phone}',
    '{$active}'
  )";
  $db->exec($query);
}


function create_subscription_test(): void {
  $db = get_db();

  // person
  $personId = 1;
  $fullname = "Person Test 1";
  $email = get_env("EMAIL_TO");
  $phone = "+59100000000";

  // subscription
  $subscriptionId = 1;
  $UTC = new DateTimeZone('UTC');
  $datetime = (new DateTime('now', $UTC))->format('Y-m-d H:i:s');
  $active = 1;

  $qr = generate_qr(
      $fullname,
      $email,
      $phone
  );

  $query = "INSERT INTO subscription (id, person_id, datetime, qr, active)
    VALUES ({$subscriptionId}, '{$personId}', '{$datetime}', '{$qr}', {$active})";
  $db->exec($query);
}

function create_event_records_test(): void {
  $db = get_db();

  // EVENT
  $eventId = 1;
  $name = "Retiro con Su Santidad Sakya Trizin 42 y Venerable Lama Rinchen Gyaltsen";
  $description = "Retiro con Su Santidad Sakya Trizin 42 y Venerable Lama Rinchen Gyaltsen";

  $UTC = new DateTimeZone('UTC');
  $currentDateTime = new DateTime('now', $UTC);

  $threeDaysLater = clone $currentDateTime;
  $threeDaysLater->modify('+3 days');

  $startDate = $currentDateTime->format('Y-m-d H:i:s');
  $endDate = $threeDaysLater->format('Y-m-d H:i:s');

  $query = "INSERT INTO event (id, name, description, start_date, end_date) values(
    {$eventId},
    '{$name}',
    '{$description}',
    '{$startDate}',
    '{$endDate}'
  )";
  $db->exec($query);


  // EVENT SESSIONS
  $sessionStartDate = clone $currentDateTime;
  $sessionEndDate = clone $currentDateTime;

  $sessions = [];

  for ($i = 1; $i < 4; $i++) {
    $sessionStartDate = clone $sessionEndDate;
    $sessionStartDate->modify("+1 minutes");

    $sessionEndDate = clone $sessionStartDate;
    $sessionEndDate->modify("+1 minutes");

    $sessions[] = [
      "name" => "Day $i - Session 1",
      "startDate" => $sessionStartDate->format('Y-m-d H:i:s'),
      "endDate" => $sessionEndDate->format('Y-m-d H:i:s')
    ];

    $sessionStartDate = clone $sessionEndDate;
    $sessionStartDate->modify("+1 minutes");

    $sessionEndDate = clone $sessionStartDate;
    $sessionEndDate->modify("+1 minutes");

    $sessions[] = [
      "name" => "Day $i - Session 2",
      "startDate" => $sessionStartDate->format('Y-m-d H:i:s'),
      "endDate" => $sessionEndDate->format('Y-m-d H:i:s')
    ];
  }

  foreach ($sessions as $session){
    $name = $session["name"];
    $startDate = $session["startDate"];
    $endDate = $session["endDate"];
    $query = "INSERT INTO event_session (event_id, name, start_date, end_date) values(
      {$eventId},
      '{$name}',
      '{$startDate}',
      '{$endDate}'
    )";
    $db->exec($query);
  }
}

function migrate_0005(): void {
  create_person_test();
  create_subscription_test();
  create_event_records_test();
}

if (isset($_GET['migrate']) && $_GET['migrate'] == 1) {
  migrate_0005();
}

?>
