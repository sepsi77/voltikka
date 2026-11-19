<?php

namespace App\Console\Commands;

use App\Console\Concerns\StagedImport;
use App\Models\Company;
use App\Models\ContractSourceObservation;
use App\Models\DataFreshnessCheckpoint;
use App\Models\Postcode;
use App\Services\AzureConsumerApiClient;
use App\Services\CompanyLogoService;
use App\Services\ContractImport\ContractAcquisitionResult;
use App\Services\ContractImport\ContractImportCompletion;
use App\Services\ContractImport\ContractImporter;
use App\Services\ContractImport\ContractPostImportCoordinator;
use App\Services\ImportPayload\ImportFetchFailed;
use App\Services\ImportPayload\ImportPayloadInvalid;
use App\Services\ImportPayload\ImportPayloadReader;
use App\Services\ImportPayload\ImportPayloadWriter;
use App\Services\ImportPayload\ImportStoreResult;
use App\Support\DataFetchFailureReporter;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Console\Command;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

class FetchContracts extends Command
{
    use StagedImport;

    protected $signature = 'contracts:fetch
                            {--postcodes= : Comma-separated list of postcodes to fetch contracts for}
                            {--skip-logos : Skip downloading company logos}';

    protected $description = 'Fetch electricity contracts from Azure Consumer API and save to database';

    /** Serializes every contract store (freshness check, checkpoint takeover, import); see store(). */
    public const STORE_LOCK = 'contract_import_store_v1';

    /**
     * Longer than a store may live (review LAR-201): Temporal kills a store activity's PHP process
     * after `STORE_TIMEOUT_MINUTES` = 60 (orchestration/src/voltikka_orchestration/staged.py), so
     * the lease cannot expire under a running store and let a second one pass its freshness check.
     * Accepted cost: a store killed while it holds the lock blocks the next store for up to 65
     * minutes; that store waits STORE_LOCK_WAIT_SECONDS, then fails `store_busy` with its payload kept.
     */
    private const STORE_LOCK_LEASE_SECONDS = 3900;

    private const STORE_LOCK_WAIT_SECONDS = 300;

    private const DEFAULT_POSTCODES = [
        '02230', '00100', '03100', '25660', '22110', '33720', '33680', '20250', '21250', '28120',
        '29570', '47610', '53100', '54960', '80100', '80510', '40100', '40660', '90140', '90940',
        '96200', '96600', '97330', '99300', '99830', '60120', '60640', '65100', '65170', '65630',
    ];

    public function __construct(
        private readonly AzureConsumerApiClient $apiClient,
        private readonly CompanyLogoService $logoService,
        private readonly ContractImporter $importer,
        private readonly ContractPostImportCoordinator $postImportCoordinator,
        private readonly ContractImportCompletion $completion,
    ) {
        parent::__construct();
    }

    private DataFetchFailureReporter $failureReporter;

    private ?string $runUuid = null;

    private ?string $checkpointDate = null;

    /** True once a fetch or store failure is in the Issue and the checkpoint. */
    private bool $failureRecorded = false;

    /** @var array{path: string, sha256: string}|null the payload the store step reads */
    private ?array $payloadReference = null;

    /** @var array{date: string, uuid: string}|null the `running` owner the separate fetch recorded in the payload */
    private ?array $fetchRun = null;

    /**
     * Staged import (tasks/staged-api-imports/): the fetch step saves the 30 raw product list
     * responses in a payload file, the store step rebuilds the acquisition from that file and runs
     * the import transaction and the post-import work. The importer owns its transaction, so the
     * store is not wrapped in another one. Date-bound (S9): the store refuses another Helsinki date.
     */
    public function handle(): int
    {
        $this->failureReporter = new DataFetchFailureReporter('contracts');
        $this->runUuid = null;
        $this->checkpointDate = null;
        $this->failureRecorded = false;
        $this->payloadReference = null;
        $this->fetchRun = null;
        $exit = self::FAILURE;
        try {
            $exit = $this->runStaged(
                fn (ImportPayloadWriter $payload): array => $this->fetch($payload),
                fn (ImportPayloadReader $payload): ImportStoreResult => $this->store($payload),
                transactional: false,
            );
        } catch (Throwable $exception) {
            $this->failureReporter->fail('unexpected', $exception);
            $this->error('Contract import failed.');
            if ($this->runUuid !== null && $this->checkpointDate !== null) {
                $this->recordFullScopeCheckpoint(true, $this->checkpointDate, DataFreshnessCheckpoint::STATUS_FAILED, ['stage' => 'unexpected']);
            }
        } finally {
            $this->failureReporter->report($exit === self::FAILURE);
        }

        return $exit;
    }

