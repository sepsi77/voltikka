<?php

namespace App\Support;

use Exception;
use Throwable;

/**
 * Recognizes the scheduler's generic exception for an import command that already sent its own
 * DataFetchFailureReporter Issue and then returned Command::FAILURE (exit code 1).
 *
 * The scheduler throws a plain Exception for every non-zero foreground exit, so one failed import
 * otherwise creates two Sentry Issues. Other exit codes (a PHP fatal error is 255, a killed process
 * 137) mean the command did not reach its own report, so they stay reportable.
 */
final class SelfReportedScheduledImportFailure
{
    /** Commands that report through DataFetchFailureReporter and catch every Throwable at their boundary. */
    public const COMMANDS = ['spot:fetch', 'futures:fetch-eex', 'contracts:fetch'];

    public static function matches(Throwable $exception): bool
    {
        if ($exception::class !== Exception::class) {
            return false;
        }

        // Framework text from ScheduleRunCommand::runEvent(); the command string comes from
        // Application::formatCommandString(), for example "'/usr/local/bin/php' 'artisan' spot:fetch".
        $commands = implode('|', array_map(fn (string $command): string => preg_quote($command, '/'), self::COMMANDS));

        return preg_match("/^Scheduled command \\[.* 'artisan' ({$commands})\\] failed with exit code \\[1\\]\\.$/", $exception->getMessage()) === 1;
    }
}
