<?php

namespace App\Services;

use App\Models\ElectricityContract;
use App\Models\SpotPriceAverage;
use App\Services\DTO\EnergyUsage;
use Illuminate\Support\Facades\Cache;

class ContractListCacheService
{
    private const CACHE_VERSION_KEY = 'contract_list_cache_version';

    private const CACHE_TTL_SECONDS = 60 * 60 * 48; // 48 hours

    /**
     * Preset consumptions used in the UI and SEO pages.
     *
     * @var list<int>
     */
    public const PRESET_CONSUMPTIONS = [2000, 3500, 5000, 8000, 10000, 12000, 18000, 20000];

    public function __construct(
        private readonly ContractPriceCalculator $calculator,
        private readonly CO2EmissionsCalculator $emissionsCalculator,
    ) {}

    public function supportsConsumption(int $consumption): bool
    {
        return in_array($consumption, self::PRESET_CONSUMPTIONS, true);
    }

    /**
     * @return array{contracts: array<string, array{calculated_cost: array<string, mixed>, emission_factor: float|null, exceeds_consumption_limit: bool, total_cost: float}>, sorted_ids: list<string>, consumption: int}|null
     */
    public function getCachedMetrics(int $consumption): ?array
    {
        if (! $this->supportsConsumption($consumption)) {
            return null;
        }

        return Cache::remember(
            $this->getCacheKey($consumption),
            self::CACHE_TTL_SECONDS,
            fn () => $this->buildCachedMetrics($consumption)
        );
    }

    public function warmPresetCaches(): void
    {
        foreach (self::PRESET_CONSUMPTIONS as $consumption) {
            $this->getCachedMetrics($consumption);
        }
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
        return sprintf('contract_list_metrics:v%d:%d', $this->getVersion(), $consumption);
    }

    /**
     * @return array{contracts: array<string, array{calculated_cost: array<string, mixed>, emission_factor: float|null, exceeds_consumption_limit: bool, total_cost: float}>, sorted_ids: list<string>, consumption: int}
     */
    private function buildCachedMetrics(int $consumption): array
    {
        $contracts = ElectricityContract::query()
            ->active()
            ->with(['electricitySource'])
            ->get();

        $spotPriceAvg = SpotPriceAverage::latestRolling365Days();
        $spotPriceDay = $spotPriceAvg?->day_avg_with_tax;
        $spotPriceNight = $spotPriceAvg?->night_avg_with_tax;

        $usage = new EnergyUsage(
            total: $consumption,
            basicLiving: $consumption,
        );

        $priceComponentsByContractId = ElectricityContract::getLatestPriceComponentsForCalculationByContractIds(
            $contracts->pluck('id')
        );

        $metrics = [];

        foreach ($contracts as $contract) {
            $priceComponents = $priceComponentsByContractId[$contract->id] ?? [];

            $contractData = [
                'contract_type' => $contract->contract_type,
                'pricing_model' => $contract->pricing_model,
                'metering' => $contract->metering,
            ];

            $result = $this->calculator->calculate($priceComponents, $contractData, $usage, $spotPriceDay, $spotPriceNight);
            $calculatedCost = $result->toArray();
            $maxConsumption = $contract->consumption_limitation_max_x_kwh_per_y;

            $metrics[$contract->id] = [
                'calculated_cost' => $calculatedCost,
                'emission_factor' => $this->emissionsCalculator->calculateEmissionFactor($contract->electricitySource),
                'exceeds_consumption_limit' => $maxConsumption > 0 && $consumption > $maxConsumption,
                'total_cost' => $calculatedCost['total_cost'] ?? PHP_FLOAT_MAX,
            ];
        }

        $sortedIds = collect($metrics)
            ->sort(function (array $a, array $b) {
                $aExceeds = $a['exceeds_consumption_limit'] ? 1 : 0;
                $bExceeds = $b['exceeds_consumption_limit'] ? 1 : 0;

                if ($aExceeds !== $bExceeds) {
                    return $aExceeds <=> $bExceeds;
                }

                return $a['total_cost'] <=> $b['total_cost'];
            })
            ->keys()
            ->values()
            ->all();

        return [
            'contracts' => $metrics,
            'sorted_ids' => $sortedIds,
            'consumption' => $consumption,
        ];
    }
}
