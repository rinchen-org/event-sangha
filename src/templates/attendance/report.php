<?php
require_once dirname(dirname(__DIR__)) . "/lib/attendance.php";
require_once dirname(dirname(__DIR__)) . "/lib/event.php";
require_once dirname(dirname(__DIR__)) . "/lib/event.php";
include dirname(__DIR__) . "/header.php";

global $BASE_URL;
?>

<h2>Attendance Report</h2>

<a href="../" class="btn btn-warning my-3">Back to the menu</a>
<a href="./form.php" class="btn btn-primary my-3">Log</a>

<?php
$events = EventSangha::list();

$event = $events[0];
$subscriptions = Subscription::list(["active" => 1]);
$sessions = EventSession::list(["event_id" => $event->id]);

if ($sessions && $subscriptions) { ?>

    <table id="attendance_list" class="w-100">
      <thead>
        <tr>
            <th>Name</th>
            <th>ID</th>
            <?php
            foreach ($sessions as $session) {
              ?>
              <th><?php echo $session->name;?></th>
              <?php
            }
            ?>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($subscriptions as $subscription): ?>
        <tr>
            <td><?php echo $subscription->person->fullname; ?></td>
            <td><?php echo $subscription->id; ?></td>
            <?php
            foreach ($sessions as $session) {
              $attendance = Attendance::get([
                "event_session_id" => $session->id,
                "person_id" => $subscription->person->id
              ]);
              if ($attendance !== null) {
              ?>
              <td><img src="../../static/images/check.png" width="20px;"></td>
              <?php
              } else { ?>
              <td><img src="../../static/images/denied.png" width="20px;"></td>
              <?php
              }
            }
            ?>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
<?php } else { ?>
    <p>No records found.</p>
<?php }; ?>

<a href="../" class="btn btn-warning my-3">Back to the menu</a>

<script src="https://code.jquery.com/jquery-3.7.0.js"></script>
<script src="https://cdn.datatables.net/1.13.7/js/jquery.dataTables.min.js"></script>
<script src="https://cdn.datatables.net/buttons/2.4.2/js/dataTables.buttons.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/jszip/3.10.1/jszip.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/pdfmake/0.1.53/pdfmake.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/pdfmake/0.1.53/vfs_fonts.js"></script>
<script src="https://cdn.datatables.net/buttons/2.4.2/js/buttons.html5.min.js"></script>
<script src="https://cdn.datatables.net/buttons/2.4.2/js/buttons.print.min.js"></script>

<link rel="stylesheet" href="https://cdn.datatables.net/1.13.7/css/jquery.dataTables.min.css">
<link rel="stylesheet" href="https://cdn.datatables.net/buttons/2.4.2/css/buttons.dataTables.min.css">

<script>
    // let table = new DataTable('#attendance_list');
    $(document).ready(function() {
      $('#attendance_list').DataTable( {
        dom: 'Bfrtip',
        buttons: [ 'copy', 'csv', 'excel', 'pdf', 'print', 'pageLength' ]
      });
    });
</script>


<?php
include dirname(__DIR__) . "/footer.php";
?>
