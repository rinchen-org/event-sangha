<?php

require_once dirname(__DIR__) . "/src/lib/attendance.php";
require_once __DIR__ . "/conftest.php";

use PHPUnit\Framework\TestCase;


class AttendanceTest extends TestCase
{
    public Person $person;
    public EventSangha $event;
    public EventSession $eventSession;

    public function setUp(): void
    {
        clean_db();
        migrate_all();

        $fullName = 'John Doe';
        $email = 'johndoe@example.com';
        $phone = '1234567890';

        $this->person = (
            new Person($fullName, $email, $phone)
        )->save();

        $currentDateTime = new DateTime();

        // Create a datetime 1 hour before the current datetime
        $oneHourBefore = clone $currentDateTime;
        $oneHourBefore->modify('-1 hour');

        // Create a datetime 1 hour after the current datetime
        $oneHourAfter = clone $currentDateTime;
        $oneHourAfter->modify('+1 hour');

        $this->event = (
            new EventSangha(
                "Event1",
                "Event1-Description",
                $oneHourBefore,
                $oneHourAfter,
            )
        )->save();
        $this->eventSession = (
            new EventSession(
                $this->event,
                "Event1-Session1",
                $oneHourBefore,
                $oneHourAfter
            )
        )->save();
    }

    // Test the log method
    public function testLogAttendance(): void
    {
        $attendance = Attendance::log(
            $this->person,
            $this->eventSession
        );

        // Assert that the log method returns true (insertion successful)
        $this->assertNotNull($attendance);
    }

    // Test the insert method (if necessary)
    public function testInsertAttendance(): void
    {
        // Create an instance of Attendance (replace with actual constructor arguments)
        $attendance = new Attendance(
            $this->person,
            $this->eventSession,
        );

        // Execute the insert method
        $result = $attendance->insert();

        // Assert that the insertion was successful (modify this assertion as needed)
        $this->assertNotNull($result);
    }

    public function testLogAttendanceSuccessful(): void
    {
        // Logging attendance, this should succeed
        $result = Attendance::log($this->person, $this->eventSession);

        // Check if the attendance was logged successfully
        $this->assertNotNull($result);

        // Retrieve the logged attendance record from the database
        $loggedAttendance = Attendance::get(['person_id' => $this->person->id]);

        // Assert that the logged attendance exists and has the correct values
        $this->assertNotNull($loggedAttendance);
        $this->assertEquals($this->person->id, $loggedAttendance->person->id);
        $this->assertEquals($this->eventSession->id, $loggedAttendance->eventSession->id);
    }

    public function testLogAttendanceOutsideEventSession(): void
    {
        // Creating a new attendance log for an event session outside the allowed time
        $currentDateTime = new DateTime();
        $oneHourBefore = clone $currentDateTime;
        $oneHourBefore->modify('-2 hours'); // Set it 2 hours before the event session start time
        $eventSession = (
            new EventSession(
                $this->event,
                "Event1-Session2",
                $oneHourBefore,
                $currentDateTime
            )
        )->save();

        $this->expectException(Exception::class);

        // Logging attendance, this should fail due to being outside the event session time
        Attendance::log($this->person, $eventSession);
    }

    public function testLogAttendanceWithoutPreviousSessionAttendance(): void
    {
        // Creating a new attendance log for an event session that's not the first session
        $currentDateTime = new DateTime();
        $oneHourBefore = clone $currentDateTime;
        $oneHourBefore->modify('-30 minutes'); // Set it 30 minutes before the event session start time
        $eventSession = (
            new EventSession(
                $this->event,
                "Event1-Session2",
                $oneHourBefore,
                $currentDateTime
            )
        )->save();

        $this->expectException(Exception::class);

        // Logging attendance, this should fail because the person didn't
        // attend the previous session
        Attendance::log($this->person, $eventSession);
    }
}

?>
