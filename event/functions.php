<?php

function generate_qr($fullname, $email, $phone) {
  // curl qrcode.show -d https://example.com
  $qr_url = "https://qrcode.show";

  $fullname_clean = urlencode($fullname);
  $email_clean = urlencode($email);
  $phone_clean = urlencode($phone);
  $endpoint = "https://rinchen.org/event-retiro/attendance_log.php";
  //$endpoint = "http://localhost:9000/attendance_log.php";

  $page_url = "$endpoint/?fullname=$fullname_clean&email=$email_clean&phone=$phone_clean";

  $qr_url_with_data = "$qr_url/$page_url";

  $ch = curl_init($qr_url_with_data);

  // Set cURL options
  curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

  // Execute the request and save the response
  $response = curl_exec($ch);

  // Close session to clear up resources
  curl_close($ch);

  // Check for errors
  if (!$response) {
    die(
      'Error: "' . curl_error($ch) .
      '" - Code: ' . curl_errno($ch) .
      ' URL: ' . $qr_url_with_data
    );
  }

  return $response;
}


function subscribe_person($fullname, $email, $phone) {
    // Open the SQLite database
    $db = new SQLite3('db.sqlite');

    // Create the 'registrations' table if it doesn't exist
    $createTableQuery = "CREATE TABLE IF NOT EXISTS subscription (
                            id INTEGER PRIMARY KEY AUTOINCREMENT,
                            fullname TEXT,
                            email TEXT,
                            phone TEXT,
                            subscription_datetime TEXT,
                            qr TEXT
                        )";
    $db->exec($createTableQuery);

    $qr = generate_qr($fullname, $email, $phone);

    // Insert the form data into the 'registrations' table
    $insertQuery = "INSERT INTO subscription (fullname, email, phone, qr)
                    VALUES ('$fullname', '$email', '$phone', '$qr')";
    $db->exec($insertQuery);

    // Close the database connection
    $db->close();

    return $qr;
}

function upload_csv($file) {
    // Database connection
    $db = new SQLite3('db.sqlite');

    // Process the uploaded CSV file
    $handle = fopen($file, 'r');
    $header = fgetcsv($handle); // Read the header row

    // Map the CSV fields to database columns
    $fieldMappings = [
        'Nombre' => 'fullname',
        'Apellidos' => 'fullname',
        'Correo electrónico' => 'email',
        'Número de celular' => 'phone',
    ];

    $count = 0;
    $existingRows = [];

    while (($row = fgetcsv($handle)) !== false) {
        $data = array_combine($header, $row); // Combine header with row data

        // Select the desired fields
        $selectedData = [
            'subscription_datetime' => $data['Timestamp'],
            'fullname' => $data['Nombre'] . ' ' . $data['Apellidos'],
            'email' => $data['Correo electrónico'],
            'phone' => $data['Número de celular'],
        ];

        $qr = subscribe_person(
            $selectedData['fullname'],
            $selectedData['email'],
            $selectedData['phone'],
        );

        if ($qr) {
            // send QR vi email
            $count++;
        }
    }

    fclose($handle);
    $db->close();

    echo "<p>Successfully imported $count rows.</p>";

    if (!empty($existingRows)) {
        echo "<p>The following rows already exist in the database:</p>";
        echo "<ul>";
        foreach ($existingRows as $row) {
            echo "<li>Fullname: " . $row['fullname'] . ", Email: " . $row['email'] . ", Phone: " . $row['phone'] . "</li>";
        }
        echo "</ul>";
    }
}


function log_attendance($fullname, $email, $phone) {
    $entry_time = date('Y-m-d H:i:s');

    // Connect to the SQLite database
    $db = new SQLite3('db.sqlite');

    // Create the attendance_table if it doesn't exist
    $query = "CREATE TABLE IF NOT EXISTS attendance_log (
        fullname TEXT,
        email TEXT,
        phone TEXT,
        entry_time TEXT
    )";
    $db->exec($query);

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

function get_subscription_list() {
    $db = new SQLite3('db.sqlite');
    $query = "SELECT * FROM subscription";
    return $db->query($query);
}

function get_attendance_list() {
    $db = new SQLite3('db.sqlite');
    $query = "SELECT * FROM attendance_log";
    return $db->query($query);
}

?>
