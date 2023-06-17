<?php

function get_db() {
    return new SQLite3('db.sqlite');
}


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
    subscription_datetime TEXT,
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
      entry_time TEXT
  )";
  $db->exec($query);
  $db->close();
}
