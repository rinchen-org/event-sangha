<?php

require_once __DIR__ . "/db.php";

class Event {
    public ?int $id;
    public string $name;
    public string $description;
    public DateTime $startDate;
    public DateTime $endDate;

    function __construct(
        string $name="",
        string $description="",
        DateTime $startDate=null,
        DateTime $endDate=null,
        ?int $id=null
    ) {
        $this->name = $name;
        $this->description = $description;
        $this->startDate = $startDate ?? new DateTime();
        $this->endDate = $endDate ?? new DateTime();
        $this->id = $id;
    }

    public static function get(array $data): ?Event {
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

        $event = new Event();
        $event->id = intval($row['id']);
        $event->name = $row['name'];
        $event->description = $row['description'];
        $event->startDate = new DateTime($row['start_date']);
        $event->endDate = new DateTime($row['end_date']);

        return $event;
    }

    /**
     * @return ?array<Event>
     */
    public static function list(): ?array {
        $db = get_db();
        $query = "SELECT * FROM event";
        $result = $db->query($query);

        if (!$result) {
            return null;
        }

        $event_list = [];

        while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
            $event_list[] = Event::get(["id" => $row['id']]);
        }

        return $event_list;
    }

    public function insert(): Event {
        $db = get_db();

        $startDateStr = $this->startDate->format('Y-m-d H:i:s');
        $endDateStr = $this->endDate->format('Y-m-d H:i:s');

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

        return Event::get(["id" => $lastInsertID]);
    }

    public function update(): Event {
        $db = get_db();
        $startDateStr = $this->startDate->format('Y-m-d H:i:s');
        $endDateStr = $this->endDate->format('Y-m-d H:i:s');

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

    public function save(): Event {
        if ($this->id !== null) {
            return $this->update();
        } else {
            return $this->insert();
        }
    }
}

class EventSession {
    public ?int $id;
    public Event $event;
    public string $name;
    public DateTime $startDate;
    public DateTime $endDate;

    function __construct(
        Event $event,
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
            // Fetch the associated Event
            $eventId = intval($row['event_id']);
            $event = Event::get(['id' => $eventId]);

            $eventSession = new EventSession(
                $event,
                $row['name'],
                new DateTime($row['start_date']),
                new DateTime($row['end_date']),
                intval($row['id'])
            );

            return $eventSession;
        }
    }

    /**
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
            // Fetch the associated Event
            $eventId = intval($row['event_id']);
            $event = Event::get(['id' => $eventId]);

            $eventSession = new EventSession(
                $event,
                $row['name'],
                new DateTime($row['start_date']),
                new DateTime($row['end_date']),
                intval($row['id'])
            );

            $eventSessions[] = $eventSession;
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
        $insertQuery = "INSERT INTO event_session (event_id, name, start_date, end_date)
            VALUES (
                {$this->event->id},
                '$this->name',
                '{$this->startDate->format('Y-m-d H:i:s')}',
                '{$this->endDate->format('Y-m-d H:i:s')}'
            )";

        $db->exec($insertQuery);
        $lastInsertID = $db->lastInsertRowID();

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
        $updateQuery = "UPDATE event_session
            SET
                event_id = {$this->event->id},
                name = '$this->name',
                start_date = '{$this->startDate->format('Y-m-d H:i:s')}',
                end_date = '{$this->endDate->format('Y-m-d H:i:s')}'
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
}

?>
