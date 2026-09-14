<?php

namespace App\Services;

use App\Models\ElectricityContract;
use App\Models\SpotPriceAverage;
use App\Services\Caching\ContractPriceCacheConflict;
use App\Services\Caching\ContractPriceCacheEvidence;
use App\Services\Caching\ContractPriceCacheLifecycle;
use App\Services\CanonicalPricing\CanonicalContractPricingService;
use App\Services\CanonicalPricing\DTO\CanonicalPricingOutcome;
use App\Services\CanonicalPricing\DTO\ContractPricingIntegrity;
use App\Services\CanonicalPricing\Enums\ContractComparability;
use App\Services\CanonicalPricing\Enums\EstimateMethod;
use App\Services\CanonicalPricing\PricingMode;
use App\Services\ContractPricing\CanonicalContractMetric;
use App\Services\ContractPricing\ContractMetricSet;
use App\Services\ContractPricing\ContractPricingViewData;
use App\Services\DTO\EnergyUsage;
use Illuminate\Support\Facades\Cache;
use InvalidArgumentException;

class ContractListCacheService
{
    // Generation keys remain private until every required payload is verified.

    /**
     * Shape marker for the cached metrics payload itself.
     *
     * The public version advances on refresh or immediate invalidation; c/r markers track
     * feature flags, so neither busts the cache when a deploy changes what the payload
     * CONTAINS. Bump this whenever a field is added to or removed from the cached
     * `calculated_cost` / `pricing_integrity` arrays, otherwise cards read a stale shape and
     * silently reuse an old payload until the next successful refresh.
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
        private readonly ContractPriceCacheLifecycle $lifecycle,
        private readonly ContractPriceCacheEvidence $evidence,
    ) {}

    /**
     * Request-scoped memoization for database-cache reads. Production uses the
     * database cache driver, so repeated calls to getCachedMetrics() during a
     * single detail render otherwise show up as repeated identical cache SQL
     * spans in Sentry.
     *
     * @var array<string, ContractMetricSet>
     */
    private array $cachedMetricsMemo = [];

    public function safetyFingerprint(): string
    {
        return $this->evidence->fingerprint();
    }

    public function calculatedAt(int $consumption = 5000): ?string
    {
        return $this->getCachedMetrics($consumption)?->toArray()['calculated_at'] ?? null;
    }

    public function supportsConsumption(int $consumption): bool
    {
        return in_array($consumption, self::PRESET_CONSUMPTIONS, true);
    }

    public function getCachedMetrics(int $consumption, bool $retryConflicts = true): ?ContractMetricSet
    {
        if (! $this->supportsConsumption($consumption)) {
            return null;
        }

        for ($attempt = 1; ; $attempt++) {
            try {
                return $this->readCachedMetrics($consumption);
            } catch (ContractPriceCacheConflict $exception) {
                $this->resetCalculationState();
                if (! $retryConflicts || $attempt === 2) {
                    throw $exception;
                }
            }
        }
    }

    public function resetCalculationState(): void
    {
        $this->cachedMetricsMemo = [];
        $this->canonicalPricing->resetMemoization();
    }

    private function readCachedMetrics(int $consumption): ContractMetricSet
    {
        $generation = $this->lifecycle->active();
        $cacheKey = $this->getCacheKey($consumption, $generation);
        $current = $this->evidence->current();
        $memoKey = $cacheKey.':'.hash('sha256', serialize($current));
        if (array_key_exists($memoKey, $this->cachedMetricsMemo)) {
            return $this->cachedMetricsMemo[$memoKey];
        }

        $payload = Cache::get($cacheKey);
        if ($payload === null) {
            $payload = $this->buildCachedMetrics($consumption)->toArray();
            if ($current !== $this->evidence->current()) {
                throw ContractPriceCacheConflict::evidenceChanged();
            }
            $this->lifecycle->write($generation, $cacheKey, $payload);
        }

        if (! is_array($payload)) {
            throw new InvalidArgumentException('Cached contract metrics must be an array payload.');
        }

        return $this->cachedMetricsMemo[$memoKey] = $this->guardEvidence($payload, $current);
    }

    public function warmPresetCaches(): void
    {
        foreach (self::PRESET_CONSUMPTIONS as $consumption) {
            $this->getCachedMetrics($consumption);
            $this->cachedMetricsMemo = [];
        }
    }

    public function bumpVersion(): int
    {
        $version = $this->lifecycle->invalidate();
        $this->cachedMetricsMemo = [];

        return $version;
    }

    public function getVersion(): int
    {
        return $this->lifecycle->active()['version'];
    }

    public function getGeneration(): string
    {
        return $this->lifecycle->active()['generation'];
    }

    public function getCacheKey(int $consumption, ?array $generation = null): string
    {
        // The pricing-basis marker (c1/c0) makes toggling CANONICAL_PRICING_ENABLED bust the
        // cache immediately instead of waiting for the next import version bump. The r1/r0
        // marker does the same for RESET_FORWARD_SHIFT_ENABLED, which changes market-reset
        // totals and therefore the sorted order.
        return sprintf(
            'contract_list_metrics:v%d:s%d:%s:%d:g%s',
            ($generation ??= $this->lifecycle->active())['version'],
            CalculatedCostPayloadSchema::VERSION,
            $this->pricingMode->cacheMarker(),
            $consumption,
            $generation['generation'],
        );
    }

