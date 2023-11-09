<?php
require_once dirname(dirname(__DIR__)) . "/lib/attendance.php";
include dirname(__DIR__) . "/header.php";

global $BASE_URL;
?>
<!DOCTYPE html>
<html>
<head>
    <title>Lista de asistencia</title>
    <link rel="stylesheet" href="<?php echo $BASE_URL; ?>/static/style.css">
</head>
<body>
    <h2>Attendance Log</h2>

    <a href="../" class="btn btn-warning my-3">Back to the menu</a>
    <a href="./form.php" class="btn btn-primary my-3">Log</a>

    <?php
    $result = Attendance::list();

    if ($result): ?>
        <table>
            <tr>
                <th>ID</th>
                <th>Full Name</th>
                <th>Email</th>
                <th>Phone</th>
                <th>Log Time</th>
            </tr>
            <?php foreach ($result as $attendance): ?>
                <tr>
                    <td><?php echo $attendance->id; ?></td>
                    <td><?php echo $attendance->person->fullname; ?></td>
                    <td><?php echo $attendance->person->email; ?></td>
                    <td><?php echo $attendance->person->phone; ?></td>
                    <td><?php echo $attendance->logTime->format('Y-m-d H:i:s'); ?></td>
                </tr>
            <?php endforeach; ?>
        </table>
    <?php else: ?>
        <p>No records found.</p>
    <?php endif; ?>

    <a href="../" class="btn btn-warning my-3">Back to the menu</a>

<?php
include dirname(__DIR__) . "/footer.php";
?>
