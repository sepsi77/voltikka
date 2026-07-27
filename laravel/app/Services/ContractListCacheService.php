<?php

namespace App\Services;

use App\Models\ElectricityContract;
use App\Models\SpotPriceAverage;
use App\Services\CanonicalPricing\CanonicalContractPricingService;
use App\Services\DTO\EnergyUsage;
use Illuminate\Support\Facades\Cache;

class ContractListCacheService
{
    private const CACHE_VERSION_KEY = 'contract_list_cache_version';

    /**
     * Shape marker for the cached metrics payload itself.
     *
     * The stored version key only advances on a data import, and the c/r markers only track
     * feature flags, so neither busts the cache when a deploy changes what the payload
     * CONTAINS. Bump this whenever a field is added to or removed from the cached
     * `calculated_cost` / `pricing_integrity` arrays, otherwise cards read a stale shape and
     * silently fall back for up to 48 hours after release.
     *
     * v2: `pricing_integrity` gained `promo_rate_cents` / `normal_rate_cents`, which the
     * contract card renders as two dated receipt rows.
     * v3: `calculated_cost.phase_breakdown` entries gained the resolved window dates and the
     * rates each phase was costed at, which the receipt reads for a dated mechanism switch.
     * v4: canonical `base_monthly_costs`, `discount_savings_total`, and
     * `monthly_discount_savings` now contain the measured promotion-free calculation.
     * v5: short annualized terms carry their actual unannualized contract-term costs and saving.
     * v6: canonical package outcomes carry typed monthly allowance and excess-rate data.
     * v7: cards use canonical-only current values and real-term offer copy in canonical mode.
     * v8: company and SEO offer surfaces use canonical measured membership and benefit copy.
     * v9: canonical outcomes carry exact typed offer terms for controlled public promotion copy.
     * v10: short BaseOnlyHybrid outcomes preserve real-term totals and offer savings.
     */
    private const PAYLOAD_SCHEMA_VERSION = 10;

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
        private readonly CanonicalContractPricingService $canonicalPricing,
    ) {}

    /**
     * Request-scoped memoization for database-cache reads. Production uses the
     * database cache driver, so repeated calls to getCachedMetrics() during a
     * single detail render otherwise show up as repeated identical cache SQL
     * spans in Sentry.
     *
     * @var array<int, array{contracts: array<string, array{calculated_cost: array<string, mixed>, emission_factor: float|null, exceeds_consumption_limit: bool, total_cost: float}>, sorted_ids: list<string>, consumption: int}>
     */
    private array $cachedMetricsMemo = [];

    private ?int $versionMemo = null;

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

        if (array_key_exists($consumption, $this->cachedMetricsMemo)) {
            return $this->cachedMetricsMemo[$consumption];
        }

        return $this->cachedMetricsMemo[$consumption] = Cache::remember(
            $this->getCacheKey($consumption),
            self::CACHE_TTL_SECONDS,
            fn () => $this->buildCachedMetrics($consumption)
        );
    }

    public function warmPresetCaches(): void
    {
        foreach (self::PRESET_CONSUMPTIONS as $consumption) {
            $this->getCachedMetrics($consumption);
            unset($this->cachedMetricsMemo[$consumption]);
        }
    }

    public function bumpVersion(): int
    {
        $version = $this->getVersion() + 1;
        Cache::forever(self::CACHE_VERSION_KEY, $version);
        $this->versionMemo = $version;
        $this->cachedMetricsMemo = [];

        return $version;
    }

    public function getVersion(): int
    {
        return $this->versionMemo ??= (int) Cache::get(self::CACHE_VERSION_KEY, 1);
    }

    private function getCacheKey(int $consumption): string
    {
        // The pricing-basis marker (c1/c0) makes toggling CANONICAL_PRICING_ENABLED bust the
        // cache immediately instead of waiting for the next import version bump. The r1/r0
        // marker does the same for RESET_FORWARD_SHIFT_ENABLED, which changes market-reset
        // totals and therefore the sorted order.
        $basis = ($this->canonicalPricing->enabled() ? 'c1' : 'c0')
            .($this->canonicalPricing->resetForwardShiftEnabled() ? 'r1' : 'r0');

        return sprintf(
            'contract_list_metrics:v%d:s%d:%s:%d',
            $this->getVersion(),
            self::PAYLOAD_SCHEMA_VERSION,
            $basis,
            $consumption,
        );
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

        $useCanonical = $this->canonicalPricing->enabled();

        $canonicalMetrics = $useCanonical
            ? $this->canonicalPricing->metricsForContracts($contracts, $usage)
            : [];

        $priceComponentsByContractId = $useCanonical
            ? []
            : ElectricityContract::getLatestPriceComponentsForCalculationByContractIds($contracts->pluck('id'));

        $metrics = [];

        foreach ($contracts as $contract) {
            $maxConsumption = $contract->consumption_limitation_max_x_kwh_per_y;
            $exceedsLimit = $maxConsumption > 0 && $consumption > $maxConsumption;
            $emissionFactor = $this->emissionsCalculator->calculateEmissionFactor($contract->electricitySource);

            if ($useCanonical) {
                $canonical = $canonicalMetrics[$contract->id] ?? null;
                $calculatedCost = $canonical['calculated_cost'] ?? [];

                $metrics[$contract->id] = [
                    'calculated_cost' => $calculatedCost,
                    'emission_factor' => $emissionFactor,
                    'exceeds_consumption_limit' => $exceedsLimit,
                    'total_cost' => $calculatedCost['total_cost'] ?? PHP_FLOAT_MAX,
                    'comparability' => $canonical['comparability'] ?? null,
                    'is_listed' => $canonical['is_listed'] ?? false,
                    'sort_key' => $canonical['sort_key'],
                    'pricing_integrity' => $canonical['integrity'] ?? null,
                ];

                continue;
            }

            $priceComponents = $priceComponentsByContractId[$contract->id] ?? [];
            $contractData = [
                'contract_type' => $contract->contract_type,
                'pricing_model' => $contract->pricing_model,
                'metering' => $contract->metering,
            ];

            $result = $this->calculator->calculate($priceComponents, $contractData, $usage, $spotPriceDay, $spotPriceNight);
            $calculatedCost = $result->toArray();

            $metrics[$contract->id] = [
                'calculated_cost' => $calculatedCost,
                'emission_factor' => $emissionFactor,
                'exceeds_consumption_limit' => $exceedsLimit,
                'total_cost' => $calculatedCost['total_cost'] ?? PHP_FLOAT_MAX,
                'comparability' => null,
                'is_listed' => true,
                'sort_key' => $calculatedCost['total_cost'] ?? PHP_FLOAT_MAX,
                'pricing_integrity' => null,
            ];
        }

        $sortedIds = collect($metrics)
            // Canonical mode drops contracts not fit for comparison from the ranking entirely.
            ->reject(fn (array $metric) => $useCanonical && ! $metric['is_listed'])
            ->sort(function (array $a, array $b) {
                $aExceeds = $a['exceeds_consumption_limit'] ? 1 : 0;
                $bExceeds = $b['exceeds_consumption_limit'] ? 1 : 0;

                if ($aExceeds !== $bExceeds) {
                    return $aExceeds <=> $bExceeds;
                }

                return ($a['sort_key'] ?? PHP_FLOAT_MAX) <=> ($b['sort_key'] ?? PHP_FLOAT_MAX);
            })
            ->keys()
            ->values()
            ->all();

        $excludedIds = collect($metrics)
            ->filter(fn (array $metric) => $useCanonical && ! $metric['is_listed'])
            ->keys()
            ->values()
            ->all();

        return [
            'contracts' => $metrics,
            'sorted_ids' => $sortedIds,
            'excluded_ids' => $excludedIds,
            'consumption' => $consumption,
        ];
    }
}
