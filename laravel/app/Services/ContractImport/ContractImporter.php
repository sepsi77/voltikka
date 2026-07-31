<?php

namespace App\Services\ContractImport;

use App\Models\ActiveContract;
use App\Models\Company;
use App\Models\ContractSourceObservation;
use App\Models\ContractSourceSnapshot;
use App\Models\Dso;
use App\Models\ElectricityContract;
use App\Models\ElectricitySource;
use App\Services\ContractInterpretation\CanonicalPriceComponentWriter;
use App\Services\ContractInterpretation\ContractSourceCanonicalizer;
use App\Services\ContractReplacement\ContractReplacementLinker;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class ContractImporter
{
    /** @var list<int> */
    private array $changedObservationIds = [];

    /** @var list<int> */
    private array $observedObservationIds = [];

    /** @var array<string, int> */
    private array $currentSnapshotIdsByContractId = [];

    public function __construct(
        private readonly ContractSourceCanonicalizer $sourceCanonicalizer,
        private readonly CanonicalPriceComponentWriter $priceComponentWriter,
        private readonly ContractReplacementLinker $replacementLinker,
    ) {}

    /**
     * @param  list<array<string, mixed>>  $contracts
     * @param  list<string>  $validPostcodes
     */
    public function import(
        array $contracts,
        array $validPostcodes,
        CarbonInterface|string $importDate,
        bool $complete,
    ): ContractImportResult {
        $date = $importDate instanceof CarbonInterface
            ? $importDate->toDateString()
            : $importDate;
        $this->changedObservationIds = [];
        $this->observedObservationIds = [];
        $this->currentSnapshotIdsByContractId = [];

        return DB::transaction(function () use ($contracts, $validPostcodes, $date, $complete): ContractImportResult {
            $companyNames = $this->processCompanies($contracts);
            $existingContracts = $this->lockExistingImportedContracts($contracts);
            $this->processContracts($contracts, $existingContracts);
            $lockedContracts = $this->lockImportedContracts($contracts);
            $this->processContractSourceSnapshots($contracts, $lockedContracts);
            $activeContractIds = $this->updateActiveContracts($contracts, $complete);
            $priceComponentCount = $this->processPriceComponents($contracts, $date);
            $this->processElectricitySources($contracts);
            $this->processContractPostcodes($contracts, $validPostcodes);
            $this->processDsos($contracts);
            $this->processSpotFutures($contracts, $date);
            $replacementStats = $complete
                ? $this->replacementLinker->linkHighConfidenceMatches()
                : $this->emptyReplacementStats();

            return new ContractImportResult(
                complete: $complete,
                contractCount: count($contracts),
                activeContractCount: count($activeContractIds),
                priceComponentCount: $priceComponentCount,
                replacementStats: $replacementStats,
                changedObservationIds: $this->changedObservationIds,
                observedObservationIds: $this->observedObservationIds,
                activeContractIds: $activeContractIds,
                companyNames: $companyNames,
            );
        });
    }

    /**
     * @param  list<array<string, mixed>>  $contracts
     * @return list<string>
     */
    private function processCompanies(array $contracts): array
    {
        $companies = [];

        foreach ($contracts as $contract) {
            $companyData = $contract['Company'] ?? [];
            $name = trim($companyData['Name'] ?? '');

            if ($name === '' || isset($companies[$name])) {
                continue;
            }

            $companies[$name] = [
                'name' => $name,
                'name_slug' => Company::generateSlug($name),
                'company_url' => trim($companyData['CompanyUrl'] ?? ''),
                'street_address' => trim($companyData['StreetAddress'] ?? ''),
                'postal_code' => trim($companyData['PostalCode'] ?? ''),
                'postal_name' => trim($companyData['PostalName'] ?? ''),
                'logo_url' => trim($companyData['LogoURL'] ?? ''),
            ];
        }

        foreach ($companies as $companyData) {
            Company::updateOrCreate(['name' => $companyData['name']], $companyData);
        }

        return array_keys($companies);
    }

    /**
     * Process and upsert contracts.
     *
     * @param  array<string, ElectricityContract>  $existingContracts
     */
    private function processContracts(array $contracts, array $existingContracts): void
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

            $existingContract = $existingContracts[$data['Id']] ?? null;

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

    }

    /**
     * Lock existing rows before any contract update.
     *
     * @param  list<array<string, mixed>>  $contracts
     * @return array<string, ElectricityContract>
     */
    private function lockExistingImportedContracts(array $contracts): array
    {
        $existing = ElectricityContract::query()
            ->whereIn('api_id', array_column($contracts, 'Id'))
            ->orderBy('id')
            ->lockForUpdate()
            ->get();
        $existing->load('publishedInterpretation:id,published_fields');

        return $existing->keyBy('api_id')->all();
    }

    /**
     * Lock all imported contract rows in stable order before source-episode mutation.
     * New rows were not available for the earlier existing-row lock.
     *
     * @param  list<array<string, mixed>>  $contracts
     * @return array<string, ElectricityContract>
     */
    private function lockImportedContracts(array $contracts): array
    {
        return ElectricityContract::query()
            ->whereIn('api_id', array_column($contracts, 'Id'))
            ->orderBy('id')
            ->lockForUpdate()
            ->get()
            ->keyBy('api_id')
            ->all();
    }

    /**
     * Persist immutable payloads and mutate only the pointed observation episode.
     *
     * @param  list<array<string, mixed>>  $contracts
     * @param  array<string, ElectricityContract>  $lockedContracts
     */
    private function processContractSourceSnapshots(array $contracts, array $lockedContracts): void
    {
        $observedAt = now();

        foreach ($contracts as $sourcePayload) {
            $contract = $lockedContracts[$sourcePayload['Id']] ?? null;

            if ($contract === null) {
                continue;
            }

            $fingerprint = $this->sourceCanonicalizer->fingerprint($sourcePayload);
            $snapshot = ContractSourceSnapshot::firstOrNew([
                'contract_id' => $contract->id,
                'source_fingerprint' => $fingerprint,
            ]);

            if (! $snapshot->exists) {
                $snapshot->source_payload = $sourcePayload;
                $snapshot->first_observed_at = $observedAt;
            }

            $snapshot->last_observed_at = $observedAt;
            $snapshot->save();

            $observation = null;
            if ($contract->current_source_observation_id !== null) {
                $observation = ContractSourceObservation::query()
                    ->lockForUpdate()
                    ->find($contract->current_source_observation_id);

                if ($observation === null || $observation->contract_id !== $contract->id) {
                    throw new RuntimeException("Contract {$contract->id} has an invalid current source observation pointer.");
                }
            }

            if ($observation !== null && $observation->source_snapshot_id === $snapshot->id) {
                $observation->last_observed_at = $observedAt;
                $observation->save();
            } else {
                $observation = ContractSourceObservation::create([
                    'contract_id' => $contract->id,
                    'source_snapshot_id' => $snapshot->id,
                    'first_observed_at' => $observedAt,
                    'last_observed_at' => $observedAt,
                ]);
                $contract->current_source_observation_id = $observation->id;
                $contract->save();
                $this->changedObservationIds[] = $observation->id;
            }

            if ($contract->current_source_observation_id === null
                || $observation->contract_id !== $contract->id
                || $contract->current_source_observation_id !== $observation->id) {
                throw new RuntimeException("Contract {$contract->id} has no valid current source observation after import.");
            }

            $this->observedObservationIds[] = $observation->id;
            $this->currentSnapshotIdsByContractId[$contract->id] = $observation->source_snapshot_id;
        }
    }

    /**
     * Update active contracts table (clear and repopulate).
     */
    private function updateActiveContracts(array $contracts, bool $complete): array
    {
        $previousActiveIds = ActiveContract::pluck('id')->flip();

        if ($complete) {
            // Clear existing active contracts using DELETE (not TRUNCATE).
            // TRUNCATE is DDL in MySQL and would commit the transaction.
            ActiveContract::query()->delete();
        }

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

        return ActiveContract::query()->pluck('id')->all();
    }

    /**
     * @return array{linked:int, skipped_existing:int, skipped_no_match:int, skipped_not_high:int}
     */
    private function emptyReplacementStats(): array
    {
        return [
            'linked' => 0,
            'skipped_existing' => 0,
            'skipped_no_match' => 0,
            'skipped_not_high' => 0,
        ];
    }

    /**
     * Process source price components that are safe to expose now.
     */
    private function processPriceComponents(array $contracts, string $date): int
    {
        $allowedContractIds = $this->contractsAllowedForImmediatePricePublication($contracts);

        return $this->priceComponentWriter->write($contracts, $date, $allowedContractIds);
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
                $currentSnapshotId = $this->currentSnapshotIdsByContractId[$contract->id] ?? null;

                return $publishedInterpretation === null
                    || ($publishedInterpretation->relational_pricing_published
                        && (int) $publishedInterpretation->source_snapshot_id === (int) $currentSnapshotId);
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
                if (! isset($processedPairs[$pairKey]) && in_array($postcode, $validPostcodes, true)) {
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

    }
}
