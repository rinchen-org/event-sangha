<?php
require_once dirname(dirname(__DIR__)) . "/lib/attendance.php";
include dirname(__DIR__) . "/header.php";

global $BASE_URL;
?>

<h2>Attendance Log</h2>

<a href="../" class="btn btn-warning my-3">Back to the menu</a>
<a href="./form.php" class="btn btn-primary my-3">Log</a>

<?php
$result = Attendance::list();

if ($result): ?>

    <table id="attendance_list" class="w-100">
      <thead>
        <tr>
            <th>ID</th>
            <th>Full Name</th>
            <th>Email</th>
            <th>Phone</th>
            <th>Event</th>
            <th>Session</th>
            <th>Log Time</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($result as $attendance): ?>
        <tr>
            <td><?php echo $attendance->id; ?></td>
            <td><?php echo $attendance->person->fullname; ?></td>
            <td><?php echo $attendance->person->email; ?></td>
            <td><?php echo $attendance->person->phone; ?></td>
            <td><?php echo $attendance->eventSession->event->name; ?></td>
            <td><?php echo $attendance->eventSession->name; ?></td>
            <td><?php echo $attendance->logTime->format('Y-m-d H:i:s'); ?></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
<?php else: ?>
    <p>No records found.</p>
<?php endif; ?>

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
