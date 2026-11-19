<?php

namespace Tests;

use Illuminate\Filesystem\Filesystem;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    /** @var callable|null */
    private $previousErrorHandler;

    protected function setUp(): void
    {
        $this->previousErrorHandler = set_error_handler(function (int $errorNumber, string $errorMessage, string $errorFile, int $errorLine): bool {
            if ($errorNumber === E_DEPRECATED && str_contains($errorMessage, 'PDO::MYSQL_ATTR_SSL_CA')) {
                return true;
            }

            if ($this->previousErrorHandler !== null) {
                return (bool) ($this->previousErrorHandler)($errorNumber, $errorMessage, $errorFile, $errorLine);
            }

            return false;
        }, E_DEPRECATED);

        parent::setUp();

        // Staged imports write payload files; keep every test's files out of storage/.
        $payloadPath = sys_get_temp_dir().'/voltikka-import-payloads-'.bin2hex(random_bytes(6));
        config(['import_payloads.path' => $payloadPath]);
        $this->beforeApplicationDestroyed(fn () => (new Filesystem)->deleteDirectory($payloadPath));
    }
}
