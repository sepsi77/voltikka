<?php

namespace App\Livewire;

use App\Models\ElectricityContract;
use App\Models\SpotPriceAverage;
use App\Services\ContractPriceCalculator;
use App\Services\DTO\EnergyUsage;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Livewire\Component;

class ContractTypeComparison extends Component
{
    /**
     * Comparison mode: 'pricing_model' or 'contract_term'.
     */
    public string $comparisonMode = 'pricing_model';

    /**
     * Whether to show the mode toggle tabs.
     */
    public bool $showModeTabs = true;

    /**
     * Comparison context. The spot article uses pörssisähkö as the anchor in both tabs.
     */
    public string $comparisonContext = 'default';

    /**
     * Annual consumption in kWh.
     */
    public int $consumption = 5000;

    /**
     * Selected contract ID for type A (null = auto-select cheapest).
     */
    public ?string $selectedContractA = null;

    /**
     * Selected contract ID for type B (null = auto-select cheapest).
     */
    public ?string $selectedContractB = null;

    /**
     * Whether the async contract picker is open for each side.
     */
    public bool $selectorOpenA = false;
    public bool $selectorOpenB = false;

    /**
     * Search terms for async contract picker results.
     */
    public string $contractSearchA = '';
    public string $contractSearchB = '';

    /**
     * Consumption presets for quick selection.
     */
    public array $consumptionPresets = [2000, 5000, 10000, 15000, 20000];

    /**
     * Finnish month names.
     */
    protected array $finnishMonths = [
        1 => 'Tammi',
        2 => 'Helmi',
        3 => 'Maalis',
        4 => 'Huhti',
        5 => 'Touko',
        6 => 'Kesä',
        7 => 'Heinä',
        8 => 'Elo',
        9 => 'Syys',
        10 => 'Loka',
        11 => 'Marras',
        12 => 'Joulu',
    ];

    /**
     * Monthly heating need distribution (Jan-Dec).
     * Source: https://www.ilmatieteenlaitos.fi/lammitystarveluvut (Jyväskylä 2021)
     */
    protected const HEATING_NEED_BY_MONTH = [
        1 => 754,   // January
        2 => 768,   // February (peak)
        3 => 623,   // March
        4 => 426,   // April
        5 => 202,   // May
        6 => 0,     // June
        7 => 0,     // July
        8 => 36,    // August
        9 => 277,   // September
        10 => 336,  // October
        11 => 537,  // November
        12 => 812,  // December
    ];

    /**
     * Base living consumption per year (kWh) - appliances, lighting, etc.
     * This is distributed evenly throughout the year.
     */
    protected const BASE_LIVING_CONSUMPTION = 4000;

    /**
     * Get monthly consumption distribution based on heating patterns.
     *
     * For higher consumption (electric heating), distributes consumption
     * seasonally with higher usage in winter months.
     *
     * @return array<int, float> Monthly consumption indexed by month number (1-12)
     */
    protected function getMonthlyConsumptionDistribution(): array
    {
        $totalConsumption = $this->consumption;

        // Base living consumption (appliances, lighting, etc.) - distributed evenly
        $baseConsumption = min($totalConsumption, self::BASE_LIVING_CONSUMPTION);
        $monthlyBase = $baseConsumption / 12;

        // Remaining consumption is assumed to be heating
        $heatingConsumption = max(0, $totalConsumption - $baseConsumption);

        // Calculate heating distribution by month
        $totalHeatingNeed = array_sum(self::HEATING_NEED_BY_MONTH);
        $monthlyDistribution = [];

        for ($month = 1; $month <= 12; $month++) {
            $heatingNeed = self::HEATING_NEED_BY_MONTH[$month];
            $monthlyHeating = $totalHeatingNeed > 0
                ? ($heatingNeed / $totalHeatingNeed) * $heatingConsumption
                : 0;

            $monthlyDistribution[$month] = $monthlyBase + $monthlyHeating;
        }

        return $monthlyDistribution;
    }

    /**
     * Set the comparison mode.
     */
    public function setComparisonMode(string $mode): void
    {
        if (in_array($mode, ['pricing_model', 'contract_term'])) {
            $this->comparisonMode = $mode;
            // Reset selections when mode changes
            $this->selectedContractA = null;
            $this->selectedContractB = null;
            $this->resetContractPickerState();
        }
    }

