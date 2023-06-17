<?php
require_once __DIR__ . "lib/subscription.php"
?>
<!DOCTYPE html>
<html>
<head>
    <title>Lista de suscriptos</title>
    <link rel="stylesheet" href="./style.css">
</head>
<body>
    <h2>Lista de suscriptos</h2>
    <a href="./">&lt;&lt; Back to the menu</a>

    <?php
    $result = get_subscription_list();
    ?>

    <?php if ($result->numColumns() > 0): ?>
        <table>
            <tr>
                <th>ID</th>
                <th>Full Name</th>
                <th>Email</th>
                <th>Phone</th>
                <th>QR</th>
            </tr>
            <?php while ($row = $result->fetchArray(SQLITE3_ASSOC)): ?>
                <tr>
                    <td><?php echo $row['id']; ?></td>
                    <td><?php echo $row['fullname']; ?></td>
                    <td><?php echo $row['email']; ?></td>
                    <td><?php echo $row['phone']; ?></td>
                    <td><pre class="qr"><?php echo $row['qr']; ?></pre></td>
                </tr>
            <?php endwhile; ?>
        </table>
    <?php else: ?>
        <p>No records found.</p>
    <?php endif; ?>

    <a href="./">&lt;&lt; Back to the menu</a>

</body>
</html>
