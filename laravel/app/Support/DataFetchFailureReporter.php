<?php

namespace App\Support;

use App\Services\Caching\ContractPriceCacheConflict;
use App\Services\Caching\ContractPriceCacheStorageException;
use App\Services\ContractImport\ContractImportCompletionStopped;
use Illuminate\Database\QueryException;
use Illuminate\Http\Client\RequestException;
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

    private array $reasons = [];

    private ?string $step = null;

    /** @var array<string, string> stage => step (fetch or store) of a staged import */
    private array $steps = [];

    private ?array $storeError = null;

    public function __construct(private readonly string $import) {}

    /** Staged imports: name the step that later failures belong to (fetch or store). */
    public function step(string $step): void
    {
        $this->step = in_array($step, ['fetch', 'store'], true) ? $step : throw new \LogicException('Unknown import step.');
    }

    /** Stage and count names must be controlled code constants, never upstream text. */
    public function fail(string $stage, ?Throwable $exception = null): void
    {
        $this->failures[$stage] = ($this->failures[$stage] ?? 0) + 1;
        if ($this->step !== null) {
            $this->steps[$stage] = $this->step;
        }
        if ($exception !== null) {
            $this->exceptionClasses[$exception::class] = true;
            $storeError = self::storeError($exception);
            if ($storeError !== null) {
                $this->storeError = $storeError;
            }
            $reason = match (true) {
                $exception instanceof ContractPriceCacheConflict, $exception instanceof ContractPriceCacheStorageException,
                $exception instanceof ContractImportCompletionStopped => $exception->reason,
                // The numeric status only; the response body and URL can contain upstream text and tokens.
                $exception instanceof RequestException && $exception->response !== null => 'http_'.$exception->response->status(),
                ($storeError['sqlstate'] ?? null) !== null => 'sqlstate_'.$storeError['sqlstate'],
                default => 'unexpected',
            };
            $this->reasons[$stage][$reason] = ($this->reasons[$stage][$reason] ?? 0) + 1;
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
            'reasons' => $this->reasons,
        ];
        if ($this->steps !== []) {
            $context['steps'] = $this->steps;
        }
        if ($this->storeError !== null) {
            $context['store_error'] = $this->storeError;
        }
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

    /**
     * Safe facts of the first QueryException in the chain: SQLSTATE, driver error code and the
     * table named by the SQL text (insert/replace into, update, delete from). getSql() has only
     * placeholders, so no bound value can enter; the message and bindings are never read.
     *
     * @return array{sqlstate: string|null, driver_code: int|null, table: string|null}|null
     */
    public static function storeError(Throwable $exception): ?array
    {
        for ($e = $exception; $e !== null; $e = $e->getPrevious()) {
            if (! $e instanceof QueryException) {
                continue;
            }
            $sqlstate = $e->errorInfo[0] ?? $e->getCode();
            $driverCode = $e->errorInfo[1] ?? null;
            preg_match(
                '/^\s*(?:insert(?:\s+or\s+\w+|\s+ignore)?\s+into|replace\s+into|update(?:\s+or\s+\w+|\s+ignore)?|delete\s+from)\s+(?:[`"\[]?\w+[`"\]]?\.)?[`"\[]?(\w{1,64})[`"\]]?/i',
                (string) $e->getSql(),
                $match,
            );

            return [
                'sqlstate' => is_string($sqlstate) && preg_match('/^[0-9A-Z]{5}$/', $sqlstate) === 1 ? $sqlstate : null,
                'driver_code' => is_int($driverCode) || (is_string($driverCode) && ctype_digit($driverCode)) ? (int) $driverCode : null,
                'table' => $match[1] ?? null,
            ];
        }

        return null;
    }
}
