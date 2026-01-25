<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        // Suppress PHP 8.4+ PDO constant deprecation from Laravel framework
        // This can be removed after upgrading to Laravel 12+
        set_error_handler(function ($errno, $errstr) {
            if ($errno === E_DEPRECATED && str_contains($errstr, 'PDO::MYSQL_ATTR_SSL_CA')) {
                return true;
            }
            return false;
        }, E_DEPRECATED);

        parent::setUp();
    }
}
