<?php

require_once __DIR__ . "/event.php";
require_once __DIR__ . "/subscription.php";
require_once __DIR__ . "/person.php";


class Attendance
{
    public ?int $id;
    public Person $person;
    public EventSession $eventSession;
    public DateTime $logTime;

    public function __construct(
        Person $person,
        EventSession $eventSession,
        ?DateTime $logTime=null,
        ?int $id=null
    ) {
        $this->person = $person;
        $this->eventSession = $eventSession;

        if ($logTime === null) {
            $this->logTime = new DateTime();
        } else {
            $this->logTime = $logTime;
        }
        $this->id = $id;
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
            $attendanceList[] = Attendance::get([
                "id" => intval($row['id'])
            ]);
        }

        // Close the database connection
        $db->close();

        return $attendanceList;
    }

    /**
     * Get an attendance record by ID.
     *
     * @param array<string,string|int> $data
     * @return Attendance|null An Attendance object or null if not found.
     */
    public static function get(array $data): ?Attendance {
        // Connect to the database (replace with your actual connection logic)
        $db = get_db();

        $query = "
        SELECT * FROM attendance
        WHERE 1=1";

        // Iterate over the data dictionary
        foreach ($data as $key => $value) {
            // Escape the values to prevent SQL injection (assuming using SQLite3 class)
            $escapedValue = $db->escapeString($value);

            // Add the key-value pair to the WHERE clause
            $query .= " AND $key='$escapedValue'";
        }

        // Execute the statement
        $result = $db->query($query);
        $row = $result->fetchArray(SQLITE3_ASSOC);

        if ($row === false) {
            // No matching record found
            return null;
        }

        $person = Person::get([
            "id" => $row['person_id']
        ]);
        $event_session = EventSession::get([
            "id" => $row['event_session_id']
        ]);

        $attendance = new Attendance(
            $person,
            $event_session,
            new DateTime($row['log_time']),
            intval($row['id'])
        );

        // Close the database connection
        $db->close();

        return $attendance;
    }

    public static function log(
        Person $person,
        EventSession $eventSession
    ): Attendance {
        $attendance = new Attendance($person, $eventSession, new DateTime());
        return $attendance->insert();
    }

    public function insert(): Attendance
    {
        $this->validate();

        $db = get_db(); // Assuming get_db returns a database connection

        // Prepare the SQL statement
        $personId = $this->person->id;
        $sessionId = $this->eventSession->id;
        $logTime = $this->logTime->format('Y-m-d H:i:s');
        $insertQuery = "
            INSERT INTO attendance (
                person_id, event_session_id, log_time
            ) VALUES (
                $personId,
                $sessionId,
                '$logTime'
            )";
        $db->exec($insertQuery);
        // Get the last inserted ID
        $lastInsertID = $db->lastInsertRowID();

        // Close the database connection
        $db->close();

        return Attendance::get(["id" => $lastInsertID]);
    }

    /**
     * Validate the attendance data.
     *
     * @throws Exception If validation fails.
     */
    public function validate(): bool
    {
        if ($this->person === null) {
            throw new Exception("Person is required.");
        }

        if (!$this->person->id) {
            throw new Exception("Person is invalid.");
        }

        if ($this->eventSession === null) {
            throw new Exception("Event Session is required.");
        }

        if (!$this->eventSession->id) {
            throw new Exception("Event Session is invalid.");
        }

        $currentDateTime = new DateTime();

        // Check if the current date and time is within the event session's interval
        if (
            $currentDateTime < $this->eventSession->startDate
            || $currentDateTime > $this->eventSession->endDate
        ) {
            throw new Exception(
                "Attendance (" . $currentDateTime->format('Y-m-d H:i:s')
                . ") is not allowed outside the event session ("
                . $this->eventSession->startDate->format('Y-m-d H:i:s')
                . " - "
                . $this->eventSession->endDate->format('Y-m-d H:i:s')
                . ")"

            );
        }

        // Check if it's the first session of the event
        $firstSession = $this->eventSession->isFirstSession(); // Implement this method

        if (!$firstSession) {
            // If it's not the first session, check if the person attended previous sessions
            $previousSessions = $this->eventSession->getPreviousSessions(); // Implement this method

            foreach ($previousSessions as $previousSession) {
                // Check if the person attended each previous session
                if (!$this->attendedSession($this->person, $previousSession)) { // Implement this method
                    throw new Exception("Attendance is not allowed. Please attend previous sessions.");
                }
            }
        }


        return true;
    }

    public static function attendedSession(
        Person $person, EventSession $eventSession
    ): bool {
        $attendance = Attendance::get([
            "person_id" => $person->id,
            "event_session_id" => $eventSession->id
        ]);

        return $attendance !== null;
    }
}

?>
