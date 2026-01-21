<?php

namespace App\Console\Commands;

use App\Models\ActiveContract;
use App\Models\Company;
use App\Models\ElectricityContract;
use App\Models\ElectricitySource;
use App\Models\Postcode;
use App\Models\PriceComponent;
use App\Models\SpotFutures;
use App\Services\AzureConsumerApiClient;
use App\Services\CompanyLogoService;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

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

    public function __construct(AzureConsumerApiClient $apiClient, CompanyLogoService $logoService)
    {
        parent::__construct();
        $this->apiClient = $apiClient;
        $this->logoService = $logoService;
    }

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $this->info('Fetching contracts from Azure Consumer API...');

        $postcodes = $this->getPostcodes();
        $today = Carbon::now()->toDateString();

        // Get valid postcodes from database
        $validPostcodes = Postcode::pluck('postcode')->toArray();

        try {
            $allContracts = $this->fetchAllContracts($postcodes);
        } catch (RequestException $e) {
            $this->error('Failed to fetch contracts: ' . $e->getMessage());
            Log::error('FetchContracts command failed', ['exception' => $e->getMessage()]);
            return Command::FAILURE;
        }

        if (empty($allContracts)) {
            $this->warn('No contracts fetched from API.');
            return Command::SUCCESS;
        }

        $this->info("Fetched " . count($allContracts) . " unique contracts. Processing...");

        // Start database transaction
        DB::beginTransaction();

        try {
            // Upload companies first
            $this->processCompanies($allContracts);

            // Upload contracts
            $this->processContracts($allContracts);

            // Update active contracts table
            $this->updateActiveContracts($allContracts);

            // Upload price components
            $this->processPriceComponents($allContracts, $today);

            // Upload electricity sources
            $this->processElectricitySources($allContracts);

            // Upload contract-postcode relationships
            $this->processContractPostcodes($allContracts, $validPostcodes);

            // Upload spot futures (from first contract)
            $this->processSpotFutures($allContracts, $today);

            DB::commit();
            $this->info('Contracts fetched successfully!');
            return Command::SUCCESS;
        } catch (\Exception $e) {
            DB::rollBack();
            $this->error('Error processing contracts: ' . $e->getMessage());
            Log::error('FetchContracts command failed during processing', [
                'exception' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            return Command::FAILURE;
        }
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
                    if ($id && !isset($processedIds[$id])) {
                        $allContracts[] = $contract;
                        $processedIds[$id] = true;
                    }
                }
            } catch (RequestException $e) {
                $this->warn("Failed to fetch contracts for postcode {$postcode}: " . $e->getMessage());
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

            if ($name && !isset($processedNames[$name])) {
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
            if (!$skipLogos && $company->logo_url && !$company->local_logo_path) {
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

        $this->info("Processed " . count($companies) . " companies.");
        if (!$skipLogos && $logosDownloaded > 0) {
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

            // Look up existing contract by API ID
            $existingContract = ElectricityContract::where('api_id', $data['Id'])->first();

            if ($existingContract) {
                $needsUpdate = false;

                // Update pricing_has_discounts if it changed (as per Python implementation)
                if ($existingContract->pricing_has_discounts !== $contractData['pricing_has_discounts']) {
                    $existingContract->pricing_has_discounts = $contractData['pricing_has_discounts'];
                    $needsUpdate = true;
                }

                // Update pricing_model if it's null (backfill new field)
                if ($existingContract->pricing_model === null && $contractData['pricing_model'] !== null) {
                    $existingContract->pricing_model = $contractData['pricing_model'];
                    $needsUpdate = true;
                }

                // Update target_group if it's null (backfill new field)
                if ($existingContract->target_group === null && $contractData['target_group'] !== null) {
                    $existingContract->target_group = $contractData['target_group'];
                    $needsUpdate = true;
                }

                if ($needsUpdate) {
                    $existingContract->save();
                }
            } else {
                // Generate new custom ID for new contracts
                $contractData['id'] = ElectricityContract::generateId($companyName, $contractName);
                ElectricityContract::create($contractData);
            }
        }

        $this->info("Processed " . count($contracts) . " contracts.");
    }

    /**
     * Update active contracts table (clear and repopulate).
     */
    private function updateActiveContracts(array $contracts): void
    {
        // Clear existing active contracts using DELETE (not TRUNCATE)
        // TRUNCATE is DDL in MySQL and would commit the transaction
        ActiveContract::query()->delete();

        // Build a mapping of API IDs to our internal IDs
        $apiIds = array_map(fn($c) => $c['Id'], $contracts);
        $contractIdMap = ElectricityContract::whereIn('api_id', $apiIds)
            ->pluck('id', 'api_id')
            ->toArray();

        // Insert new active contracts using our internal IDs
        $activeContracts = [];
        foreach ($contracts as $contract) {
            $apiId = $contract['Id'];
            if (isset($contractIdMap[$apiId])) {
                $activeContracts[] = ['id' => $contractIdMap[$apiId]];
            }
        }

        // Use insert ignore to handle any duplicates
        ActiveContract::insertOrIgnore($activeContracts);

        $this->info("Updated active contracts table with " . count($activeContracts) . " contracts.");
    }

    /**
     * The null UUID returned by the Azure API when no ID is provided.
     */
    private const NULL_UUID = '00000000-0000-0000-0000-000000000000';

    /**
     * Process and insert price components.
     */
    private function processPriceComponents(array $contracts, string $date): void
    {
        // Build a mapping of API IDs to our internal IDs
        $apiIds = array_map(fn($c) => $c['Id'], $contracts);
        $contractIdMap = ElectricityContract::whereIn('api_id', $apiIds)
            ->pluck('id', 'api_id')
            ->toArray();

        $priceComponents = [];

        foreach ($contracts as $data) {
            $data = $this->trimDictValues($data);
            $pricing = $data['Details']['Pricing'] ?? [];
            $apiContractId = $pricing['ElectricitySupplyProductId'] ?? $data['Id'];
            // Map API ID to our internal ID
            $contractId = $contractIdMap[$apiContractId] ?? $contractIdMap[$data['Id']] ?? null;
            if (!$contractId) {
                continue;
            }
            $components = $pricing['PriceComponents'] ?? [];

            foreach ($components as $component) {
                $component = $this->trimDictValues($component);
                $discount = $component['Discount'] ?? [];

                // Parse discount until date
                $discountUntilDate = null;
                $untilDateStr = $discount['UntilDate'] ?? '0001-01-01T00:00:00';
                if ($untilDateStr !== '0001-01-01T00:00:00') {
                    try {
                        $discountUntilDate = Carbon::parse($untilDateStr);
                    } catch (\Exception $e) {
                        // Invalid date, keep null
                    }
                }

                // Generate a deterministic ID if the API returns a null UUID
                // Uses contract_id + type + fuse_size to create reproducible ID
                $componentId = $component['Id'] ?? self::NULL_UUID;
                if ($componentId === self::NULL_UUID) {
                    $priceComponentType = $component['PriceComponentType'];
                    $fuseSize = $component['FuseSize'] ?? 'null';
                    // Create deterministic UUID from unique component attributes
                    $componentId = md5("{$contractId}:{$priceComponentType}:{$fuseSize}");
                }

                $priceComponents[] = [
                    'id' => $componentId,
                    'price_date' => $date,
                    'price_component_type' => $component['PriceComponentType'],
                    'fuse_size' => $component['FuseSize'] ?? null,
                    'electricity_contract_id' => $contractId,
                    'has_discount' => $component['HasDiscount'] ?? false,
                    'discount_value' => $discount['DiscountValue'] ?? null,
                    'discount_is_percentage' => $discount['IsPercentage'] ?? false,
                    'discount_type' => $discount['DiscountType'] ?? null,
                    'discount_discount_n_first_kwh' => $discount['NFirstKwh'] ?? null,
                    'discount_discount_n_first_months' => $discount['NfirstMonths'] ?? null,
                    'discount_discount_until_date' => $discountUntilDate,
                    'price' => $component['OriginalPayment']['Price'] ?? 0,
                    'payment_unit' => $component['OriginalPayment']['PaymentUnit'] ?? null,
                ];
            }
        }

        // Use insertOrIgnore to handle composite key conflicts
        foreach (array_chunk($priceComponents, 500) as $chunk) {
            PriceComponent::insertOrIgnore($chunk);
        }

        $this->info("Processed " . count($priceComponents) . " price components.");
    }

    /**
     * Process and insert electricity sources.
     */
    private function processElectricitySources(array $contracts): void
    {
        // Build a mapping of API IDs to our internal IDs
        $apiIds = array_map(fn($c) => $c['Id'], $contracts);
        $contractIdMap = ElectricityContract::whereIn('api_id', $apiIds)
            ->pluck('id', 'api_id')
            ->toArray();

        foreach ($contracts as $data) {
            $data = $this->trimDictValues($data);
            $source = $data['Details']['ElectricitySource'] ?? [];
            $apiId = $data['Id'];
            $contractId = $contractIdMap[$apiId] ?? null;
            if (!$contractId) {
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

        $this->info("Processed " . count($contracts) . " electricity sources.");
    }

    /**
     * Process contract-postcode relationships.
     */
    private function processContractPostcodes(array $contracts, array $validPostcodes): void
    {
        // Build a mapping of API IDs to our internal IDs
        $apiIds = array_map(fn($c) => $c['Id'], $contracts);
        $contractIdMap = ElectricityContract::whereIn('api_id', $apiIds)
            ->pluck('id', 'api_id')
            ->toArray();

        $relationships = [];
        $processedPairs = [];

        foreach ($contracts as $data) {
            $data = $this->trimDictValues($data);
            $apiId = $data['Id'];
            $contractId = $contractIdMap[$apiId] ?? null;
            if (!$contractId) {
                continue;
            }
            $postcodes = $data['Details']['AvailabilityArea']['PostalCodes'] ?? [];

            foreach ($postcodes as $postcode) {
                $pairKey = "{$contractId}:{$postcode}";
                if (!isset($processedPairs[$pairKey]) && in_array($postcode, $validPostcodes)) {
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

        $this->info("Processed " . count($relationships) . " contract-postcode relationships.");
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
}
