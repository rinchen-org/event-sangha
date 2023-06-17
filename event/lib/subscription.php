<?php
require __DIR__ . "/qr.php";
require __DIR__ . "/db.php";

class Subscription {
    public $id;
    public $fullname;
    public $email;
    public $phone;
    public $qr;

    function __construct($fullname="", $email="", $phone="", $qr="", $id=null) {
        $this->fullname = $fullname;
        $this->email = $email;
        $this->phone = $phone;
        $this->qr = $qr;
        $this->id = $id;
    }

    public static function get($fullname, $email, $phone) {
        $db = get_db();
        $query = "
        SELECT * FROM subscription
        WHERE fullname='$fullname'
          AND email='$email'
          AND phone='$phone'";
        $result = $db->query($query);

        $row = $result->fetchArray(SQLITE3_ASSOC);

        if ($row === false) {
            // No rows returned, result is empty
            $subscription = null;
        } else {
            $subscription = new Subscription();
            $subscription->id = $row['id'];
            $subscription->fullname = $row['fullname'];
            $subscription->email = $row['email'];
            $subscription->phone = $row['phone'];
            $subscription->qr= $row['qr'];
        }

        return $subscription;
    }

    function validate() {

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
        $insertQuery = "INSERT INTO subscription (fullname, email, phone, qr)
                        VALUES ('$this->fullname', '$this->email', '$this->phone', '$this->qr')";
        $db->exec($insertQuery);

        // Close the database connection
        $db->close();
    }

    function update() {

    }

}


function subscribe_person($fullname, $email, $phone) {
    // Open the SQLite database
    $subscription = new Subscription($fullname, $email, $phone);
    $error = $subscription->insert();

    if ($error) {
        die($error);
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


function get_subscription_list() {
    $db = new SQLite3('db.sqlite');
    $query = "SELECT * FROM subscription";
    return $db->query($query);
}


?>
