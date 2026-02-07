<?php

namespace App\Services;

use App\Models\ElectricityContract;
use App\Models\SpotPriceAverage;
use App\Services\DTO\EnergyUsage;
use Illuminate\Support\Facades\Cache;

class ContractRankingService
{
    private const CACHE_TTL_SECONDS = 3600; // 1 hour
    private const CACHE_KEY_RANKINGS = 'contract_rankings_5000kwh';
    private const DEFAULT_CONSUMPTION = 5000;

    public function __construct(
        private ContractPriceCalculator $calculator,
    ) {}

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
