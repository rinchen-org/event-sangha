<?php
require_once __DIR__ . "/qr.php";
require_once __DIR__ . "/db.php";


class Person {
    public ?int $id;
    public string $fullname;
    public string $email;
    public string $phone;
    public ?int $active;

    function __construct(
        string $fullname="",
        string $email="",
        string $phone="",
        ?int $id=null,
        ?int $active=null
    ) {
        $this->fullname = $fullname;
        $this->email = $email;
        $this->phone = $phone;
        $this->id = $id;
        $this->active = $active;
    }

    /**
     * @param array<string,string|int> $data
     */
    public static function get(array $data): ?Person {
        $db = get_db();

        $query = "
        SELECT * FROM person
        WHERE 1=1";

        // Iterate over the data dictionary
        foreach ($data as $key => $value) {
            // Escape the values to prevent SQL injection (assuming using SQLite3 class)
            $escapedValue = $db->escapeString($value);

            // Add the key-value pair to the WHERE clause
            $query .= " AND $key='$escapedValue'";
        }

        $result = $db->query($query);

        $row = $result->fetchArray(SQLITE3_ASSOC);

        if ($row === false) {
            // No rows returned, result is empty
            $person = null;
        } else {
            $person = new Person();
            $person->id = intval($row['id']);
            $person->fullname = $row['fullname'];
            $person->email = $row['email'];
            $person->phone = $row['phone'];
            $person->active = intval($row['active']);
        }

        return $person;
    }

    /**
     * @return array<Person>
     */
    public static function list(): array {
        $db = get_db();
        $query = "SELECT * FROM person";
        $result = $db->query($query);

        $person_list = [];

        while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
            $person = Person::get([
                "id" => $row['id']
            ]);
            $person_list[] = $person;
        }

        return $person_list;
    }

    function validate(): bool {
        if ($this->fullname == "") {
            throw new Exception("Fullname is required.");
        }

        if ($this->email == "") {
            throw new Exception("Email is required.");
        }

        if (!filter_var($this->email, FILTER_VALIDATE_EMAIL)) {
            throw new Exception("Email is invalid.");
        }

        if ($this->phone == "") {
            throw new Exception("Phone is required.");
        }

        // Remove any whitespace from the phone number
        $phone = str_replace(' ', '', $this->phone);

        // Define the regex pattern for phone number validation
        $pattern = '/^\+?\d+$/';

        // Perform the validation
        if (!preg_match($pattern, $phone)) {
            throw new Exception("Phone is invalid.");
        }

        return true;
    }

    function save(): Person {
        if ($this->id > 0) {
            return $this->update();
        }
        return $this->insert();
    }

    function insert(): Person {
        $this->validate();

        $db = get_db();
        // Insert the form data into the 'registrations' table
        $insertQuery = "
            INSERT INTO person (fullname, email, phone, active)
            VALUES (
                '$this->fullname',
                '$this->email',
                '$this->phone',
                '$this->active'
            )";
        $db->exec($insertQuery);

        $lastInsertID = $db->lastInsertRowID();

        // Close the database connection
        $db->close();

        $person = Person::get(["id" => $lastInsertID]);

        if ($person === null) {
            throw new Exception(
                "The new person is not available yet in the database."
            );
        }

        return $person;
    }

    function update(): Person {
        $this->validate();

        if (!$this->id > 0) {
            throw new Exception("This person is not registered yet.");
        }

        $db = get_db();
        $insertQuery = "
            UPDATE person
            SET
                fullname='$this->fullname',
                email='$this->email',
                phone='$this->phone',
                active=$this->active
            WHERE id=$this->id";
        $db->exec($insertQuery);

        // Close the database connection
        $db->close();

        return $this;
    }
}
?>
