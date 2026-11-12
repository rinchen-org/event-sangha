<?php
require_once __DIR__ . '/auth.php';
auth_block_direct(__FILE__);
require_once __DIR__ . "/qr.php";
require_once __DIR__ . "/email.php";
require_once __DIR__ . "/db.php";
require_once __DIR__ . "/person.php";
require_once __DIR__ . "/settings.php";
require_once __DIR__ . "/datetime.php";
require_once __DIR__ . "/subscription_csv_import.php";
require_once __DIR__ . '/qr_assets.php';


class Subscription {
    public ?int $id;
    public ?Person $person;
    public DateTime $datetime;
    public string $qr;
    public ?int $active;

    function __construct(
        ?Person $person=null,
        string $qr="",
        ?DateTime $datetime=null,
        ?int $id=null,
        ?int $active=null
    ) {
        $this->person = $person;
        $this->qr = $qr;
        $this->id = $id;
        $this->datetime = $datetime ?? new DateTime('now', new DateTimeZone("America/La_Paz"));
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
            $subscription->person = Person::get(["id" => $row['person_id']]);
            $subscription->qr = $row['qr'];
            $subscription->datetime = convert_from_utc0(new DateTime($row['datetime']));
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
        $query = "
            SELECT subscription.*
            FROM subscription INNER JOIN person
              ON (subscription.person_id=person.id)
            WHERE 1=1
        ";

        if ($filter) {
            // Iterate over the data dictionary
            foreach ($filter as $key => $value) {
                // Escape the values to prevent SQL injection (assuming using SQLite3 class)
                $escapedValue = $db->escapeString($value);

                // Add the key-value pair to the WHERE clause
                $query .= " AND subscription.{$key}='$escapedValue'";
            }
        }

        $query .= " ORDER BY person.fullname";
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

        $datetime = convert_to_utc0($this->datetime)->format('Y-m-d H:i:s');

        $db = get_db();
        $person_id = $this->person->id;
        // Insert the form data into the 'registrations' table
        $insertQuery = "INSERT INTO subscription (person_id, datetime, qr, active)
                        VALUES ('$person_id', '$datetime', '$this->qr', 1)";
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
        $datetime = convert_to_utc0($this->datetime)->format('Y-m-d H:i:s');

        $updateQuery = "
            UPDATE subscription
            SET
                person_id='$person_id',
                datetime='$datetime',
                qr='$this->qr',
                active=$this->active
            WHERE id=$this->id";
        $db->exec($updateQuery);

        // Close the database connection
        $db->close();

        return $this;
    }

    /** Change status only; reviewed CSV phone warnings must not block activation. */
    public static function setActive(SQLite3 $db, int $id, int $active): void {
        if ($id < 1 || !in_array($active, [0, 1], true)) {
            throw new DomainException('La inscripción o el estado no son válidos.');
        }
        $db->enableExceptions(true);
        $db->busyTimeout(5000);
        $db->exec('BEGIN IMMEDIATE');
        try {
            $statement = $db->prepare('SELECT person_id FROM subscription WHERE id=:id');
            if ($statement === false) { throw new RuntimeException('No se pudo revisar la inscripción.'); }
            $statement->bindValue(':id', $id, SQLITE3_INTEGER);
            $result = $statement->execute();
            if ($result === false) { throw new RuntimeException('No se pudo revisar la inscripción.'); }
            $row = $result->fetchArray(SQLITE3_ASSOC);
            $result->finalize();
            $statement->close();
            if (!$row) { throw new DomainException('La inscripción no existe.'); }
            $personId = $row['person_id'];
            if (!is_int($personId) || $personId < 1) {
                throw new RuntimeException('La inscripción no tiene una persona válida.');
            }
            foreach ([['person', $personId], ['subscription', $id]] as [$table, $recordId]) {
                // Table names are fixed above, never supplied by the request.
                $statement = $db->prepare("UPDATE $table SET active=:active WHERE id=:id");
                if ($statement === false) { throw new RuntimeException('No se pudo preparar el cambio de estado.'); }
                $statement->bindValue(':active', $active, SQLITE3_INTEGER);
                $statement->bindValue(':id', $recordId, SQLITE3_INTEGER);
                $result = $statement->execute();
                if ($result === false) { throw new RuntimeException('No se pudo cambiar el estado.'); }
                $result->finalize();
                $statement->close();
                if ($db->changes() !== 1) { throw new RuntimeException('La inscripción no tiene una persona válida.'); }
            }
            $db->exec('COMMIT');
        } catch (Throwable $exception) {
            $db->exec('ROLLBACK');
            throw $exception;
        }
    }

    public static function subscribe_person(
        string $fullname, string $email, string $phone
    ): Subscription {
        app_base_url(); // Reject missing URL configuration before creating a person.
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
        Subscription::send_email($subscription_saved);
        return $subscription_saved;
    }

    /** @return array<string, mixed> */
    public static function upload_csv(string $file, bool $confirmed = false, string $actor = '', string $notes = ''): array {
        $review = SubscriptionCsv::read($file);
        if ($review['warnings'] && !$confirmed) {
            throw new DomainException('Revisa las advertencias y confirma la importación antes de continuar.');
        }
        $db = new SQLite3(dirname(__DIR__) . '/db.sqlite', SQLITE3_OPEN_READWRITE);
        try {
            return (new SubscriptionCsvImport($db))->run($review, $confirmed, $actor, $notes);
        } finally {
            $db->close();
        }
    }

    public static function send_email(Subscription $subscription): bool {
        $templateFile = dirname(__DIR__) . '/templates/subscription-email.html';

        $context = [
            '<QR>' => '<img src="cid:participant-qr" alt="Tu código QR personal" />',
            '{{HOST_ADDRESS}}' => auth_escape(app_base_url()),
        ];
        $image = qr_inline_image($subscription->qr);

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
                $cc,
                ['participant-qr' => $image]
            )
        ) {
            throw new Exception('Email could not be sent.');
        }
        return true;
    }
}

?>
