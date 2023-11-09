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

?>
