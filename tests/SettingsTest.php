<?php

require_once dirname(__DIR__) . "/event/lib/settings.php";
use PHPUnit\Framework\TestCase;

class SettingsTest extends TestCase
{
    public function testHostAddress(): void
    {
        $envFilePath = dirname(__DIR__) . '/event/.env';

        $address = "http://localhost:8000";
        file_put_contents($envFilePath, "HOST_ADDRESS=$address\n");
        $this->assertEquals($address, get_env("HOST_ADDRESS"));

        $address = "http://localhost:8001";
        file_put_contents($envFilePath, "HOST_ADDRESS=$address\n");
        $this->assertEquals($address, get_env("HOST_ADDRESS"));
    }
}
