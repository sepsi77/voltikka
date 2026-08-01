<?php

namespace App\Services;

use App\Enums\TargetGroup;
use App\Models\Company;
use App\Models\ElectricityContract;
use App\Models\Municipality;
use App\Models\Postcode;
use App\Services\ContractListing\ContractListingPipeline;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class LocalContractsService
{
    /**
     * Maximum distance in kilometers to consider a company as "local".
     */
    private const MAX_DISTANCE_KM = 100;

    public function __construct(
        private ContractListingPipeline $listingPipeline,
    ) {}

    /**
     * Get local contracts for a municipality.
     *
     * Returns structured result with:
     * - Tier 1: Contracts from companies headquartered in the municipality
     * - Tier 2: Regional contracts (availability_is_national = false) available in the city
     *
     * @param  int  $consumption  Annual consumption in kWh
     * @return array{local_companies: Collection, regional_contracts: Collection, has_content: bool}
     */
    public function getLocalContracts(Municipality $municipality, int $consumption = 5000): array
    {
        // Tier 1: Get contracts from companies headquartered in this municipality
        $localCompanyContracts = $this->getLocalCompanyContracts($municipality, $consumption);

        // Tier 2: Get regional contracts available in this municipality (excluding local companies)
        $localCompanyNames = $localCompanyContracts->pluck('company_name')->unique()->toArray();
        $regionalContracts = $this->getRegionalContracts($municipality, $consumption, $localCompanyNames);

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
    ): Collection {
        // Find nearby companies using coordinates
        $nearbyCompanies = $this->findNearbyCompanies($municipality);

        if ($nearbyCompanies->isEmpty()) {
            return collect();
        }

        $contracts = ElectricityContract::query()
            ->active()
            ->with(['company', 'electricitySource'])
            ->whereIn('company_name', $nearbyCompanies->pluck('name')->toArray())
            ->where(function ($q) {
                $q->whereIn('target_group', [TargetGroup::Household->value, TargetGroup::Both->value])
                    ->orWhereNull('target_group');
            })
            ->get();

        // Add distance info to contracts for display
        $companyDistances = $nearbyCompanies->pluck('distance_km', 'name')->toArray();
        $contracts->each(function ($contract) use ($companyDistances) {
            $contract->company_distance_km = $companyDistances[$contract->company_name] ?? null;
        });

        // Filter by consumption range and calculate costs
        return $this->processContracts($contracts, $consumption);
    }

    /**
     * Find companies headquartered within MAX_DISTANCE_KM of the municipality.
     *
     * @return Collection Collection of companies with distance_km attribute
     */
    private function findNearbyCompanies(Municipality $municipality): Collection
    {
        if (! $municipality->hasCoordinates()) {
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

        // Get all companies with their postal code coordinates. Load postcodes in one
        // query instead of calling Postcode::find() per company; city SEO pages are
        // crawled heavily and Sentry flags the per-company lookups as an N+1 query.
        $companies = Company::whereNotNull('postal_code')->get();
        $postcodes = Postcode::whereIn('postcode', $companies->pluck('postal_code')->filter()->unique()->values())
            ->get()
            ->keyBy('postcode');

        $nearbyCompanies = collect();

        foreach ($companies as $company) {
            $postcode = $postcodes->get($company->postal_code);

            if (! $postcode || ! $postcode->latitude || ! $postcode->longitude) {
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
    ): Collection {
        $cityName = $municipality->name;

        $contracts = ElectricityContract::query()
            ->active()
            ->with(['company', 'electricitySource'])
            ->where('availability_is_national', false)
            ->whereNotIn('company_name', $excludeCompanyNames)
            ->where(function ($q) {
                $q->whereIn('target_group', [TargetGroup::Household->value, TargetGroup::Both->value])
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
        return $this->processContracts($contracts, $consumption);
    }

    /**
     * Filter contracts by consumption and calculate costs.
     */
    private function processContracts(Collection $contracts, int $consumption): Collection
    {
        $contracts = $this->listingPipeline->filterForConsumption($contracts, $consumption);

        return $this->listingPipeline->enrichAndSortAnnual(
            $contracts,
            $consumption,
            loadLegacyCardPrices: true,
        );
    }
}
