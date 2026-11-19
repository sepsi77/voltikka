<?php

namespace App\Console\Concerns;

use App\Services\ImportPayload\ImportPayloadInvalid;
use App\Services\ImportPayload\ImportPayloadReader;
use App\Services\ImportPayload\ImportPayloadWriter;
use App\Services\ImportPayload\ImportStoreResult;
use App\Support\DataFetchFailureReporter;
use Closure;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;

/**
 * Two-step external import (tasks/staged-api-imports/design.md section 3): the fetch step saves
 * the raw responses in a payload file, the store step parses that file and writes the database.
 *
 * Modes: default (fetch, then store from the written file, in one process), --fetch-only, and
 * --from-payload=<path> [--payload-sha256=<hex>] (store only; no API call). The last stdout line is
 * always one JSON object with a closed status and error key; orchestration reads only that line.
 * See app/Services/ImportPayload/AGENTS.md.
 */
trait StagedImport
{
    /** The payload directory name of this import, a lowercase slug such as `nordpool-predict-fi`. */
    abstract protected function stagedImportSource(): string;

    protected function configure(): void
    {
        parent::configure();
        $this->addOption('fetch-only', null, InputOption::VALUE_NONE, 'Fetch and save the payload file only, then print its reference');
        $this->addOption('from-payload', null, InputOption::VALUE_REQUIRED, 'Store from this saved payload file; no API call');
        $this->addOption('payload-sha256', null, InputOption::VALUE_REQUIRED, 'Expected SHA-256 of --from-payload (default: its .sha256 file)');
    }

    /**
     * $fetch records every request on the writer, may set facts and return end facts, and throws
     * when nothing usable was fetched. $store parses the payload and writes rows. $transactional
     * is false only for a store that owns its transactions (chunked backfill, contracts:fetch).
     *
     * @param  Closure(ImportPayloadWriter): mixed  $fetch
     * @param  Closure(ImportPayloadReader): ImportStoreResult  $store
     */
    protected function runStaged(Closure $fetch, Closure $store, bool $transactional = true): int
    {
        $payloadPath = $this->option('from-payload');
        if ($payloadPath !== null && $this->option('fetch-only')) {
            return $this->stagedFailure('fetch', 'invalid_options');
        }

        $sha256 = $this->option('payload-sha256');
        if ($payloadPath === null) {
            $writer = null;
            try {
                $writer = ImportPayloadWriter::open($this->stagedImportSource(), $this->getName(), $this->stagedDataOptions());
                $reference = $writer->finish($fetch($writer) ?? []);
            } catch (Throwable $e) {
                $writer?->abandon();

                return $this->stagedFailure('fetch', 'fetch_failed', $e);
            }
            if ($this->option('fetch-only')) {
                return $this->stagedResult(['status' => 'fetched', 'step' => 'fetch', 'payload' => $reference->toArray()]);
            }
            [$payloadPath, $sha256] = [$reference->path, $reference->sha256];
        }

        try {
            $reader = ImportPayloadReader::open($payloadPath, $sha256);
            $header = $reader->header();
            if ($header['source'] !== $this->stagedImportSource() || $header['command'] !== $this->getName()) {
                throw ImportPayloadInvalid::because('wrong_source');
            }
            if ($this->option('from-payload') !== null && self::normalizedOptions($header['options']) !== self::normalizedOptions($this->stagedDataOptions())) {
                throw ImportPayloadInvalid::because('options_mismatch');
            }
        } catch (ImportPayloadInvalid $e) {
            return $this->stagedFailure('store', 'payload_invalid', $e, ['reason' => $e->reason]);
        }

        $typedStore = fn (): ImportStoreResult => $store($reader);
        try {
            $result = $transactional ? DB::transaction($typedStore) : $typedStore();
        } catch (ImportPayloadInvalid $e) {
            // For example wrong_date, which only the store step can judge.
            return $this->stagedFailure('store', 'payload_invalid', $e, ['reason' => $e->reason]);
        } catch (Throwable $e) {
            return $this->stagedFailure('store', 'store_failed', $e);
        }

        try {
            foreach ($result->afterCommit as $afterCommit) {
                $afterCommit();
            }
        } catch (Throwable $e) {
            return $this->stagedFailure('store', 'after_commit_failed', $e);
        }

        $line = [
            'status' => $result->error === null ? 'stored' : 'failed',
            'step' => $result->error === null ? 'store' : $result->errorStep,
            ...($result->error === null ? [] : ['error' => $result->error]),
            'payload' => ['path' => $reader->path(), 'sha256' => $reader->sha256()],
            'complete' => $reader->complete(),
            'counts' => (object) $result->counts,
        ];

        // A closed error from the store result: the command reported it itself; the rows stay.
        return $this->stagedResult($line, $result->error === null ? self::SUCCESS : self::FAILURE);
    }

    /**
     * Print the failure JSON line, report it through reportStagedFailure() and return exit 1.
     * Commands also use it for their own closed failures before runStaged() (bad options).
     *
     * @param  array<string, scalar|null>  $fields  closed facts only
     */
    protected function stagedFailure(string $step, string $error, ?Throwable $exception = null, array $fields = []): int
    {
        if ($exception !== null && ($storeError = DataFetchFailureReporter::storeError($exception)) !== null) {
            $fields += $storeError;
        }
        $this->reportStagedFailure($step, $error, $exception, $fields);
        $this->error("Import {$step} step failed: {$error}");

        return $this->stagedResult(['status' => 'failed', 'step' => $step, 'error' => $error, ...$fields], self::FAILURE);
    }

    /**
     * One safe log line: closed keys, the exception class and SQL facts, never a message, URL,
     * body or binding. Commands with a DataFetchFailureReporter override this to send their Issue.
     *
     * @param  array<string, scalar|null>  $fields
     */
    protected function reportStagedFailure(string $step, string $error, ?Throwable $exception, array $fields): void
    {
        Log::error("Import failed: {$this->getName()}", [
            'command' => $this->getName(),
            'step' => $step,
            'error' => $error,
            'exception_class' => $exception !== null ? $exception::class : null,
            ...$fields,
        ]);
    }

    /**
     * The command's data options as given (defaults included). The header records them, and a
     * --from-payload run must give the same. Override to leave out an option that changes no row.
     *
     * @return array<string, mixed>
     */
    protected function stagedDataOptions(): array
    {
        $global = array_keys($this->getApplication()?->getDefinition()->getOptions() ?? []);

        return array_diff_key($this->options(), array_flip([...$global, 'fetch-only', 'from-payload', 'payload-sha256']));
    }

    /** @param  array<string, mixed>  $line */
    private function stagedResult(array $line, int $exitCode = self::SUCCESS): int
    {
        // Raw and at quiet verbosity: the line must be last and printed exactly, even with -q.
        $this->output->writeln(
            json_encode($line, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
            OutputInterface::OUTPUT_RAW | OutputInterface::VERBOSITY_QUIET,
        );

        return $exitCode;
    }

    /** @param  array<string, mixed>  $options */
    private static function normalizedOptions(array $options): string
    {
        ksort($options);

        return json_encode($options, JSON_THROW_ON_ERROR);
    }
}
