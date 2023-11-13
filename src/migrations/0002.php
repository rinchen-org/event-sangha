<?php

require_once dirname(__DIR__) . "/lib/db.php";

function person_add_active_column(): void {
  $db = get_db();

  // add activate field to person table
  $alterTableQuery = "ALTER TABLE person
    ADD active INTEGER DEFAULT 1
  ";
  $db->exec($alterTableQuery);
  $db->close();
}

function subscription_add_active_column(): void {
  $db = get_db();

  // add active field to subscription
  $alterTableQuery = "ALTER TABLE subscription
    ADD active INTEGER DEFAULT 1
  ";
  $db->exec($alterTableQuery);
  $db->close();
}

function migrate_0002(): void {
  person_add_active_column();
  subscription_add_active_column();
}

if (isset($_GET['migrate']) && $_GET['migrate'] == 1) {
  migrate_0002();
}
