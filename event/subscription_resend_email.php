<?php
require_once __DIR__ . "/lib/subscription.php";

session_start();

$subscription = Subscription::get(["id" => intval($_POST["id"])]);

try {
  Subscription::send_email($subscription);

  $_SESSION['message'] = [
    "type" => "success",
    "text" => "Email sent",
  ];
} catch (Exception $e) {
  $message = $e->getMessage();
  $_SESSION['message'] = [
    "type" => "error",
    "text" => "$message",
  ];
}

header('Location: subscription_list.php');
exit();