    /**
     * Set the consumption level.
     */
    public function setConsumption(int $value): void
    {
        $this->consumption = max(500, min(50000, $value));
    }

    /**
     * Select a specific contract for type A.
     */
    public function selectContractA(?string $contractId): void
    {
        $this->selectedContractA = $contractId ?: null;
        $this->selectorOpenA = false;
        $this->contractSearchA = '';
    }

    /**
     * Select a specific contract for type B.
     */
    public function selectContractB(?string $contractId): void
    {
        $this->selectedContractB = $contractId ?: null;
        $this->selectorOpenB = false;
        $this->contractSearchB = '';
    }

    public function openSelectorA(): void
    {
        $this->selectorOpenA = true;
        $this->contractSearchA = '';
    }

    public function openSelectorB(): void
    {
        $this->selectorOpenB = true;
        $this->contractSearchB = '';
    }

    public function closeSelectorA(): void
    {
        $this->selectorOpenA = false;
        $this->contractSearchA = '';
    }

    public function closeSelectorB(): void
    {
        $this->selectorOpenB = false;
        $this->contractSearchB = '';
    }

    protected function resetContractPickerState(): void
    {
        $this->selectorOpenA = false;
        $this->selectorOpenB = false;
        $this->contractSearchA = '';
        $this->contractSearchB = '';
    }

    /**
     * Get the comparison mode configuration.
     */
    public function getModeConfigProperty(): array
    {
        if ($this->comparisonContext === 'spot_article') {
            return match ($this->comparisonMode) {
                'contract_term' => [
                    'typeA' => 'Spot',
                    'typeB' => 'FixedTerm',
                    'labelA' => 'Pörssisähkö',
                    'labelB' => 'Määräaikainen',
                    'filterFieldA' => 'pricing_model',
                    'filterFieldB' => 'contract_type',
                ],
                default => [
                    'typeA' => 'Spot',
                    'typeB' => 'FixedPrice',
                    'labelA' => 'Pörssisähkö',
                    'labelB' => 'Kiinteähintainen',
                    'filterFieldA' => 'pricing_model',
                    'filterFieldB' => 'pricing_model',
                ],
            };
        }

        return match ($this->comparisonMode) {
            'pricing_model' => [
                'typeA' => 'Spot',
                'typeB' => 'FixedPrice',
                'labelA' => 'Pörssisähkö',
                'labelB' => 'Kiinteähintainen',
                'filterFieldA' => 'pricing_model',
                'filterFieldB' => 'pricing_model',
            ],
            'contract_term' => [
                'typeA' => 'FixedTerm',
                'typeB' => 'OpenEnded',
                'labelA' => 'Määräaikainen',
                'labelB' => 'Toistaiseksi voimassa',
                'filterFieldA' => 'contract_type',
                'filterFieldB' => 'contract_type',
            ],
            default => [
                'typeA' => 'Spot',
                'typeB' => 'FixedPrice',
                'labelA' => 'Pörssisähkö',
                'labelB' => 'Kiinteähintainen',
                'filterFieldA' => 'pricing_model',
                'filterFieldB' => 'pricing_model',
            ],
        };
    }

    /**
     * Labels for the optional mode tabs.
     */
    public function getModeTabLabelsProperty(): array
    {
        if ($this->comparisonContext === 'spot_article') {
            return [
                'pricing_model' => 'Pörssisähkö vs kiinteähintainen',
                'contract_term' => 'Pörssisähkö vs määräaikainen',
            ];
        }

        return [
            'pricing_model' => 'Pörssisähkö vs kiinteähintainen',
            'contract_term' => 'Määräaikainen vs toistaiseksi',
        ];
    }

    /**
     * Get monthly spot price averages from the same months last year.
     */
    public function getMonthlySpotPricesLastYear(): array
    {
        return Cache::remember('contract-type-comparison:monthly-spot-prices:' . now()->format('Y-m'), now()->addDay(), function () {
            $prices = [];
            $now = Carbon::now();

            for ($i = 0; $i < 12; $i++) {
                $futureMonth = $now->copy()->addMonths($i);
                $lastYearMonth = $futureMonth->copy()->subYear()->format('Y-m');

                $avg = SpotPriceAverage::forMonth($lastYearMonth, 'FI');

                $prices[$futureMonth->format('Y-m')] = [
                    'month_name' => $this->finnishMonths[$futureMonth->month],
                    'month_number' => $futureMonth->month,
                    'year' => $futureMonth->year,
                    'day_avg' => $avg?->day_avg_with_tax ?? 8.0,  // fallback if no data
                    'night_avg' => $avg?->night_avg_with_tax ?? 6.0,
                    'avg_price' => $avg?->avg_price_with_tax ?? 7.0,
                ];
            }

            return $prices;
        });
    }

