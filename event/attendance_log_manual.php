<?php
die("No disponible aún.");
?>
<!DOCTYPE html>
<html>
<head>
    <title>Registro de asistencia manual</title>
    <link rel="stylesheet" href="./style.css">
</head>
<body class="container">
    <h2>Registro de asistencia manual</h2>

    <?php

    if (isset($_POST['submit'])) {
        $fullname = $_POST['fullname'];
        $email = $_POST['email'];
        $phone = $_POST['phone'];

        $result = log_attendance($fullname, $email, $phone);

        if (!$result) {
            die();
        }

        echo '<p style="color: green;">Asistencia registrada con éxito!</p>';
        echo '<a href="./attendance_log_manual.php">&lt;&lt; Back to the form</a>';
    } else {
    ?>

    <form method="POST" action="<?php echo $_SERVER['PHP_SELF']; ?>">
        <label for="fullname">Full Name:</label>
        <input type="text" name="fullname" required><br>

        <label for="email">Email:</label>
        <input type="email" name="email" required><br>

        <label for="phone">Phone:</label>
        <input type="text" name="phone" required><br>

        <input type="submit" name="submit" value="Register">
    </form>

    <a href="./">&lt;&lt; Back to the menu</a>
<?php
    }
?>
</body>
</html>
