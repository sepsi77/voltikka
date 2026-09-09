<?php

namespace App\Support;

use Illuminate\Support\Facades\Log;
use Sentry\Severity;
use Sentry\State\Scope;
use Throwable;

use function Sentry\captureMessage;
use function Sentry\withScope;

/** One instance belongs to one command invocation, not one request or retry. */
class DataFetchFailureReporter
{
    private array $failures = [];

    private array $exceptionClasses = [];

    private array $counts = [];

    public function __construct(private readonly string $import) {}

    /** Stage and count names must be controlled code constants, never upstream text. */
    public function fail(string $stage, ?Throwable $exception = null): void
    {
        $this->failures[$stage] = ($this->failures[$stage] ?? 0) + 1;
        if ($exception !== null) {
            $this->exceptionClasses[$exception::class] = true;
        }
    }

    public function count(string $name, int $count): void
    {
        $this->counts[$name] = $count;
    }

    public function report(bool $terminal): void
    {
        if ($this->failures === []) {
            return;
        }

        $context = [
            'import' => $this->import,
            'failures' => $this->failures,
            'counts' => $this->counts,
            'exception_classes' => array_keys($this->exceptionClasses),
        ];
        $message = "Data fetch failed: {$this->import}";
        $level = $terminal ? Severity::error() : Severity::warning();

        withScope(function (Scope $scope) use ($context, $message, $level): void {
            // HTTP breadcrumbs can contain query tokens. Keep this Issue aggregate-only.
            $scope->clear();
            $scope->setFingerprint(['data-fetch-failure', $this->import]);
            $scope->setTag('data_import', $this->import);
            $scope->setContext('data_fetch', $context);
            captureMessage($message, $level);
        });

        Log::log((string) $level, $message, $context);
    }
}
