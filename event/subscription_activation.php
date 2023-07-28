<?php
require_once __DIR__ . "/lib/subscription.php";

session_start();

$active =  intval($_POST['active']);

if ($active == 1){
  $message = "Registro habilitado.";
} else {
  $message = "Registro inhabilitado.";
}

$subscription = Subscription::get(["id" => intval($_POST["id"])]);
$subscription->active = $active;
$subscription->save();
$subscription->person->active = $active;
$subscription->person->save();

$_SESSION['message'] = [
  "type" => "success",
  "text" => $message,
];

header('Location: subscription_list.php');
exit();