    protected function stagedImportSource(): string
    {
        return 'azure-consumer-api';
    }

    /** Logo downloads run after the store and are not in the payload, so --skip-logos is no data option. */
    protected function stagedDataOptions(): array
    {
        return ['postcodes' => $this->option('postcodes')];
    }

    /**
     * Failures the fetch and store steps did not classify themselves: an unexpected exception or a
     * refused payload. They go into the one Issue of this run and fail an owned checkpoint.
     */
    protected function reportStagedFailure(string $step, string $error, ?Throwable $exception, array $fields): void
    {
        if ($this->failureRecorded) {
            return;
        }
        $stage = in_array($error, ['fetch_failed', 'store_failed', 'after_commit_failed'], true) ? 'unexpected' : $error;
        $this->failureReporter->step($step);
        $this->failureReporter->fail($stage, $exception);
        // A refused payload records its closed reason (wrong_date, superseded) as the checkpoint stage.
        $checkpointStage = $error === 'payload_invalid' && is_string($fields['reason'] ?? null) ? $fields['reason'] : $stage;
        if ($this->runUuid !== null && $this->checkpointDate !== null) {
            $this->recordFullScopeCheckpoint(true, $this->checkpointDate, DataFreshnessCheckpoint::STATUS_FAILED, ['stage' => $checkpointStage] + $this->payloadMetadata());
        } else {
            $this->failFetchRun($checkpointStage);
        }
    }

    /**
     * Fetch step: the `running` checkpoint, then one GET per postcode. Every answer or failure is
     * one payload line; nothing is parsed or written here. Fails only when no postcode answered.
     *
     * @return array{failed_postcodes: list<string>}
     */
    private function fetch(ImportPayloadWriter $payload): array
    {
        $this->failureReporter->step('fetch');
        $this->info('Fetching contracts from Azure Consumer API...');
        // The import date of the rows; the store step takes it from the header (S5).
        $date = $payload->fetchedAt()->setTimezone('Europe/Helsinki')->toDateString();
        $fullScope = $this->option('postcodes') === null;
        $payload->setFact('effective_date', $date);

        if (! $this->recordFullScopeCheckpoint($fullScope, $date, DataFreshnessCheckpoint::STATUS_RUNNING, ['stage' => 'started'])) {
            $this->failureRecorded = true;

            throw new RuntimeException('The contract freshness checkpoint could not be started.');
        }
        if ($fullScope && $this->option('fetch-only')) {
            // The separate store process finishes this owner when it fails before its takeover
            // (LAR-202). A default-mode run finishes its owner itself, so its payload has no owner.
            $payload->setFact('checkpoint_run_uuid', $this->runUuid);
        }

        $postcodes = $this->getPostcodes();
        $payload->setFact('postcodes', $postcodes);
        $failedPostcodes = [];
        $this->failureReporter->count('requested_postcodes', count($postcodes));

        foreach ($postcodes as $postcode) {
            $this->info("Fetching contracts for postcode: {$postcode}");

            try {
                $payload->record(['postcode' => $postcode], $this->apiClient->fetchProductList($postcode));
            } catch (RequestException|ConnectionException $exception) {
                $payload->recordFailure(['postcode' => $postcode], 'GET', $this->apiClient->productListUrl($postcode), [], $exception);
                $failedPostcodes[] = $postcode;
                $this->failureReporter->fail('acquisition', $exception);
                $this->failureReporter->count('failed_postcodes', count($failedPostcodes));
                $this->warn("Failed to fetch contracts for postcode {$postcode} after retries.");

                if (count($failedPostcodes) === count($postcodes)) {
                    $this->error('Failed to fetch contracts after retries.');
                    $this->recordFullScopeCheckpoint($fullScope, $date, DataFreshnessCheckpoint::STATUS_FAILED, ['stage' => 'acquisition']);
                    $this->failureRecorded = true;

                    // The trait keeps the payload with every error body (design 7a), then fails the fetch.
                    throw ImportFetchFailed::because('all_failed', ['failed_postcodes' => $failedPostcodes], $exception);
                }
            }
        }

        return ['failed_postcodes' => $failedPostcodes];
    }

