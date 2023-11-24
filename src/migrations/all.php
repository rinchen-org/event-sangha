<?php

require_once __DIR__ . "/0001.php";
require_once __DIR__ . "/0002.php";
require_once __DIR__ . "/0003.php";
require_once __DIR__ . "/0004.php";


function migrate_all(): void {
  migrate_0001();
  migrate_0002();
  migrate_0003();
  migrate_0004();
}

migrate_all();
