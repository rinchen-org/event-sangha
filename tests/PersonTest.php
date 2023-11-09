<?php

require_once dirname(__DIR__) . "/src/lib/person.php";
require_once __DIR__ . "/conftest.php";

use PHPUnit\Framework\TestCase;

class PersonTest extends TestCase
{
    protected function setUp(): void
    {
        // Set up your database and other configurations here.
        // You can migrate tables, clean the database, etc., as needed.
        clean_db();
        migrate_all();
    }

    public function testGetPerson(): void
    {
        $fullname = "test";
        $email = "test@test.com";
        $phone = "111111111";

        $result = Person::get([
            "fullname" => $fullname,
            "email" => $email,
            "phone" => $phone
        ]);

        $this->assertNull($result);

        $person = new Person();

        $person->fullname = $fullname;
        $person->email = $email;
        $person->phone = $phone;
        $person->save();

        $result = Person::get([
            "fullname" => $fullname,
            "email" => $email,
            "phone" => $phone
        ]);

        $this->assertNotNull($result);
        $this->assertInstanceOf(Person::class, $result);
        $this->assertEquals($fullname, $result->fullname);
        $this->assertEquals($email, $result->email);
        $this->assertEquals($phone, $result->phone);
        $this->assertEquals(1, $result->id);
    }

    public function testGetPersonById(): void
    {
        $fullname = "test";
        $email = "test@test.com";
        $phone = "111111111";

        $result = Person::get(["id" => 1]);

        $this->assertNull($result);

        $person = new Person();

        $person->fullname = $fullname;
        $person->email = $email;
        $person->phone = $phone;
        $person->save();

        $result = Person::get(["id" => 1]);

        $this->assertNotNull($result);
        $this->assertInstanceOf(Person::class, $result);
        $this->assertEquals($fullname, $result->fullname);
        $this->assertEquals($email, $result->email);
        $this->assertEquals($phone, $result->phone);
        $this->assertEquals(1, $result->id);
    }

    public function testPersonList(): void
    {
        $result = Person::list();
        $this->assertEmpty($result);

        $fullname = "test";
        $email = "test@test.com";
        $phone = "111111111";

        $maxRows = range(1, 5);

        foreach ($maxRows as $index) {
            $person = new Person();
            $person->fullname = $fullname . $index;
            $person->email = $email . $index;
            $person->phone = $phone . $index;
            $person->save();
        }

        $result = Person::list();

        foreach ($result as $person) {
            $this->assertInstanceOf(Person::class, $person);
            $this->assertNotNull($person);
            $this->assertEquals($fullname . $person->id, $person->fullname);
            $this->assertEquals($email . $person->id, $person->email);
            $this->assertEquals($phone . $person->id, $person->phone);
            $this->assertGreaterThan(0, $person->id);
        }
    }

    public function testPersonInvalid(): void
    {
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

        foreach ($people as $p) {
            $person = new Person();
            $person->fullname = $p["fullname"];
            $person->email = $p["email"];
            $person->phone = $p["phone"];

            $this->expectException(Exception::class);
            $this->expectExceptionMessage($p["error"]);

            $person->save();
        }
    }
}
