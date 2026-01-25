<?php

namespace App\Services;

use App\Models\Company;
use App\Models\ElectricityContract;
use App\Models\Municipality;
use App\Models\Postcode;
use App\Models\SpotPriceAverage;
use App\Services\DTO\EnergyUsage;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class LocalContractsService
{
    /**
     * Maximum distance in kilometers to consider a company as "local".
     */
    private const MAX_DISTANCE_KM = 100;

    public function __construct(
        private ContractPriceCalculator $calculator,
        private CO2EmissionsCalculator $emissionsCalculator,
    ) {}

    /**
     * Get local contracts for a municipality.
     *
     * Returns structured result with:
     * - Tier 1: Contracts from companies headquartered in the municipality
     * - Tier 2: Regional contracts (availability_is_national = false) available in the city
     *
     * @param Municipality $municipality
     * @param int $consumption Annual consumption in kWh
     * @return array{local_companies: Collection, regional_contracts: Collection, has_content: bool}
     */
    public function getLocalContracts(Municipality $municipality, int $consumption = 5000): array
    {
        // Get spot price averages for calculations
        $spotPriceAvg = SpotPriceAverage::latestRolling365Days();
        $spotPriceDay = $spotPriceAvg?->day_avg_with_tax;
        $spotPriceNight = $spotPriceAvg?->night_avg_with_tax;

        // Tier 1: Get contracts from companies headquartered in this municipality
        $localCompanyContracts = $this->getLocalCompanyContracts($municipality, $consumption, $spotPriceDay, $spotPriceNight);

        // Tier 2: Get regional contracts available in this municipality (excluding local companies)
        $localCompanyNames = $localCompanyContracts->pluck('company_name')->unique()->toArray();
        $regionalContracts = $this->getRegionalContracts($municipality, $consumption, $localCompanyNames, $spotPriceDay, $spotPriceNight);

        return [
            'local_companies' => $localCompanyContracts,
            'regional_contracts' => $regionalContracts,
            'has_content' => $localCompanyContracts->isNotEmpty() || $regionalContracts->isNotEmpty(),
        ];
    }

    /**
     * Get active contracts from companies headquartered near the municipality.
     */
    private function getLocalCompanyContracts(
        Municipality $municipality,
        int $consumption,
        ?float $spotPriceDay,
        ?float $spotPriceNight
    ): Collection {
        // Find nearby companies using coordinates
        $nearbyCompanies = $this->findNearbyCompanies($municipality);

        if ($nearbyCompanies->isEmpty()) {
            return collect();
        }

        $contracts = ElectricityContract::query()
            ->active()
            ->with(['company', 'priceComponents', 'electricitySource'])
            ->whereIn('company_name', $nearbyCompanies->pluck('name')->toArray())
            ->where(function ($q) {
                $q->whereIn('target_group', ['Household', 'Both'])
                  ->orWhereNull('target_group');
            })
            ->get();

        // Add distance info to contracts for display
        $companyDistances = $nearbyCompanies->pluck('distance_km', 'name')->toArray();
        $contracts->each(function ($contract) use ($companyDistances) {
            $contract->company_distance_km = $companyDistances[$contract->company_name] ?? null;
        });

        // Filter by consumption range and calculate costs
        return $this->processContracts($contracts, $consumption, $spotPriceDay, $spotPriceNight);
    }

    /**
     * Find companies headquartered within MAX_DISTANCE_KM of the municipality.
     *
     * @return Collection Collection of companies with distance_km attribute
     */
    private function findNearbyCompanies(Municipality $municipality): Collection
    {
        if (!$municipality->hasCoordinates()) {
            // Fallback to exact match if no coordinates
            return Company::where('postal_name', $municipality->name)
                ->get()
                ->map(function ($company) {
                    $company->distance_km = 0;
                    return $company;
                });
        }

        $targetLat = $municipality->center_latitude;
        $targetLon = $municipality->center_longitude;

        // Get all companies with their postal code coordinates
        $companies = Company::whereNotNull('postal_code')->get();

        $nearbyCompanies = collect();

        foreach ($companies as $company) {
            // Look up the postcode to get coordinates
            $postcode = Postcode::find($company->postal_code);

            if (!$postcode || !$postcode->latitude || !$postcode->longitude) {
                // If no coordinates for postcode, check if it's in the same municipality
                if ($company->postal_name === $municipality->name) {
                    $company->distance_km = 0;
                    $nearbyCompanies->push($company);
                }
                continue;
            }

            // Calculate distance using Haversine formula
            $distance = $this->calculateDistanceKm(
                $targetLat, $targetLon,
                $postcode->latitude, $postcode->longitude
            );

            if ($distance <= self::MAX_DISTANCE_KM) {
                $company->distance_km = round($distance, 1);
                $nearbyCompanies->push($company);
            }
        }

        // Sort by distance
        return $nearbyCompanies->sortBy('distance_km')->values();
    }

    /**
     * Calculate distance between two coordinates using the Haversine formula.
     */
    private function calculateDistanceKm(
        float $lat1, float $lon1,
        float $lat2, float $lon2
    ): float {
        $earthRadiusKm = 6371;

        $dLat = deg2rad($lat2 - $lat1);
        $dLon = deg2rad($lon2 - $lon1);

        $a = sin($dLat / 2) * sin($dLat / 2) +
             cos(deg2rad($lat1)) * cos(deg2rad($lat2)) *
             sin($dLon / 2) * sin($dLon / 2);

        $c = 2 * atan2(sqrt($a), sqrt(1 - $a));

        return $earthRadiusKm * $c;
    }

    /**
     * Get regional (non-national) contracts available in the municipality.
     */
    private function getRegionalContracts(
        Municipality $municipality,
        int $consumption,
        array $excludeCompanyNames,
        ?float $spotPriceDay,
        ?float $spotPriceNight
    ): Collection {
        $cityName = $municipality->name;

        $contracts = ElectricityContract::query()
            ->active()
            ->with(['company', 'priceComponents', 'electricitySource'])
            ->where('availability_is_national', false)
            ->whereNotIn('company_name', $excludeCompanyNames)
            ->where(function ($q) {
                $q->whereIn('target_group', ['Household', 'Both'])
                  ->orWhereNull('target_group');
            })
            // Available in the city (has postcodes in this municipality)
            ->whereExists(function ($subquery) use ($cityName) {
                $subquery->select(DB::raw(1))
                         ->from('contract_postcode')
                         ->join('postcodes', 'contract_postcode.postcode', '=', 'postcodes.postcode')
                         ->whereColumn('contract_postcode.contract_id', 'electricity_contracts.id')
                         ->where('postcodes.municipal_name_fi', $cityName);
            })
            ->get();

        // Filter by consumption range and calculate costs
        return $this->processContracts($contracts, $consumption, $spotPriceDay, $spotPriceNight);
    }

    /**
     * Filter contracts by consumption and calculate costs.
     */
    private function processContracts(
        Collection $contracts,
        int $consumption,
        ?float $spotPriceDay,
        ?float $spotPriceNight
    ): Collection {
        // Filter by consumption range
        $contracts = $contracts->filter(fn ($contract) => $contract->isConsumptionInRange($consumption));

        // Calculate cost for each contract
        return $contracts->map(function ($contract) use ($consumption, $spotPriceDay, $spotPriceNight) {
            $priceComponents = $contract->priceComponents
                ->sortByDesc('price_date')
                ->groupBy('price_component_type')
                ->map(fn ($group) => $group->sortByDesc('price_date')->first(fn ($item) => $item->price > 0) ?? $group->sortByDesc('price_date')->first())
                ->values()
                ->map(fn ($pc) => [
                    'price_component_type' => $pc->price_component_type,
                    'price' => $pc->price,
                ])
                ->toArray();

            $usage = new EnergyUsage(
                total: $consumption,
                basicLiving: $consumption,
            );

            $contractData = [
                'contract_type' => $contract->contract_type,
                'pricing_model' => $contract->pricing_model,
                'metering' => $contract->metering,
            ];

            $result = $this->calculator->calculate($priceComponents, $contractData, $usage, $spotPriceDay, $spotPriceNight);
            $contract->calculated_cost = $result->toArray();
            $contract->emission_factor = $this->emissionsCalculator->calculateEmissionFactor($contract->electricitySource);

            return $contract;
        })->sortBy(fn ($c) => $c->calculated_cost['total_cost'] ?? PHP_FLOAT_MAX)->values();
    }
}
