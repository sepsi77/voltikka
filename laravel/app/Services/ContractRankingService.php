<?php

namespace App\Services;

use App\Models\ElectricityContract;
use App\Models\SpotPriceAverage;
use App\Services\CanonicalPricing\CanonicalContractPricingService;
use App\Services\ContractCard\Enums\PricingBucket;
use App\Services\ContractCard\PricingCategoryResolver;
use App\Services\DTO\EnergyUsage;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

class ContractRankingService
{
    private const CACHE_TTL_SECONDS = 3600; // 1 hour

    private const CACHE_KEY_RANKINGS = 'contract_rankings_5000kwh';

    /** Bump when ranking eligibility, ordering, or the cached payload shape changes. */
    private const PAYLOAD_SCHEMA_VERSION = 1;

    private const DEFAULT_CONSUMPTION = 5000;

    public function __construct(
        private ContractPriceCalculator $calculator,
        private ContractListCacheService $listCache,
        private CanonicalContractPricingService $canonicalPricing,
    ) {}

    /**
     * Request-scoped memo so the three public methods below share the same
     * filtered list for a given viewed contract + consumption. Keyed by
     * "{contractId}:{consumption}".
     *
     * @var array<string, array{sortedIds: list<string>, selfCost: float}|null>
     */
    private array $eligibleSortedIdsMemo = [];

    /**
     * Request-scoped memo for getBucketCostSummary(), keyed by
     * "{contractId}:{consumption}:{bucket}". The detail page asks for at most
     * two buckets per render and each costs one contract query.
     *
     * @var array<string, array{count: int, cheapest_id: ?string, cheapest_cost: ?float, median_cost: ?float}|null>
     */
    private array $bucketCostSummaryMemo = [];

    /**
     * Request-scoped memo for the default 5 000 kWh rankings cache payload.
     * Contract detail reads rank and total separately for SEO/layout data; with
     * the database cache driver, without this both reads become identical cache
     * SQL spans in Sentry.
     *
     * @var array{contract_ranks: array<string, int>, company_ranks: array<string, int>, total_contracts: int, total_companies: int}|null
     */
    private ?array $rankingsMemo = null;

    /**
     * Contracts cheaper than the given one at the given consumption.
     *
     * Returns at most $limit contracts, ranked 1..N within the
     * target-group-eligible universe (household-eligible vs company-only so
     * we don't recommend a business contract to a household shopper). Empty
     * when the consumption isn't cache-supported or the contract itself isn't
     * in the eligible list.
     *
     * @return Collection<int, array{contract: ElectricityContract, total_cost: float, rank: int, savings: float}>
     */
    public function getCheaperContracts(string $contractId, int $consumption, int $limit = 4): Collection
    {
        $summary = $this->getEligibleSortedIds($contractId, $consumption);
        if ($summary === null) {
            return collect();
        }

        $selfPosition = array_search($contractId, $summary['sortedIds'], true);
        if ($selfPosition === false || $selfPosition === 0) {
            return collect();
        }

        $selfCost = $summary['selfCost'];
        $candidateIds = array_slice($summary['sortedIds'], 0, $selfPosition);
        if (empty($candidateIds)) {
            return collect();
        }

        $topIds = array_slice($candidateIds, 0, $limit);
        $contracts = ElectricityContract::query()
            ->with(['company'])
            ->whereIn('id', $topIds)
            ->get()
            ->keyBy('id');

        $metrics = $this->listCache->getCachedMetrics($consumption);

        return collect($topIds)
            ->map(function (string $id, int $i) use ($contracts, $metrics, $selfCost) {
                $contract = $contracts->get($id);
                if (! $contract) {
                    return null;
                }
                $cost = (float) ($metrics['contracts'][$id]['total_cost'] ?? 0);

                return [
                    'contract' => $contract,
                    'total_cost' => $cost,
                    'rank' => $i + 1,
                    'savings' => max(0.0, $selfCost - $cost),
                ];
            })
            ->filter()
            ->values();
    }

