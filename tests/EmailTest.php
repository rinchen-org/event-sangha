<?php

require_once dirname(__DIR__) . "/src/lib/email.php";
require_once dirname(__DIR__) . "/src/lib/settings.php";

use PHPUnit\Framework\TestCase;


class EmailTest extends TestCase
{
    protected function setUp(): void
    {
        $envFilePath = dirname(__DIR__) . '/src/.env';

        $content = "TEST_EMAIL_TO=myemail+to@gmail.com\n";
        $content .= "EMAIL_REPLY_TO=myemail+replyto@gmail.com\n";
        $content .= "EMAIL_FROM=myemail+from@gmail.com\n";
        $content .= "EMAIL_CC=myemail+cc@gmail.com\n";
        file_put_contents($envFilePath, $content);
    }

    public function testSendEmail(): void
    {
        // Replace these values with your actual environment variable values
        $to = get_env("TEST_EMAIL_TO");
        $from = get_env("EMAIL_FROM");
        $replyTo = get_env("EMAIL_REPLY_TO");
        $cc = get_env("EMAIL_CC");
        $subject = 'Subscription Confirmation';

        $varEnv = [
            "EMAIL_TO" => $to,
            "EMAIL_FROM" => $from,
            "EMAIL_REPLY_TO" => $replyTo,
            "EMAIL_CC" => $cc
        ];

        foreach ($varEnv as $key => $value) {
            $this->assertNotEmpty(
                $value,
                "The `$key` environment variable is not set in the .env file."
            );
        }

        $templateFile = dirname(__DIR__) . "/src/templates/subscription-email.html";

        $qrCode = "<img src='https://rinchen.org/wp-content/uploads/2021/07/logo-head.png'/>";
        $context = [
            "<QR>" => $qrCode
        ];

        // Send the email
        $result = send_email(
            $templateFile,
            $context,
            $subject,
            $to,
            $from,
            $replyTo,
            $cc
        );

        $this->assertTrue($result, 'Email could not be sent.');
    }
}
