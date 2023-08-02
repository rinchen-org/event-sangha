<?php
require_once __DIR__ . "/qr.php";
require_once __DIR__ . "/email.php";
require_once __DIR__ . "/db.php";
require_once __DIR__ . "/person.php";
require_once __DIR__ . "/settings.php";


class Subscription {
    public ?int $id;
    public ?Person $person;
    public string $datetime;
    public string $qr;
    public ?int $active;

    function __construct(
        ?Person $person=null,
        string $qr="",
        string $datetime="",
        ?int $id=null,
        ?int $active=null
    ) {
        $this->person = $person;
        $this->qr = $qr;
        $this->id = $id;
        $this->datetime = $datetime;
        $this->active = $active;
    }

    /**
     * @param array<string,string|int> $data
     */
    public static function get(array $data): ?Subscription {
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
            $subscription->id = intval($row['id']);
            $subscription->person = Person::get(["id" => $row['id']]);
            $subscription->qr = $row['qr'];
            $subscription->datetime = $row['datetime'];
            $subscription->active = intval($row['active']);
        }

        return $subscription;
    }

    /**
     * @param array<string,string|int> $filter
     * @return ?array<Subscription>
     */
    public static function list(?array $filter=null): ?array {
        $db = get_db();
        $query = "SELECT * FROM subscription WHERE 1=1";

        if ($filter) {
            // Iterate over the data dictionary
            foreach ($filter as $key => $value) {
                // Escape the values to prevent SQL injection (assuming using SQLite3 class)
                $escapedValue = $db->escapeString($value);

                // Add the key-value pair to the WHERE clause
                $query .= " AND $key='$escapedValue'";
            }
        }

        $result = $db->query($query);

        $subscription_list = [];

        if (!$result) {
            return null;
        }

        while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
            $subscription = Subscription::get([
                "id" => $row['id']
            ]);
            $subscription_list[] = $subscription;
        }

        return $subscription_list;
    }


    function validate(): bool {

        if ($this->person == null) {
            throw new Exception("Person is required.");
        }

        if (!$this->person->id) {
            throw new Exception("Person is invalid.");
        }

        return true;
    }

    function save(): Subscription {
        if ($this->id) {
            return $this->update();
        }
        return $this->insert();
    }

    function insert(): Subscription {
        $this->validate();

        $this->qr = generate_qr(
            $this->person->fullname,
            $this->person->email,
            $this->person->phone
        );

        $datetime = date('Y-m-d H:i:s');

        $db = get_db();
        $person_id = $this->person->id;
        // Insert the form data into the 'registrations' table
        $insertQuery = "INSERT INTO subscription (person_id, datetime, qr, active)
                        VALUES ('$person_id', '$this->datetime', '$this->qr', 1)";
        $db->exec($insertQuery);

        // Get the last inserted ID
        $lastInsertID = $db->lastInsertRowID();

        // Close the database connection
        $db->close();

        return Subscription::get(["id" => $lastInsertID]);
    }

    function update(): Subscription {
        $this->validate();

        if (!$this->id > 0) {
            throw new Exception("This subscription is not registered yet.");
        }

        $db = get_db();
        $person_id = $this->person->id;
        $updateQuery = "
            UPDATE subscription
            SET
                person_id='$person_id',
                datetime='$this->datetime',
                qr='$this->qr',
                active=$this->active
            WHERE id=$this->id";
        $db->exec($updateQuery);

        // Close the database connection
        $db->close();

        return $this;
    }

    public static function subscribe_person(
        string $fullname, string $email, string $phone
    ): Subscription {
        // Open the SQLite database
        $person_data = [
            "fullname" => $fullname,
            "email" => $email,
            "phone" => $phone,
        ];

        $person = Person::get($person_data);

        if ($person == null) {
            $person = new Person();
            $person->fullname = $fullname;
            $person->email = $email;
            $person->phone = $phone;
            $person->active = 1;

            $person = $person->save();
        }

        $subscription = Subscription::get([
            "person_id" => $person->id
        ]);

        if ($subscription) {
            throw new Exception(
                "This person is already subscribed: "
                . $person_data["fullname"]
            );
        }

        $subscription = new Subscription();
        $subscription->person = $person;

        $subscription_saved = $subscription->insert();
        // TODO: just skip it for development, it should be enabled
        // before the PR is merged.
        // Subscription::send_email($subscription_saved);
        return $subscription_saved;
    }

    public static function upload_csv(string $file): void {
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
        $error = "";

        while (($row = fgetcsv($handle)) !== false) {
            $data = array_combine($header, $row); // Combine header with row data

            if ($data["valido"] != 1) {
                $err = "Warning: Dato marcado como no valido (falta pago): " . $data['Correo electrónico'];
                print("<p>$err</p>");
                continue;
            }

            // Select the desired fields
            $selectedData = [
                'fullname' => $data['Nombre'] . ' ' . $data['Apellidos'],
                'email' => $data['Correo electrónico'],
                'phone' => $data['Número de celular'],
            ];

            try {
                $subscription = Subscription::subscribe_person(
                    $selectedData['fullname'],
                    $selectedData['email'],
                    $selectedData['phone'],
                );
                $count++;
            } catch (Exception $e) {
                $subscription = null;
                $err = "\nWarning: " . $e->getMessage() . "\n";
                print("<p>$err</p>");
                $error = $error . $err;
                continue;
            }
        }

        fclose($handle);

        // note: it is not ideal to have this html here.
        echo "<p>Successfully imported $count rows.</p>";
    }

    public static function send_email(Subscription $subscription): bool {
        $templateFile = dirname(__DIR__) . '/templates/subscription-email.html';

        $context = [
            "<QR>" => "<img src='$subscription->qr'/>",
        ];

        $to = $subscription->person->email;
        $from = get_env("EMAIL_FROM");
        $reply_to = get_env("EMAIL_REPLY_TO");
        $cc = get_env("EMAIL_CC");
        $subject = 'Centro Sakya Rinchen Ling - Confirmación Inscripción al Retiro';

        // Send the email
        if (
            !send_email(
                $templateFile,
                $context,
                $subject,
                $from,
                $to,
                $reply_to,
                $cc
            )
        ) {
            throw new Exception('Email could not be sent.');
        }
        return true;
    }
}

?>
