<?php

namespace App\Services;

use App\Models\ElectricityContract;
use App\Models\SpotPriceAverage;
use App\Services\CanonicalPricing\CanonicalContractPricingService;
use App\Services\CanonicalPricing\PricingMode;
use App\Services\ContractPricing\CanonicalContractMetric;
use App\Services\ContractPricing\ContractMetricSet;
use App\Services\ContractPricing\ContractPricingViewData;
use App\Services\DTO\EnergyUsage;
use Illuminate\Support\Facades\Cache;
use InvalidArgumentException;

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
     * v11: `other` cadence recurring resets become eligible canonical list estimates.
     */
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
        private readonly PricingMode $pricingMode,
    ) {}

    /**
     * Request-scoped memoization for database-cache reads. Production uses the
     * database cache driver, so repeated calls to getCachedMetrics() during a
     * single detail render otherwise show up as repeated identical cache SQL
     * spans in Sentry.
     *
     * @var array<int, ContractMetricSet>
     */
    private array $cachedMetricsMemo = [];

    private ?int $versionMemo = null;

    public function supportsConsumption(int $consumption): bool
    {
        return in_array($consumption, self::PRESET_CONSUMPTIONS, true);
    }

    public function getCachedMetrics(int $consumption): ?ContractMetricSet
    {
        if (! $this->supportsConsumption($consumption)) {
            return null;
        }

        if (array_key_exists($consumption, $this->cachedMetricsMemo)) {
            return $this->cachedMetricsMemo[$consumption];
        }

        $payload = Cache::remember(
            $this->getCacheKey($consumption),
            self::CACHE_TTL_SECONDS,
            fn (): array => $this->buildCachedMetrics($consumption)->toArray(),
        );

        if (! is_array($payload)) {
            throw new InvalidArgumentException('Cached contract metrics must be an array payload.');
        }

        return $this->cachedMetricsMemo[$consumption] = ContractMetricSet::fromArray($payload);
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
        return sprintf(
            'contract_list_metrics:v%d:s%d:%s:%d',
            $this->getVersion(),
            CalculatedCostPayloadSchema::VERSION,
            $this->pricingMode->cacheMarker(),
            $consumption,
        );
    }

    private function buildCachedMetrics(int $consumption): ContractMetricSet
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
                if (! $canonical instanceof CanonicalContractMetric) {
                    throw new InvalidArgumentException('Canonical metrics are missing contract '.$contract->id.'.');
                }

                $pricing = $canonical->pricing();
                $metrics[$contract->id] = [
                    'calculated_cost' => $pricing->toArray(),
                    'emission_factor' => $emissionFactor,
                    'exceeds_consumption_limit' => $exceedsLimit,
                    // Keep the historical excluded-row sentinel in stored payloads. Typed
                    // consumers use pricing()->total(), which stays null for exclusions.
                    'total_cost' => $pricing->total() ?? PHP_FLOAT_MAX,
                    'comparability' => $canonical->comparability()->value,
                    'is_listed' => $canonical->isListed(),
                    'sort_key' => $canonical->sortKey(),
                    'pricing_integrity' => $canonical->integrity()->toArray(),
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
            $calculatedCost = ContractPricingViewData::fromLegacyResult($result)->toArray();

            $metrics[$contract->id] = [
                'calculated_cost' => $calculatedCost,
                'emission_factor' => $emissionFactor,
                'exceeds_consumption_limit' => $exceedsLimit,
                'total_cost' => $result->totalCost,
                'comparability' => null,
                'is_listed' => true,
                'sort_key' => $result->totalCost,
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

                if (! is_float($a['sort_key']) && ! is_int($a['sort_key'])) {
                    throw new InvalidArgumentException('A listed contract metric requires a numeric sort key.');
                }
                if (! is_float($b['sort_key']) && ! is_int($b['sort_key'])) {
                    throw new InvalidArgumentException('A listed contract metric requires a numeric sort key.');
                }

                return $a['sort_key'] <=> $b['sort_key'];
            })
            ->keys()
            ->values()
            ->all();

        $excludedIds = collect($metrics)
            ->filter(fn (array $metric) => $useCanonical && ! $metric['is_listed'])
            ->keys()
            ->values()
            ->all();

        return ContractMetricSet::fromArray([
            'contracts' => $metrics,
            'sorted_ids' => $sortedIds,
            'excluded_ids' => $excludedIds,
            'consumption' => $consumption,
        ]);
    }
}
