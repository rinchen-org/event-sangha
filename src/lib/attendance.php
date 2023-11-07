<?php

require_once __DIR__ . "/event.php";
require_once __DIR__ . "/subscription.php";
require_once __DIR__ . "/person.php";


class Attendance
{
    public Person $person;
    public EventSession $eventSession;
    public DateTime $logTime;

    public function __construct(Person $person, EventSession $eventSession, DateTime $logTime)
    {
        $this->person = $person;
        $this->eventSession = $eventSession;
        $this->logTime = $logTime;
    }

    public static function log(string $fullName, string $email, string $phone): bool
    {
        // Get the person using the provided data
        $person = Person::get([
            "fullname" => $fullName,
            "email" => $email,
            "phone" => $phone
        ]);

        if ($person === null) {
            // Person not found, cannot log attendance
            return false;
        }

        // Get the current EventSession based on the current date and time
        $currentEventSession = EventSession::getCurrentEventSession();

        if ($currentEventSession === null) {
            // No active event session found, cannot log attendance
            return false;
        }

        // Create an Attendance object and populate it
        $attendance = new Attendance($person, $currentEventSession, new DateTime());

        // Insert the attendance record
        $attendance->insert();

        return true;
    }

    /**
     * List all attendance records.
     *
     * @return array<Attendance> An array of Attendance objects.
     */
    public static function list(): array {
        // Connect to the database (replace with your actual connection logic)
        $db = get_db();

        // Perform a query to retrieve all attendance records
        $query = "SELECT * FROM attendance";
        $result = $db->query($query);

        $attendanceList = [];

        // Fetch the results and create Attendance objects
        while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
            $attendance = new Attendance();
            $attendance->id = intval($row['id']);
            $attendance->subscriptionId = intval($row['subscription_id']);
            $attendance->sessionId = intval($row['session_id']);
            $attendance->logTime = $row['log_time'];

            $attendanceList[] = $attendance;
        }

        // Close the database connection
        $db->close();

        return $attendanceList;
    }

    /**
     * Get an attendance record by ID.
     *
     * @param int $id The ID of the attendance record to retrieve.
     * @return Attendance|null An Attendance object or null if not found.
     */
    public static function get(int $id): ?Attendance {
        // Connect to the database (replace with your actual connection logic)
        $db = get_db();

        // Prepare a statement to retrieve the attendance record by ID
        $stmt = $db->prepare("SELECT * FROM attendance WHERE id = :id");
        $stmt->bindValue(':id', $id, SQLITE3_INTEGER);

        // Execute the statement
        $result = $stmt->execute();

        // Fetch the result and create an Attendance object
        $row = $result->fetchArray(SQLITE3_ASSOC);

        if ($row === false) {
            // No matching record found
            return null;
        }

        $attendance = new Attendance();
        $attendance->id = intval($row['id']);
        $attendance->subscriptionId = intval($row['subscription_id']);
        $attendance->sessionId = intval($row['session_id']);
        $attendance->logTime = $row['log_time'];

        // Close the database connection
        $db->close();

        return $attendance;
    }

    public function insert(): bool
    {
        $db = get_db(); // Assuming get_db returns a database connection

        // Prepare the SQL statement
        $stmt = $db->prepare('INSERT INTO attendance (person_id, event_session_id, log_time) VALUES (:person_id, :event_session_id, :log_time)');
        $stmt->bindValue(':person_id', $this->person->id, SQLITE3_INTEGER);
        $stmt->bindValue(':event_session_id', $this->eventSession->id, SQLITE3_INTEGER);
        $stmt->bindValue(':log_time', $this->logTime->format('Y-m-d H:i:s'), SQLITE3_TEXT);

        // Execute the SQL statement
        $result = $stmt->execute();

        // Check if the insertion was successful
        if ($result) {
            $stmt->close();
            return true;
        } else {
            $stmt->close();
            return false;
        }
    }

}

?>
