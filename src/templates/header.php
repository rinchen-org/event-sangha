<?php
session_start();

include_once __DIR__ . "/config.php";

global $BASE_URL;
global $ENV_PROD;
?>

<!DOCTYPE html>
<html>
<head>
    <title>Evento Retiro con Su Santidad Sakya Trizin 42 y Venerable Lama Rinchen Gyaltsen</title>
    <link rel="stylesheet" href="<?php echo $BASE_URL; ?>/static/style.css">
    <!-- datatables -->
    <script src="https://ajax.googleapis.com/ajax/libs/jquery/3.6.0/jquery.min.js"></script>
    <link rel="stylesheet" href="https://cdn.datatables.net/1.13.4/css/jquery.dataTables.css" />
    <script src="https://cdn.datatables.net/1.13.4/js/jquery.dataTables.js"></script>
    <!-- bootstrap -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.2.3/dist/css/bootstrap.min.css"
      rel="stylesheet"
      integrity="sha384-rbsA2VBKQhggwzxH7pPCaAqO46MgnOM80zW1RWuH61DGLwZJEdK2Kadq2F9CUG65"
      crossorigin="anonymous">
      <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.2.3/dist/js/bootstrap.bundle.min.js"
        integrity="sha384-kenU1KFdBIe4zVF0s0G1M5b4hcpxyD9F7jL+jjXkk+Q2h455rYXK/7HAuoJl+0I4"
        crossorigin="anonymous">
      </script>
</head>
<body>
  <div class="logo_image" style="background-image: url('https://rinchen.org/wp-content/uploads/2021/07/head5.png');">
    <a href="https://rinchen.org">
        <img class="spiritual-std-logo" src="https://rinchen.org/wp-content/uploads/2021/07/logo-head.png" alt="Sakya Rinchen Ling" data-retina="https://rinchen.org/wp-content/uploads/2021/07/logo_head_retina.png">
    </a>
  </div>
  <?php if ($ENV_PROD === false) { ?>
  <div class="alert alert-danger">Development Mode</div>
  <?php } ?>
  <div class="container">
    <div class="">
<?php
  $message_type = $_SESSION['message']["type"] ?? "";
  $message_text = $_SESSION['message']["text"] ?? "";
  $_SESSION['message'] = [];
?>
<?php if ($message_type == "success") { ?>
      <div class="alert alert-success" role="alert">
<?php print($message_text); ?>
      </div>
<?php } else if ($message_type == "error") { ?>
      <div class="alert alert-danger" role="alert">
<?php print($message_text); ?>
      </div>
<?php } ?>
    </div>
