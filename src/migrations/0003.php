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


function migrate_0003(): void {
  event_create_table();
  event_session_create_table();
}

migrate_0003();
