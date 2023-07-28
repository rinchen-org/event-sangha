<?php

print("[II] Configuring tests ...\n");
assert_options(ASSERT_ACTIVE,   true);
assert_options(ASSERT_BAIL,     true);
assert_options(ASSERT_WARNING,  false);
assert_options(ASSERT_CALLBACK, 'assert_failure');


function clean_db() {
  $file = dirname(__DIR__) . '/event/db.sqlite';

  if (file_exists($file)) {
      if (unlink($file)) {
          echo "File '$file' has been successfully deleted.\n";
      } else {
          echo "Unable to delete file '$file'.\n";
      }
  } else {
      echo "File '$file' does not exist.\n";
  }
}


class TestCase {
    public static function run_tests($test_list, $migrate_list = []) {
        // Iterate over the function list and call the functions
        foreach ($test_list as $test_fn) {
            // Check if the function exists
            if (!function_exists($test_fn)) {
                echo "Function '$test_fn' does not exist.";
                continue;
            }

            print("\n=======================================\n");
            print(">>> Test $test_fn");
            print("\n=======================================\n");

            // start up step
            // =============

            // clean database
            print("\nCleaning the database ... ");
            clean_db();
            print("OK\n");

            // migrate the tables for person
            print("\nMigrating database ... ");
            foreach ($migrate_list as $migrate_fn) {
                $migrate_fn();
            }
            print("OK\n");

            // Call test function
            // ==================
            print("\nRunning tests ... ");
            $test_fn();
            print("OK\n");
        }
    }
}

?>
