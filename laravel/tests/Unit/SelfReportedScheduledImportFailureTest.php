<?php

namespace Tests\Unit;

use App\Support\SelfReportedScheduledImportFailure;
use Exception;
use Illuminate\Console\Application;
use Illuminate\Contracts\Debug\ExceptionHandler;
use RuntimeException;
use Tests\TestCase;

class SelfReportedScheduledImportFailureTest extends TestCase
{
    public function test_self_reporting_import_exit_code_one_is_not_reported(): void
    {
        foreach (SelfReportedScheduledImportFailure::COMMANDS as $command) {
            $exception = $this->schedulerException($command, 1);

            $this->assertTrue(SelfReportedScheduledImportFailure::matches($exception), $command);
            $this->assertFalse(app(ExceptionHandler::class)->shouldReport($exception), $command);
        }
    }

    public function test_other_failures_stay_reportable(): void
    {
        foreach ([
            $this->schedulerException('spot:fetch', 255), // PHP fatal error: no own Issue was sent.
            $this->schedulerException('spot:fetch', 137), // Killed process.
            $this->schedulerException('backup:run --only-db', 1),
            $this->schedulerException('spot:fetch-forecast', 1),
            $this->schedulerException('contracts:fetch --postcode=00100', 1),
            new RuntimeException("Scheduled command [{$this->commandString('spot:fetch')}] failed with exit code [1]."),
        ] as $exception) {
            $this->assertFalse(SelfReportedScheduledImportFailure::matches($exception), $exception->getMessage());
            $this->assertTrue(app(ExceptionHandler::class)->shouldReport($exception), $exception->getMessage());
        }
    }

    /** The same text ScheduleRunCommand::runEvent() throws for a non-zero foreground exit. */
    private function schedulerException(string $command, int $exitCode): Exception
    {
        return new Exception("Scheduled command [{$this->commandString($command)}] failed with exit code [{$exitCode}].");
    }

    private function commandString(string $command): string
    {
        return Application::formatCommandString($command);
    }
}