    /**
     * The contract directly behind the given one in the ranking.
     *
     * The rank-1 contract has nothing cheaper to compare against, so its hero
     * verdict states the gap to the runner-up instead of rendering an empty
     * "no comparison data" state.
     *
     * @return array{contract: ElectricityContract, total_cost: float, extra_cost: float}|null
     */
    public function getNextCheapestContract(string $contractId, int $consumption): ?array
    {
        $summary = $this->getEligibleSortedIds($contractId, $consumption);
        if ($summary === null) {
            return null;
        }

        $selfPosition = array_search($contractId, $summary['sortedIds'], true);
        if ($selfPosition === false) {
            return null;
        }

        $nextId = $summary['sortedIds'][$selfPosition + 1] ?? null;
        if ($nextId === null) {
            return null;
        }

        $contract = ElectricityContract::query()->with('company')->find($nextId);
        if (! $contract) {
            return null;
        }

        $metrics = $this->listCache->getCachedMetrics($consumption);
        $cost = (float) ($metrics['contracts'][$nextId]['total_cost'] ?? 0);

        return [
            'contract' => $contract,
            'total_cost' => $cost,
            'extra_cost' => max(0.0, $cost - $summary['selfCost']),
        ];
    }

    /**
     * Contract's rank within the target-group-eligible universe for the given
     * consumption. Matches the household/business audience of the viewed
     * contract so the number is consistent with getCheaperContracts().
     */
    public function getRankForConsumption(string $contractId, int $consumption): ?int
    {
        $summary = $this->getEligibleSortedIds($contractId, $consumption);
        if ($summary === null) {
            return null;
        }
        $position = array_search($contractId, $summary['sortedIds'], true);

        return $position === false ? null : $position + 1;
    }

    /**
     * Total number of contracts in the same target-group-eligible universe as
     * the viewed contract, at the given consumption.
     */
    public function getTotalContractsForConsumption(string $contractId, int $consumption): ?int
    {
        $summary = $this->getEligibleSortedIds($contractId, $consumption);

        return $summary === null ? null : count($summary['sortedIds']);
    }

    /**
     * Cost summary for one pricing bucket inside the same eligible universe the
     * viewed contract is ranked in, at the given consumption. The viewed
     * contract itself is excluded.
     *
     * The detail page reads it twice: for the counterfactual line ("what would a
     * typical pörssisähkö contract cost instead?") and for the same-type
     * alternative tile. Both must describe the market the rest of that page
     * describes, so this reuses getEligibleSortedIds() instead of re-deriving
     * the target-group and consumption-limit filtering.
     *
     * `median_cost` is the median annual total inside the bucket, which is what
     * "typical" means here. Every spot total in it comes from the same
     * trailing-12-month realized spot average plus that contract's own margin as
     * the statistics page uses, so the median embodies a typical margin without
     * a second market-wide calculation.
     *
     * @return array{count: int, cheapest_id: ?string, cheapest_cost: ?float, median_cost: ?float}|null
     */
    public function getBucketCostSummary(string $contractId, int $consumption, PricingBucket $bucket): ?array
    {
        $memoKey = $contractId.':'.$consumption.':'.$bucket->value;
        if (array_key_exists($memoKey, $this->bucketCostSummaryMemo)) {
            return $this->bucketCostSummaryMemo[$memoKey];
        }

        $summary = $this->getEligibleSortedIds($contractId, $consumption);
        if ($summary === null) {
            return $this->bucketCostSummaryMemo[$memoKey] = null;
        }

        $candidateIds = array_values(array_filter(
            $summary['sortedIds'],
            fn (string $id) => $id !== $contractId,
        ));

        if (empty($candidateIds)) {
            return $this->bucketCostSummaryMemo[$memoKey] = [
                'count' => 0,
                'cheapest_id' => null,
                'cheapest_cost' => null,
                'median_cost' => null,
            ];
        }

        // The shared scope is what keeps this line, the pricing-type filter and
        // the card band from drifting; never hand-write the bucket SQL here.
        $bucketIds = ElectricityContract::query()
            ->whereIn('id', $candidateIds)
            ->where(function ($query) use ($bucket) {
                PricingCategoryResolver::scopeBucket($query, $bucket);
            })
            ->pluck('id')
            ->flip();

        $metrics = $this->listCache->getCachedMetrics($consumption);

        $costs = [];
        $cheapestId = null;
        foreach ($candidateIds as $id) {
            if (! $bucketIds->has($id)) {
                continue;
            }

            $cost = $metrics['contracts'][$id]['total_cost'] ?? null;
            if (! is_numeric($cost) || ! is_finite((float) $cost)) {
                continue;
            }

            $costs[] = (float) $cost;
            $cheapestId ??= $id;
        }

        if (empty($costs)) {
            return $this->bucketCostSummaryMemo[$memoKey] = [
                'count' => 0,
                'cheapest_id' => null,
                'cheapest_cost' => null,
                'median_cost' => null,
            ];
        }

        $sorted = $costs;
        sort($sorted);
        $middle = (int) floor((count($sorted) - 1) / 2);
        $median = count($sorted) % 2 === 1
            ? $sorted[$middle]
            : ($sorted[$middle] + $sorted[$middle + 1]) / 2;

        return $this->bucketCostSummaryMemo[$memoKey] = [
            'count' => count($sorted),
            'cheapest_id' => $cheapestId,
            'cheapest_cost' => $sorted[0],
            'median_cost' => $median,
        ];
    }

