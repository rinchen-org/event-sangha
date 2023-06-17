<?php

require __DIR__ . "/../event/lib/subscription.php";
require __DIR__ . "/conftest.php";


function test_subscription_get() {
  $fullname = "test";
  $email = "test@test.com";
  $phone = "111111111";

  $result = Subscription::get(
    $fullname, $email, $phone
  );

  assert($result == null);

  $subscription = new Subscription();

  $subscription->fullname = $fullname;
  $subscription->email = $email;
  $subscription->phone = $phone;
  $subscription->save();

  $result = Subscription::get(
    $fullname, $email, $phone
  );

  assert($result != null);
  assert(is_a($result, 'Subscription'));
  assert($result->fullname == $fullname);
  assert($result->email == $email);
  assert($result->phone == $phone);
  assert($result->id == 1);
  assert($result->qr != "");
}

function run_tests() {
  // start the tests with empty database
  print("\nCleaning the database ... ");
  clean_db();
  print("OK\n");

  // migrate the tables for subscription
  print("\nMigrating database ... ");
  subscription_table();
  print("OK\n");

  // tests
  print("\nRunning tests ... ");
  test_subscription_get();
  print("OK\n");
}
run_tests();

?>
