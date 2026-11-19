<?php

namespace App\Console\Concerns;

use App\Services\ImportPayload\ImportFetchFailed;
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
     * $fetch records every request on the writer, may set facts and return end facts. When nothing
     * usable was fetched it throws ImportFetchFailed after recording its requests (the file is
     * kept) or any other throwable (the file is dropped). $store parses the payload and writes
     * rows. $transactional is false only for a store that owns its transactions (chunked backfill,
     * contracts:fetch).
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
            } catch (ImportFetchFailed $e) {
                // Every request is recorded: keep the file with its error bodies, then fail the fetch.
                try {
                    // A failed fetch never supersedes an older good payload (refuseStagedOlderPayload).
                    $writer->setFact('fetch_failed', true);
                    $kept = $writer->finish($e->endFacts);
                } catch (Throwable) {
                    $writer->abandon();

                    return $this->stagedFailure('fetch', 'fetch_failed', $e, ['reason' => $e->reason]);
                }

                return $this->stagedFailure('fetch', 'fetch_failed', $e, [
                    'reason' => $e->reason, 'payload_path' => $kept->path, 'payload_sha256' => $kept->sha256,
                ]);
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
        } catch (ImportPayloadInvalid $e) {
            return $this->stagedFailure('store', 'payload_invalid', $e, ['reason' => $e->reason]);
        }
        // Every store failure line from here on names the verified file, so it can be replayed.
        $opened = ['payload_path' => $reader->path(), 'payload_sha256' => $reader->sha256()];

        try {
            $header = $reader->header();
            if ($header['source'] !== $this->stagedImportSource() || $header['command'] !== $this->getName()) {
                throw ImportPayloadInvalid::because('wrong_source');
            }
            if ($this->option('from-payload') !== null) {
                if (self::normalizedOptions($header['options']) !== self::normalizedOptions($this->stagedDataOptions())) {
                    throw ImportPayloadInvalid::because('options_mismatch');
                }
                $this->refuseStagedOlderPayload($reader);
            }
        } catch (ImportPayloadInvalid $e) {
            return $this->stagedFailure('store', 'payload_invalid', $e, ['reason' => $e->reason, ...$opened]);
        }

        $typedStore = fn (): ImportStoreResult => $store($reader);
        try {
            $result = $transactional ? DB::transaction($typedStore) : $typedStore();
        } catch (ImportPayloadInvalid $e) {
            // For example wrong_date, which only the store step can judge.
            return $this->stagedFailure('store', 'payload_invalid', $e, ['reason' => $e->reason, ...$opened]);
        } catch (Throwable $e) {
            return $this->stagedFailure('store', 'store_failed', $e, $opened);
        }

        try {
            foreach ($result->afterCommit as $afterCommit) {
                $afterCommit();
            }
        } catch (Throwable $e) {
            return $this->stagedFailure('store', 'after_commit_failed', $e, $opened);
        }

        $line = [
            'status' => $result->error === null ? 'stored' : 'failed',
            'step' => $result->error === null ? 'store' : $result->errorStep,
            ...($result->error === null ? [] : ['error' => $result->error, ...$opened]),
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

    /**
     * A replay stores only the newest payload (design 7a): it exists to recover the newest data
     * after a fix, so a complete payload (with its .sha256 file) of the same command and data
     * options fetched later supersedes this one. Reads only each candidate's header line. A
     * candidate whose every request failed holds nothing usable and never supersedes; a header
     * without the counts (older files) counts as usable.
     *
     * @throws ImportPayloadInvalid superseded
     */
    private function refuseStagedOlderPayload(ImportPayloadReader $reader): void
    {
        $header = $reader->header();
        $fromDate = $reader->fetchedAt()->setTimezone('Europe/Helsinki')->toDateString();
        $options = self::normalizedOptions($header['options']);
        $own = realpath($reader->path());
        $root = rtrim((string) config('import_payloads.path'), '/').'/'.$this->stagedImportSource();

        foreach (glob("{$root}/*/*.ndjson.gz") ?: [] as $path) {
            if (basename(dirname($path)) < $fromDate || realpath($path) === $own
                || ! is_file(substr($path, 0, -strlen('.ndjson.gz')).'.sha256')) {
                continue;
            }
            $handle = @gzopen($path, 'rb');
            $line = $handle !== false ? gzgets($handle) : false;
            if ($handle !== false) {
                gzclose($handle);
            }
            $other = $line !== false ? json_decode($line, true) : null;
            $allFailed = (is_int($other['requests'] ?? null) && $other['requests'] > 0
                && ($other['failed_units'] ?? null) === $other['requests'])
                || (($other['facts']['fetch_failed'] ?? false) === true);
            if (is_array($other) && ! $allFailed && ($other['command'] ?? null) === $header['command']
                && is_string($other['fetched_at'] ?? null) && $other['fetched_at'] > $header['fetched_at']
                && is_array($other['options'] ?? null) && self::normalizedOptions($other['options']) === $options) {
                throw ImportPayloadInvalid::because('superseded');
            }
        }
    }

    /** @param  array<string, mixed>  $options */
    private static function normalizedOptions(array $options): string
    {
        ksort($options);

        return json_encode($options, JSON_THROW_ON_ERROR);
    }
}