    /**
     * Store step: rebuild the deduplicated acquisition from the payload, then the unchanged import
     * transaction and post-import work. Data times come from the header: the import date is
     * `effective_date` and the observation time is `fetched_at` (S5).
     */
    private function store(ImportPayloadReader $payload): ImportStoreResult
    {
        $this->failureReporter->step('store');
        $this->payloadReference = ['path' => $payload->path(), 'sha256' => $payload->sha256()];
        // Before any write: the rows carry the import date, and a replay is for the same day only.
        $date = $payload->fact('effective_date');
        $fetchRunUuid = $payload->fact('checkpoint_run_uuid');
        if ($this->option('from-payload') !== null && is_string($date) && is_string($fetchRunUuid)) {
            $this->fetchRun = ['date' => $date, 'uuid' => $fetchRunUuid];
        }
        if ($date !== Carbon::now('Europe/Helsinki')->toDateString()) {
            throw ImportPayloadInvalid::because('wrong_date');
        }
        $fullScope = $this->option('postcodes') === null;

        // The freshness check, the replay's checkpoint takeover and the import transaction run
        // under one lock that every contract store takes, full or scoped (review PRC-001): the
        // check then sees every store that committed before this one, and no newer store can
        // commit between the check and the write. The fetch above and the post-import work below
        // stay outside; the post-import work has its own lock (ContractImportCompletion::LOCK).
        $lock = Cache::lock(self::STORE_LOCK, self::STORE_LOCK_LEASE_SECONDS);
        try {
            $lock->block(self::STORE_LOCK_WAIT_SECONDS);
        } catch (LockTimeoutException) {
            // No write: the payload stays for a replay; failed like any store failure before takeover.
            $this->error('Another contract store holds the store lock.');
            $this->reportStagedFailure('store', 'store_busy', null, []);
            $this->failureRecorded = true;

            return new ImportStoreResult(error: 'store_busy');
        }

        try {
            // Again under the lock: the wait can last past midnight.
            if ($date !== Carbon::now('Europe/Helsinki')->toDateString()) {
                throw ImportPayloadInvalid::because('wrong_date');
            }
            $this->refuseSupersededPayload($payload->fetchedAt());

            // A replay of a saved payload owns the checkpoint from here on, with its own run UUID.
            if ($this->option('from-payload') !== null && $fullScope && ($refused = $this->takeOverCheckpoint($date)) !== null) {
                return $refused;
            }

            $acquisition = $this->acquisitionFromPayload($payload);
            $counts = ['contracts' => count($acquisition->contracts), 'failed_postcodes' => count($acquisition->failedPostcodes)];
            $this->failureReporter->count('fetched_contracts', count($acquisition->contracts));
            if (! $acquisition->complete) {
                $this->warn('Contract acquisition was incomplete. Failed postcodes: '.implode(', ', $acquisition->failedPostcodes).'.');
            }

            if ($acquisition->contracts === []) {
                $this->warn('No contracts fetched from API.');
                $this->failureReporter->fail('no_contracts');
                $this->failureRecorded = true;
                $this->recordFullScopeCheckpoint(
                    $fullScope,
                    $date,
                    DataFreshnessCheckpoint::STATUS_FAILED,
                    ['stage' => 'acquisition', 'reason' => 'no_contracts'] + $this->payloadMetadata(),
                );

                // A scoped run fails when postcodes failed; an empty answer alone only warns.
                return match (true) {
                    $fullScope => new ImportStoreResult($counts, error: 'no_contracts'),
                    $acquisition->failedPostcodes !== [] => new ImportStoreResult($counts, error: 'fetch_incomplete', errorStep: 'fetch'),
                    default => new ImportStoreResult($counts),
                };
            }

            $this->info('Fetched '.count($acquisition->contracts).' unique contracts. Processing...');

            try {
                $import = $this->importer->import(
                    contracts: $acquisition->contracts,
                    validPostcodes: Postcode::pluck('postcode')->all(),
                    importDate: $date,
                    complete: $acquisition->complete,
                    observedAt: $payload->fetchedAt(),
                );
            } catch (Throwable $exception) {
                // The importer rolled back; the payload stays for a replay after the fix.
                $this->error('Error processing contracts.');
                $this->failureReporter->fail('import', $exception);
                $this->failureRecorded = true;
                $this->recordFullScopeCheckpoint(
                    $fullScope,
                    $date,
                    DataFreshnessCheckpoint::STATUS_FAILED,
                    ['stage' => 'import'] + $this->payloadMetadata(),
                );

                throw $exception;
            }
        } finally {
            $lock->release();
        }

        $counts += ['active_contracts' => $import->activeContractCount, 'price_components' => $import->priceComponentCount];
        $this->info('Processed '.count($import->companyNames).' companies.');
        $this->info("Processed {$import->contractCount} contracts.");
        $this->info("Updated active contracts table with {$import->activeContractCount} contracts.");
        $this->info("Processed {$import->priceComponentCount} price components.");
        $this->info(sprintf(
            'Replacement links: linked %d, skipped existing %d, skipped no match %d, skipped not high confidence %d.',
            $import->replacementStats['linked'],
            $import->replacementStats['skipped_existing'],
            $import->replacementStats['skipped_no_match'],
            $import->replacementStats['skipped_not_high'],
        ));

        // Logo downloads are default-mode work: a store from a saved payload makes no HTTP call (S7).
        if (! $this->option('skip-logos') && $this->option('from-payload') === null) {
            $this->syncCompanyLogos($import->companyNames);
        }

        $postImport = $this->postImportCoordinator->run($import, $date, $this->runUuid);

        foreach ($postImport->interpretationDispatchFailureObservationIds as $observationId) {
            $this->warn("Contracts were updated, but interpretation dispatch failed for observation {$observationId}.");
        }
        foreach ($postImport->optionalFailures as $stage => $message) {
            if (! str_starts_with($stage, 'interpretation:')) {
                $this->warn("Optional post-import stage {$stage} failed: {$message}");
            }
        }
        foreach ($postImport->requiredFailures as $stage => $message) {
            $this->error("Required post-import stage {$stage} failed.");
            $this->failureReporter->fail(match ($stage) {
                'daily_statistics', 'cache_invalidation', 'price_cache_refresh' => $stage,
                default => 'required_post_import',
            }, $postImport->requiredExceptions[$stage] ?? null);
        }

        if (! $postImport->succeeded()) {
            $this->failureRecorded = true;
            $this->recordFullScopeCheckpoint(
                $fullScope,
                $date,
                DataFreshnessCheckpoint::STATUS_FAILED,
                ['stage' => 'post_import'] + $postImport->completionMetadata + $this->payloadMetadata(),
            );

            return new ImportStoreResult($counts, error: 'post_import_failed');
        }

        $checkpointRecorded = $this->recordFullScopeCheckpoint(
            $fullScope,
            $date,
            $postImport->deferred ? ContractImportCompletion::PENDING : ($acquisition->complete
                ? DataFreshnessCheckpoint::STATUS_READY
                : DataFreshnessCheckpoint::STATUS_INCOMPLETE),
            $postImport->completionMetadata + [
                'observed_source_observation_ids' => $import->observedObservationIds,
                'active_contract_ids' => $import->activeContractIds,
            ] + ($postImport->statisticsCompletedAt === null ? [] : [
                // A completion deferred before its initial statistics records no statistics times.
                'statistics_started_at' => $postImport->statisticsStartedAt?->toIso8601String(),
                'statistics_completed_at' => $postImport->statisticsCompletedAt->toIso8601String(),
            ]),
        );

        if ($fullScope && ! $checkpointRecorded) {
            $this->failureRecorded = true;

            return new ImportStoreResult($counts, error: 'checkpoint_failed');
        }

        if ($fullScope && DataFreshnessCheckpoint::query()
            ->where('key', DataFreshnessCheckpoint::KEY_CONTRACT_IMPORT)->where('effective_date', $date)
            ->where('metadata->run_uuid', $this->runUuid)->where('status', ContractImportCompletion::PENDING)->exists()) {
            $this->info('Contracts imported. Required completion is deferred until current interpretations settle.');
        } else {
            $this->info('Contracts fetched successfully!');
        }

        return new ImportStoreResult($counts);
    }

