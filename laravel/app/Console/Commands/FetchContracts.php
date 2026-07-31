<?php

namespace App\Console\Commands;

use App\Models\Company;
use App\Models\DataFreshnessCheckpoint;
use App\Models\Postcode;
use App\Services\AzureConsumerApiClient;
use App\Services\CompanyLogoService;
use App\Services\ContractImport\ContractAcquisitionResult;
use App\Services\ContractImport\ContractImporter;
use App\Services\ContractImport\ContractPostImportCoordinator;
use App\Services\MorningFreshness\MorningJobFreshnessService;
use Carbon\Carbon;
use Illuminate\Console\Command;
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
        private readonly MorningJobFreshnessService $freshness,
    ) {
        parent::__construct();
    }

    public function handle(): int
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
        } catch (RequestException $exception) {
            $this->error('Failed to fetch contracts: '.$exception->getMessage());
            Log::error('FetchContracts acquisition failed', [
                'exception_class' => $exception::class,
            ]);
            $this->recordFullScopeCheckpoint(
                $fullScope,
                $today,
                DataFreshnessCheckpoint::STATUS_FAILED,
                ['stage' => 'acquisition'],
            );

            return self::FAILURE;
        }

        if (! $acquisition->complete) {
            $this->warn('Contract acquisition was incomplete. Failed postcodes: '.implode(', ', $acquisition->failedPostcodes).'.');
        }

        if ($acquisition->contracts === []) {
            $this->warn('No contracts fetched from API.');
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
            $this->error('Error processing contracts: '.$exception->getMessage());
            Log::error('FetchContracts authoritative import failed', [
                'exception_class' => $exception::class,
                'exception' => $exception->getMessage(),
            ]);
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

        $postImport = $this->postImportCoordinator->run($import, $today);

        foreach ($postImport->interpretationDispatchFailureObservationIds as $observationId) {
            $this->warn("Contracts were updated, but interpretation dispatch failed for observation {$observationId}.");
        }
        foreach ($postImport->optionalFailures as $stage => $message) {
            if (! str_starts_with($stage, 'interpretation:')) {
                $this->warn("Optional post-import stage {$stage} failed: {$message}");
            }
        }
        foreach ($postImport->requiredFailures as $stage => $message) {
            $this->error("Required post-import stage {$stage} failed: {$message}");
        }

        if (! $postImport->succeeded()) {
            $this->recordFullScopeCheckpoint(
                $fullScope,
                $today,
                DataFreshnessCheckpoint::STATUS_FAILED,
                ['stage' => 'post_import'],
            );

            return self::FAILURE;
        }

        $checkpointRecorded = $this->recordFullScopeCheckpoint(
            $fullScope,
            $today,
            $acquisition->complete
                ? DataFreshnessCheckpoint::STATUS_READY
                : DataFreshnessCheckpoint::STATUS_INCOMPLETE,
            [
                'observed_source_observation_ids' => $import->observedObservationIds,
                'active_contract_ids' => $import->activeContractIds,
                'statistics_started_at' => $postImport->statisticsStartedAt?->toIso8601String(),
                'statistics_completed_at' => $postImport->statisticsCompletedAt?->toIso8601String(),
            ],
        );

        if ($fullScope && ! $checkpointRecorded) {
            return self::FAILURE;
        }

        $this->info('Contracts fetched successfully!');

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

        foreach ($postcodes as $postcode) {
            $this->info("Fetching contracts for postcode: {$postcode}");

            try {
                foreach ($this->apiClient->fetchContractsForPostcode($postcode) as $contract) {
                    $id = $contract['Id'] ?? null;
                    if ($id && ! isset($contractsById[$id])) {
                        $contractsById[$id] = $contract;
                    }
                }
            } catch (RequestException $exception) {
                $failedPostcodes[] = $postcode;
                $this->warn("Failed to fetch contracts for postcode {$postcode}: ".$exception->getMessage());

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
            $this->freshness->record(
                DataFreshnessCheckpoint::KEY_CONTRACT_IMPORT,
                $date,
                $status,
                $metadata,
            );

            return true;
        } catch (Throwable $exception) {
            $this->error('Failed to record the contract freshness checkpoint.');
            Log::error('FetchContracts freshness checkpoint failed', [
                'status' => $status,
                'exception_class' => $exception::class,
            ]);

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
