<?php
include dirname(__DIR__) . "/header.php";

global $BASE_URL;
?>
<!DOCTYPE html>
<html>
<head>
    <title>Registro de asistencia manual</title>
    <link rel="stylesheet" href="<?php echo $BASE_URL; ?>/static/style.css">
</head>
<body class="container">
<?php
// Check if all fields are present
if (isset($_GET['fullname'], $_GET['email'], $_GET['phone'])) {
    // Get the values from GET parameters
    $fullname = $_GET['fullname'];
    $email = $_GET['email'];
    $phone = $_GET['phone'];

    $result = log_attendance($fullname, $email, $phone);

    if (!$result) {
        echo '<p style="color:red;">';
        echo "Check if the information is valid!";
        echo '</p>';
        die();
    }

    // Output a success message
    echo "<strong>Asistencia confirmada</strong>!";
} else {
    // Output an error message if any field is missing
    echo '<p style="color:red;">';
    echo "Error: All fields are required!";
    echo '</p>';
}
?>
</body>
</html>
