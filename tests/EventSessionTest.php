<?php

require_once dirname(__DIR__) . "/src/lib/event.php";
require_once __DIR__ . "/conftest.php";

use PHPUnit\Framework\TestCase;


class EventSessionTest extends TestCase
{
    public function setUp(): void
    {
        clean_db();
        migrate_all();
    }

    public function testInsertAndGet()
    {
        // Create an event
        $event = new Event("Test Event");
        $event->save();

        // Create an event session
        $eventSession = new EventSession(
            $event,
            "Test Session",
            new DateTime("2023-01-01 10:00:00"),
            new DateTime("2023-01-01 12:00:00"),
            null,
            new DateTime("2023-01-01 09:30:00"),
            new DateTime("2023-01-01 12:30:00")
        );
        $eventSession->save();

        // Get the event session by ID
        $retrievedSession = EventSession::get(["id" => $eventSession->id]);

        // Assert that the retrieved event session matches the original
        $this->assertEquals($eventSession->event->id, $retrievedSession->event->id);
        $this->assertEquals($eventSession->name, $retrievedSession->name);
        $this->assertEquals($eventSession->startDate, $retrievedSession->startDate);
        $this->assertEquals($eventSession->endDate, $retrievedSession->endDate);
        $this->assertEquals($eventSession->startControlDateTime, $retrievedSession->startControlDateTime);
        $this->assertEquals($eventSession->endControlDateTime, $retrievedSession->endControlDateTime);
    }

    public function testUpdate()
    {
        // Create an event
        $event = new Event("Test Event");
        $event->save();

        // Create an event session
        $eventSession = new EventSession(
            $event,
            "Test Session",
            new DateTime("2023-01-01 10:00:00"),
            new DateTime("2023-01-01 12:00:00"),
            null,
            new DateTime("2023-01-01 09:30:00"),
            new DateTime("2023-01-01 12:30:00")
        );
        $eventSession->save();

        // Update the event session
        $eventSession->name = "Updated Session Name";
        $eventSession->startControlDateTime = new DateTime("2023-01-01 09:00:00");
        $eventSession->update();

        // Get the updated event session by ID
        $retrievedSession = EventSession::get(["id" => $eventSession->id]);

        // Assert that the retrieved event session matches the updated values
        $this->assertEquals("Updated Session Name", $retrievedSession->name);
        $this->assertEquals("2023-01-01 09:00:00", $retrievedSession->startControlDateTime->format('Y-m-d H:i:s'));
    }

    public function testList()
    {
        // Create an event
        $event = new Event("Test Event");
        $event->save();

        // Create multiple event sessions
        $eventSessions = [];
        for ($i = 1; $i <= 3; $i++) {
            $eventSession = new EventSession(
                $event,
                "Session $i",
                new DateTime("2023-01-01 10:00:00"),
                new DateTime("2023-01-01 12:00:00")
            );
            $eventSession->save();
            $eventSessions[] = $eventSession;
        }

        // Get a list of event sessions
        $retrievedSessions = EventSession::list();

        // Assert that the number of retrieved event sessions matches the created ones
        $this->assertCount(3, $retrievedSessions);

        // Assert that each retrieved session is in the created list
        foreach ($retrievedSessions as $retrievedSession) {
            $this->assertContainsEquals($retrievedSession, $eventSessions);
        }
    }
}