    public function refresh(CompanyListCacheService $companies, ?callable $candidateGuard = null): int
    {
        for ($attempt = 1; ; $attempt++) {
            $this->resetCalculationState();
            try {
                return $this->refreshCandidate($companies, $candidateGuard);
            } catch (ContractPriceCacheConflict $exception) {
                if ($attempt === 2) {
                    throw $exception;
                }
            }
        }
    }

    private function refreshCandidate(CompanyListCacheService $companies, ?callable $candidateGuard): int
    {
        $starting = $this->lifecycle->active();
        $candidate = $this->lifecycle->candidate($starting);
        $expected = [];
        $source = $this->safetyFingerprint();
        try {
            foreach (self::PRESET_CONSUMPTIONS as $consumption) {
                $metrics = $this->buildCachedMetrics($consumption);
                $payload = $metrics->toArray();
                $key = $this->getCacheKey($consumption, $candidate);
                $this->lifecycle->write($candidate, $key, $payload, true);
                $expected[$key] = hash('sha256', serialize($payload));
                if ($consumption === 5000) {
                    $companyPayload = $companies->buildCachedCompanies($consumption, $metrics);
                    $companyKey = $companies->getCacheKey($consumption, $candidate);
                    $this->lifecycle->write($candidate, $companyKey, $companyPayload, true);
                    $expected[$companyKey] = hash('sha256', serialize($companyPayload));
                    unset($companyPayload);
                }
                unset($metrics, $payload);
                $this->cachedMetricsMemo = [];
            }
            if ($source !== $this->safetyFingerprint()) {
                throw ContractPriceCacheConflict::evidenceChanged();
            }
            if ($candidateGuard !== null) {
                $candidateGuard();
            }
            $this->lifecycle->promote($starting, $candidate, $expected);
        } catch (\Throwable $exception) {
            $this->lifecycle->retire($candidate, required: true);
            throw $exception;
        }

        return $candidate['version'];
    }

    private function guardEvidence(array $payload, array $current): ContractMetricSet
    {
        $validated = ContractMetricSet::fromArray($payload);
        $changed = false;
        foreach (array_unique(array_merge(array_keys($payload['contracts']), array_keys($current))) as $id) {
            $row = $current[$id] ?? null;
            if ($row !== null && ! isset($payload['contracts'][$id]) && $this->evidence->isCurrent($row)) {
                // No stale facts exist for a new safe contract. Direct consumers may calculate it.
                continue;
            }
            if ($row !== null && isset($payload['contracts'][$id])
                && (! $this->canonicalPricing->enabled()
                    || (($payload['source_evidence'][$id] ?? null) === $row && $this->evidence->isCurrent($row)))) {
                continue;
            }
            $changed = true;
            if (! $this->canonicalPricing->enabled()) {
                unset($payload['contracts'][$id]);
                $payload['sorted_ids'] = array_values(array_diff($payload['sorted_ids'], [$id]));
                $payload['excluded_ids'] = array_values(array_diff($payload['excluded_ids'], [$id]));

                continue;
            }
            $payload['contracts'][$id] = $this->excludedMetric();
            $payload['sorted_ids'] = array_values(array_diff($payload['sorted_ids'], [$id]));
            $payload['excluded_ids'] = array_values(array_unique([...$payload['excluded_ids'], $id]));
        }

        return $changed ? ContractMetricSet::fromArray($payload) : $validated;
    }

    private function excludedMetric(): array
    {
        $outcome = new CanonicalPricingOutcome(
            comparability: ContractComparability::ExcludedIncomplete,
            estimateMethod: EstimateMethod::None,
            totalCost: null,
            monthlyCosts: array_fill(0, 12, 0.0),
            baseTotalCost: null,
            baseMonthlyCosts: array_fill(0, 12, 0.0),
            measuredDiscountSavingsTotal: 0.0,
            monthlyDiscountSavings: array_fill(0, 12, 0.0),
            structuredOnlyTotal: null,
            isSpotContract: false,
            assumptions: ['cached_source_evidence_is_not_current'],
        );

        return [
            'calculated_cost' => ContractPricingViewData::fromCanonicalOutcome($outcome)->toArray(),
            'emission_factor' => null, 'exceeds_consumption_limit' => false,
            'total_cost' => PHP_FLOAT_MAX, 'comparability' => $outcome->comparability->value,
            'is_listed' => false, 'sort_key' => null,
            'pricing_integrity' => ContractPricingIntegrity::none()->toArray(),
        ];
    }

    protected function buildCachedMetrics(int $consumption): ContractMetricSet
    {
        $startingEvidence = $this->evidence->current();
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

        $evidence = $this->evidence->current();
        if ($startingEvidence !== $evidence) {
            throw ContractPriceCacheConflict::evidenceChanged();
        }

        return $this->guardEvidence([
            'source_evidence' => $evidence,
            'calculated_at' => now('Europe/Helsinki')->toIso8601String(),
            'contracts' => $metrics,
            'sorted_ids' => $sortedIds,
            'excluded_ids' => $excludedIds,
            'consumption' => $consumption,
        ], $evidence);
    }
}