    /**
     * An older payload must not roll newer seller evidence back: the import would move source
     * pointers, availability and today's prices to the older fetch. Refused before any write when
     * the newest accepted observation is later than the payload's fetch time; an equal time is
     * the same payload stored again and stays idempotent. The caller holds STORE_LOCK, so the
     * importer's own row locks need not compare source times.
     */
    private function refuseSupersededPayload(CarbonInterface $fetchedAt): void
    {
        // Stored as wall time in the application timezone (ContractImporter::import()).
        $newest = ContractSourceObservation::query()->max('last_observed_at');
        if ($newest !== null && Carbon::parse($newest, config('app.timezone'))->gt($fetchedAt)) {
            throw ImportPayloadInvalid::because('superseded');
        }
    }

    /**
     * A replay's takeover of the full-scope checkpoint, under the store lock. It never seizes a
     * live run: when another run is `running` for the date (a newer fetch, or a store that is not
     * this payload's own fetch), the replay writes nothing and fails `checkpoint_busy`, and that
     * run keeps the date. The payload's own fetch owner, a settled run or no row can be taken over,
     * so the current failed run stays recoverable. Null when the replay owns the date.
     */
    private function takeOverCheckpoint(string $date): ?ImportStoreResult
    {
        try {
            $owner = $this->completion->takeOver($date, $this->fetchRun['uuid'] ?? null);
        } catch (Throwable $exception) {
            $this->error('Failed to record the contract freshness checkpoint.');
            $this->failureReporter->fail('checkpoint', $exception);
            $this->failureRecorded = true;
            $this->failFetchRun('checkpoint');

            return new ImportStoreResult(error: 'checkpoint_failed');
        }
        if ($owner === null) {
            $this->error('Another contract import run is running for this date.');
            $this->failureReporter->fail('checkpoint_busy');
            $this->failureRecorded = true;

            return new ImportStoreResult(error: 'checkpoint_busy');
        }
        [$this->checkpointDate, $this->runUuid] = [$date, $owner];

        return null;
    }

