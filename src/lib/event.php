<?php

require_once __DIR__ . "/db.php";
require_once __DIR__ . "/datetime.php";


class EventSangha {
    public ?int $id;
    public string $name;
    public string $description;
    public DateTime $startDate;
    public DateTime $endDate;

    public function __construct(
        string $name,
        string $description,
        DateTime $startDate,
        DateTime $endDate,
        ?int $id=null
    ) {
        $this->name = $name;
        $this->description = $description;
        $this->startDate = $startDate;
        $this->endDate = $endDate;
        $this->id = $id;
    }

    /**
     * @param array<string,string|int> $data
     */
    public static function get(array $data): ?EventSangha {
        $db = get_db();

        $query = "SELECT * FROM event WHERE 1=1";

        foreach ($data as $key => $value) {
            $escapedValue = $db->escapeString($value);
            $query .= " AND $key='$escapedValue'";
        }

        $result = $db->query($query);

        if (!$result) {
            return null;
        }

        $row = $result->fetchArray(SQLITE3_ASSOC);

        if ($row === false) {
            return null;
        }

        return new EventSangha(
            $row['name'],
            $row['description'],
            convert_from_utc0(new DateTime($row['start_date'])),
            convert_from_utc0(new DateTime($row['end_date'])),
            intval($row['id'])
        );
    }

    /**
     * @param array<string,string|int> $filters
     * @return array<EventSangha>
     */
    public static function list(array $filters = []): array {
        $db = get_db();
        $query = "SELECT * FROM event WHERE 1=1";

        foreach ($filters as $key => $value) {
            $escapedValue = $db->escapeString($value);
            $query .= " AND $key='$escapedValue'";
        }
        $result = $db->query($query);

        if (!$result) {
            return [];
        }

        $event_list = [];

        while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
            $event_list[] = EventSangha::get(["id" => $row['id']]);
        }

        return $event_list;
    }

    public function insert(): EventSangha {
        $db = get_db();

        $startDateStr = convert_to_utc0($this->startDate)->format('Y-m-d H:i:s');
        $endDateStr = convert_to_utc0($this->endDate)->format('Y-m-d H:i:s');

        $insertQuery = "
            INSERT INTO event (name, description, start_date, end_date)
            VALUES (
                '$this->name',
                '$this->description',
                '$startDateStr',
                '$endDateStr'
            )";
        $db->exec($insertQuery);
        $lastInsertID = $db->lastInsertRowID();
        $db->close();

        return EventSangha::get(["id" => $lastInsertID]);
    }

    public function update(): EventSangha {
        $db = get_db();
        $startDateStr = convert_to_utc0($this->startDate)->format('Y-m-d H:i:s');
        $endDateStr = convert_to_utc0($this->endDate)->format('Y-m-d H:i:s');

        if (!$this->id) {
            throw new Exception("This event is not registered yet.");
        }

        $insertQuery = "
            UPDATE event
            SET
                name='$this->name',
                description='$this->description',
                start_date='$startDateStr',
                end_date='$endDateStr'
            WHERE id=$this->id";
        $db->exec($insertQuery);

        return $this;
    }

    public function save(): EventSangha {
        if ($this->id !== null) {
            return $this->update();
        } else {
            return $this->insert();
        }
    }

    /**
     * @return array<EventSession>
     */
    public function getSessions(): array {
        return EventSession::list([
            "event_id" => $this->id
        ]);
    }

    public function getFirstSession(): ?EventSession {
        // Retrieve the first session of the event
        // Assuming $eventSessions is an array of EventSession objects for this event
        $eventSessions = $this->getSessions();

        if (empty($eventSessions)) {
            return null; // No sessions for this event
        }

        // Sort sessions by start date (you may need to modify this depending on your data)
        usort($eventSessions, function ($a, $b) {
            return $a->startDate <=> $b->startDate;
        });

        return $eventSessions[0];
    }
}

class EventSession {
    public ?int $id;
    public EventSangha $event;
    public string $name;
    public DateTime $startDate;
    public DateTime $endDate;

