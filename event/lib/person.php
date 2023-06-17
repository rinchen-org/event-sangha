<?php
require __DIR__ . "/qr.php";
require __DIR__ . "/db.php";


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

    public static function get($fullname, $email, $phone) {
        $db = get_db();
        $query = "
        SELECT * FROM person
        WHERE fullname='$fullname'
          AND email='$email'
          AND phone='$phone'";
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
            return "Fullname is required.";
        }

        if ($this->email == "") {
            return "Email is required.";
        }

        if (!filter_var($this->email, FILTER_VALIDATE_EMAIL)) {
            return "Email is invalid.";
        }

        if ($this->phone == "") {
            return "Phone is required.";
        }

        // Remove any whitespace from the phone number
        $phone = str_replace(' ', '', $this->phone);

        // Define the regex pattern for phone number validation
        $pattern = '/^\+?\d+$/';

        // Perform the validation
        if (!preg_match($pattern, $phone)) {
            return "Phone is invalid.";
        }

        return null;
    }

    function save() {
        if ($this->id) {
            return $this->update();
        }
        return $this->insert();
    }

    function insert() {
        $error = $this->validate();

        if ($error) {
            return $error;
        }

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

        // Close the database connection
        $db->close();
    }

    function update() {

    }

}
?>
