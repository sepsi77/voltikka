<?php

namespace App\Services;

use App\Models\ElectricityContract;
use App\Models\SpotPriceAverage;
use App\Services\DTO\EnergyUsage;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

class ContractRankingService
{
    private const CACHE_TTL_SECONDS = 3600; // 1 hour
    private const CACHE_KEY_RANKINGS = 'contract_rankings_5000kwh';
    private const DEFAULT_CONSUMPTION = 5000;

    public function __construct(
        private ContractPriceCalculator $calculator,
        private ContractListCacheService $listCache,
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
     * Filter the cached sorted_ids to entries matching the viewed contract's
     * target-group eligibility, preserving cost order. Returns null when the
     * consumption isn't cache-supported or the viewed contract isn't in the
     * cache (e.g. consumption exceeds its limits).
     *
     * @return array{sortedIds: list<string>, selfCost: float}|null
     */
    private function getEligibleSortedIds(string $viewedContractId, int $consumption): ?array
    {
        $memoKey = $viewedContractId . ':' . $consumption;
        if (array_key_exists($memoKey, $this->eligibleSortedIdsMemo)) {
            return $this->eligibleSortedIdsMemo[$memoKey];
        }

        $metrics = $this->listCache->getCachedMetrics($consumption);
        if ($metrics === null || ! isset($metrics['contracts'][$viewedContractId])) {
            return $this->eligibleSortedIdsMemo[$memoKey] = null;
        }

        $viewed = ElectricityContract::find($viewedContractId);
        $eligibleTargets = $this->eligibleTargetGroups($viewed?->target_group);

        $targetGroups = ElectricityContract::query()
            ->whereIn('id', $metrics['sorted_ids'])
            ->pluck('target_group', 'id')
            ->all();

        $filtered = [];
        foreach ($metrics['sorted_ids'] as $id) {
            if ($this->matchesTargetGroup($targetGroups[$id] ?? null, $eligibleTargets)) {
                $filtered[] = $id;
            }
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
     * @param array<int, string> $eligible
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
        return Cache::remember(self::CACHE_KEY_RANKINGS, self::CACHE_TTL_SECONDS, function () {
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
            ->with(['priceComponents'])
            ->where(function ($q) {
                $q->whereIn('target_group', ['Household', 'Both'])
                  ->orWhereNull('target_group');
            })
            ->get();

        $spotPriceAvg = SpotPriceAverage::latestRolling365Days();
        $spotPriceDay = $spotPriceAvg?->day_avg_with_tax;
        $spotPriceNight = $spotPriceAvg?->night_avg_with_tax;

        $consumption = self::DEFAULT_CONSUMPTION;
        $usage = new EnergyUsage(total: $consumption, basicLiving: $consumption);

        // Calculate cost for each contract
        $contractCosts = [];
        foreach ($contracts as $contract) {
            if (! $contract->isConsumptionInRange($consumption)) {
                continue;
            }

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
