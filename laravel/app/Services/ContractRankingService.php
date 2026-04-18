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
     * Contracts cheaper than the given one at the given consumption.
     *
     * Returns at most $limit contracts, ranked 1..N by annual cost. Matches the
     * viewed contract's target group (household-eligible vs company-only) so we
     * don't recommend a business contract to a household shopper. Falls back to
     * an empty collection when the consumption isn't cache-supported or the
     * contract itself isn't in the cached metrics.
     *
     * @return Collection<int, array{contract: ElectricityContract, total_cost: float, rank: int, savings: float}>
     */
    public function getCheaperContracts(string $contractId, int $consumption, int $limit = 4): Collection
    {
        $metrics = $this->listCache->getCachedMetrics($consumption);

        if ($metrics === null) {
            return collect();
        }

        $self = $metrics['contracts'][$contractId] ?? null;
        if ($self === null) {
            return collect();
        }

        $selfCost = (float) $self['total_cost'];
        $selfPosition = array_search($contractId, $metrics['sorted_ids'], true);
        if ($selfPosition === false || $selfPosition === 0) {
            return collect();
        }

        $viewedContract = ElectricityContract::find($contractId);
        $eligibleTargets = $this->eligibleTargetGroups($viewedContract?->target_group);

        // Walk the sorted list from the top, collecting eligible contracts that
        // are strictly cheaper than the viewed one.
        $candidateIds = [];
        foreach ($metrics['sorted_ids'] as $position => $id) {
            if ($id === $contractId || $position >= $selfPosition) {
                break;
            }
            $candidateIds[] = $id;
        }

        if (empty($candidateIds)) {
            return collect();
        }

        $contracts = ElectricityContract::query()
            ->with(['company'])
            ->whereIn('id', $candidateIds)
            ->get()
            ->keyBy('id');

        $results = collect();
        foreach ($candidateIds as $position => $id) {
            $contract = $contracts->get($id);
            if (! $contract) {
                continue;
            }
            if (! $this->matchesTargetGroup($contract->target_group, $eligibleTargets)) {
                continue;
            }

            $cost = (float) ($metrics['contracts'][$id]['total_cost'] ?? 0);
            $results->push([
                'contract' => $contract,
                'total_cost' => $cost,
                'rank' => $position + 1,
                'savings' => max(0.0, $selfCost - $cost),
            ]);

            if ($results->count() >= $limit) {
                break;
            }
        }

        return $results;
    }

    /**
     * Contract's rank within the sorted list for a given consumption.
     */
    public function getRankForConsumption(string $contractId, int $consumption): ?int
    {
        $metrics = $this->listCache->getCachedMetrics($consumption);
        if ($metrics === null) {
            return null;
        }

        $position = array_search($contractId, $metrics['sorted_ids'], true);
        return $position === false ? null : $position + 1;
    }

    public function getTotalContractsForConsumption(int $consumption): ?int
    {
        $metrics = $this->listCache->getCachedMetrics($consumption);
        return $metrics === null ? null : count($metrics['sorted_ids']);
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
