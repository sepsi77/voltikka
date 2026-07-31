<?php

namespace App\Services;

use App\Models\ElectricityContract;
use App\Services\CanonicalPricing\CanonicalContractPricingService;
use App\Services\CanonicalPricing\PricingMode;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

class CompanyListCacheService
{
    private const CACHE_VERSION_KEY = 'company_list_cache_version';

    /**
     * Shape marker for the prepared company payload.
     *
     * The import version and pricing flags do not change on a code-only deploy. Bump this
     * value whenever company metric membership or payload fields change.
     */
    private const PAYLOAD_SCHEMA_VERSION = 2;

    private const CACHE_TTL_SECONDS = 60 * 60 * 48; // 48 hours

    private const DEFAULT_CONSUMPTION = 5000;

    /** @var array<int, Collection<int, array<string, mixed>>> */
    private array $cachedCompaniesMemo = [];

    private ?int $versionMemo = null;

    public function __construct(
        private readonly ContractListCacheService $contractListCache,
        private readonly CanonicalContractPricingService $canonicalPricing,
        private readonly PricingMode $pricingMode,
    ) {}

    public function getCachedCompanies(int $consumption = self::DEFAULT_CONSUMPTION): Collection
    {
        if (isset($this->cachedCompaniesMemo[$consumption])) {
            return $this->cachedCompaniesMemo[$consumption];
        }

        return $this->cachedCompaniesMemo[$consumption] = Cache::remember(
            $this->getCacheKey($consumption),
            self::CACHE_TTL_SECONDS,
            fn () => $this->buildCachedCompanies($consumption)
        );
    }

    public function warm(): void
    {
        $this->getCachedCompanies();
    }

    public function bumpVersion(): int
    {
        $version = $this->getVersion() + 1;
        Cache::forever(self::CACHE_VERSION_KEY, $version);
        $this->versionMemo = $version;
        $this->cachedCompaniesMemo = [];

        return $version;
    }

    public function getVersion(): int
    {
        return $this->versionMemo ??= (int) Cache::get(self::CACHE_VERSION_KEY, 1);
    }

    private function getCacheKey(int $consumption): string
    {
        return sprintf(
            'company_list:v%d:s%d:%s:lv%d:%s:%d',
            $this->getVersion(),
            self::PAYLOAD_SCHEMA_VERSION,
            CalculatedCostPayloadSchema::cacheMarker(),
            $this->contractListCache->getVersion(),
            $this->pricingMode->cacheMarker(),
            $consumption,
        );
    }

    private function buildCachedCompanies(int $consumption): Collection
    {
        $cachedMetrics = $this->contractListCache->getCachedMetrics($consumption);

        $contracts = ElectricityContract::query()
            ->active()
            ->with(['company', 'electricitySource'])
            ->whereHas('company')
            ->get();

        $contractsByCompany = $contracts->groupBy('company_name');
        $companies = collect();
        $useCanonical = $this->canonicalPricing->enabled();

        foreach ($contractsByCompany as $allCompanyContracts) {
            $companyContracts = $useCanonical
                ? $allCompanyContracts->filter(function (ElectricityContract $contract) use ($cachedMetrics): bool {
                    $metric = $cachedMetrics['contracts'][$contract->id] ?? null;
                    $total = $metric['calculated_cost']['total_cost'] ?? null;

                    return is_array($metric)
                        && ($metric['is_listed'] ?? false) === true
                        && ($metric['calculated_cost']['pricing_basis'] ?? null) === 'canonical'
                        && is_numeric($total)
                        && is_finite((float) $total);
                })
                : $allCompanyContracts;

            $company = $companyContracts->first()?->company;

            if (! $company) {
                continue;
            }

            $applicableContracts = $companyContracts->filter(function (ElectricityContract $contract) use ($cachedMetrics) {
                return ! ($cachedMetrics['contracts'][$contract->id]['exceeds_consumption_limit'] ?? false);
            });

            $priceMetrics = $applicableContracts
                ->mapWithKeys(function (ElectricityContract $contract) use ($cachedMetrics) {
                    return [$contract->id => $cachedMetrics['contracts'][$contract->id]];
                });

            $spotContracts = $applicableContracts->filter(fn (ElectricityContract $contract) => $contract->pricing_model === 'Spot');

            $companies->push([
                'company' => $company,
                'contractCount' => $companyContracts->count(),
                'avgPrice' => $priceMetrics->isNotEmpty()
                    ? $priceMetrics->avg(fn (array $metric) => (float) $metric['calculated_cost']['total_cost'])
                    : null,
                'lowestPrice' => $priceMetrics->isNotEmpty()
                    ? $priceMetrics->min(fn (array $metric) => (float) $metric['calculated_cost']['total_cost'])
                    : null,
                'avgEmissions' => $companyContracts->avg(fn (ElectricityContract $contract) => $cachedMetrics['contracts'][$contract->id]['emission_factor'] ?? 0),
                'lowestEmissions' => $companyContracts->min(fn (ElectricityContract $contract) => $cachedMetrics['contracts'][$contract->id]['emission_factor'] ?? PHP_FLOAT_MAX),
                'avgRenewable' => $companyContracts->avg(fn (ElectricityContract $contract) => $contract->electricitySource?->renewable_total ?? 0),
                'maxRenewable' => $companyContracts->max(fn (ElectricityContract $contract) => $contract->electricitySource?->renewable_total ?? 0),
                'lowestMonthlyFee' => $priceMetrics
                    ->map(fn (array $metric) => $metric['calculated_cost']['monthly_fixed_fee'] ?? null)
                    ->filter(fn ($fee) => is_numeric($fee) && is_finite((float) $fee))
                    ->map(fn ($fee) => (float) $fee)
                    ->min(),
                'lowestSpotMargin' => $spotContracts
                    ->map(fn (ElectricityContract $contract) => $cachedMetrics['contracts'][$contract->id]['calculated_cost']['spot_price_margin'] ?? null)
                    ->filter(fn ($margin) => is_numeric($margin) && is_finite((float) $margin))
                    ->map(fn ($margin) => (float) $margin)
                    ->min(),
                'hasSpotContracts' => $spotContracts->isNotEmpty(),
                'hasFullyRenewable' => $companyContracts->contains(fn (ElectricityContract $contract) => $contract->electricitySource && $contract->electricitySource->isFullyRenewable()
                ),
            ]);
        }

        return $companies;
    }
}
