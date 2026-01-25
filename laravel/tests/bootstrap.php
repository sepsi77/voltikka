<?php

/**
 * PHPUnit Bootstrap
 *
 * Sets up error handling before loading the Laravel application.
 */

// Temporarily suppress PHP 8.4+ PDO::MYSQL_ATTR_SSL_CA deprecation from Laravel framework
// This deprecation occurs when the config is loaded, before tests run
// Can be removed after upgrading to Laravel 12+
$previousHandler = set_error_handler(function ($errno, $errstr, $errfile) use (&$previousHandler) {
    if ($errno === E_DEPRECATED && str_contains($errstr, 'PDO::MYSQL_ATTR_SSL_CA')) {
        return true;
    }
    if ($previousHandler) {
        return $previousHandler($errno, $errstr, $errfile);
    }
    return false;
}, E_DEPRECATED);

// Load the Composer autoloader (this triggers the config load and deprecation)
require __DIR__ . '/../vendor/autoload.php';

// Restore the original error handler so PHPUnit doesn't complain
restore_error_handler();
