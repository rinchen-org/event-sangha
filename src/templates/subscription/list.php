<?php
require_once dirname(dirname(__DIR__)) . "/lib/subscription.php";
include dirname(__DIR__) . "/header.php";

$filter = $_POST["filter"] ?? "all";


if ($filter == "active") {
  $result = Subscription::list([
    "active" => 1,
  ]);
} else if ($filter == "inactive") {
  $result = Subscription::list([
    "active" => 0,
  ]);
} else {
  $result = Subscription::list();
}

global $MESSAGE;

?>
  <h2>Lista de suscriptos</h2>
  <a href="../" class="btn btn-warning my-3">Back to the menu</a>
  <a href="./form.php" class="btn btn-primary my-3">New Subscription</a>

  <div class="alert alert-secondary input-group" role="alert">
    Filters: <br/>
    <form method="POST" class="mx-1">
      <input type="hidden" name="filter" value="all">
      <input type="submit"
        value="Todos"
        class="btn btn-success btn-sm"
        <?php if ($filter == "all") { ?>
        style="background:#fafafa!important;color:#888888;"
        disabled="disabled"
        <?php } ?>
      />
    </form>
    <form method="POST" class="mx-1">
      <input type="hidden" name="filter" value="active">
      <input type="submit"
        value="Activos"
        class="btn btn-success btn-sm"
        <?php if ($filter == "active") { ?>
        style="background:#fafafa!important;color:#888888;"
        disabled="disabled"
        <?php } ?>
      />
    </form>
    <form method="POST" class="mx-1">
      <input type="hidden" name="filter" value="inactive">
      <input type="submit"
        value="Inactivos"
        class="btn btn-success btn-sm"
        <?php if ($filter == "inactive") { ?>
        style="background:#fafafa!important;color:#888888;"
        disabled="disabled"
        <?php } ?>
      />
    </form>
  </div>

<?php if ($result): ?>
        <table id="subscription_list">
          <thead>
            <tr>
              <th>ID</th>
              <th>Full Name</th>
              <th>Email</th>
              <th>Phone</th>
              <th>Active</th>
              <th>QR</th>
              <th>DateTime</th>
              <th>Actions</th>
            </tr>
          </thead>
          <tbody>
<?php foreach ($result as $subscription) { ?>
            <tr>
              <td><?php echo $subscription->id; ?></td>
              <td><?php echo $subscription->person->fullname; ?></td>
              <td><?php echo $subscription->person->email; ?></td>
              <td><?php echo $subscription->person->phone; ?></td>
              <td>
                <?php if ($subscription->person->active == 1) { ?>
                  <span class="badge bg-success">ACTIVE</span>
                <?php } else { ?>
                  <span class="badge bg-danger">INACTIVE</span>
                <?php } ?>
              </td>
              <td><img src="<?php echo $subscription->qr; ?>" /></td>
              <td><?php echo $subscription->datetime->format('Y-m-d H:i:s'); ?></td>
              <td>
                <form action="./resend_email.php" method="POST">
                  <input type="hidden" name="id" value="<?php echo $subscription->id; ?>" />
                  <input type="submit"
                    value="reenviar email"
                    class="btn btn-success"
                    onclick="return confirm('Are you sure?')"
                    style="background:#ffcc00!important;" />
                </form>
  <?php if ($subscription->active == 0) { ?>
                <form action="./activation.php" method="POST">
                  <input type="hidden" name="id" value="<?php echo $subscription->id; ?>" />
                  <input type="hidden" name="active" value="1" />
                  <input type="submit"
                    value="habilitar"
                    class="btn btn-success"
                    style="background:#1CD324!important;" />
                </form>
  <?php } else { ?>
                <form action="./activation.php" method="POST">
                  <input type="hidden" name="id" value="<?php echo $subscription->id; ?>" />
                  <input type="hidden" name="active" value="0" />
                  <input type="submit"
                    value="inhabilitar"
                    class="btn btn-warning"
                    style="background:#E50E0E!important;"/>
                </form>
  <?php } ?>
                <form action="./log_attendance.php" method="POST">
                  <input type="hidden" name="id" value="<?php echo $subscription->id; ?>" />
                  <button type="submit"
                    class="btn btn-success"
                    style="background:ea3c89!important;"
                  >log attendance</button>

                </form>
                <form action="./log_attendance_force.php" method="POST">
                  <input type="hidden" name="id" value="<?php echo $subscription->id; ?>" />
                  <button type="submit"
                    class="btn btn-warning"
                  >log FORCE</button>

                </form>
              </td>
            </tr>
<?php } ?>
          </tbody>
        </table>
<?php else: ?>
        <p>No records found.</p>
<?php endif; ?>

    <a href="../" class="btn btn-warning my-3">Back to the menu</a>

    <script>
        let table = new DataTable('#subscription_list');
    </script>

<?php
include dirname(__DIR__) . "/footer.php";
?>
