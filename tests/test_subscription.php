<?php

require_once __DIR__ . "/../event/lib/subscription.php";
require_once __DIR__ . "/conftest.php";


function setup($n_samples=1) {
  $max_rows = range(1, $n_samples);
  $result = [];

  foreach ($max_rows as $index) {
    $fullname = "test" . $index;
    $email = "test@test.com" . $index;
    $phone = "111111111" . $index;

    $person = new Person();
    $person->fullname = $fullname;
    $person->email = $email;
    $person->phone = $phone;
    $error = $person->save();

    assert(!$error);

    $person = Person::get([
      "fullname" => $fullname,
      "email" => $email,
      "phone" => $phone,
    ]);

    assert($person);
    $result[] = $person;
  }

  return [
    "people" => $result,
  ];
}

function test_subscription_get() {
  $data = setup();
  $person = $data["people"][0];

  $result = Subscription::get(
    [
      "person_id" => $person->id,
    ]
  );
  assert($result == null);

  $subscription = new Subscription();

  $subscription->person = $person;
  $subscription->qr = "asdf";
  $subscription->save();

  $result = Subscription::get(
    [
      "person_id" => $person->id,
    ]
  );

  assert($result != null);
  assert(is_a($result, 'Subscription'));
  assert($result->person->fullname == $person->fullname);
  assert($result->person->email == $person->email);
  assert($result->person->phone == $person->phone);
  assert($result->id == 1);
}

function test_subscription_get_by_id() {
  $data = setup();
  $person = $data["people"][0];

  $subscription = new Subscription();
  $subscription->person = $person;
  $subscription->qr = "sdf";
  $subscription->save();

  $result = Subscription::get([
    "id" => 1,
  ]);

  assert($result != null);
  assert(is_a($result, 'Subscription'));
  assert($result->person->fullname == $person->fullname);
  assert($result->person->email == $person->email);
  assert($result->person->phone == $person->phone);
  assert($result->id == 1);
}

function test_subscription_list() {
  $n_samples = 5;
  $data = setup($n_samples);
  $people = $data["people"];

  $result = Subscription::list();
  assert($result == []);

  $max_rows = range(1, $n_samples);

  foreach ($max_rows as $index) {
    $subscription = new Subscription();
    $subscription->person = $people[$index - 1];
    $subscription->qr = "asdf";
    $subscription->save();
  }

  $result = Subscription::list();

  assert($result != null);

  // Iterate over the list of Subscription objects
  foreach ($result as $subscription) {
    assert(is_object($subscription) && $subscription instanceof Subscription);

    $person_ind = $subscription->id -1;
    $suffix = $subscription->id;

    assert($subscription != null);
    assert(is_a($subscription, 'Subscription'));
    assert($subscription->person->fullname);
    assert($subscription->person->fullname == $people[$person_ind]->fullname);
    assert($subscription->person->email);
    assert($subscription->person->email == $people[$person_ind]->email);
    assert($subscription->person->phone);
    assert($subscription->person->phone == $people[$person_ind]->phone);
    assert($subscription->id);
  }
}

function test_subscription_invalid() {
  $data = setup();
  $person = $data["people"][0];

  $subscriptions = [
      [
          "person" => null,
          "qr" => "sdfasdf",
          "error" => "Person is required."
      ],
      [
          "person" => new Person(),
          "qr" => "asdf",
          "error" => "Person is invalid."
      ],
      [
        "person" => $person,
        "qr" => "",
        "error" => "QR is required."
      ],
  ];

  // Iterate over the list of dictionaries
  foreach ($subscriptions as $p) {
      $subscription = new Subscription();
      $subscription->person = $p["person"];
      $subscription->qr = $p["qr"];

      try {
        $error = $subscription->save();
        assert(False);
      } catch (Exception $e) {
        assert($e->getMessage() == $p["error"]);
      }
  }
}


function run_tests() {
  // Create a list of function names
  $test_list = [
    'test_subscription_get',
    'test_subscription_get_by_id',
    'test_subscription_list',
    'test_subscription_invalid'
  ];

  $migrate_list = ['person_table', 'subscription_table'];

  TestCase::run_tests($test_list, $migrate_list);
}

run_tests();

?>
