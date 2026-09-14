<?php

namespace App\Console\Commands;

use App\Models\Company;
use App\Models\DataFreshnessCheckpoint;
use App\Models\Postcode;
use App\Services\AzureConsumerApiClient;
use App\Services\CompanyLogoService;
use App\Services\ContractImport\ContractAcquisitionResult;
use App\Services\ContractImport\ContractImportCompletion;
use App\Services\ContractImport\ContractImporter;
use App\Services\ContractImport\ContractPostImportCoordinator;
use App\Support\DataFetchFailureReporter;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Log;
use Throwable;

class FetchContracts extends Command
{
    protected $signature = 'contracts:fetch
                            {--postcodes= : Comma-separated list of postcodes to fetch contracts for}
                            {--skip-logos : Skip downloading company logos}';

    protected $description = 'Fetch electricity contracts from Azure Consumer API and save to database';

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

    public function handle(): int
    {
        $this->failureReporter = new DataFetchFailureReporter('contracts');
        $this->runUuid = null;
        $exit = self::FAILURE;
        try {
            $exit = $this->fetch();
        } catch (Throwable $exception) {
            $this->failureReporter->fail('unexpected', $exception);
            $this->error('Contract import failed.');
        } finally {
            $this->failureReporter->report($exit === self::FAILURE);
        }

        return $exit;
    }

    private function fetch(): int
    {
        $this->info('Fetching contracts from Azure Consumer API...');
        $today = Carbon::now('Europe/Helsinki')->toDateString();
        $fullScope = $this->option('postcodes') === null;

        if (! $this->recordFullScopeCheckpoint(
            $fullScope,
            $today,
            DataFreshnessCheckpoint::STATUS_FAILED,
            ['stage' => 'started'],
        )) {
            return self::FAILURE;
        }

        $postcodes = $this->getPostcodes();

        try {
            $acquisition = $this->fetchAllContracts($postcodes);
        } catch (RequestException|ConnectionException $exception) {
            $this->error('Failed to fetch contracts after retries.');
            $this->recordFullScopeCheckpoint(
                $fullScope,
                $today,
                DataFreshnessCheckpoint::STATUS_FAILED,
                ['stage' => 'acquisition'],
            );

            return self::FAILURE;
        }

        $this->failureReporter->count('fetched_contracts', count($acquisition->contracts));
        if (! $acquisition->complete) {
            $this->warn('Contract acquisition was incomplete. Failed postcodes: '.implode(', ', $acquisition->failedPostcodes).'.');
        }

        if ($acquisition->contracts === []) {
            $this->warn('No contracts fetched from API.');
            $this->failureReporter->fail('no_contracts');
            $this->recordFullScopeCheckpoint(
                $fullScope,
                $today,
                DataFreshnessCheckpoint::STATUS_FAILED,
                ['stage' => 'acquisition', 'reason' => 'no_contracts'],
            );

            return $fullScope ? self::FAILURE : self::SUCCESS;
        }

        $this->info('Fetched '.count($acquisition->contracts).' unique contracts. Processing...');

        try {
            $import = $this->importer->import(
                contracts: $acquisition->contracts,
                validPostcodes: Postcode::pluck('postcode')->all(),
                importDate: $today,
                complete: $acquisition->complete,
            );
        } catch (Throwable $exception) {
            $this->error('Error processing contracts.');
            $this->failureReporter->fail('import', $exception);
            $this->recordFullScopeCheckpoint(
                $fullScope,
                $today,
                DataFreshnessCheckpoint::STATUS_FAILED,
                ['stage' => 'import'],
            );

            return self::FAILURE;
        }

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

        if (! $this->option('skip-logos')) {
            $this->syncCompanyLogos($import->companyNames);
        }

        $postImport = $this->postImportCoordinator->run($import, $today, $this->runUuid);

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
            $this->recordFullScopeCheckpoint(
                $fullScope,
                $today,
                DataFreshnessCheckpoint::STATUS_FAILED,
                ['stage' => 'post_import'] + $postImport->completionMetadata,
            );

            return self::FAILURE;
        }

        $checkpointRecorded = $this->recordFullScopeCheckpoint(
            $fullScope,
            $today,
            $postImport->deferred ? ContractImportCompletion::PENDING : ($acquisition->complete
                ? DataFreshnessCheckpoint::STATUS_READY
                : DataFreshnessCheckpoint::STATUS_INCOMPLETE),
            $postImport->completionMetadata + [
                'observed_source_observation_ids' => $import->observedObservationIds,
                'active_contract_ids' => $import->activeContractIds,
                'statistics_started_at' => $postImport->statisticsStartedAt?->toIso8601String(),
                'statistics_completed_at' => $postImport->statisticsCompletedAt?->toIso8601String(),
            ],
        );

        if ($fullScope && ! $checkpointRecorded) {
            return self::FAILURE;
        }

        if ($fullScope && DataFreshnessCheckpoint::query()
            ->where('key', DataFreshnessCheckpoint::KEY_CONTRACT_IMPORT)->where('effective_date', $today)
            ->where('metadata->run_uuid', $this->runUuid)->where('status', ContractImportCompletion::PENDING)->exists()) {
            $this->info('Contracts imported. Required completion is deferred until current interpretations settle.');
        } else {
            $this->info('Contracts fetched successfully!');
        }

        return self::SUCCESS;
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
     * @param  list<string>  $postcodes
     */
    private function fetchAllContracts(array $postcodes): ContractAcquisitionResult
    {
        $contractsById = [];
        $failedPostcodes = [];
        $this->failureReporter->count('requested_postcodes', count($postcodes));

        foreach ($postcodes as $postcode) {
            $this->info("Fetching contracts for postcode: {$postcode}");

            try {
                foreach ($this->apiClient->fetchContractsForPostcode($postcode) as $contract) {
                    $id = $contract['Id'] ?? null;
                    if ($id && ! isset($contractsById[$id])) {
                        $contractsById[$id] = $contract;
                    }
                }
            } catch (RequestException|ConnectionException $exception) {
                $failedPostcodes[] = $postcode;
                $this->failureReporter->fail('acquisition', $exception);
                $this->failureReporter->count('failed_postcodes', count($failedPostcodes));
                $this->warn("Failed to fetch contracts for postcode {$postcode} after retries.");

                if ($postcode === end($postcodes) && $contractsById === []) {
                    throw $exception;
                }
            }
        }

        return new ContractAcquisitionResult(
            contracts: array_values($contractsById),
            failedPostcodes: $failedPostcodes,
        );
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
                $this->runUuid = $this->completion->start($date);

                return true;
            }

            return $this->runUuid !== null && $this->completion->record($date, $this->runUuid, $status, $metadata);
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
