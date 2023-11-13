<?php

require_once __DIR__ . "/0001.php";
require_once __DIR__ . "/0002.php";
require_once __DIR__ . "/0003.php";
require_once __DIR__ . "/0005_test_data.php";


function migrate_all_test(): void {
  migrate_0001();
  migrate_0002();
  migrate_0003();
  migrate_0005();
}

migrate_all_test();
