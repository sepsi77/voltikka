<?php

namespace App\Console\Commands;

use App\Models\ActiveContract;
use App\Models\Company;
use App\Models\ContractSourceSnapshot;
use App\Models\Dso;
use App\Models\ElectricityContract;
use App\Models\ElectricitySource;
use App\Models\Postcode;
use App\Services\AzureConsumerApiClient;
use App\Services\CompanyListCacheService;
use App\Services\CompanyLogoService;
use App\Services\ContractInterpretation\CanonicalPriceComponentWriter;
use App\Services\ContractInterpretation\ContractInterpretationDispatcher;
use App\Services\ContractInterpretation\ContractSourceCanonicalizer;
use App\Services\ContractListCacheService;
use App\Services\ContractReplacement\ContractReplacementLinker;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class FetchContracts extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'contracts:fetch
                            {--postcodes= : Comma-separated list of postcodes to fetch contracts for}
                            {--skip-logos : Skip downloading company logos}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Fetch electricity contracts from Azure Consumer API and save to database';

    /**
     * Default postcodes to fetch if none provided (representative sample for national coverage).
     */
    private const DEFAULT_POSTCODES = [
        '02230', '00100', '03100', '25660', '22110', '33720', '33680', '20250', '21250', '28120',
        '29570', '47610', '53100', '54960', '80100', '80510', '40100', '40660', '90140', '90940',
        '96200', '96600', '97330', '99300', '99830', '60120', '60640', '65100', '65170', '65630',
    ];

    private AzureConsumerApiClient $apiClient;

    private CompanyLogoService $logoService;

    private ContractSourceCanonicalizer $sourceCanonicalizer;

    private ContractInterpretationDispatcher $interpretationDispatcher;

    private CanonicalPriceComponentWriter $priceComponentWriter;

    /** @var list<int> */
    private array $sourceSnapshotIds = [];

    /** @var array<string, int> */
    private array $sourceSnapshotIdsByContractId = [];

    public function __construct(
        AzureConsumerApiClient $apiClient,
        CompanyLogoService $logoService,
        ContractSourceCanonicalizer $sourceCanonicalizer,
        ContractInterpretationDispatcher $interpretationDispatcher,
        CanonicalPriceComponentWriter $priceComponentWriter,
    ) {
        parent::__construct();
        $this->apiClient = $apiClient;
        $this->logoService = $logoService;
        $this->sourceCanonicalizer = $sourceCanonicalizer;
        $this->interpretationDispatcher = $interpretationDispatcher;
        $this->priceComponentWriter = $priceComponentWriter;
    }

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $this->info('Fetching contracts from Azure Consumer API...');
        $this->sourceSnapshotIds = [];
        $this->sourceSnapshotIdsByContractId = [];

        $postcodes = $this->getPostcodes();
        $today = Carbon::now()->toDateString();

        // Get valid postcodes from database
        $validPostcodes = Postcode::pluck('postcode')->toArray();

        try {
            $allContracts = $this->fetchAllContracts($postcodes);
        } catch (RequestException $e) {
            $this->error('Failed to fetch contracts: '.$e->getMessage());
            Log::error('FetchContracts command failed', ['exception' => $e->getMessage()]);

            return Command::FAILURE;
        }

        if (empty($allContracts)) {
            $this->warn('No contracts fetched from API.');

            return Command::SUCCESS;
        }

        $this->info('Fetched '.count($allContracts).' unique contracts. Processing...');

        // Start database transaction
        DB::beginTransaction();

        try {
            // Upload companies first
            $this->processCompanies($allContracts);

            // Upload contracts
            $this->processContracts($allContracts);

            // Preserve the complete upstream payload for later interpretation
            $this->processContractSourceSnapshots($allContracts);

            // Update active contracts table
            $this->updateActiveContracts($allContracts);

            // Upload price components
            $this->processPriceComponents($allContracts, $today);

            // Upload electricity sources
            $this->processElectricitySources($allContracts);

            // Upload contract-postcode relationships
            $this->processContractPostcodes($allContracts, $validPostcodes);

            // Upload DSOs and contract-DSO relationships
            $this->processDsos($allContracts);

            // Upload spot futures (from first contract)
            $this->processSpotFutures($allContracts, $today);

            $replacementStats = app(ContractReplacementLinker::class)->linkHighConfidenceMatches();
            $this->info(sprintf(
                'Replacement links: linked %d, skipped existing %d, skipped no match %d, skipped not high confidence %d.',
                $replacementStats['linked'],
                $replacementStats['skipped_existing'],
                $replacementStats['skipped_no_match'],
                $replacementStats['skipped_not_high']
            ));

            DB::commit();

            try {
                foreach (ContractSourceSnapshot::whereKey($this->sourceSnapshotIds)->get() as $snapshot) {
                    $this->interpretationDispatcher->dispatch($snapshot);
                }
            } catch (\Throwable $dispatchException) {
                Log::warning('Failed to dispatch contract interpretations after import', [
                    'exception' => $dispatchException->getMessage(),
                ]);
                $this->warn('Contracts were updated, but interpretation dispatch failed.');
            }

            try {
                $this->info('Clearing stale application caches before warming fresh contract data...');
                $this->clearApplicationCacheAfterContractUpdate();
                $this->info('Stale application caches cleared.');

                /** @var ContractListCacheService $contractListCache */
                $contractListCache = app(ContractListCacheService::class);
                $version = $contractListCache->bumpVersion();
                $this->info("Contract list cache version bumped to {$version}. Warming preset caches...");
                $contractListCache->warmPresetCaches();
                $this->info('Contract list caches warmed successfully.');

                /** @var CompanyListCacheService $companyListCache */
                $companyListCache = app(CompanyListCacheService::class);
                $companyVersion = $companyListCache->bumpVersion();
                $this->info("Company list cache version bumped to {$companyVersion}. Warming company list cache...");
                $companyListCache->warm();
                $this->info('Company list cache warmed successfully.');

                // Store daily contract-price statistics for trend pages before
                // optional cache/UX metrics. The public statistics page should
                // continue to advance even if percentile badge recalculation
                // later fails or exhausts memory.
                $this->info('Calculating daily contract price statistics...');
                $this->call('contracts:calculate-price-statistics', [
                    '--date' => $today,
                    '--overwrite' => true,
                ]);

                // Recalculate percentile thresholds for smart callout badges.
                $this->info('Recalculating pricing percentiles...');
                $this->call('contracts:calculate-percentiles');
            } catch (\Throwable $cacheException) {
                Log::warning('Failed to warm caches after contracts fetch', [
                    'exception' => $cacheException->getMessage(),
                ]);
                $this->warn('Contracts were updated, but cache warming failed. The cache will be rebuilt on demand.');
            }

            $this->info('Contracts fetched successfully!');

            return Command::SUCCESS;
        } catch (\Exception $e) {
            DB::rollBack();
            $this->error('Error processing contracts: '.$e->getMessage());
            Log::error('FetchContracts command failed during processing', [
                'exception' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return Command::FAILURE;
        }
    }

    /**
     * Clear stale public/application caches after contract data changes.
     *
     * Database cache needs TRUNCATE instead of Cache::flush() because Laravel's
     * database store uses DELETE, which leaves large InnoDB cache.ibd files
     * allocated after oversized page-data cache rows expire.
     */
    private function clearApplicationCacheAfterContractUpdate(): void
    {
        $defaultStore = config('cache.default');
        $storeConfig = config("cache.stores.{$defaultStore}", []);

        if (($storeConfig['driver'] ?? null) === 'database') {
            $connectionName = $storeConfig['connection'] ?? config('database.default');
            $table = $storeConfig['table'] ?? 'cache';
            $connection = DB::connection($connectionName);
            $wrappedTable = $connection->getQueryGrammar()->wrapTable($table);

            $connection->statement("TRUNCATE TABLE {$wrappedTable}");

            return;
        }

        $this->callSilent('cache:clear');
    }

    /**
     * Get postcodes from option or use defaults.
     */
    private function getPostcodes(): array
    {
        $postcodesOption = $this->option('postcodes');

        if ($postcodesOption) {
            return array_map('trim', explode(',', $postcodesOption));
        }

        return self::DEFAULT_POSTCODES;
    }

    /**
     * Fetch all contracts from API for given postcodes.
     */
    private function fetchAllContracts(array $postcodes): array
    {
        $allContracts = [];
        $processedIds = [];

        foreach ($postcodes as $postcode) {
            $this->info("Fetching contracts for postcode: {$postcode}");

            try {
                $contracts = $this->apiClient->fetchContractsForPostcode($postcode);

                foreach ($contracts as $contract) {
                    $id = $contract['Id'] ?? null;
                    if ($id && ! isset($processedIds[$id])) {
                        $allContracts[] = $contract;
                        $processedIds[$id] = true;
                    }
                }
            } catch (RequestException $e) {
                $this->warn("Failed to fetch contracts for postcode {$postcode}: ".$e->getMessage());
                // Continue with other postcodes but throw if all fail
                if ($postcode === end($postcodes) && empty($allContracts)) {
                    throw $e;
                }
            }
        }

        return $allContracts;
    }

    /**
     * Process and upsert companies.
     */
    private function processCompanies(array $contracts): void
    {
        $companies = [];
        $processedNames = [];
        $skipLogos = $this->option('skip-logos');

        foreach ($contracts as $contract) {
            $companyData = $contract['Company'] ?? [];
            $name = trim($companyData['Name'] ?? '');

            if ($name && ! isset($processedNames[$name])) {
                $companies[] = [
                    'name' => $name,
                    'name_slug' => Company::generateSlug($name),
                    'company_url' => trim($companyData['CompanyUrl'] ?? ''),
                    'street_address' => trim($companyData['StreetAddress'] ?? ''),
                    'postal_code' => trim($companyData['PostalCode'] ?? ''),
                    'postal_name' => trim($companyData['PostalName'] ?? ''),
                    'logo_url' => trim($companyData['LogoURL'] ?? ''),
                ];
                $processedNames[$name] = true;
            }
        }

        // Upsert companies and download logos
        $logosDownloaded = 0;
        foreach ($companies as $companyData) {
            $company = Company::updateOrCreate(
                ['name' => $companyData['name']],
                $companyData
            );

            // Download logo if needed and not skipped
            if (! $skipLogos && $company->logo_url && ! $company->local_logo_path) {
                $this->output->write("Downloading logo for {$company->name}... ");
                $localPath = $this->logoService->downloadAndStore($company);
                if ($localPath) {
                    $company->local_logo_path = $localPath;
                    $company->save();
                    $logosDownloaded++;
                    $this->output->writeln('<info>OK</info>');
                } else {
                    $this->output->writeln('<comment>Failed</comment>');
                }
            }
        }

        $this->info('Processed '.count($companies).' companies.');
        if (! $skipLogos && $logosDownloaded > 0) {
            $this->info("Downloaded {$logosDownloaded} company logos.");
        }
    }

    /**
     * Process and upsert contracts.
     */
    private function processContracts(array $contracts): void
    {
        foreach ($contracts as $data) {
            $data = $this->trimDictValues($data);
            $details = $data['Details'] ?? [];
            $pricing = $details['Pricing'] ?? [];
            $consumptionLimitation = $details['ConsumptionLimitation'] ?? [];
            $extraInformation = $details['ExtraInformation'] ?? [];
            $microProduction = $details['MicroProduction'] ?? [];

            $companyName = $data['Company']['Name'] ?? '';
            $contractName = $data['Name'];

            $contractData = [
                'api_id' => $data['Id'],
                'name' => $contractName,
                'company_name' => $companyName,
                'contract_type' => $details['ContractType'] ?? null,
                'spot_price_selection' => $details['SpotPriceSelection'] ?? null,
                'fixed_time_range' => $details['FixedTimeRange'] ?? null,
                'metering' => $details['Metering'] ?? null,
                'pricing_model' => $details['PricingModel'] ?? null,
                'target_group' => $details['TargetGroup'] ?? null,
                'pricing_name' => $pricing['Name'] ?? null,
                'pricing_has_discounts' => $pricing['HasDiscount'] ?? false,
                'consumption_control' => $details['ConsumptionControl'] ?? false,
                'consumption_limitation_min_x_kwh_per_y' => $consumptionLimitation['MinXKWhPerY'] ?? null,
                'consumption_limitation_max_x_kwh_per_y' => $consumptionLimitation['MaxXKWhPerY'] ?? null,
                'pre_billing' => $details['PreBilling'] ?? false,
                'available_for_existing_users' => $details['AvailableForExistingUsers'] ?? true,
                'delivery_responsibility_product' => $details['DeliveryResponsibilityProduct'] ?? false,
                'order_link' => $details['OrderLink'] ?? null,
                'product_link' => $details['ProductLink'] ?? null,
                'billing_frequency' => $details['BillingFrequency'] ?? null,
                'time_period_definitions' => $details['TimePeriodDefinitions'] ?? null,
                'transparency_index' => $details['TransparencyIndex'] ?? null,
                'extra_information_default' => $extraInformation['Default'] ?? null,
                'extra_information_fi' => $extraInformation['FI'] ?? null,
                'extra_information_en' => $extraInformation['EN'] ?? null,
                'extra_information_sv' => $extraInformation['SV'] ?? null,
                'availability_is_national' => $details['AvailabilityArea']['IsNational'] ?? false,
                'microproduction_buys' => $microProduction['Buys'] ?? false,
                'microproduction_default' => $microProduction['Details']['Default'] ?? null,
                'microproduction_fi' => $microProduction['Details']['FI'] ?? null,
                'microproduction_sv' => $microProduction['Details']['SV'] ?? null,
                'microproduction_en' => $microProduction['Details']['EN'] ?? null,
            ];

            // Preserve legacy descriptions when the current API omits these optional fields.
            if (array_key_exists('ShortDescription', $details)) {
                $contractData['short_description'] = $details['ShortDescription'];
            }
            if (array_key_exists('LongDescription', $details)) {
                $contractData['long_description'] = $details['LongDescription'];
            }

            // Look up existing contract by API ID
            $existingContract = ElectricityContract::where('api_id', $data['Id'])->first();

            if ($existingContract) {
                // Preserve only fields that the published interpretation supplied.
                // Uncertain fields continue to refresh from the source fallback.
                $publishedFields = $existingContract->publishedInterpretation?->published_fields ?? [];
                foreach ($publishedFields as $publishedField) {
                    unset($contractData[$publishedField]);
                }

                $existingContract->fill($contractData);
                $existingContract->save();
            } else {
                // Generate new custom ID for new contracts
                $contractData['id'] = ElectricityContract::generateId($companyName, $contractName);
                ElectricityContract::create($contractData);
            }
        }

        $this->info('Processed '.count($contracts).' contracts.');
    }

    /**
     * Persist one immutable source snapshot for each distinct upstream payload.
     */
    private function processContractSourceSnapshots(array $contracts): void
    {
        $apiIds = array_column($contracts, 'Id');
        $contractIdMap = ElectricityContract::whereIn('api_id', $apiIds)
            ->pluck('id', 'api_id')
            ->toArray();
        $observedAt = now();

        foreach ($contracts as $sourcePayload) {
            $contractId = $contractIdMap[$sourcePayload['Id']] ?? null;

            if ($contractId === null) {
                continue;
            }

            $fingerprint = $this->sourceCanonicalizer->fingerprint($sourcePayload);
            $snapshot = ContractSourceSnapshot::firstOrNew([
                'contract_id' => $contractId,
                'source_fingerprint' => $fingerprint,
            ]);

            if (! $snapshot->exists) {
                $snapshot->source_payload = $sourcePayload;
                $snapshot->first_observed_at = $observedAt;
            }

            $snapshot->last_observed_at = $observedAt;
            $snapshot->save();
            $this->sourceSnapshotIds[] = $snapshot->id;
            $this->sourceSnapshotIdsByContractId[$contractId] = $snapshot->id;
        }

        $this->info('Processed '.count($contracts).' contract source snapshots.');
    }

    /**
     * Update active contracts table (clear and repopulate).
     */
    private function updateActiveContracts(array $contracts): void
    {
        $previousActiveIds = ActiveContract::pluck('id')->flip();

        // Clear existing active contracts using DELETE (not TRUNCATE)
        // TRUNCATE is DDL in MySQL and would commit the transaction
        ActiveContract::query()->delete();

        // Build a mapping of API IDs to our internal models.
        $apiIds = array_column($contracts, 'Id');
        $contractMap = ElectricityContract::with(
            'publishedInterpretation:id,relational_pricing_published'
        )
            ->whereIn('api_id', $apiIds)
            ->get(['id', 'api_id', 'published_interpretation_id'])
            ->keyBy('api_id');

        // New contracts remain hidden until their first automatic validation publishes.
        $activeContracts = [];
        foreach ($contracts as $sourceContract) {
            /** @var ElectricityContract|null $contract */
            $contract = $contractMap->get($sourceContract['Id']);
            if ($contract === null) {
                continue;
            }

            $canActivate = ! config('contract_interpretation.enabled')
                || $contract->publishedInterpretation?->relational_pricing_published === true
                || $previousActiveIds->has($contract->id);
            if ($canActivate) {
                $activeContracts[] = ['id' => $contract->id];
            }
        }

        // Use insert ignore to handle any duplicates
        ActiveContract::insertOrIgnore($activeContracts);

        $this->info('Updated active contracts table with '.count($activeContracts).' contracts.');
    }

    /**
     * Process source price components that are safe to expose now.
     */
    private function processPriceComponents(array $contracts, string $date): void
    {
        $allowedContractIds = $this->contractsAllowedForImmediatePricePublication($contracts);
        $count = $this->priceComponentWriter->write($contracts, $date, $allowedContractIds);

        $this->info("Processed {$count} price components.");
    }

    /**
     * Hold new prices for an already interpreted contract until its new snapshot passes validation.
     *
     * @return list<string>|null
     */
    private function contractsAllowedForImmediatePricePublication(array $contracts): ?array
    {
        if (! config('contract_interpretation.enabled')) {
            return null;
        }

        $apiIds = array_column($contracts, 'Id');
        $contractModels = ElectricityContract::with(
            'publishedInterpretation:id,source_snapshot_id,relational_pricing_published'
        )
            ->whereIn('api_id', $apiIds)
            ->get();

        return $contractModels
            ->filter(function (ElectricityContract $contract): bool {
                $publishedInterpretation = $contract->publishedInterpretation;
                $latestSnapshotId = $this->sourceSnapshotIdsByContractId[$contract->id] ?? null;

                return $publishedInterpretation === null
                    || ($publishedInterpretation->relational_pricing_published
                        && (int) $publishedInterpretation->source_snapshot_id === (int) $latestSnapshotId);
            })
            ->pluck('id')
            ->all();
    }

    /**
     * Process and insert electricity sources.
     */
    private function processElectricitySources(array $contracts): void
    {
        // Build a mapping of API IDs to our internal IDs
        $apiIds = array_map(fn ($c) => $c['Id'], $contracts);
        $contractIdMap = ElectricityContract::whereIn('api_id', $apiIds)
            ->pluck('id', 'api_id')
            ->toArray();

        foreach ($contracts as $data) {
            $data = $this->trimDictValues($data);
            $source = $data['Details']['ElectricitySource'] ?? [];
            $apiId = $data['Id'];
            $contractId = $contractIdMap[$apiId] ?? null;
            if (! $contractId) {
                continue;
            }

            $renewable = $source['Renewable'] ?? [];
            $fossil = $source['Fossil'] ?? [];
            $nuclear = $source['Nuclear'] ?? [];

            $sourceData = [
                'contract_id' => $contractId,
                'renewable_total' => $renewable['Total'] ?? null,
                'renewable_biomass' => $renewable['BioMass'] ?? null,
                'renewable_solar' => $renewable['Solar'] ?? null,
                'renewable_wind' => $renewable['Wind'] ?? null,
                'renewable_general' => $renewable['General'] ?? null,
                'renewable_hydro' => $renewable['Hydro'] ?? null,
                'fossil_total' => $fossil['Total'] ?? null,
                'fossil_oil' => $fossil['Oil'] ?? null,
                'fossil_coal' => $fossil['Coal'] ?? null,
                'fossil_natural_gas' => $fossil['NaturalGas'] ?? null,
                'fossil_peat' => $fossil['Peat'] ?? null,
                'nuclear_total' => $nuclear['Total'] ?? null,
                'nuclear_general' => $nuclear['General'] ?? null,
            ];

            ElectricitySource::updateOrCreate(
                ['contract_id' => $contractId],
                $sourceData
            );
        }

        $this->info('Processed '.count($contracts).' electricity sources.');
    }

    /**
     * Process contract-postcode relationships.
     */
    private function processContractPostcodes(array $contracts, array $validPostcodes): void
    {
        // Build a mapping of API IDs to our internal IDs
        $apiIds = array_map(fn ($c) => $c['Id'], $contracts);
        $contractIdMap = ElectricityContract::whereIn('api_id', $apiIds)
            ->pluck('id', 'api_id')
            ->toArray();

        DB::table('contract_postcode')
            ->whereIn('contract_id', array_values($contractIdMap))
            ->delete();

        $relationships = [];
        $processedPairs = [];

        foreach ($contracts as $data) {
            $data = $this->trimDictValues($data);
            $apiId = $data['Id'];
            $contractId = $contractIdMap[$apiId] ?? null;
            if (! $contractId) {
                continue;
            }
            $postcodes = $data['Details']['AvailabilityArea']['PostalCodes'] ?? [];

            foreach ($postcodes as $postcode) {
                $pairKey = "{$contractId}:{$postcode}";
                if (! isset($processedPairs[$pairKey]) && in_array($postcode, $validPostcodes)) {
                    $relationships[] = [
                        'contract_id' => $contractId,
                        'postcode' => $postcode,
                    ];
                    $processedPairs[$pairKey] = true;
                }
            }
        }

        // Use insertOrIgnore to handle duplicate entries
        foreach (array_chunk($relationships, 500) as $chunk) {
            DB::table('contract_postcode')->insertOrIgnore($chunk);
        }

        $this->info('Processed '.count($relationships).' contract-postcode relationships.');
    }

    /**
     * Process and insert spot futures.
     */
    private function processSpotFutures(array $contracts, string $date): void
    {
        if (empty($contracts)) {
            return;
        }

        // Get spot futures from the first contract
        $firstContract = $contracts[0];
        $spotFuturesPrice = $firstContract['Details']['SpotFutures'] ?? null;

        if ($spotFuturesPrice !== null) {
            // Use direct DB query to handle the composite primary key properly
            DB::table('spot_futures')->updateOrInsert(
                ['date' => $date],
                ['price' => $spotFuturesPrice]
            );
            $this->info("Processed spot futures price: {$spotFuturesPrice}");
        }
    }

    /**
     * Trim whitespace from string values in an array.
     */
    private function trimDictValues(array $data): array
    {
        $result = [];
        foreach ($data as $key => $value) {
            if (is_string($value)) {
                $result[$key] = trim($value);
            } elseif (is_array($value)) {
                $result[$key] = $this->trimDictValues($value);
            } else {
                $result[$key] = $value;
            }
        }

        return $result;
    }

    /**
     * Process DSOs and contract-DSO relationships.
     */
    private function processDsos(array $contracts): void
    {
        // Build a mapping of API IDs to our internal IDs
        $apiIds = array_map(fn ($c) => $c['Id'], $contracts);
        $contractIdMap = ElectricityContract::whereIn('api_id', $apiIds)
            ->pluck('id', 'api_id')
            ->toArray();

        // Collect all unique DSO names
        $allDsoNames = [];
        foreach ($contracts as $data) {
            $dsoNames = $data['Details']['AvailabilityArea']['Dsos'] ?? [];
            foreach ($dsoNames as $name) {
                $name = trim($name);
                if ($name !== '') {
                    $allDsoNames[$name] = true;
                }
            }
        }

        // Create or update DSOs
        $dsoIdMap = [];
        foreach (array_keys($allDsoNames) as $name) {
            $dso = Dso::firstOrCreate(
                ['name' => $name],
                ['name_slug' => Dso::generateSlug($name)]
            );
            $dsoIdMap[$name] = $dso->id;
        }

        // Build contract-DSO relationships from the current payload only.
        DB::table('contract_dso')
            ->whereIn('contract_id', array_values($contractIdMap))
            ->delete();

        $relationships = [];
        $processedPairs = [];

        foreach ($contracts as $data) {
            $data = $this->trimDictValues($data);
            $apiId = $data['Id'];
            $contractId = $contractIdMap[$apiId] ?? null;
            if (! $contractId) {
                continue;
            }

            $dsoNames = $data['Details']['AvailabilityArea']['Dsos'] ?? [];
            foreach ($dsoNames as $name) {
                $name = trim($name);
                if ($name === '' || ! isset($dsoIdMap[$name])) {
                    continue;
                }

                $dsoId = $dsoIdMap[$name];
                $pairKey = "{$contractId}:{$dsoId}";
                if (! isset($processedPairs[$pairKey])) {
                    $relationships[] = [
                        'contract_id' => $contractId,
                        'dso_id' => $dsoId,
                    ];
                    $processedPairs[$pairKey] = true;
                }
            }
        }

        // Use insertOrIgnore to handle duplicate entries
        foreach (array_chunk($relationships, 500) as $chunk) {
            DB::table('contract_dso')->insertOrIgnore($chunk);
        }

        $this->info('Processed '.count($dsoIdMap).' DSOs and '.count($relationships).' contract-DSO relationships.');
    }
}
