<?php

require_once dirname(__DIR__) . "/src/lib/event.php";
require_once __DIR__ . "/conftest.php";

use PHPUnit\Framework\TestCase;


class EventTest extends TestCase {

    public function setUp(): void
    {
        clean_db();
        migrate_all();
    }

    public function testInsertAndGetEvent() {
        $event = new Event("Test Event", "Description", new DateTime("2023-06-01 09:00:00"), new DateTime("2023-06-02 17:00:00"));
        $event->startControlDateTime = new DateTime("2023-06-01 08:30:00");
        $event->endControlDateTime = new DateTime("2023-06-02 17:30:00");

        // Insert the event into the database
        $insertedEvent = $event->save();

        // Get the event by ID
        $retrievedEvent = Event::get(["id" => $insertedEvent->id]);

        $this->assertNotNull($retrievedEvent);
        $this->assertEquals($event->name, $retrievedEvent->name);
        $this->assertEquals($event->description, $retrievedEvent->description);
        $this->assertEquals($event->startDate->format('Y-m-d H:i:s'), $retrievedEvent->startDate->format('Y-m-d H:i:s'));
        $this->assertEquals($event->endDate->format('Y-m-d H:i:s'), $retrievedEvent->endDate->format('Y-m-d H:i:s'));
    }

    public function testUpdateEvent() {
        $event = new Event("Test Event", "Description", new DateTime("2023-06-01 09:00:00"), new DateTime("2023-06-02 17:00:00"));
        $event->startDate = new DateTime("2023-06-01 08:30:00");
        $event->endDate = new DateTime("2023-06-02 17:30:00");

        // Insert the event into the database
        $insertedEvent = $event->save();

        // Get the event by ID
        $retrievedEvent = Event::get(["id" => $insertedEvent->id]);

        // Update the event
        $retrievedEvent->name = "Updated Event Name";
        $retrievedEvent->description = "Updated Description";
        $retrievedEvent->startDate = new DateTime("2023-06-03 10:00:00");
        $retrievedEvent->endDate = new DateTime("2023-06-04 18:00:00");

        // Save the updated event
        $updatedEvent = $retrievedEvent->save();

        // Get the event again by ID
        $retrievedUpdatedEvent = Event::get(["id" => $updatedEvent->id]);

        $this->assertEquals($updatedEvent->name, $retrievedUpdatedEvent->name);
        $this->assertEquals($updatedEvent->description, $retrievedUpdatedEvent->description);
        $this->assertEquals($updatedEvent->startDate->format('Y-m-d H:i:s'), $retrievedUpdatedEvent->startDate->format('Y-m-d H:i:s'));
        $this->assertEquals($updatedEvent->endDate->format('Y-m-d H:i:s'), $retrievedUpdatedEvent->endDate->format('Y-m-d H:i:s'));
    }
}

?>