    /**
     * Filter the cached sorted_ids to entries the viewed contract's audience can
     * actually buy at this consumption, preserving cost order. Returns null when
     * the consumption isn't cache-supported or the viewed contract isn't in the
     * cache (e.g. consumption exceeds its limits).
     *
     * Two filters, and both are load-bearing:
     * - target group, so a household shopper is not ranked against business-only
     *   contracts;
     * - the contract's own consumption limits, because a contract that cannot be
     *   bought at this consumption is not part of the comparison the visitor is
     *   making. It also keeps this universe identical in size to the one behind
     *   getTotalActiveContracts(), which has always applied isConsumptionInRange().
     *   Without it the detail page stated two different market sizes on one screen
     *   (measured 2026-07-26: title 291, hero 299, the 8 being capped contracts).
     *
     * @return array{sortedIds: list<string>, selfCost: float}|null
     */
    private function getEligibleSortedIds(string $viewedContractId, int $consumption): ?array
    {
        $memoKey = $viewedContractId.':'.$consumption;
        if (array_key_exists($memoKey, $this->eligibleSortedIdsMemo)) {
            return $this->eligibleSortedIdsMemo[$memoKey];
        }

        $metrics = $this->listCache->getCachedMetrics($consumption);
        if ($metrics === null || ! isset($metrics['contracts'][$viewedContractId])) {
            return $this->eligibleSortedIdsMemo[$memoKey] = null;
        }

        $candidates = ElectricityContract::query()
            ->whereIn('id', $metrics['sorted_ids'])
            ->get([
                'id',
                'target_group',
                'consumption_limitation_min_x_kwh_per_y',
                'consumption_limitation_max_x_kwh_per_y',
            ])
            ->keyBy('id');

        $viewed = ElectricityContract::find($viewedContractId);
        $eligibleTargets = $this->eligibleTargetGroups($viewed?->target_group);

        $filtered = [];
        foreach ($metrics['sorted_ids'] as $id) {
            $candidate = $candidates->get($id);

            if (! $this->matchesTargetGroup($candidate?->target_group, $eligibleTargets)) {
                continue;
            }

            // The viewed contract always stays in its own ranking, even if bad
            // limit data would exclude it; a page cannot rank against nothing.
            if ($id !== $viewedContractId && $candidate && ! $candidate->isConsumptionInRange($consumption)) {
                continue;
            }

            $filtered[] = $id;
        }

        return $this->eligibleSortedIdsMemo[$memoKey] = [
            'sortedIds' => $filtered,
            'selfCost' => (float) $metrics['contracts'][$viewedContractId]['total_cost'],
        ];
    }

    /**
     * @return array<int, string>
     */
    private function eligibleTargetGroups(?string $viewedTargetGroup): array
    {
        // Company-only viewed contract → recommend business-eligible.
        // Anything else (Household, Both, null) → household-eligible is the safe default.
        if ($viewedTargetGroup === 'Company') {
            return ['Company', 'Both'];
        }

        return ['Household', 'Both'];
    }

    /**
     * @param  array<int, string>  $eligible
     */
    private function matchesTargetGroup(?string $candidate, array $eligible): bool
    {
        if ($candidate === null) {
            // Treat unset target group as household-eligible (matches existing ranking logic).
            return in_array('Household', $eligible, true);
        }

        return in_array($candidate, $eligible, true);
    }

    /**
     * Get the price rank of a specific contract among all active household contracts.
     * Returns null if the contract is not found in the rankings.
     */
    public function getContractRank(string $contractId): ?int
    {
        $rankings = $this->getRankings();

        return $rankings['contract_ranks'][$contractId] ?? null;
    }

    /**
     * Get the company rank by cheapest contract among all active household contracts.
     * Returns null if the company is not found in the rankings.
     */
    public function getCompanyRank(string $companyName): ?int
    {
        $rankings = $this->getRankings();

        return $rankings['company_ranks'][$companyName] ?? null;
    }