    /**
     * A `--from-payload` store that fails before its checkpoint takeover (store_busy, superseded,
     * wrong_date, a failed takeover) finishes the `running` marker its `--fetch-only` fetch wrote,
     * so that marker is not left running (review LAR-202). Only while that owner still holds the
     * date and the row is still `running`, compared under the row lock (record() compares the
     * owner): a newer run or replay keeps the date, and a replay of an old payload changes
     * nothing. It never starts an owner. A payload the trait refuses before the store step
     * (changed file, other source or options) is not read, so its owner stays as it is.
     */
    private function failFetchRun(string $stage): void
    {
        if ($this->runUuid !== null || $this->fetchRun === null) {
            return;
        }

        try {
            DB::transaction(function () use ($stage): void {
                $status = DataFreshnessCheckpoint::query()->where('key', DataFreshnessCheckpoint::KEY_CONTRACT_IMPORT)
                    ->where('effective_date', $this->fetchRun['date'])->lockForUpdate()->value('status');
                if ($status === DataFreshnessCheckpoint::STATUS_RUNNING) {
                    $this->completion->record(
                        $this->fetchRun['date'],
                        $this->fetchRun['uuid'],
                        DataFreshnessCheckpoint::STATUS_FAILED,
                        ['stage' => $stage] + $this->payloadMetadata(),
                    );
                }
            });
        } catch (Throwable $exception) {
            $this->error('Failed to record the contract freshness checkpoint.');
            $this->failureReporter->fail('checkpoint', $exception);
        }
    }

