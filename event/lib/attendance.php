<?php
require_once "lib/qr.php";
require_once "lib/db.php";


function log_attendance($fullname, $email, $phone) {
    $entry_time = date('Y-m-d H:i:s');

    // ensure that attendance_log table exists


    // Connect to the SQLite database
    $db = get_db();

    // Check if the person is already registered
    $query = "SELECT * FROM subscription WHERE fullname = '$fullname' AND email = '$email' AND phone = '$phone'";
    $result = $db->query($query);

    if (!$result->fetchArray(SQLITE3_ASSOC)) {
        echo '<p style="color: red;">';
        echo 'No estás registrado aún para este evento. ';
        echo 'Para ingresar al evento, por favor, realice tu inscripción.';
        echo '</p>';
        return false;
    }

    // Prepare the insert statement
    $query = "INSERT INTO attendance_log (fullname, email, phone, entry_time)
              VALUES (:fullname, :email, :phone, :entryTime)";
    $stmt = $db->prepare($query);
    $stmt->bindValue(':fullname', $fullname, SQLITE3_TEXT);
    $stmt->bindValue(':email', $email, SQLITE3_TEXT);
    $stmt->bindValue(':phone', $phone, SQLITE3_TEXT);
    $stmt->bindValue(':entryTime', $entry_time, SQLITE3_TEXT);

    // Execute the statement
    $stmt->execute();

    // Close the database connection
    $db->close();

    return true;
}

function get_attendance_list() {
    $db = new SQLite3('db.sqlite');
    $query = "SELECT * FROM attendance_log";
    return $db->query($query);
}

?>
