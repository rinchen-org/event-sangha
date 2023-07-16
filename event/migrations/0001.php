<?php

require_once __DIR__ . "/../lib/db.php";

function person_table() {
  $db = get_db();

  // Create the 'registrations' table if it doesn't exist
  $createTableQuery = "CREATE TABLE IF NOT EXISTS person (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    fullname TEXT,
    email TEXT,
    phone TEXT
  )";
  $db->exec($createTableQuery);
  $db->close();
}


function subscription_table() {
  $db = get_db();

  // Create the 'registrations' table if it doesn't exist
  $createTableQuery = "CREATE TABLE IF NOT EXISTS subscription (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    person_id INTEGER,
    datetime TEXT,
    qr TEXT
  )";
  $db->exec($createTableQuery);
  $db->close();
}


function attendance_table() {
  // Connect to the SQLite database
  $db = get_db();

  // Create the attendance_table if it doesn't exist
  $query = "CREATE TABLE IF NOT EXISTS attendance (
      id INTEGER PRIMARY KEY AUTOINCREMENT,
      person_id INTEGER,
      datetime TEXT
  )";
  $db->exec($query);
  $db->close();
}


person_table();
subscription_table();
attendance_table();
