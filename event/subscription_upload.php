<?php
include "functions.php"
?>

<!DOCTYPE html>
<html>
<head>
    <title>CSV Upload</title>
    <link rel="stylesheet" href="./style.css">
</head>
<body class="container">
    <h2>CSV Upload</h2>

    <?php
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['csv_file'])) {
        upload_csv($_FILES['csv_file']['tmp_name']);
    }
    ?>

    <form method="POST" enctype="multipart/form-data">
        <input type="file" name="csv_file" accept=".csv" required>
        <input type="submit" value="Upload">
    </form>

    <a href="./">&lt;&lt; Back to the menu</a>

</body>
</html>
