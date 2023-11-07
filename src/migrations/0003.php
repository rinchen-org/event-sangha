<?php

require_once dirname(__DIR__) . "/lib/db.php";

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
            description TEXT,
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


function migrate_0003(): void {
  event_create_table();
  event_session_create_table();
  modify_attendance_table();
}

migrate_0003();
