<?php

require_once __DIR__ . "/../lib/db.php";

function person_add_activate_column() {
  $db = get_db();

  // add activate field to person table
  $alterTableQuery = "ALTER TABLE person
    ADD active INTEGER DEFAULT 1
  ";
  $db->exec($alterTableQuery);
  $db->close();
}

function subscription_add_activate_column() {
  $db = get_db();

  // add active field to subscription
  $alterTableQuery = "ALTER TABLE subscription
    ADD active INTEGER DEFAULT 1
  ";
  $db->exec($alterTableQuery);
  $db->close();
}

person_add_activate_column();
subscription_add_activate_column();