    function __construct(
        EventSangha $event,
        string $name,
        DateTime $startDate,
        DateTime $endDate,
        ?int $id = null
    ) {
        $this->event = $event;
        $this->name = $name;
        $this->startDate = $startDate;
        $this->endDate = $endDate;
        $this->id = $id;
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function get(array $data): ?EventSession {
        $db = get_db();

        $query = "SELECT * FROM event_session WHERE 1=1";

        foreach ($data as $key => $value) {
            $escapedValue = $db->escapeString($value);
            $query .= " AND $key='$escapedValue'";
        }

        $result = $db->query($query);

        $row = $result->fetchArray(SQLITE3_ASSOC);

        if ($row === false) {
            return null;
        } else {
            // Fetch the associated EventSangha
            $eventId = intval($row['event_id']);
            $event = EventSangha::get(['id' => $eventId]);

            $eventSession = new EventSession(
                $event,
                $row['name'],
                convert_from_utc0(new DateTime($row['start_date'])),
                convert_from_utc0(new DateTime($row['end_date'])),
                intval($row['id'])
            );

            return $eventSession;
        }
    }

    /**
     * @param array<string,string|int> $filters
     * @return array<EventSession>
     */
    public static function list(array $filters = []): array {
        $db = get_db();
        $query = "SELECT * FROM event_session WHERE 1=1";

        foreach ($filters as $key => $value) {
            $escapedValue = $db->escapeString($value);
            $query .= " AND $key='$escapedValue'";
        }

        $result = $db->query($query);

        $eventSessions = [];

        while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
            // Fetch the associated EventSangha
            $eventSessionId = intval($row['id']);
            $eventSessions[] = EventSession::get(['id' => $eventSessionId]);
        }

        return $eventSessions;
    }

    function validate(): bool {
        if (empty($this->name)) {
            throw new Exception("Event session name is required.");
        }

        if ($this->startDate >= $this->endDate) {
            throw new Exception("Event session end date should be after the start date.");
        }

        return true;
    }

    function insert(): EventSession {
        $this->validate();

        $db = get_db();

        $startDate = convert_to_utc0($this->startDate)->format('Y-m-d H:i:s');
        $endDate = convert_to_utc0($this->endDate)->format('Y-m-d H:i:s');

        $insertQuery = "INSERT INTO event_session (event_id, name, start_date, end_date)
            VALUES (
                {$this->event->id},
                '$this->name',
                '{$startDate}',
                '{$endDate}'
            )";

        $db->exec($insertQuery);
        $lastInsertID = $db->lastInsertRowID();
        $this->id = $lastInsertID;

        $eventSession = EventSession::get(["id" => $lastInsertID]);

        if ($eventSession === null) {
            throw new Exception("The new event session is not available yet in the database.");
        }

        return $eventSession;
    }

    function update(): EventSession {
        $this->validate();

        if (!$this->id) {
            throw new Exception("This event session is not registered yet.");
        }

        $db = get_db();

        $startDate = convert_to_utc0($this->startDate)->format('Y-m-d H:i:s');
        $endDate = convert_to_utc0($this->endDate)->format('Y-m-d H:i:s');

        $updateQuery = "UPDATE event_session
            SET
                event_id = {$this->event->id},
                name = '$this->name',
                start_date = '{$startDate}',
                end_date = '{$endDate}'
            WHERE id = {$this->id}";

        $db->exec($updateQuery);

        return $this;
    }

    function save(): EventSession {
        if ($this->id) {
            return $this->update();
        }

        return $this->insert();
    }

    public function isFirstSession(): bool {
        $firstSession = $this->event->getFirstSession();
        return $this->id === $firstSession->id;
    }

    /**
     * @return array<EventSession>
     */
    public function getPreviousSessions(): array {
        // Retrieve all previous sessions of the same event
        $event = $this->event;
        $eventSessions = $event->getSessions();

        $previousSessions = [];

        foreach ($eventSessions as $session) {
            if ($session->startDate < $this->startDate) {
                $previousSessions[] = $session;
            }
        }

        return $previousSessions;
    }

    public static function getNextSession(int $eventId): ?EventSession
    {
        // Get the current datetime in UTC
        $currentDatetimeUtc = (
          new DateTime('now', new DateTimeZone('UTC'))
        )->format('Y-m-d H:i:s');

        // Connect to the database (You can use your own method for this)
        $db = get_db();

        // Query to find the next session for the specified event
        $query = "
          SELECT id FROM event_session
          WHERE event_id = {$eventId}
          AND datetime('{$currentDatetimeUtc}') >= datetime(start_date)
          AND datetime('{$currentDatetimeUtc}') <= datetime(end_date)
          ORDER BY start_date ASC
          LIMIT 1
        ";

        $result = $db->query($query);

        if ($row = $result->fetchArray(SQLITE3_ASSOC)) {
            // Construct and return the EventSession object
            return EventSession::get(
                ["id" => $row['id']]
            );
        }

        // No next session found
        return null;
    }
}

?>
