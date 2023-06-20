<?php
include __DIR__ . "/header.php";
require_once __DIR__ . "/lib/subscription.php";
?>
<?php
// SQLite database file

// Check if the form is submitted
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Retrieve form data
    $fullname = $_POST['fullname'];
    $email = $_POST['email'];
    $phone = $_POST['phone'];

    try {
        $subscription = Subscription::subscribe_person($fullname, $email, $phone);
    } catch (Exception $e) {
        print("<p>" . $e->getMessage() . "</p>");
        echo '<p><a href="./subscription_manual.php">&lt;&lt; Back to the form</a></p>';
    }

    if ($subscription) {
?>

<h1>¡ CONFIRMAMOS TU PARTICIPACIÓN EN EL RETIRO!</h1>

<p>
Seguramente al igual que tú, estamos ansiosos de compartir contigo estos 3 días de enseñanzas y meditación con el Su Santidad Sakya Trizin 42 y posteriormente un retiro online guiado por el Venerable Lama Rinchen Gyaltsen.
</p>
<p>
TE ENVIAMOS TU CÓDIGO QR QUE ACREDITA TU APORTE VOLUNTARIO, CON EL QUE PODRÁS INGRESAR TODOS LOS DÍAS.
</p>

<p style="color:red;">
Recuerda por favor llevar este código, ya que sin él no podrás ingresar.
</p>

<?php
        echo "<pre class='qr'>\n";
        echo $subscription->qr;
        echo "\n</pre>";
        echo '<a href="./subscription_manual.php">&lt;&lt; Back to the form</a>';
    }
}

// Check if the form is submitted
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
?>
<h1>Formulario de suscripción para personas que ya han pagado la tasa de registro</h1>

<form method="POST" action="">
    <label for="fullname">Full Name:</label>
    <input type="text" name="fullname" id="fullname" required><br><br>

    <label for="email">Email:</label>
    <input type="email" name="email" id="email" required><br><br>

    <label for="phone">Phone:</label>
    <input type="tel" name="phone" id="phone" required><br><br>

    <input type="submit" value="Submit">
</form>

<a href="./">&lt;&lt; Back to the menu</a>

</body>
</html>

<?php
}
include __DIR__ . "/footer.php";
?>
