<?php

require_once dirname(__DIR__) . "/lib/db.php";
require_once dirname(__DIR__) . "/lib/datetime.php";


function event_create_table(): void {
    $db = get_db();

    // Create the 'event' table
    $createQuery = "
        CREATE TABLE IF NOT EXISTS event (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            name TEXT NOT NULL,
            description TEXT,
            start_date DATETIME NOT NULL,
            end_date DATETIME NOT NULL
        )
    ";

    $db->exec($createQuery);
    $db->close();
}

function event_session_create_table(): void {
    $db = get_db();

    // Create the 'event_session' table
    $createQuery = "
        CREATE TABLE IF NOT EXISTS event_session (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            event_id INTEGER NOT NULL,
            name TEXT NOT NULL,
            start_date DATETIME NOT NULL,
            end_date DATETIME NOT NULL
        )
    ";

    $db->exec($createQuery);
    $db->close();
}

function modify_attendance_table(): void {
    // Connect to the SQLite database
    $db = get_db();

    // Modify the attendance_table if it exists
    $query = "ALTER TABLE attendance RENAME TO temp_attendance";
    $db->exec($query);

    // Create the modified attendance table
    $query = "CREATE TABLE IF NOT EXISTS attendance (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        person_id INTEGER,
        event_session_id INTEGER,
        log_time TEXT
    )";
    $db->exec($query);

    // Drop the temporary table
    $query = "DROP TABLE temp_attendance";
    $db->exec($query);

    $db->close();
}

function create_event_records(): void {
  $db = get_db();

  // EVENT
  $eventId = 1;
  $name = "Retiro con Su Santidad Sakya Trizin 42 y Venerable Lama Rinchen Gyaltsen";
  $description = "Retiro con Su Santidad Sakya Trizin 42 y Venerable Lama Rinchen Gyaltsen";
  $startDate = convert_to_utc0(new DateTime("2023-11-24 06:00:00"))->format('Y-m-d H:i:s');
  $endDate = convert_to_utc0(new DateTime("2023-11-26 20:00:00"))->format('Y-m-d H:i:s');

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
      "startDate" => convert_to_utc0(new DateTime("2023-11-24 06:00:00")),
      "endDate" => convert_to_utc0(new DateTime("2023-11-24 12:00:00"))
    ],
    [
      "name" => "Day 1 - Session 2",
      "startDate" => convert_to_utc0(new DateTime("2023-11-24 13:00:00")),
      "endDate" => convert_to_utc0(new DateTime("2023-11-24 20:00:00"))
    ],
    [
      "name" => "Day 2 - Session 1",
      "startDate" => convert_to_utc0(new DateTime("2023-11-25 06:00:00")),
      "endDate" => convert_to_utc0(new DateTime("2023-11-25 12:00:00"))
    ],
    [
      "name" => "Day 2 - Session 2",
      "startDate" => convert_to_utc0(new DateTime("2023-11-25 13:00:00")),
      "endDate" => convert_to_utc0(new DateTime("2023-11-25 20:00:00"))
    ],
    [
      "name" => "Day 3 - Session 1",
      "startDate" => convert_to_utc0(new DateTime("2023-11-26 06:00:00")),
      "endDate" => convert_to_utc0(new DateTime("2023-11-26 12:00:00"))
    ],
    [
      "name" => "Day 3 - Session 2",
      "startDate" => convert_to_utc0(new DateTime("2023-11-26 13:00:00")),
      "endDate" => convert_to_utc0(new DateTime("2023-11-26 20:00:00"))
    ],
  ];

  foreach ($sessions as $session){
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

function migrate_0003(): void {
  event_create_table();
  event_session_create_table();
  modify_attendance_table();
}

if (isset($_GET['migrate']) && $_GET['migrate'] == 1) {
  migrate_0003();
}
