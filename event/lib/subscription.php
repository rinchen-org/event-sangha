<?php
require_once __DIR__ . "/qr.php";
require_once __DIR__ . "/db.php";
require_once __DIR__ . "/person.php";

class Subscription {
    public $id;
    public $person;
    public $datetime;
    public $qr;

    function __construct($person=null, $qr="", $datetime="", $id=null) {
        $this->person = $person;
        $this->qr = $qr;
        $this->id = $id;
        $this->datetime = $datetime;
    }

    public static function get($data) {
        $db = get_db();

        $query = "
        SELECT * FROM subscription
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
            $subscription = null;
        } else {
            $subscription = new Subscription();
            $subscription->id = $row['id'];
            $subscription->person = Person::get(["id" => $row['id']]);
            $subscription->qr= $row['qr'];
            $subscription->datetime= $row['datetime'];
        }

        return $subscription;
    }

    public static function list() {
        $db = get_db();
        $query = "SELECT * FROM subscription";
        $result = $db->query($query);

        $subscription_list = [];

        while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
            $subscription = new Subscription();

            $subscription->id = $row['id'];
            $subscription->person = Person::get([
                "id" => $row['person_id']
            ]);
            $subscription->datetime = $row['datetime'];
            $subscription->qr = $row['qr'];
            $subscription_list[] = $subscription;
        }

        return $subscription_list;
    }


    function validate() {

        if ($this->person == null) {
            return "Person is required.";
        }

        if (!$this->person->id) {
            return "Person is invalid.";
        }

        if ($this->qr == "") {
            return "QR is required.";
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
            throw new Exception($error);
        }

        $this->qr = generate_qr(
            $this->person->fullname,
            $this->person->email,
            $this->person->phone
        );

        $datetime = date('Y-m-d H:i:s');

        $db = get_db();
        $person_id = $this->person->id;
        // Insert the form data into the 'registrations' table
        $insertQuery = "INSERT INTO subscription (person_id, datetime, qr)
                        VALUES ('$person_id', '$this->datetime', '$this->qr')";
        $db->exec($insertQuery);

        // Close the database connection
        $db->close();
    }

    function update() {

    }

}


function subscribe_person($fullname, $email, $phone) {
    // Open the SQLite database
    $person = Person::get([
        "fullname" => $fullname,
        "email" => $email,
        "phone" => $phone
    ]);

    if ($person == null) {
        $person = new Person();
        $person->fullname = $fullname;
        $person->email = $email;
        $person->phone = $phone;
        $error = $person->save();

        if ($error) {
            throw new Exception($error);
        }
    }

    $subscription = Subscription::get([
        "person" => $person
    ]);

    if ($subscription) {
        throw new Exception("This person is already subscribed.");
    }

    $subscription = new Subscription();
    $subscription->person = $person;
    $error = $subscription->insert();

    if ($error) {
        throw new Exception($error);
    }

    return $subscription->qr;
}

function upload_csv($file) {
    // Process the uploaded CSV file
    $handle = fopen($file, 'r');
    $header = fgetcsv($handle); // Read the header row

    // Map the CSV fields to database columns
    $fieldMappings = [
        'Nombre' => 'fullname',
        'Apellidos' => 'fullname',
        'Correo electrónico' => 'email',
        'Número de celular' => 'phone',
    ];

    $count = 0;
    $existingRows = [];

    while (($row = fgetcsv($handle)) !== false) {
        $data = array_combine($header, $row); // Combine header with row data

        // Select the desired fields
        $selectedData = [
            'subscription_datetime' => $data['Timestamp'],
            'fullname' => $data['Nombre'] . ' ' . $data['Apellidos'],
            'email' => $data['Correo electrónico'],
            'phone' => $data['Número de celular'],
        ];

        $qr = subscribe_person(
            $selectedData['fullname'],
            $selectedData['email'],
            $selectedData['phone'],
        );

        if ($qr) {
            // send QR vi email
            $count++;
        }
    }

    fclose($handle);
    $db->close();

    echo "<p>Successfully imported $count rows.</p>";

    if (!empty($existingRows)) {
        echo "<p>The following rows already exist in the database:</p>";
        echo "<ul>";
        foreach ($existingRows as $row) {
            echo "<li>Fullname: " . $row['fullname'] . ", Email: " . $row['email'] . ", Phone: " . $row['phone'] . "</li>";
        }
        echo "</ul>";
    }
}


?>
