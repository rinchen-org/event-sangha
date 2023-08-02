<?php

require_once dirname(__DIR__) . "/event/lib/email.php";
require_once dirname(__DIR__) . "/event/lib/settings.php";


function test_mail_function(): void {
    // requires sendmail configured locally.

    $to = get_env("TEST_EMAIL_TO");
    $from = get_env("EMAIL_FROM");
    $reply_to = get_env("EMAIL_REPLY_TO");
    $cc = get_env("EMAIL_CC");
    $subject = 'Subscription Confirmation';

    $varEnv = [
        "EMAIL_TO" => $to,
        "EMAIL_FROM" => $from,
        "EMAIL_REPLY_TO" => $reply_to,
        "EMAIL_CC" => $cc
    ];

    foreach ($varEnv as $key => $value) {
        if (!$value){
            throw new Exception(
                "The `$key` environment variable is not set in the .env file."
            );
        }
    }

    $templateFile = dirname(__DIR__) . "/event/templates/subscription-email.html";

    $qrCode = "<img src='https://rinchen.org/wp-content/uploads/2021/07/logo-head.png'/>";
    $context = [
        "<QR>" => $qrCode
    ];

    // Send the email
    if (send_email(
        $templateFile,
        $context,
        $subject,
        $to,
        $from,
        $reply_to,
        $cc)
    ) {
        echo 'Email sent successfully.';
    } else {
        echo 'Email could not be sent.';
    }
}

test_mail_function();

?>
