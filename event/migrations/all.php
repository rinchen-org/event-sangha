<?php

require_once __DIR__ . "/0001.php";
require_once __DIR__ . "/0002.php";


function migrate_all(): void {
  # 0001
  person_table();
  subscription_table();
  attendance_table();
  # 0002
  person_add_active_column();
  subscription_add_active_column();
}