    /** @return list<string> */
    private function getPostcodes(): array
    {
        $postcodesOption = $this->option('postcodes');

        return $postcodesOption
            ? array_map('trim', explode(',', $postcodesOption))
            : self::DEFAULT_POSTCODES;
    }

    /**
     * The acquisition the import received before the staged import: contracts deduplicated by
     * `Id` in postcode order (first wins), and the postcodes that got no answer (S10).
     */
    private function acquisitionFromPayload(ImportPayloadReader $payload): ContractAcquisitionResult
    {
        $contractsById = [];
        $failedPostcodes = [];
        foreach ($payload->requests() as $request) {
            $status = $request['status'] ?? null;
            if (($request['connection_failed'] ?? false) || ! is_int($status) || $status < 200 || $status >= 300) {
                $failedPostcodes[] = (string) $request['unit']['postcode'];

                continue;
            }
            $contracts = json_decode((string) ImportPayloadReader::body($request), true);
            foreach (is_array($contracts) ? $contracts : [] as $contract) {
                $id = $contract['Id'] ?? null;
                if ($id && ! isset($contractsById[$id])) {
                    $contractsById[$id] = $contract;
                }
            }
        }

        return new ContractAcquisitionResult(
            contracts: array_values($contractsById),
            failedPostcodes: $failedPostcodes,
        );
    }

    /** @return array<string, array{path: string, sha256: string}> the payload of a failed store, for a replay (S11) */
    private function payloadMetadata(): array
    {
        return $this->payloadReference === null ? [] : ['payload' => $this->payloadReference];
    }

    /** @param array<string, mixed> $metadata */
    private function recordFullScopeCheckpoint(
        bool $fullScope,
        string $date,
        string $status,
        array $metadata,
    ): bool {
        if (! $fullScope) {
            return true;
        }

        try {
            if (($metadata['stage'] ?? null) === 'started') {
                $this->checkpointDate = $date;
                $this->runUuid = $this->completion->start($date);

                return true;
            }

            if ($this->runUuid !== null && $this->completion->record($date, $this->runUuid, $status, $metadata)) {
                return true;
            }

            // A refused record fails the run; the scheduler exception for it is not reported.
            $this->failureReporter->fail('checkpoint');

            return false;
        } catch (Throwable $exception) {
            $this->error('Failed to record the contract freshness checkpoint.');
            $this->failureReporter->fail('checkpoint', $exception);

            return false;
        }
    }

    /** @param list<string> $companyNames */
    private function syncCompanyLogos(array $companyNames): void
    {
        $downloaded = 0;

        foreach (Company::whereIn('name', $companyNames)->get() as $company) {
            if (! $company->logo_url || $company->local_logo_path) {
                continue;
            }

            $this->output->write("Downloading logo for {$company->name}... ");
            try {
                $localPath = $this->logoService->downloadAndStore($company);
                if ($localPath) {
                    $company->update(['local_logo_path' => $localPath]);
                    $downloaded++;
                    $this->output->writeln('<info>OK</info>');
                } else {
                    $this->output->writeln('<comment>Failed</comment>');
                }
            } catch (Throwable $exception) {
                Log::warning('Optional company logo sync failed', [
                    'company' => $company->name,
                    'exception_class' => $exception::class,
                ]);
                $this->output->writeln('<comment>Failed</comment>');
            }
        }

        if ($downloaded > 0) {
            $this->info("Downloaded {$downloaded} company logos.");
        }
    }
}
