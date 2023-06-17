<?php
require_once __DIR__ . "/qr.php";
require_once __DIR__ . "/db.php";


class Person {
    public $id;
    public $fullname;
    public $email;
    public $phone;

    function __construct($fullname="", $email="", $phone="", $id=null) {
        $this->fullname = $fullname;
        $this->email = $email;
        $this->phone = $phone;
        $this->id = $id;
    }

    public static function get($data) {
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
            $person->id = $row['id'];
            $person->fullname = $row['fullname'];
            $person->email = $row['email'];
            $person->phone = $row['phone'];
        }

        return $person;
    }

    public static function list() {
        $db = get_db();
        $query = "SELECT * FROM person";
        $result = $db->query($query);

        $person_list = [];

        while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
            $person = new Person();

            $person->id = $row['id'];
            $person->fullname = $row['fullname'];
            $person->phone = $row['phone'];
            $person->email = $row['email'];
            $person_list[] = $person;
        }

        return $person_list;
    }

    function validate() {
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

    function save() {
        if ($this->id) {
            return $this->update();
        }
        return $this->insert();
    }

    function insert() {
        $this->validate();

        $this->qr = generate_qr(
            $this->fullname,
            $this->email,
            $this->phone
        );

        $db = get_db();
        // Insert the form data into the 'registrations' table
        $insertQuery = "INSERT INTO person (fullname, email, phone)
                        VALUES ('$this->fullname', '$this->email', '$this->phone')";
        $db->exec($insertQuery);

        $lastInsertID = $db->lastInsertRowID();

        // Close the database connection
        $db->close();

        return Person::get(["id" => $lastInsertID]);
    }

    function update() {

    }

}
?>
