<?php
require_once dirname(__DIR__, 2) . '/lib/auth.php';
auth_require();
auth_post_only();
require_once dirname(dirname(__DIR__)) . "/lib/subscription.php";


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

header('Location: ./list.php');
exit();
