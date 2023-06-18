<?php
include __DIR__ . "/header.php";

die("No disponible aún.");
?>
<!DOCTYPE html>
<html>
<head>
    <title>Lista de asistencia</title>
    <link rel="stylesheet" href="./static/style.css">
</head>
<body>
    <h2>Lista de asistencia</h2>

    <a href="./">&lt;&lt; Back to the menu</a>

    <?php
    $result = get_attendance_list();
    ?>

    <?php if ($result->numColumns() > 0): ?>
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

    <a href="./">&lt;&lt; Back to the menu</a>

<?php
include __DIR__ . "/footer.php";
?>
