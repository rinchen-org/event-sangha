<?php

require_once dirname(__DIR__) . "/event/lib/person.php";
require_once dirname(__DIR__) . "/event/migrations/all.php";
require_once __DIR__ . "/conftest.php";


function test_person_get(): void {
  $fullname = "test";
  $email = "test@test.com";
  $phone = "111111111";

  $result = Person::get(
    [
      "fullname" => $fullname,
      "email" => $email,
      "phone" => $phone
    ]
  );

  assert($result == null);

  $person = new Person();

  $person->fullname = $fullname;
  $person->email = $email;
  $person->phone = $phone;
  $person->save();

  $result = Person::get(
    [
      "fullname" => $fullname,
      "email" => $email,
      "phone" => $phone
    ]
  );

  assert($result != null);
  assert(is_a($result, 'Person'));
  assert($result->fullname == $fullname);
  assert($result->email == $email);
  assert($result->phone == $phone);
  assert($result->id == 1);
}

function test_person_get_by_id(): void {
  $fullname = "test";
  $email = "test@test.com";
  $phone = "111111111";

  $result = Person::get(
    [
      "id" => 1
    ]
  );

  assert($result == null);

  $person = new Person();

  $person->fullname = $fullname;
  $person->email = $email;
  $person->phone = $phone;
  $person->save();

  $result = Person::get(
    [
      "id" => 1
    ]
  );

  assert($result != null);
  assert(is_a($result, 'Person'));
  assert($result->fullname == $fullname);
  assert($result->email == $email);
  assert($result->phone == $phone);
  assert($result->id == 1);
}

function test_person_list(): void {
  $result = Person::list();
  assert($result == []);

  $fullname = "test";
  $email = "test@test.com";
  $phone = "111111111";

  $max_rows = range(1, 5);

  foreach ($max_rows as $index) {
    $person = new Person();
    $person->fullname = $fullname . $index;
    $person->email = $email . $index;
    $person->phone = $phone . $index;
    $person->save();
  }

  $result = Person::list();

  // Iterate over the list of Person objects
  foreach ($result as $person) {
    assert(is_object($person) && $person instanceof Person);

    assert($person != null);
    assert(is_a($person, 'Person'));
    assert($person->fullname == $fullname . $person->id);
    assert($person->email == $email . $person->id);
    assert($person->phone == $phone . $person->id);
    assert($person->id > 0);
  }
}

function test_person_invalid(): void {
  $people = [
      [
          "fullname" => "",
          "email" => "john@example.com",
          "phone" => "1234567890",
          "error" => "Fullname is required."
      ],
      [
          "fullname" => "Jane Smith",
          "email" => "",
          "phone" => "9876543210",
          "error" => "Email is required."
      ],
      [
        "fullname" => "Jane Smith",
        "email" => "jane",
        "phone" => "9876543210",
        "error" => "Email is invalid."
      ],
      [
        "fullname" => "Jane Smith",
        "email" => "jane@example.com",
        "phone" => "",
        "error" => "Phone is required."
      ],
      [
        "fullname" => "Jane Smith",
        "email" => "jane@example.com",
        "phone" => "234sdfasdf",
        "error" => "Phone is invalid."
      ],
  ];

  // Iterate over the list of dictionaries
  foreach ($people as $p) {
      $person = new Person();
      $person->fullname = $p["fullname"];
      $person->email = $p["email"];
      $person->phone = $p["phone"];

      $person_saved = false;
      try {
        $person->save();
        $person_saved = true;
      } catch (Exception $e) {
        assert ($e->getMessage() == $p["error"]);
      }
      assert($person_saved == false);
  }
}

function run_tests(): void {
  // Create a list of function names
  $test_list = [
    'test_person_get',
    'test_person_get_by_id',
    'test_person_list',
    'test_person_invalid',
  ];

  $migrate_list = [
    'person_table',
    'person_add_activate_column'
  ];

  TestCase::run_tests($test_list, $migrate_list);
}


run_tests();

?>
