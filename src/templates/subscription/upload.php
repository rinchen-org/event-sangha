<?php
include dirname(__DIR__) . "/header.php";
require_once dirname(dirname(__DIR__)) . "/lib/subscription.php";
?>
<h2>CSV Upload</h2>

<?php
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['csv_file'])) {
    Subscription::upload_csv($_FILES['csv_file']['tmp_name']);
}
?>

<form method="POST" enctype="multipart/form-data">
    <input type="file" name="csv_file" accept=".csv" required>
    <input type="submit" value="Upload">
</form>

<a href="../" class="btn btn-primary my-3">Back to the menu</a>
<?php
include dirname(__DIR__) . "/footer.php";
?>
