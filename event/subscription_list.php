<?php
require_once __DIR__ . "/lib/subscription.php";
include __DIR__ . "/header.php";

$result = Subscription::list();
?>
    <h2>Lista de suscriptos</h2>
    <a href="./">&lt;&lt; Back to the menu</a>

<?php if ($result): ?>
        <table id="subscription_list">
          <thead>
            <tr>
              <th>ID</th>
              <th>Full Name</th>
              <th>Email</th>
              <th>Phone</th>
              <th>QR</th>
            </tr>
          </thead>
          <tbody>
<?php foreach ($result as $subscription) { ?>
            <tr>
              <td><?php echo $subscription->id; ?></td>
              <td><?php echo $subscription->person->fullname; ?></td>
              <td><?php echo $subscription->person->email; ?></td>
              <td><?php echo $subscription->person->phone; ?></td>
              <td><pre class="qr"><?php echo $subscription->qr; ?></pre></td>
            </tr>
<?php } ?>
          </tbody>
        </table>
<?php else: ?>
        <p>No records found.</p>
<?php endif; ?>

    <a href="./">&lt;&lt; Back to the menu</a>

    <script>
        let table = new DataTable('#subscription_list');
    </script>

<?php
include __DIR__ . "/footer.php";
?>
