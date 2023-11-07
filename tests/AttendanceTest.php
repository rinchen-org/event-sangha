<?php

require_once dirname(__DIR__) . "/src/lib/attendance.php";
require_once __DIR__ . "/conftest.php";

use PHPUnit\Framework\TestCase;


class AttendanceTest extends TestCase
{
    public function setUp(): void
    {
        clean_db();
        migrate_all();
    }

    // Test the log method
    public function testLogAttendance()
    {
        // Create a mock EventSession object for the current session
        $eventSession = $this->createMock(EventSession::class);
        $eventSession->expects($this->any())
            ->method('get')
            ->willReturn($eventSession); // Return the same object for simplicity

        // Mock data for log
        $fullName = 'John Doe';
        $email = 'johndoe@example.com';
        $phone = '1234567890';

        // Mock the Person::get method
        $person = $this->createMock(Person::class);
        $person->expects($this->once())
            ->method('get')
            ->with(['fullname' => $fullName, 'email' => $email, 'phone' => $phone])
            ->willReturn($person); // Return the same object for simplicity

        // Create an Attendance object (replace with your actual constructor arguments)
        $attendance = new Attendance($person, $eventSession);

        // Mock the insert method
        $attendance->expects($this->once())
            ->method('insert')
            ->willReturn(true); // Assume insertion is successful

        // Call the log method
        $result = Attendance::log($fullName, $email, $phone);

        // Assert that the log method returns true (insertion successful)
        $this->assertTrue($result);
    }

    // Test the insert method (if necessary)
    public function testInsertAttendance()
    {
        // Create an instance of Attendance (replace with actual constructor arguments)
        $attendance = new Attendance($person, $eventSession);

        // Use your database setup logic to set up a valid database connection
        // Replace the following with your actual database connection logic
        $db = get_db(); // Assuming get_db returns a database connection

        // Execute the insert method
        $result = $attendance->insert($db);

        // Assert that the insertion was successful (modify this assertion as needed)
        $this->assertTrue($result);
    }
}

?>
