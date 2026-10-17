<?php
require_once dirname(__DIR__, 2) . '/lib/auth.php';
auth_require();
auth_post_only();
require_once dirname(dirname(__DIR__)) . "/lib/subscription.php";


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

header('Location: ./list.php');
exit();