    /**
     * Get the total number of active household contracts.
     */
    public function getTotalActiveContracts(): int
    {
        $rankings = $this->getRankings();

        return $rankings['total_contracts'];
    }

    /**
     * Get the total number of companies with active household contracts.
     */
    public function getTotalActiveCompanies(): int
    {
        $rankings = $this->getRankings();

        return $rankings['total_companies'];
    }

    /**
     * Get all rankings (cached).
     *
     * @return array{contract_ranks: array<string, int>, company_ranks: array<string, int>, total_contracts: int, total_companies: int}
     */
    private function getRankings(): array
    {
        if ($this->rankingsMemo !== null) {
            return $this->rankingsMemo;
        }

        // Vary the cache key by pricing basis so toggling either flag does not serve stale ranks.
        $cacheKey = self::CACHE_KEY_RANKINGS
            .':s'.self::PAYLOAD_SCHEMA_VERSION
            .':lv'.$this->listCache->getVersion()
            .($this->canonicalPricing->enabled() ? ':c1' : ':c0')
            .($this->canonicalPricing->resetForwardShiftEnabled() ? ':r1' : ':r0');

        return $this->rankingsMemo = Cache::remember($cacheKey, self::CACHE_TTL_SECONDS, function () {
            return $this->calculateRankings();
        });
    }

    /**
     * Calculate rankings for all active household contracts.
     */
    private function calculateRankings(): array
    {
        $contracts = ElectricityContract::query()
            ->active()
            ->where(function ($q) {
                $q->whereIn('target_group', ['Household', 'Both'])
                    ->orWhereNull('target_group');
            })
            ->get();

        $consumption = self::DEFAULT_CONSUMPTION;
        $usage = new EnergyUsage(total: $consumption, basicLiving: $consumption);

        $useCanonical = $this->canonicalPricing->enabled();
        $canonicalMetrics = $useCanonical ? $this->canonicalPricing->metricsForContracts($contracts, $usage) : [];

        $spotPriceAvg = $useCanonical ? null : SpotPriceAverage::latestRolling365Days();
        $spotPriceDay = $spotPriceAvg?->day_avg_with_tax;
        $spotPriceNight = $spotPriceAvg?->night_avg_with_tax;

        $priceComponentsByContractId = $useCanonical
            ? []
            : ElectricityContract::getLatestPriceComponentsForCalculationByContractIds($contracts->pluck('id'));

        // Calculate cost for each contract
        $contractCosts = [];
        foreach ($contracts as $contract) {
            if (! $contract->isConsumptionInRange($consumption)) {
                continue;
            }

            if ($useCanonical) {
                $canonical = $canonicalMetrics[$contract->id] ?? null;
                // Contracts unfit for comparison are excluded from rankings entirely.
                if ($canonical === null || ! ($canonical['is_listed'] ?? false)) {
                    continue;
                }

                $contractCosts[] = [
                    'id' => $contract->id,
                    'company_name' => $contract->company_name,
                    'total_cost' => $canonical['sort_key'] ?? PHP_FLOAT_MAX,
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
            $totalCost = $result->toArray()['total_cost'] ?? PHP_FLOAT_MAX;

            $contractCosts[] = [
                'id' => $contract->id,
                'company_name' => $contract->company_name,
                'total_cost' => $totalCost,
            ];
        }

        // Sort by total cost ascending
        usort($contractCosts, fn ($a, $b) => $a['total_cost'] <=> $b['total_cost']);

        // Build contract rank map (1-indexed)
        $contractRanks = [];
        foreach ($contractCosts as $index => $item) {
            $contractRanks[$item['id']] = $index + 1;
        }

        // Build company rank map: rank by their cheapest contract
        $companyBestCost = [];
        foreach ($contractCosts as $item) {
            $company = $item['company_name'];
            if (! isset($companyBestCost[$company]) || $item['total_cost'] < $companyBestCost[$company]) {
                $companyBestCost[$company] = $item['total_cost'];
            }
        }

        asort($companyBestCost);
        $companyRanks = [];
        $rank = 1;
        foreach ($companyBestCost as $companyName => $cost) {
            $companyRanks[$companyName] = $rank++;
        }

        return [
            'contract_ranks' => $contractRanks,
            'company_ranks' => $companyRanks,
            'total_contracts' => count($contractCosts),
            'total_companies' => count($companyRanks),
        ];
    }
}
