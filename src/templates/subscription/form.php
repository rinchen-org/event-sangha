<?php
require_once dirname(dirname(__DIR__)) . "/lib/subscription.php";

include dirname(__DIR__) . "/header.php";

$status = true;
$subscription = null;

// Check if the form is submitted
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Retrieve form data
    $fullname = $_POST['fullname'];
    $email = $_POST['email'];
    $phone = $_POST['phone'];

    try {
        $subscription = Subscription::subscribe_person(
          $fullname, $email, $phone
        );
    } catch (Exception $e) {
        $msg = $e->getMessage();
        print("<p class='alert alert-danger'>" . $msg . "</p>");

        $status = false;
        $person = new Person($fullname, $email, $phone);
        $subscription = new Subscription($person);

        if ($msg == "Email could not be sent.") {
          // exception of the exception
          $status = true;
        }
    }

    if ($subscription !== null && $status === true) {
?>

<h1>¡ CONFIRMAMOS TU PARTICIPACIÓN EN EL RETIRO!</h1>

<p>
  Seguramente al igual que tú, estamos esperando que lleguen estos 3 días
  de enseñanzas y meditación con Su Santidad Sakya Trizin 42.
</p>
<p>
  Te enviamos el código QR que acredita tu aporte voluntario, con el que
  podrás ingresar a las ceremonias que se llevarán a cabo el viernes 24,
  sábado 25 y domingo 26 de noviembre del 2023.
</p>

<p style="color:red;">
  Por favor recuerda guardar este código en tu celular o impreso, para
  presentarlo al ingreso los 3 días. Si no lo presentas, no podrás participar
  de las ceremonias.
</p>

<?php
        echo "<pre class='qr'>\n";
        echo "<img src='$subscription->qr'/>";
        echo "\n</pre>";
        echo '<a href="./form.php">Back to the form</a>';
?>

<h3>SUGERENCIAS</h3>

<p>
  Para aprovechar al máximo estos 3 días, te recomendamos:
</p>

<ul>
  <li><strong>
      RESERVAR LAS FECHAS CON ANTELACIÓN EN TU AGENDA</strong>

    <p>
      Es un privilegio contar con la presencia de un Maestro auténtico,
      aprovecha de la mejor forma esta oportunidad única y organízate para tener
      el tiempo suficiente para el retiro.
    </p>
    <p>
      Deja tus pendientes terminados para poder concentrarte.
    </p>
    <p>
      Genera una buena motivación, el tiempo que le dedicas al Dharma es tiempo
      que no solo te beneficia a ti, sino a muchos seres.
    </p>
    <p>
      Recuerda que para participar del día sábado, es indispensable haber
      participado el día viernes y para participar del día domingo es necesario
      haber participado el sábado. Bajo ningún concepto se permitirá el ingreso
      si no se ha comprobado tu participación del día previo.
    </p>
  </li>
  <li>
    <strong>TOMA DE REFUGIO</strong>

    <p>
      Si desarrollaste el interés y luego de reflexionar decidiste tomar
      refugio, tienes la oportunidad de hacerlo en una sesión del día viernes 24
      de noviembre, eso sí, es necesario que previamente llenes el siguiente
      formulario:
      https://forms.gle/t8C25haEL8XSvPUS7
    </p>
    <p>
      La Toma de Refugio es una ceremonia en la que los participantes formalizan
      su compromiso con el sendero budista. En ella, los participantes se
      comprometen a:
    <ul>
      <li>
        Tomar al Buddha como su maestro espiritual
      </li>
      <li>
        Seguir el Dharma como su sendero espiritual
      </li>
      <li>
        Mantener a la comunidad de practicantes budistas como sus amigos
        espirituales.
      </li>
      <li>
        De ese momento en adelante, los participantes pasan a formar parte de la
        tradición budista.
      </li>
    </ul>
    </p>
    <p>
      La toma de refugio formal puede ser más indicada para todos aquellos que
      hayan tomado varios cursos sobre las enseñanzas budistas, tengan algo de
      experiencia meditativa y deseen vincularse con esta tradición. Esto
      también les capacitará para poder recibir en el futuro otros compromisos,
      como los votos del bodhisattva o los compromisos del vajrayana. (Fuente:
      Paramita. org)
    </p>

  </li>
  <li>
    <strong>MÁS INFORMACIÓN</strong>
    <p>
      Días antes del evento, recibirás un nuevo correo con recomendaciones para
      participar.
    </p>
  </li>
  <li>
    <strong>COMPARTE</strong>
    <p>
      Es hermoso poder compartir espacios de aprendizaje, reflexión y meditación
      con otros amigos espirituales, si piensas (como nosotros) que más personas
      pueden beneficiarse de este retiro, invítalas a participar compartiendo la
      información a través del siguiente link:
      https://www.paramita.org/sakya-trizin-latinoamerica/bolivia
    </p>
  </li>
</ul>
<br />
<p>
  Muchas gracias.<br />
  ¡Hasta pronto!
</p>
<p>
  Equipo Sakya Rinchen Ling
</p>

<img src="https://rinchen.org/wp-content/uploads/2020/08/Sakya-Rinchen-Ling.png"
  alt="Equipo Sakya Rinchen Ling" />

<a href="./form.php">Back to the form</a>

<?php
    }
}

// Check if the form is submitted
if ($_SERVER['REQUEST_METHOD'] === 'GET' || $status == false) {
?>
<h1>Formulario de suscripción para personas que ya han pagado la tasa de registro</h1>

<div class="mt-5">
  <form method="POST" action="">
      <label for="fullname">Full Name:</label>
      <input
        type="text"
        name="fullname"
        id="fullname"
        required
        value="<?php echo $subscription !== null ? $subscription->person->fullname : ""; ?>"
      /><br><br>

      <label for="email">Email:</label>
      <input
        type="email"
        name="email"
        id="email"
        required
        value="<?php echo $subscription !== null ? $subscription->person->email : ""; ?>"
      /><br><br>

      <label for="phone">Phone:</label>
      <input
        type="tel"
        name="phone"
        id="phone"
        required
        value="<?php echo $subscription !== null ? $subscription->person->phone : ""; ?>"
      /><br><br>

      <button type="submit" class="btn btn-primary">Save</button>
  </form>

  <a href="../" class="btn btn-warning my-3">Back to the menu</a>
  <a href="./list.php" class="btn btn-warning my-3">Back to the list</a>
</div>

<?php
}
include dirname(__DIR__) . "/footer.php";
?>
