<?php

namespace App\Services;

use App\Models\ElectricityContract;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

class CompanyListCacheService
{
    private const CACHE_VERSION_KEY = 'company_list_cache_version';
    private const CACHE_TTL_SECONDS = 60 * 60 * 48; // 48 hours
    private const DEFAULT_CONSUMPTION = 5000;

    public function __construct(
        private readonly ContractListCacheService $contractListCache,
    ) {}

    public function getCachedCompanies(int $consumption = self::DEFAULT_CONSUMPTION): Collection
    {
        return Cache::remember(
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

        return $version;
    }

    public function getVersion(): int
    {
        return (int) Cache::get(self::CACHE_VERSION_KEY, 1);
    }

    private function getCacheKey(int $consumption): string
    {
        return sprintf('company_list:v%d:%d', $this->getVersion(), $consumption);
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

        foreach ($contractsByCompany as $companyContracts) {
            $company = $companyContracts->first()?->company;

            if (! $company) {
                continue;
            }

            $applicableContracts = $companyContracts->filter(function (ElectricityContract $contract) use ($cachedMetrics) {
                return !($cachedMetrics['contracts'][$contract->id]['exceeds_consumption_limit'] ?? false);
            });

            $priceMetrics = $applicableContracts
                ->mapWithKeys(function (ElectricityContract $contract) use ($cachedMetrics) {
                    return [$contract->id => $cachedMetrics['contracts'][$contract->id] ?? null];
                })
                ->filter();

            $spotContracts = $applicableContracts->filter(fn (ElectricityContract $contract) => $contract->pricing_model === 'Spot');

            $companies->push([
                'company' => $company,
                'contractCount' => $companyContracts->count(),
                'avgPrice' => $priceMetrics->isNotEmpty()
                    ? $priceMetrics->avg(fn (array $metric) => $metric['calculated_cost']['total_cost'] ?? 0)
                    : null,
                'lowestPrice' => $priceMetrics->isNotEmpty()
                    ? $priceMetrics->min(fn (array $metric) => $metric['calculated_cost']['total_cost'] ?? PHP_FLOAT_MAX)
                    : null,
                'avgEmissions' => $companyContracts->avg(fn (ElectricityContract $contract) => $cachedMetrics['contracts'][$contract->id]['emission_factor'] ?? 0),
                'lowestEmissions' => $companyContracts->min(fn (ElectricityContract $contract) => $cachedMetrics['contracts'][$contract->id]['emission_factor'] ?? PHP_FLOAT_MAX),
                'avgRenewable' => $companyContracts->avg(fn (ElectricityContract $contract) => $contract->electricitySource?->renewable_total ?? 0),
                'maxRenewable' => $companyContracts->max(fn (ElectricityContract $contract) => $contract->electricitySource?->renewable_total ?? 0),
                'lowestMonthlyFee' => $priceMetrics
                    ->map(fn (array $metric) => $metric['calculated_cost']['monthly_fixed_fee'] ?? PHP_FLOAT_MAX)
                    ->filter(fn (float|int $fee) => $fee < PHP_FLOAT_MAX)
                    ->min() ?? null,
                'lowestSpotMargin' => $spotContracts
                    ->map(fn (ElectricityContract $contract) => $cachedMetrics['contracts'][$contract->id]['calculated_cost']['spot_price_margin'] ?? PHP_FLOAT_MAX)
                    ->filter(fn (float|int $margin) => $margin < PHP_FLOAT_MAX)
                    ->min() ?? null,
                'hasSpotContracts' => $spotContracts->isNotEmpty(),
                'hasFullyRenewable' => $companyContracts->contains(fn (ElectricityContract $contract) =>
                    $contract->electricitySource && $contract->electricitySource->isFullyRenewable()
                ),
            ]);
        }

        return $companies;
    }
}
