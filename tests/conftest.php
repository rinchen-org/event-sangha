<?php

print("[II] Configuring tests ...\n");
assert_options(ASSERT_ACTIVE,   true);
assert_options(ASSERT_BAIL,     true);
assert_options(ASSERT_WARNING,  false);
assert_options(ASSERT_CALLBACK, 'assert_failure');

require_once dirname(__DIR__) . "/src/migrations/all.php";


function clean_db(): void {
  $file = dirname(__DIR__) . '/src/db.sqlite';

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

    /**
     * @param array<string> $test_list
     */
    public static function run_tests(
        array $test_list,
    ): void {
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
            migrate_all();
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