    /**
     * Get available contracts for type A.
     */
    public function getAvailableContractsAProperty(): Collection
    {
        $config = $this->modeConfig;

        return $this->getContractsForType($config['filterFieldA'], $config['typeA']);
    }

    /**
     * Get available contracts for type B.
     */
    public function getAvailableContractsBProperty(): Collection
    {
        $config = $this->modeConfig;

        return $this->getContractsForType($config['filterFieldB'], $config['typeB']);
    }

    /**
     * Get contracts filtered by a specific field and value.
     */
    protected function getContractsForType(string $field, string $value): Collection
    {
        return $this->baseContractsForTypeQuery($field, $value)
            ->with(['company', 'priceComponents'])
            ->get()
            ->filter(fn ($contract) => $contract->isConsumptionInRange($this->consumption));
    }

    protected function baseContractsForTypeQuery(string $field, string $value)
    {
        return ElectricityContract::query()
            ->active()
            ->where($field, $value)
            ->where(function ($q) {
                $q->whereIn('target_group', ['Household', 'Both'])
                    ->orWhereNull('target_group');
            })
            ->where(function ($q) {
                $q->whereNull('consumption_limitation_min_x_kwh_per_y')
                    ->orWhere('consumption_limitation_min_x_kwh_per_y', '<=', $this->consumption);
            })
            ->where(function ($q) {
                $q->whereNull('consumption_limitation_max_x_kwh_per_y')
                    ->orWhere('consumption_limitation_max_x_kwh_per_y', '>=', $this->consumption);
            });
    }

    protected function searchContractsForType(string $field, string $value, string $term): Collection
    {
        $term = trim($term);

        if (mb_strlen($term) < 2) {
            return collect();
        }

        $like = '%' . str_replace(['%', '_'], ['\\%', '\\_'], $term) . '%';

        return $this->baseContractsForTypeQuery($field, $value)
            ->with('company')
            ->where(function ($q) use ($like) {
                $q->where('name', 'like', $like)
                    ->orWhere('company_name', 'like', $like);
            })
            ->orderBy('company_name')
            ->orderBy('name')
            ->limit(8)
            ->get()
            ->filter(fn ($contract) => $contract->isConsumptionInRange($this->consumption))
            ->values();
    }

    public function getContractSearchResultsAProperty(): Collection
    {
        if (! $this->selectorOpenA) {
            return collect();
        }

        $config = $this->modeConfig;

        return $this->searchContractsForType($config['filterFieldA'], $config['typeA'], $this->contractSearchA);
    }

    public function getContractSearchResultsBProperty(): Collection
    {
        if (! $this->selectorOpenB) {
            return collect();
        }

        $config = $this->modeConfig;

        return $this->searchContractsForType($config['filterFieldB'], $config['typeB'], $this->contractSearchB);
    }

    /**
     * Get the selected or cheapest contract for type A.
     */
    public function getContractAProperty(): ?ElectricityContract
    {
        $contracts = $this->availableContractsA;

        if ($this->selectedContractA) {
            $selected = $contracts->firstWhere('id', $this->selectedContractA);
            if ($selected) {
                return $selected;
            }
        }

        // Auto-select cheapest
        return $this->findCheapestContract($contracts);
    }

    /**
     * Get the selected or cheapest contract for type B.
     */
    public function getContractBProperty(): ?ElectricityContract
    {
        $contracts = $this->availableContractsB;

        if ($this->selectedContractB) {
            $selected = $contracts->firstWhere('id', $this->selectedContractB);
            if ($selected) {
                return $selected;
            }
        }

        // Auto-select cheapest
        return $this->findCheapestContract($contracts);
    }

