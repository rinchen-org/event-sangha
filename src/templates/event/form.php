<?php
require_once dirname(__DIR__, 2) . '/lib/auth.php';
auth_require();
include dirname(__DIR__) . "/header.php";
require_once dirname(dirname(__DIR__)) . "/lib/event.php";

global $BASE_URL;

// Initialize variables
$event = null;
$isNewEvent = true;

// Check if an event ID is provided for updating
if (isset($_GET['id'])) {
    $event = EventSangha::get(['id' => $_GET['id']]);
    if ($event) {
        $isNewEvent = false;
    }
}

// Check if the form is submitted
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Retrieve form data
    $name = $_POST['name'];
    $description = $_POST['description'];
    $startDateStr = $_POST['start_date'];
    $endDateStr = $_POST['end_date'];

    // Convert the string dates to DateTime objects
    $startDate = new DateTime($startDateStr);
    $endDate = new DateTime($endDateStr);

    // Create or update the event
    if ($isNewEvent) {
        $event = (
            new EventSangha(
                $name,
                $description,
                $startDate,
                $endDate
            )
        )->save();
    } else {
        $event->name = $name;
        $event->description = $description;
        $event->startDate = $startDate;
        $event->endDate = $endDate;
        $event->update();
    }

    if ($event) {
        echo '<div class="alert alert-success" role="alert">';
        echo $isNewEvent ? 'Evento creado correctamente.' : 'Evento actualizado correctamente.';
        echo '</div>';
        echo '<a class="btn btn-primary" href="' . auth_escape(auth_url('templates/index.php#eventos/' . (int) $event->id . '/participantes')) . '">Volver al evento</a> ';
        echo '<a href="' . auth_escape(auth_url('templates/index.php#eventos')) . '" class="btn btn-primary">Volver a eventos</a>';
    }
}

// If it's a new event or an existing one, show the form
if ($isNewEvent || $event) {
?>

<div class="mt-5">
    <h1><?php echo $isNewEvent ? 'Nuevo evento' : 'Editar evento'; ?></h1>

    <form method="POST" action="">
      <?php auth_csrf_field(); ?>
        <div class="mb-3">
            <label for="name" class="form-label">Nombre del evento:</label>
            <input type="text" class="form-control" name="name" id="name" required value="<?php echo auth_escape($event ? $event->name : ''); ?>">
        </div>

        <div class="mb-3">
            <label for="description" class="form-label">Descripción:</label>
            <textarea class="form-control" name="description" id="description" rows="4"><?php echo auth_escape($event ? $event->description : ''); ?></textarea>
        </div>

        <div class="mb-3">
            <label for="start_date" class="form-label">Inicio:</label>
            <input type="date" class="form-control" name="start_date" id="start_date" required value="<?php echo $event ? $event->startDate->format('Y-m-d') : ''; ?>">
        </div>

        <div class="mb-3">
            <label for="end_date" class="form-label">Fin:</label>
            <input type="date" class="form-control" name="end_date" id="end_date" required value="<?php echo $event ? $event->endDate->format('Y-m-d') : ''; ?>">
        </div>

        <button type="submit" class="btn btn-primary"><?php echo $isNewEvent ? 'Crear evento' : 'Guardar cambios'; ?></button>
    </form>

    <a href="../" class="btn btn-warning my-3">Volver a mi comunidad</a>
    <a href="./list.php" class="btn btn-warning my-3">Volver a eventos</a>

</div>

<?php
}

include dirname(__DIR__) . "/footer.php";
?>
