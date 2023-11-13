<?php

require_once dirname(__DIR__) . "/lib/db.php";
require_once dirname(__DIR__) . "/lib/datetime.php";


function create_event_records(): void {
  $db = get_db();

  // EVENT
  $TZ = new DateTimeZone('America/La_Paz');
  $eventId = 1;
  $name = "Retiro con Su Santidad Sakya Trizin 42 y Venerable Lama Rinchen Gyaltsen";
  $description = "Retiro con Su Santidad Sakya Trizin 42 y Venerable Lama Rinchen Gyaltsen";
  $startDate = convert_to_utc0(new DateTime("2023-11-24 06:00:00", $TZ))->format('Y-m-d H:i:s');
  $endDate = convert_to_utc0(new DateTime("2023-11-26 20:00:00", $TZ))->format('Y-m-d H:i:s');

  $query = "INSERT INTO event (id, name, description, start_date, end_date) values(
    {$eventId},
    '{$name}',
    '{$description}',
    '{$startDate}',
    '{$endDate}'
  )";
  $db->exec($query);


  // EVENT SESSIONS
  // NOTE: this dates and times should be revised
  $sessions = [
    [
      "name" => "Day 1 - Session 1",
      "startDate" => convert_to_utc0(new DateTime("2023-11-24 06:00:00", $TZ)),
      "endDate" => convert_to_utc0(new DateTime("2023-11-24 12:00:00", $TZ))
    ],
    [
      "name" => "Day 1 - Session 2",
      "startDate" => convert_to_utc0(new DateTime("2023-11-24 13:00:00", $TZ)),
      "endDate" => convert_to_utc0(new DateTime("2023-11-24 20:00:00", $TZ))
    ],
    [
      "name" => "Day 2 - Session 1",
      "startDate" => convert_to_utc0(new DateTime("2023-11-25 06:00:00", $TZ)),
      "endDate" => convert_to_utc0(new DateTime("2023-11-25 12:00:00", $TZ))
    ],
    [
      "name" => "Day 2 - Session 2",
      "startDate" => convert_to_utc0(new DateTime("2023-11-25 13:00:00", $TZ)),
      "endDate" => convert_to_utc0(new DateTime("2023-11-25 20:00:00", $TZ))
    ],
    [
      "name" => "Day 3 - Session 1",
      "startDate" => convert_to_utc0(new DateTime("2023-11-26 06:00:00", $TZ)),
      "endDate" => convert_to_utc0(new DateTime("2023-11-26 12:00:00", $TZ))
    ],
    [
      "name" => "Day 3 - Session 2",
      "startDate" => convert_to_utc0(new DateTime("2023-11-26 13:00:00", $TZ)),
      "endDate" => convert_to_utc0(new DateTime("2023-11-26 20:00:00", $TZ))
    ],
  ];

  foreach ($sessions as $session) {
    $name = $session["name"];
    $startDate = $session["startDate"]->format('Y-m-d H:i:s');
    $endDate = $session["endDate"]->format('Y-m-d H:i:s');

    $query = "INSERT INTO event_session (event_id, name, start_date, end_date) values(
      $eventId,
      '{$name}',
      '{$startDate}',
      '{$endDate}'
    );";
    $db->exec($query);
  }
}

function migrate_0004(): void {
  create_event_records();
}

if (isset($_GET['migrate']) && $_GET['migrate'] == 1) {
  migrate_0004();
}