    /**
     * Find the cheapest contract from a collection based on projected costs.
     */
    protected function findCheapestContract(Collection $contracts): ?ElectricityContract
    {
        if ($contracts->isEmpty()) {
            return null;
        }

        $calculator = app(ContractPriceCalculator::class);
        $monthlySpotPrices = $this->getMonthlySpotPricesLastYear();

        // Calculate average spot prices across 12 months
        $avgSpotDay = collect($monthlySpotPrices)->avg('day_avg');
        $avgSpotNight = collect($monthlySpotPrices)->avg('night_avg');

        $cheapest = null;
        $lowestCost = PHP_FLOAT_MAX;

        foreach ($contracts as $contract) {
            $cost = $this->calculateContractCost($contract, $calculator, $avgSpotDay, $avgSpotNight);
            if ($cost < $lowestCost) {
                $lowestCost = $cost;
                $cheapest = $contract;
            }
        }

        return $cheapest;
    }

    /**
     * Calculate the annual cost for a contract.
     */
    protected function calculateContractCost(
        ElectricityContract $contract,
        ContractPriceCalculator $calculator,
        float $spotPriceDay,
        float $spotPriceNight
    ): float {
        $priceComponents = $contract->getLatestPriceComponentsForCalculation();

        $usage = new EnergyUsage(
            total: $this->consumption,
            basicLiving: $this->consumption,
        );

        $contractData = [
            'contract_type' => $contract->contract_type,
            'pricing_model' => $contract->pricing_model,
            'metering' => $contract->metering,
        ];

        $result = $calculator->calculate($priceComponents, $contractData, $usage, $spotPriceDay, $spotPriceNight);

        return $result->totalCost;
    }

    /**
     * Get the projected monthly costs for contract A.
     */
    public function getProjectedCostsAProperty(): array
    {
        return $this->calculateProjectedCosts($this->contractA);
    }

    /**
     * Get the projected monthly costs for contract B.
     */
    public function getProjectedCostsBProperty(): array
    {
        return $this->calculateProjectedCosts($this->contractB);
    }

    /**
     * Calculate 12-month projected costs for a contract.
     *
     * Uses seasonal consumption distribution based on heating patterns
     * for higher consumption levels.
     */
    protected function calculateProjectedCosts(?ElectricityContract $contract): array
    {
        if (!$contract) {
            return [
                'monthly' => array_fill(0, 12, 0),
                'total' => 0,
                'labels' => [],
                'consumption' => array_fill(0, 12, 0),
            ];
        }

        $calculator = app(ContractPriceCalculator::class);
        $monthlySpotPrices = $this->getMonthlySpotPricesLastYear();
        $monthlyConsumptionDist = $this->getMonthlyConsumptionDistribution();

        $priceComponents = $contract->getLatestPriceComponentsForCalculation();

        $contractData = [
            'contract_type' => $contract->contract_type,
            'pricing_model' => $contract->pricing_model,
            'metering' => $contract->metering,
        ];

        $isSpotContract = $contract->pricing_model === 'Spot';
        $monthlyCosts = [];
        $monthlyConsumption = [];
        $labels = [];

        foreach ($monthlySpotPrices as $yearMonth => $priceData) {
            $labels[] = $priceData['month_name'];
            $monthNumber = $priceData['month_number'];

            // Get consumption for this specific month based on seasonal distribution
            $consumption = $monthlyConsumptionDist[$monthNumber] ?? ($this->consumption / 12);
            $monthlyConsumption[] = round($consumption, 0);

            $usage = new EnergyUsage(
                total: (int) $consumption,
                basicLiving: (int) $consumption,
            );

            $spotDay = $isSpotContract ? $priceData['day_avg'] : null;
            $spotNight = $isSpotContract ? $priceData['night_avg'] : null;

            $result = $calculator->calculate($priceComponents, $contractData, $usage, $spotDay, $spotNight);

            $monthlyCosts[] = round($result->totalCost, 2);
        }

        return [
            'monthly' => $monthlyCosts,
            'total' => round(array_sum($monthlyCosts), 2),
            'labels' => $labels,
            'consumption' => $monthlyConsumption,
        ];
    }

