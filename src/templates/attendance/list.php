<?php
require_once dirname(dirname(__DIR__)) . "/lib/attendance.php";
include dirname(__DIR__) . "/header.php";
?>
<!DOCTYPE html>
<html>
<head>
    <title>Lista de asistencia</title>
    <link rel="stylesheet" href="<?php $BASE_URL?>/static/style.css">
</head>
<body>
    <h2>Lista de asistencia</h2>

    <a href="../" class="btn btn-warning my-3">Back to the menu</a>
    <a href="./form.php" class="btn btn-primary my-3">Log Attendance</a>

    <?php
    $result = Attendance::list();

    if ($result): ?>
        <table>
            <tr>
                <th>ID</th>
                <th>Full Name</th>
                <th>Email</th>
                <th>Phone</th>
                <th>Date and Time of entry</th>
            </tr>
            <?php while ($row = $result->fetchArray(SQLITE3_ASSOC)): ?>
                <tr>
                    <td><?php echo $row['id']; ?></td>
                    <td><?php echo $row['fullname']; ?></td>
                    <td><?php echo $row['email']; ?></td>
                    <td><?php echo $row['phone']; ?></td>
                    <td><?php echo $row['entry_time']; ?></td>
                </tr>
            <?php endwhile; ?>
        </table>
    <?php else: ?>
        <p>No records found.</p>
    <?php endif; ?>

    <a href="../" class="btn btn-warning my-3">Back to the menu</a>

<?php
include dirname(__DIR__) . "/footer.php";
?>
