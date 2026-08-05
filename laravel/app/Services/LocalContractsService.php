<?php

namespace App\Services;

use App\Enums\TargetGroup;
use App\Models\Company;
use App\Models\ElectricityContract;
use App\Models\Municipality;
use App\Models\Postcode;
use App\Services\ContractListing\ContractListingPipeline;
use Illuminate\Support\Collection;

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
     * - Tier 2: Regional contracts linked to the visitor's exact selected postcode
     *
     * @param  int  $consumption  Annual consumption in kWh
     * @return array{local_companies: Collection, regional_contracts: Collection, has_content: bool}
     */
    public function getLocalContracts(
        Municipality $municipality,
        int $consumption = 5000,
        string $postcode = '',
    ): array {
        $postcode = trim($postcode);

        // Tier 1: Keep nearby-company contracts eligible for the visitor's actual postcode.
        $localCompanyContracts = $this->getLocalCompanyContracts($municipality, $consumption, $postcode);

        // Tier 2 belongs to this city page, so an exact postcode from another
        // municipality must not add contracts to the city's regional section.
        $regionalPostcode = $this->postcodeBelongsToMunicipality($postcode, $municipality)
            ? $postcode
            : '';
        $localCompanyNames = $localCompanyContracts->pluck('company_name')->unique()->toArray();
        $regionalContracts = $this->getRegionalContracts($consumption, $localCompanyNames, $regionalPostcode);

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
        string $postcode,
    ): Collection {
        // Find nearby companies using coordinates
        $nearbyCompanies = $this->findNearbyCompanies($municipality);

        if ($nearbyCompanies->isEmpty()) {
            return collect();
        }

        $query = ElectricityContract::query()
            ->active()
            ->with(['company', 'electricitySource'])
            ->whereIn('company_name', $nearbyCompanies->pluck('name')->toArray())
            ->where(function ($q) {
                $q->whereIn('target_group', [TargetGroup::Household->value, TargetGroup::Both->value])
                    ->orWhereNull('target_group');
            });

        $this->listingPipeline->applyAvailabilityConstraint($query, $postcode);
        $contracts = $query->get();

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

    private function postcodeBelongsToMunicipality(string $postcode, Municipality $municipality): bool
    {
        if (preg_match('/^\d{5}$/', $postcode) !== 1) {
            return false;
        }

        $selected = Postcode::query()->find($postcode);
        if (! $selected) {
            return false;
        }

        if ($selected->municipal_code && $municipality->code) {
            return (string) $selected->municipal_code === (string) $municipality->code;
        }

        return mb_strtolower(trim((string) $selected->municipal_name_fi))
            === mb_strtolower(trim((string) $municipality->name));
    }

    /**
     * Get non-national contracts linked to the exact selected postcode.
     */
    private function getRegionalContracts(
        int $consumption,
        array $excludeCompanyNames,
        string $postcode,
    ): Collection {
        $query = ElectricityContract::query()
            ->active()
            ->with(['company', 'electricitySource'])
            ->where('availability_is_national', false)
            ->whereNotIn('company_name', $excludeCompanyNames)
            ->where(function ($q) {
                $q->whereIn('target_group', [TargetGroup::Household->value, TargetGroup::Both->value])
                    ->orWhereNull('target_group');
            });

        $this->listingPipeline->applyAvailabilityConstraint($query, $postcode);
        $contracts = $query->get();

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
