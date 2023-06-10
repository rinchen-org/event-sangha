<!DOCTYPE html>
<html>
<head>
    <title>Inscription for people who already paid the registration fee</title>
</head>
<body>

<h1>Inscription for people who already paid the registration fee</h1>

<?php
// SQLite database file
$databaseFile = 'db.sqlite';

// Check if the form is submitted
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Retrieve form data
    $fullname = $_POST['fullname'];
    $email = $_POST['email'];
    $phone = $_POST['phone'];

    // Open the SQLite database
    $db = new SQLite3($databaseFile);

    // Create the 'registrations' table if it doesn't exist
    $createTableQuery = "CREATE TABLE IF NOT EXISTS registrations (
                            id INTEGER PRIMARY KEY AUTOINCREMENT,
                            fullname TEXT,
                            email TEXT,
                            phone TEXT
                        )";
    $db->exec($createTableQuery);

    // Insert the form data into the 'registrations' table
    $insertQuery = "INSERT INTO registrations (fullname, email, phone)
                    VALUES ('$fullname', '$email', '$phone')";
    $db->exec($insertQuery);

    // Close the database connection
    $db->close();

    echo '<p>Thank you for your registration!</p>';
}
?>

<form method="POST" action="">
    <label for="fullname">Full Name:</label>
    <input type="text" name="fullname" id="fullname" required><br><br>

    <label for="email">Email:</label>
    <input type="email" name="email" id="email" required><br><br>

    <label for="phone">Phone:</label>
    <input type="tel" name="phone" id="phone" required><br><br>

    <input type="submit" value="Submit">
</form>

</body>
</html>