    /**
     * Get the comparison result summary.
     */
    public function getComparisonResultProperty(): array
    {
        $costA = $this->projectedCostsA['total'];
        $costB = $this->projectedCostsB['total'];

        if ($costA === 0 && $costB === 0) {
            return [
                'hasResult' => false,
                'winner' => null,
                'savings' => 0,
                'savingsPercent' => 0,
            ];
        }

        $config = $this->modeConfig;

        if ($costA < $costB) {
            $savings = $costB - $costA;
            $savingsPercent = $costB > 0 ? ($savings / $costB) * 100 : 0;

            return [
                'hasResult' => true,
                'winner' => 'A',
                'winnerLabel' => $config['labelA'],
                'loserLabel' => $config['labelB'],
                'savings' => round($savings, 0),
                'savingsPercent' => round($savingsPercent, 1),
                'costA' => round($costA, 0),
                'costB' => round($costB, 0),
            ];
        } elseif ($costB < $costA) {
            $savings = $costA - $costB;
            $savingsPercent = $costA > 0 ? ($savings / $costA) * 100 : 0;

            return [
                'hasResult' => true,
                'winner' => 'B',
                'winnerLabel' => $config['labelB'],
                'loserLabel' => $config['labelA'],
                'savings' => round($savings, 0),
                'savingsPercent' => round($savingsPercent, 1),
                'costA' => round($costA, 0),
                'costB' => round($costB, 0),
            ];
        } else {
            return [
                'hasResult' => true,
                'winner' => 'tie',
                'winnerLabel' => null,
                'loserLabel' => null,
                'savings' => 0,
                'savingsPercent' => 0,
                'costA' => round($costA, 0),
                'costB' => round($costB, 0),
            ];
        }
    }

    /**
     * Get the latest price info for a contract.
     */
    public function getContractPriceInfo(?ElectricityContract $contract): array
    {
        if (!$contract) {
            return [];
        }

        $prices = [];

        foreach ($contract->priceComponents->sortByDesc('price_date')->groupBy('price_component_type') as $type => $components) {
            $latest = $components->sortByDesc('price_date')->first(fn ($item) => $item->price > 0) ?? $components->sortByDesc('price_date')->first();
            $prices[$type] = [
                'price' => $latest->price,
                'unit' => $latest->payment_unit,
            ];
        }

        return $prices;
    }

    /**
     * Get display price for a contract.
     */
    public function getDisplayPrice(?ElectricityContract $contract): array
    {
        if (!$contract) {
            return ['type' => 'none', 'value' => null, 'unit' => null];
        }

        $priceInfo = $this->getContractPriceInfo($contract);

        // Monthly fee
        $monthlyFee = $priceInfo['Monthly']['price'] ?? null;

        // For spot contracts, show margin
        if ($contract->pricing_model === 'Spot') {
            $margin = $priceInfo['General']['price'] ?? null;

            return [
                'type' => 'spot',
                'margin' => $margin,
                'monthlyFee' => $monthlyFee,
            ];
        }

        // For fixed price contracts, show general rate or day/night rates
        $generalRate = $priceInfo['General']['price'] ?? null;
        $dayRate = $priceInfo['DayTime']['price'] ?? null;
        $nightRate = $priceInfo['NightTime']['price'] ?? null;

        return [
            'type' => 'fixed',
            'generalRate' => $generalRate,
            'dayRate' => $dayRate,
            'nightRate' => $nightRate,
            'monthlyFee' => $monthlyFee,
        ];
    }

    public function placeholder(): string
    {
        return <<<'HTML'
            <div class="rounded-2xl border border-slate-200 bg-white p-6 animate-pulse" aria-label="Ladataan vertailulaskuria">
                <div class="h-6 w-56 rounded bg-slate-200"></div>
                <div class="mt-6 grid gap-4 sm:grid-cols-2">
                    <div class="h-32 rounded-xl bg-slate-100"></div>
                    <div class="h-32 rounded-xl bg-slate-100"></div>
                </div>
                <div class="mt-6 h-56 rounded-xl bg-slate-100"></div>
            </div>
        HTML;
    }

    public function render()
    {
        return view('livewire.contract-type-comparison', [
            'modeConfig' => $this->modeConfig,
            'modeTabLabels' => $this->modeTabLabels,
            'contractA' => $this->contractA,
            'contractB' => $this->contractB,
            'contractSearchResultsA' => $this->contractSearchResultsA,
            'contractSearchResultsB' => $this->contractSearchResultsB,
            'projectedCostsA' => $this->projectedCostsA,
            'projectedCostsB' => $this->projectedCostsB,
            'comparisonResult' => $this->comparisonResult,
            'monthlySpotPrices' => $this->getMonthlySpotPricesLastYear(),
        ]);
    }
}
