<?php

namespace App\Livewire;

use App\Enums\BuildingEnergyRating;
use App\Enums\BuildingRegion;
use App\Enums\BuildingType;
use App\Enums\HeatingMethod;
use App\Enums\SupplementaryHeatingMethod;
use App\Models\ElectricityContract;
use App\Models\Postcode;
use App\Models\PriceComponent;
use App\Models\SpotPriceAverage;
use App\Services\BillComparison\BillComparisonService;
use App\Services\Caching\ContractPageCacheVersion;
use App\Services\CO2EmissionsCalculator;
use App\Services\ContractListCacheService;
use App\Services\ContractMarketInsights\ContractMarketInsightService;
use App\Services\ContractPriceCalculator;
use App\Services\DTO\BillComparisonRequest;
use App\Services\DTO\EnergyCalculatorRequest;
use App\Services\DTO\EnergyUsage;
use App\Services\EnergyCalculator;
use Carbon\Carbon;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

class ContractsList extends Component
{
    use WithPagination;

    /**
     * Number of contracts to show per page.
     */
    public int $perPage = 25;

    /**
     * Current page number (URL-bound for SEO).
     */
    #[Url]
    public int|string $page = 1;

    /**
     * Base path for filter links (used for SEO crawlable links).
     */
    public string $basePath = '/';

    /**
     * Cache for all filtered contracts (before pagination).
     * Used for metrics that need to count all contracts, not just current page.
     */
    protected ?\Illuminate\Support\Collection $allFilteredContractsCache = null;

    /**
     * Per-request memoized paginator so render helpers do not rebuild the same list repeatedly.
     */
    protected ?LengthAwarePaginator $contractsCache = null;

    /**
     * Active tab for consumption selection ('presets' or 'calculator').
     */
    public string $activeTab = 'presets';

    /**
     * Currently selected preset key.
     */
    public ?string $selectedPreset = 'large_apartment';

    /**
     * Current consumption value in kWh.
     */
    #[Url]
    public int $consumption = 5000;

    /**
     * Free-text annual consumption input (kWh/v) for visitors who know their
     * own consumption. String/null tolerant for mobile blank states. Mirrors
     * $consumption for display; editing it sets $consumption and clears the
     * active preset (see updatedDirectConsumption()). Seeded in booted().
     */
    public int|string|null $directConsumption = null;

    /**
     * Available consumption presets matching ConsumptionCalculator.
     *
     * @var array<string, array{label: string, description: string, icon: string, consumption: int}>
     */
    public array $presets = [
        'small_apartment' => [
            'label' => 'Pieni yksiö',
            'description' => '1 hlö, 35 m²',
            'icon' => 'apartment',
            'consumption' => 2000,
        ],
        'large_apartment' => [
            'label' => 'Kerrostalo perhe',
            'description' => '4 hlö, 80 m²',
            'icon' => 'apartment',
            'consumption' => 5000,
        ],
        'row_house' => [
            'label' => 'Rivitalo',
            'description' => '4 hlö, 100 m²',
            'icon' => 'house',
            'consumption' => 10000,
        ],
        'large_house_electric' => [
            'label' => 'Suuri talo + sähkö',
            'description' => 'Suora sähkölämmitys',
            'icon' => 'house',
            'consumption' => 18000,
        ],
    ];

    // ===== Bill comparison ("Maksatko liikaa") in-listing mode =====

    /**
     * Whether the optional bill-comparison entry is shown on this listing.
     * Off by default; SahkosopimusIndex turns it on so /sahkosopimus proves the
     * feature first. This is the single rollout switch for other listing pages.
     */
    public bool $showBillComparison = false;

    /**
     * Whether a valid bill has been entered and the listing is in period mode
     * (cards show the bill-period cost + savings vs the user's bill instead of
     * the annual estimate). Interactive state only; never URL-bound, so a fresh
     * GET always starts with this off and the cached default listing is unaffected.
     */
    public bool $billActive = false;

    /**
     * Bill period preset: last 3 completed calendar months plus 'custom'.
     */
    public string $billPeriodPreset = 'last_month';

    public string $billStartDate = '';

    public string $billEndDate = '';

    /**
     * Bill inputs are string|null tolerant because mobile browsers can send
     * blank values while a number field is being cleared.
     */
    public float|string|null $billKwh = null;

    public float|string|null $billTotalEur = null;

    public bool $billIncludesVat = true;

    public bool $billIncludesHeating = false;

    /**
     * Finnish month labels for the period preset chips, recomputed each request.
     *
     * @var array<string, string>
     */
    public array $billPresetLabels = [];

    /**
     * Per-request summary for the current-contract anchor (rank, monthly cost,
     * cheapest saving). Populated by buildBillModePaginator() during render.
     */
    protected ?array $billSummaryCache = null;

    // ===== Inline Calculator Fields =====

    /**
     * Living area in square meters.
     */
    public int $calcLivingArea = 80;

    /**
     * Number of people in household.
     */
    public int $calcNumPeople = 2;

    /**
     * Building type.
     */
    public string $calcBuildingType = 'apartment';

    /**
     * Whether to include heating in calculation.
     */
    public bool $calcIncludeHeating = false;

    /**
     * Heating method (only used when calcIncludeHeating is true).
     */
    public string $calcHeatingMethod = 'electricity';

    /**
     * Building region (only used when calcIncludeHeating is true).
     */
    public string $calcBuildingRegion = 'south';

    /**
     * Building energy efficiency rating (only used when calcIncludeHeating is true).
     */
    public string $calcBuildingEnergyEfficiency = '2000';

    /**
     * Supplementary heating method (only used when calcIncludeHeating is true).
     */
    public ?string $calcSupplementaryHeating = null;

    // ===== Extras Toggles =====

    /**
     * Whether underfloor/bathroom heating is enabled.
     */
    public bool $calcUnderfloorHeatingEnabled = false;

    /**
     * Whether sauna consumption is enabled.
     */
    public bool $calcSaunaEnabled = false;

    /**
     * Whether electric vehicle consumption is enabled.
     */
    public bool $calcElectricVehicleEnabled = false;

    /**
     * Whether cooling/air conditioning is enabled.
     */
    public bool $calcCooling = false;

    // ===== Extra Input Fields =====

    /**
     * Bathroom/underfloor heating area in square meters.
     */
    public int $calcBathroomHeatingArea = 0;

    /**
     * Sauna usage per week (number of sessions).
     */
    public int $calcSaunaUsagePerWeek = 0;

    /**
     * Electric vehicle kilometers driven per week.
     */
    public int $calcElectricVehicleKmsPerWeek = 0;

    /**
     * Available building types with labels.
     *
     * @var array<string, string>
     */
    public array $buildingTypes = [
        'apartment' => 'Kerrostalo',
        'row_house' => 'Rivitalo',
        'detached_house' => 'Omakotitalo',
    ];

    /**
     * Available heating methods with labels.
     *
     * @var array<string, string>
     */
    public array $heatingMethods = [
        'electricity' => 'Suora sähkölämmitys',
        'air_to_water_heat_pump' => 'Ilma-vesilämpöpumppu',
        'ground_heat_pump' => 'Maalämpö',
        'district_heating' => 'Kaukolämpö',
        'oil' => 'Öljylämmitys',
        'pellets' => 'Pelletti',
        'other' => 'Muu',
    ];

    /**
     * Available building regions with labels.
     *
     * @var array<string, string>
     */
    public array $buildingRegions = [
        'south' => 'Etelä-Suomi',
        'central' => 'Keski-Suomi',
        'north' => 'Pohjois-Suomi',
    ];

    /**
     * Available building energy efficiency ratings with labels.
     *
     * @var array<string, string>
     */
    public array $energyRatings = [
        'passive' => 'Passiivitalo',
        'low_energy' => 'Matalaenergia',
        '2010' => '2010-luku',
        '2000' => '2000-luku',
        '1990' => '1990-luku',
        '1980' => '1980-luku',
        '1970' => '1970-luku',
        '1960' => '1960-luku',
        'older' => 'Vanhempi',
    ];

    /**
     * Available supplementary heating methods with labels.
     *
     * @var array<string, string>
     */
    public array $supplementaryHeatingMethods = [
        'heat_pump' => 'Ilmalämpöpumppu',
        'exhaust_air_heat_pump' => 'Poistoilmalämpöpumppu',
        'fireplace' => 'Takka / puulämmitys',
    ];

    /**
     * Contract type filter (FixedTerm, OpenEnded).
     */
    #[Url]
    public string $contractTypeFilter = '';

    /**
     * Pricing model filter (Spot, FixedPrice, Hybrid).
     */
    #[Url]
    public string $pricingModelFilter = '';

    /**
     * Metering type filter (General, Time, Seasonal).
     */
    #[Url]
    public string $meteringFilter = '';

    /**
     * Postcode filter for availability.
     */
    #[Url]
    public string $postcodeFilter = '';

    /**
     * Postcode search input for suggestions.
     */
    public string $postcodeSearch = '';

    /**
     * Filter for renewable energy (>= 50%).
     */
    #[Url]
    public bool $renewableFilter = false;

    /**
     * Filter for contracts with nuclear energy.
     */
    #[Url]
    public bool $nuclearFilter = false;

    /**
     * Filter for fossil-free contracts.
     */
    #[Url]
    public bool $fossilFreeFilter = false;

    /**
     * Available contract types (duration).
     *
     * @var array<string, string>
     */
    public array $contractTypes = [
        'FixedTerm' => 'Määräaikainen',
        'OpenEnded' => 'Toistaiseksi',
    ];

    /**
     * Available pricing models.
     *
     * @var array<string, string>
     */
    public array $pricingModels = [
        'FixedPrice' => 'Kiinteä hinta',
        'Spot' => 'Pörssisähkö',
        'Hybrid' => 'Hybridi',
        'Quarterly' => 'Kvartaalisähkö',
        'TimeOfUse' => 'Aikasähkö',
        'Seasonal' => 'Kausisähkö',
    ];

    /**
     * Available metering types.
     *
     * @var array<string, string>
     */
    public array $meteringTypes = [
        'General' => 'Yleismittarointi',
        'Time' => 'Aikamittarointi',
        'Season' => 'Kausimittarointi',
    ];

    /**
     * Set the active tab.
     */
    public function setActiveTab(string $tab): void
    {
        $this->activeTab = $tab;
    }

    /**
     * Select a preset and update consumption.
     */
    public function selectPreset(string $preset): void
    {
        $this->selectedPreset = $preset;

        if (isset($this->presets[$preset])) {
            $this->consumption = $this->presets[$preset]['consumption'];
            // Clear the free-text field so it shows only its placeholder while a
            // preset is the active choice (no contradictory second value).
            $this->directConsumption = null;
            $this->resetPage();

            // Track preset change
            $this->dispatch('track',
                eventName: 'Contracts Preset Changed',
                props: [
                    'preset' => $preset,
                    'consumption' => $this->presets[$preset]['consumption'],
                ]
            );
        }
    }

    /**
     * Set the consumption to a specific value (clears preset selection).
     */
    public function setConsumption(int $value): void
    {
        $this->consumption = $value;
        $this->directConsumption = $value;
        $this->selectedPreset = null;
        $this->resetPage();
    }

    /**
     * Apply the free-text consumption input. Tolerant of blank/partial values
     * while typing (mobile sends empty strings); only a positive number is
     * applied, and it clears the active preset so the direct value wins.
     */
    public function updatedDirectConsumption($value): void
    {
        if (! is_numeric($value)) {
            return;
        }

        $clean = max(0, (int) $value);
        if ($clean <= 0) {
            return;
        }

        $this->consumption = $clean;
        $this->selectedPreset = null;
        $this->resetPage();
    }

    /**
     * Read the selected consumption defensively.
     *
     * Some stale Livewire snapshots can reach inherited listing components
     * without the URL-bound consumption property hydrated. Using isset avoids
     * triggering Livewire's __get PropertyNotFoundException and restores the
     * default before price calculations continue.
     */
    protected function selectedConsumptionValue(): int
    {
        if (! isset($this->consumption) || ! is_numeric($this->consumption)) {
            $this->consumption = 5000;
        }

        return max(0, (int) $this->consumption);
    }

    // ===== Bill comparison ("Maksatko liikaa") in-listing mode =====

    /**
     * Lifecycle hook (runs every request after mount/hydrate). Keeps the period
     * preset labels current and seeds default dates for the bill entry form.
     */
    public function booted(): void
    {
        // Seed the free-text consumption input only when consumption is custom
        // (no preset selected), so the field reflects a typed value but stays
        // empty (placeholder) whenever a preset card is the active choice.
        if ($this->selectedPreset === null
            && ($this->directConsumption === null || $this->directConsumption === '')) {
            $this->directConsumption = $this->selectedConsumptionValue();
        }

        if (! $this->showBillComparison) {
            return;
        }

        $this->billPresetLabels = $this->computeBillPresetLabels();

        if ($this->billStartDate === '' || $this->billEndDate === '') {
            $this->applyBillPreset();
        }
    }

    public function setBillPeriodPreset(string $key): void
    {
        $this->billPeriodPreset = $key;

        if ($key !== 'custom') {
            $this->applyBillPreset();
        }

        $this->recomputeBill();
    }

    public function updatedBillStartDate(): void
    {
        $this->billPeriodPreset = 'custom';
        $this->recomputeBill();
    }

    public function updatedBillEndDate(): void
    {
        $this->billPeriodPreset = 'custom';
        $this->recomputeBill();
    }

    public function updatedBillKwh(): void
    {
        $this->recomputeBill();
    }

    public function updatedBillTotalEur(): void
    {
        $this->recomputeBill();
    }

    public function updatedBillIncludesVat(): void
    {
        $this->recomputeBill();
    }

    public function updatedBillIncludesHeating(): void
    {
        $this->recomputeBill();
    }

    /**
     * Clear the bill and return the listing to its normal annual-cost view.
     */
    public function clearBill(): void
    {
        $this->billActive = false;
        $this->billKwh = null;
        $this->billTotalEur = null;
        $this->billSummaryCache = null;
        $this->contractsCache = null;
        $this->allFilteredContractsCache = null;
        $this->resetPage();
    }

    /**
     * Recompute bill-mode state after any bill input change and drop the cached
     * paginator/metrics so the next render rebuilds in the correct mode.
     */
    protected function recomputeBill(): void
    {
        $this->billActive = $this->isBillInputValid();
        $this->contractsCache = null;
        $this->allFilteredContractsCache = null;
        $this->billSummaryCache = null;
        $this->resetPage();
    }

    protected function isBillInputValid(): bool
    {
        return $this->showBillComparison
            && $this->billKwhValue() > 0
            && $this->billTotalValue() > 0
            && $this->billDatesValid();
    }

    /**
     * Whether the listing should render in bill (period) mode this request.
     */
    public function isBillModeActive(): bool
    {
        return $this->billActive && $this->isBillInputValid();
    }

    public function getBillSummaryProperty(): ?array
    {
        return $this->billSummaryCache;
    }

    protected function billFloat(mixed $value): ?float
    {
        return is_numeric($value) ? (float) $value : null;
    }

    protected function billKwhValue(): float
    {
        $value = $this->billFloat($this->billKwh);

        return ($value !== null && $value > 0) ? $value : 0.0;
    }

    protected function billTotalValue(): float
    {
        $value = $this->billFloat($this->billTotalEur);

        return ($value !== null && $value > 0) ? $value : 0.0;
    }

    protected function billParseDate(string $value): ?Carbon
    {
        if ($value === '') {
            return null;
        }

        try {
            return Carbon::parse($value, 'Europe/Helsinki');
        } catch (\Throwable) {
            return null;
        }
    }

    protected function billDatesValid(): bool
    {
        $start = $this->billParseDate($this->billStartDate);
        $end = $this->billParseDate($this->billEndDate);

        return $start !== null && $end !== null && $end >= $start;
    }

    protected function buildBillRequest(): BillComparisonRequest
    {
        $total = $this->billTotalValue();

        // Normalize to Voltikka's comparable basis: energy only, incl. ALV 25.5 %.
        $comparable = $this->billIncludesVat ? $total : ($total * BillComparison::VAT_MULTIPLIER);

        return new BillComparisonRequest(
            startDate: Carbon::parse($this->billStartDate, 'Europe/Helsinki'),
            endDate: Carbon::parse($this->billEndDate, 'Europe/Helsinki'),
            kwh: $this->billKwhValue(),
            userTotalEur: $comparable,
            includesHeating: $this->billIncludesHeating,
            annualKwhOverride: null,
        );
    }

    /**
     * Period presets cover only fully completed billing months (the current
     * unbilled month is excluded), matching the standalone /maksatko-liikaa tool.
     */
    protected function applyBillPreset(): void
    {
        $today = Carbon::today('Europe/Helsinki');

        $start = match ($this->billPeriodPreset) {
            'last_month' => $today->copy()->subMonthNoOverflow()->startOfMonth(),
            'month_before' => $today->copy()->subMonthsNoOverflow(2)->startOfMonth(),
            'two_months_before' => $today->copy()->subMonthsNoOverflow(3)->startOfMonth(),
            default => null,
        };

        if ($start === null) {
            return; // custom — keep user-entered dates
        }

        $this->billStartDate = $start->toDateString();
        $this->billEndDate = $start->copy()->endOfMonth()->toDateString();
    }

    /**
     * @return array<string, string>
     */
    protected function computeBillPresetLabels(): array
    {
        $today = Carbon::today('Europe/Helsinki');

        return [
            'last_month' => $this->billMonthLabel($today->copy()->subMonthNoOverflow()->startOfMonth()),
            'month_before' => $this->billMonthLabel($today->copy()->subMonthsNoOverflow(2)->startOfMonth()),
            'two_months_before' => $this->billMonthLabel($today->copy()->subMonthsNoOverflow(3)->startOfMonth()),
            'custom' => 'Muu jakso',
        ];
    }

    protected function billMonthLabel(Carbon $date): string
    {
        $fiMonths = [
            1 => 'tammikuu', 2 => 'helmikuu', 3 => 'maaliskuu', 4 => 'huhtikuu',
            5 => 'toukokuu', 6 => 'kesäkuu', 7 => 'heinäkuu', 8 => 'elokuu',
            9 => 'syyskuu', 10 => 'lokakuu', 11 => 'marraskuu', 12 => 'joulukuu',
        ];

        return ucfirst($fiMonths[(int) $date->format('n')]).' '.$date->format('Y');
    }

    /**
     * Build the period-mode paginator: every contract priced for the user's
     * exact billing period + kWh (reusing BillComparisonService), sorted by
     * period cost, with the user's bill anchored into the ranking. Attaches a
     * `period_comparison` payload to each visible contract for the card.
     */
    protected function buildBillModePaginator(Collection $contracts): LengthAwarePaginator
    {
        $request = $this->buildBillRequest();
        $data = app(BillComparisonService::class)->periodRowsForContracts($contracts, $request);

        /** @var array<int, \App\Services\DTO\BillComparisonRow> $rows */
        $rows = $data['rows'];

        // Only contracts with an available period row participate (spot without
        // history for the period, or no usable pricing, are omitted).
        $available = $contracts->filter(fn ($contract) => isset($rows[$contract->id]))->values();
        $sorted = $available->sortBy(fn ($contract) => $rows[$contract->id]->periodCostEur)->values();

        $userTotal = (float) $data['user_period_cost'];
        $periodMonths = max(0.0001, (float) $data['period_months']);

        $cheaperThanUser = $sorted->filter(fn ($contract) => $rows[$contract->id]->periodCostEur < $userTotal)->count();
        $cheapest = $sorted->first();
        $cheapestPeriodCost = $cheapest ? $rows[$cheapest->id]->periodCostEur : null;
        $cheapestSaving = $cheapestPeriodCost !== null ? ($userTotal - $cheapestPeriodCost) : 0.0;

        $this->billSummaryCache = [
            'user_period_cost' => $userTotal,
            'user_monthly_cost' => $userTotal / $periodMonths,
            'user_rank' => $cheaperThanUser + 1,
            'total_ranked' => $sorted->count() + 1,
            'cheapest_saving' => $cheapestSaving,
            'cheapest_monthly_saving' => $cheapestSaving / $periodMonths,
            'period_months' => $periodMonths,
            'annual_kwh' => $data['annual_kwh'],
            'spot_avg_cents_per_kwh' => $data['spot_avg_cents_per_kwh'],
            'has_spot_in_top' => $sorted->take(3)->contains(fn ($contract) => $rows[$contract->id]->isSpot),
            'is_overpaying' => $cheaperThanUser > 0 && $cheapestSaving > 0,
        ];

        $this->allFilteredContractsCache = $sorted;

        $total = $sorted->count();
        $page = max(1, $this->page);
        $perPage = $this->perPage;
        $offset = ($page - 1) * $perPage;

        $items = $this->loadVisibleContracts($sorted, $offset, $perPage);

        $emissionsCalculator = app(CO2EmissionsCalculator::class);

        $items = $items->map(function (ElectricityContract $contract) use ($rows, $userTotal, $periodMonths, $emissionsCalculator) {
            $row = $rows[$contract->id] ?? null;

            if ($row !== null) {
                $periodCost = $row->periodCostEur;
                $saving = $userTotal - $periodCost;
                $contract->period_comparison = [
                    'period_cost' => $periodCost,
                    'period_monthly_cost' => $periodCost / $periodMonths,
                    'period_saving' => $saving,
                    'period_monthly_saving' => $saving / $periodMonths,
                    'period_months' => $periodMonths,
                    'is_spot' => $row->isSpot,
                ];
            }

            // loadVisibleContracts copies annual metrics from summaries that
            // never received them in bill mode; recompute the emission factor so
            // the card's CO2 stripe stays correct.
            $contract->emission_factor = $emissionsCalculator->calculateEmissionFactor($contract->electricitySource);
            $contract->exceeds_consumption_limit = false;

            return $contract;
        })->values();

        return new \Illuminate\Pagination\LengthAwarePaginator(
            $items,
            $total,
            $perPage,
            $page,
            [
                'path' => url($this->basePath),
                'pageName' => 'page',
            ]
        );
    }

    /**
     * Calculate consumption from inline calculator and update.
     */
    public function calculateFromInlineCalculator(): void
    {
        $calculator = app(EnergyCalculator::class);

        $includeHeating = $this->calculatorBoolValue('calcIncludeHeating', false);
        $underfloorHeatingEnabled = $this->calculatorBoolValue('calcUnderfloorHeatingEnabled', false);
        $saunaEnabled = $this->calculatorBoolValue('calcSaunaEnabled', false);
        $electricVehicleEnabled = $this->calculatorBoolValue('calcElectricVehicleEnabled', false);

        $buildingType = BuildingType::tryFrom($this->calculatorStringValue('calcBuildingType', BuildingType::Apartment->value))
            ?? BuildingType::Apartment;
        $heatingMethod = HeatingMethod::tryFrom($this->calculatorStringValue('calcHeatingMethod', HeatingMethod::Electricity->value))
            ?? HeatingMethod::Electricity;
        $supplementaryHeating = SupplementaryHeatingMethod::tryFrom($this->calculatorStringValue('calcSupplementaryHeating', ''));
        $buildingEnergyEfficiency = BuildingEnergyRating::tryFrom($this->calculatorStringValue('calcBuildingEnergyEfficiency', BuildingEnergyRating::Year2000->value))
            ?? BuildingEnergyRating::Year2000;
        $buildingRegion = BuildingRegion::tryFrom($this->calculatorStringValue('calcBuildingRegion', BuildingRegion::South->value))
            ?? BuildingRegion::South;

        // Convert weekly EV kms to monthly (roughly 4.33 weeks per month)
        $evKmsPerMonth = (int) round($this->calculatorIntValue('calcElectricVehicleKmsPerWeek', 0) * 4.33);

        $request = new EnergyCalculatorRequest(
            livingArea: max(10, $this->calculatorIntValue('calcLivingArea', 80)),
            numPeople: max(1, $this->calculatorIntValue('calcNumPeople', 2)),
            buildingType: $buildingType,
            heatingMethod: $includeHeating ? $heatingMethod : null,
            supplementaryHeating: $includeHeating ? $supplementaryHeating : null,
            buildingEnergyEfficiency: $includeHeating ? $buildingEnergyEfficiency : null,
            buildingRegion: $includeHeating ? $buildingRegion : null,
            electricVehicleKmsPerMonth: $electricVehicleEnabled ? $evKmsPerMonth : 0,
            bathroomHeatingArea: $underfloorHeatingEnabled ? $this->calculatorIntValue('calcBathroomHeatingArea', 0) : 0,
            saunaUsagePerWeek: $saunaEnabled ? $this->calculatorIntValue('calcSaunaUsagePerWeek', 0) : 0,
            externalHeating: !$includeHeating,
            externalHeatingWater: !$includeHeating,
            cooling: $this->calculatorBoolValue('calcCooling', false),
        );

        $result = $calculator->estimate($request);
        $this->consumption = $result->total;
        $this->directConsumption = $result->total;
        $this->selectedPreset = null;
        $this->resetPage();
    }

    /**
     * Safely read calculator values from Livewire state.
     *
     * This keeps stale or partially hydrated Livewire updates from crashing SEO
     * listing pages when a browser sends calculator fields while a component
     * class/release no longer exposes exactly the same public property set.
     */
    protected function calculatorRawValue(string $property, mixed $default): mixed
    {
        if (! property_exists($this, $property)) {
            return $default;
        }

        return $this->{$property} ?? $default;
    }

    protected function calculatorIntValue(string $property, int $default): int
    {
        $value = $this->calculatorRawValue($property, $default);

        if ($value === '' || $value === null || $value === false) {
            return $default;
        }

        return (int) $value;
    }

    protected function calculatorBoolValue(string $property, bool $default): bool
    {
        $value = $this->calculatorRawValue($property, $default);

        return filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? $default;
    }

    protected function calculatorStringValue(string $property, string $default): string
    {
        $value = $this->calculatorRawValue($property, $default);

        if ($value === '' || $value === null) {
            return $default;
        }

        return (string) $value;
    }

    /**
     * Hook called when any calculator field is updated.
     */
    public function updatedCalcLivingArea(): void
    {
        if ($this->activeTab === 'calculator') {
            $this->calculateFromInlineCalculator();
        }
    }

    public function updatedCalcNumPeople(): void
    {
        if ($this->activeTab === 'calculator') {
            $this->calculateFromInlineCalculator();
        }
    }

    public function updatedCalcBuildingType(): void
    {
        if ($this->activeTab === 'calculator') {
            $this->calculateFromInlineCalculator();
        }
    }

    public function updatedCalcIncludeHeating(): void
    {
        if ($this->activeTab === 'calculator') {
            $this->calculateFromInlineCalculator();
        }
    }

    public function updatedCalcHeatingMethod(): void
    {
        if ($this->activeTab === 'calculator') {
            $this->calculateFromInlineCalculator();
        }
    }

    public function updatedCalcBuildingRegion(): void
    {
        if ($this->activeTab === 'calculator') {
            $this->calculateFromInlineCalculator();
        }
    }

    public function updatedCalcBuildingEnergyEfficiency(): void
    {
        if ($this->activeTab === 'calculator') {
            $this->calculateFromInlineCalculator();
        }
    }

    public function updatedCalcSupplementaryHeating(): void
    {
        if ($this->activeTab === 'calculator') {
            $this->calculateFromInlineCalculator();
        }
    }

    public function updatedCalcUnderfloorHeatingEnabled(): void
    {
        if ($this->activeTab === 'calculator') {
            $this->calculateFromInlineCalculator();
        }
    }

    public function updatedCalcSaunaEnabled(): void
    {
        if ($this->activeTab === 'calculator') {
            $this->calculateFromInlineCalculator();
        }
    }

    public function updatedCalcElectricVehicleEnabled(): void
    {
        if ($this->activeTab === 'calculator') {
            $this->calculateFromInlineCalculator();
        }
    }

    public function updatedCalcCooling(): void
    {
        if ($this->activeTab === 'calculator') {
            $this->calculateFromInlineCalculator();
        }
    }

    public function updatedCalcBathroomHeatingArea(): void
    {
        if ($this->activeTab === 'calculator') {
            $this->calculateFromInlineCalculator();
        }
    }

    public function updatedCalcSaunaUsagePerWeek(): void
    {
        if ($this->activeTab === 'calculator') {
            $this->calculateFromInlineCalculator();
        }
    }

    public function updatedCalcElectricVehicleKmsPerWeek(): void
    {
        if ($this->activeTab === 'calculator') {
            $this->calculateFromInlineCalculator();
        }
    }

    /**
     * Select a building type (for clickable cards).
     */
    public function selectBuildingType(string $type): void
    {
        $this->calcBuildingType = $type;
        $this->selectedPreset = null;
        if ($this->activeTab === 'calculator') {
            $this->calculateFromInlineCalculator();
        }
    }

    /**
     * Toggle an extra option (underfloor heating, sauna, EV, cooling).
     */
    public function toggleExtra(string $extra): void
    {
        switch ($extra) {
            case 'underfloor':
                $this->calcUnderfloorHeatingEnabled = !$this->calcUnderfloorHeatingEnabled;
                if (!$this->calcUnderfloorHeatingEnabled) {
                    $this->calcBathroomHeatingArea = 0;
                }
                break;
            case 'sauna':
                $this->calcSaunaEnabled = !$this->calcSaunaEnabled;
                if (!$this->calcSaunaEnabled) {
                    $this->calcSaunaUsagePerWeek = 0;
                }
                break;
            case 'ev':
                $this->calcElectricVehicleEnabled = !$this->calcElectricVehicleEnabled;
                if (!$this->calcElectricVehicleEnabled) {
                    $this->calcElectricVehicleKmsPerWeek = 0;
                }
                break;
            case 'cooling':
                $this->calcCooling = !$this->calcCooling;
                break;
        }
        $this->selectedPreset = null;
        if ($this->activeTab === 'calculator') {
            $this->calculateFromInlineCalculator();
        }
    }

    /**
     * Set the contract type filter.
     */
    public function setContractTypeFilter(string $type): void
    {
        $newValue = $this->contractTypeFilter === $type ? '' : $type;
        $this->contractTypeFilter = $newValue;
        $this->resetPage();

        // Track filter change
        if ($newValue !== '') {
            $this->dispatch('track',
                eventName: 'Contracts Filter Applied',
                props: [
                    'filter_type' => 'contract_type',
                    'value' => $newValue,
                ]
            );
        }
    }

    /**
     * Set the pricing model filter.
     */
    public function setPricingModelFilter(string $model): void
    {
        $newValue = $this->pricingModelFilter === $model ? '' : $model;
        $this->pricingModelFilter = $newValue;
        $this->resetPage();

        // Track filter change
        if ($newValue !== '') {
            $this->dispatch('track',
                eventName: 'Contracts Filter Applied',
                props: [
                    'filter_type' => 'pricing_model',
                    'value' => $newValue,
                ]
            );
        }
    }

    /**
     * Set the metering type filter.
     */
    public function setMeteringFilter(string $type): void
    {
        $this->meteringFilter = $this->meteringFilter === $type ? '' : $type;
        $this->resetPage();
    }

    /**
     * Set the postcode filter and clear search.
     */
    public function selectPostcode(string $postcode): void
    {
        $this->postcodeFilter = $postcode;
        $this->postcodeSearch = '';
        $this->resetPage();
    }

    /**
     * Clear the postcode filter.
     */
    public function clearPostcodeFilter(): void
    {
        $this->postcodeFilter = '';
        $this->postcodeSearch = '';
        $this->resetPage();
    }

    /**
     * Toggle renewable filter.
     */
    public function toggleRenewableFilter(): void
    {
        $this->renewableFilter = !$this->renewableFilter;
        $this->resetPage();

        // Track filter change
        if ($this->renewableFilter) {
            $this->dispatch('track',
                eventName: 'Contracts Filter Applied',
                props: [
                    'filter_type' => 'energy_source',
                    'value' => 'renewable',
                ]
            );
        }
    }

    /**
     * Toggle nuclear filter.
     */
    public function toggleNuclearFilter(): void
    {
        $this->nuclearFilter = !$this->nuclearFilter;
        $this->resetPage();
    }

    /**
     * Toggle fossil-free filter.
     */
    public function toggleFossilFreeFilter(): void
    {
        $this->fossilFreeFilter = !$this->fossilFreeFilter;
        $this->resetPage();

        // Track filter change
        if ($this->fossilFreeFilter) {
            $this->dispatch('track',
                eventName: 'Contracts Filter Applied',
                props: [
                    'filter_type' => 'energy_source',
                    'value' => 'fossil_free',
                ]
            );
        }
    }

    /**
     * Reset all filters to their default values.
     */
    public function resetFilters(): void
    {
        $this->contractTypeFilter = '';
        $this->pricingModelFilter = '';
        $this->meteringFilter = '';
        $this->postcodeFilter = '';
        $this->postcodeSearch = '';
        $this->renewableFilter = false;
        $this->nuclearFilter = false;
        $this->fossilFreeFilter = false;
        $this->resetPage();
    }

    /**
     * Reset pagination to page 1.
     */
    public function resetPage(): void
    {
        $this->page = 1;
    }

    /**
     * Keep URL-bound pagination tolerant of empty/malformed query values.
     *
     * Livewire assigns query-string values before component mount. A request like
     * `?page=` therefore arrives as an empty string; normalize it before any SEO
     * pagination calculations and after interactive updates.
     */
    public function updatedPage(mixed $value): void
    {
        $this->normalizePageProperty();
        $this->contractsCache = null;
    }

    protected function normalizePageProperty(): void
    {
        $this->page = $this->currentPageNumber();
    }

    protected function currentPageNumber(): int
    {
        if (is_int($this->page)) {
            return max(1, $this->page);
        }

        $page = trim((string) $this->page);

        if ($page === '' || ! ctype_digit($page)) {
            return 1;
        }

        return max(1, (int) $page);
    }

    /**
     * Get postcode suggestions based on search input.
     */
    public function getPostcodeSuggestionsProperty(): Collection
    {
        if (strlen($this->postcodeSearch) < 2) {
            return new Collection();
        }

        return Postcode::query()
            ->search($this->postcodeSearch)
            ->limit(10)
            ->get();
    }

    /**
     * Check if any filters are active.
     */
    public function hasActiveFilters(): bool
    {
        return $this->contractTypeFilter !== ''
            || $this->pricingModelFilter !== ''
            || $this->meteringFilter !== ''
            || $this->postcodeFilter !== ''
            || $this->renewableFilter
            || $this->nuclearFilter
            || $this->fossilFreeFilter;
    }

    /**
     * Get all filtered contracts (ensures cache is populated).
     */
    protected function getAllFilteredContracts(): \Illuminate\Support\Collection
    {
        // Trigger the contracts property to populate the cache if needed
        if ($this->allFilteredContractsCache === null) {
            $this->contracts;
        }

        return $this->allFilteredContractsCache ?? collect();
    }

    /**
     * Get the count of unique companies in all filtered contracts.
     */
    public function getUniqueCompanyCount(): int
    {
        return $this->getAllFilteredContracts()->pluck('company_name')->unique()->count();
    }

    /**
     * Get the count of zero-emission contracts in all filtered contracts.
     */
    public function getZeroEmissionCount(): int
    {
        return $this->getAllFilteredContracts()->filter(function ($contract) {
            return $contract->electricitySource
                && ($contract->electricitySource->fossil_total ?? 0) == 0
                && (($contract->electricitySource->renewable_total ?? 0) > 0
                    || ($contract->electricitySource->nuclear_total ?? 0) > 0);
        })->count();
    }

    /**
     * Generate a dynamic page title based on active filters.
     *
     * The title follows Finnish grammar conventions:
     * - Base: "Sähkösopimukset"
     * - With pricing model: "Pörssisähkösopimukset", "Kiinteähintaiset sähkösopimukset"
     * - With contract type: "Määräaikaiset sähkösopimukset", "Toistaiseksi voimassa olevat sähkösopimukset"
     * - Combined: "Määräaikaiset pörssisähkösopimukset"
     * - Page suffix: "– Sivu N" when on page > 1
     */
    public function getPageTitleProperty(): string
    {
        $parts = [];

        // Energy source modifiers (come first as adjectives)
        $energySourcePrefix = '';
        if ($this->fossilFreeFilter) {
            $parts[] = 'Fossiilittomat';
        } elseif ($this->renewableFilter) {
            $parts[] = 'Uusiutuvat';
        } elseif ($this->nuclearFilter) {
            // Nuclear is a compound word prefix (ydinvoima-)
            $energySourcePrefix = 'ydinvoima';
        }

        // Contract type modifier
        if ($this->contractTypeFilter === 'FixedTerm') {
            $parts[] = 'Määräaikaiset';
        } elseif ($this->contractTypeFilter === 'OpenEnded') {
            $parts[] = 'Toistaiseksi voimassa olevat';
        }

        // Pricing model determines the base noun
        if ($this->pricingModelFilter === 'Spot') {
            $parts[] = $energySourcePrefix . 'pörssisähkösopimukset';
        } elseif ($this->pricingModelFilter === 'FixedPrice') {
            $parts[] = $energySourcePrefix . 'kiinteähintaiset sähkösopimukset';
        } elseif ($this->pricingModelFilter === 'Hybrid') {
            $parts[] = $energySourcePrefix . 'hybridisähkösopimukset';
        } elseif ($this->pricingModelFilter === 'Quarterly') {
            $parts[] = $energySourcePrefix . 'kvartaalisähkösopimukset';
        } elseif ($this->pricingModelFilter === 'TimeOfUse') {
            $parts[] = $energySourcePrefix . 'aikasähkösopimukset';
        } elseif ($this->pricingModelFilter === 'Seasonal') {
            $parts[] = $energySourcePrefix . 'kausisähkösopimukset';
        } else {
            $parts[] = $energySourcePrefix . 'sähkösopimukset';
        }

        // Combine parts and capitalize first letter
        $title = implode(' ', $parts);
        $title = mb_strtoupper(mb_substr($title, 0, 1)) . mb_substr($title, 1);

        // Add page suffix for pages > 1
        if ($this->page > 1) {
            $lastPage = $this->contracts->lastPage();
            $title .= " – Sivu {$this->page}/{$lastPage}";
        }

        return $title;
    }

    /**
     * Generate a dynamic meta description based on active filters.
     *
     * The description is tailored to the selected filters for better SEO.
     */
    public function getMetaDescriptionProperty(): string
    {
        $baseDescription = 'Vertaile sähkösopimuksia ja löydä edullisin vaihtoehto.';

        if ($this->pricingModelFilter === 'Spot') {
            return 'Vertaile pörssisähkösopimuksia ja löydä edullisin vaihtoehto. Katso marginaalit, perusmaksut ja arvioidut vuosikustannukset.';
        }

        if ($this->pricingModelFilter === 'FixedPrice') {
            return 'Vertaile kiinteähintaisia sähkösopimuksia ja löydä paras hinta. Kiinteä sähkön hinta takaa ennustettavat kustannukset.';
        }

        if ($this->pricingModelFilter === 'Hybrid') {
            return 'Vertaile hybridisähkösopimuksia, jotka yhdistävät kiinteän hinnan ja pörssisähkön edut.';
        }

        if ($this->pricingModelFilter === 'Quarterly') {
            return 'Vertaile kvartaalisähkösopimuksia. Kvartaalisähkössä hinta päivittyy neljä kertaa vuodessa. Löydä paras kvartaalisähkösopimus.';
        }

        if ($this->pricingModelFilter === 'TimeOfUse') {
            return 'Vertaile aikasähkösopimuksia. Aikasähkössä sähkön hinta vaihtelee vuorokaudenajan mukaan. Edullisempi yöhinta 22-07.';
        }

        if ($this->pricingModelFilter === 'Seasonal') {
            return 'Vertaile kausisähkösopimuksia. Kausisähkössä hinta vaihtelee vuodenajan mukaan. Talvella korkeampi, muulloin edullisempi.';
        }

        if ($this->contractTypeFilter === 'FixedTerm') {
            return 'Vertaile määräaikaisia sähkösopimuksia ja lukitse hinta haluamaksesi ajaksi.';
        }

        if ($this->contractTypeFilter === 'OpenEnded') {
            return 'Vertaile toistaiseksi voimassa olevia sähkösopimuksia. Joustava sopimus ilman sitoutumisaikaa.';
        }

        if ($this->renewableFilter) {
            return 'Vertaile uusiutuvalla energialla tuotettuja sähkösopimuksia. Vihreä sähkö tuuli- ja aurinkovoimasta.';
        }

        if ($this->fossilFreeFilter) {
            return 'Vertaile fossiilittomia sähkösopimuksia. Ilmastoystävällinen sähkö ilman hiilidioksidipäästöjä.';
        }

        if ($this->nuclearFilter) {
            return 'Vertaile ydinvoimalla tuotettuja sähkösopimuksia. Päästötön ja vakaa sähköntuotanto.';
        }

        return $baseDescription . ' Katso hinnat, sopimusehdot ja energialähteet yhdestä paikasta.';
    }

    /**
     * Get contracts with calculated costs.
     *
     * Memory optimization: We do NOT eager load availabilityPostcodes as that
     * relationship has 7000+ pivot records which causes memory exhaustion.
     * Instead, we filter by postcode at the database level using a subquery.
     *
     * Returns a paginated result for SEO-friendly pagination.
     */
    public function getContractsProperty(): LengthAwarePaginator
    {
        if ($this->contractsCache !== null) {
            return $this->contractsCache;
        }

        $query = ElectricityContract::query()
            ->active()
            ->with(['electricitySource'])
            // Filter for household contracts only (exclude company-only contracts)
            ->where(function ($q) {
                $q->whereIn('target_group', ['Household', 'Both'])
                  ->orWhereNull('target_group');
            });

        // Apply contract type filter (FixedTerm, OpenEnded)
        if ($this->contractTypeFilter !== '') {
            $query->where('contract_type', $this->contractTypeFilter);
        }

        // Apply pricing model filter (Spot, FixedPrice, Hybrid, Quarterly, TimeOfUse)
        if ($this->pricingModelFilter !== '') {
            if ($this->pricingModelFilter === 'Quarterly') {
                // Quarterly contracts are identified by name or description patterns
                $query->where(function ($q) {
                    $q->where('name', 'LIKE', '%kvartaali%')
                      ->orWhere('extra_information_fi', 'LIKE', '%kvartaali%')
                      ->orWhere('extra_information_fi', 'LIKE', '%kolmen kuukauden jaksoissa%')
                      ->orWhere('extra_information_fi', 'LIKE', '%kolmen kuukauden jaksolle%')
                      ->orWhere('extra_information_fi', 'LIKE', '%kolmen kuukauden välein%');
                });
            } elseif ($this->pricingModelFilter === 'TimeOfUse') {
                // Time-of-use (aikasähkö) contracts have day/night pricing
                $query->where(function ($q) {
                    $q->where('metering', 'Time')
                      ->orWhere('name', 'LIKE', '%aikasähkö%')
                      ->orWhere('name', 'LIKE', '%Aikasähkö%')
                      ->orWhere('extra_information_fi', 'LIKE', '%aikasähkö%');
                });
            } elseif ($this->pricingModelFilter === 'Seasonal') {
                // Seasonal (kausisähkö) contracts have seasonal pricing
                $query->where(function ($q) {
                    $q->where('metering', 'Season')
                      ->orWhere('name', 'LIKE', '%kausisähkö%')
                      ->orWhere('name', 'LIKE', '%Kausisähkö%')
                      ->orWhere('extra_information_fi', 'LIKE', '%kausisähkö%');
                });
            } else {
                $query->where('pricing_model', $this->pricingModelFilter);
            }
        }

        // Apply metering type filter
        if ($this->meteringFilter !== '') {
            $query->where('metering', $this->meteringFilter);
        }

        // Apply postcode filter at database level (memory optimization)
        // This avoids loading all pivot records into memory
        if ($this->postcodeFilter !== '') {
            $postcode = $this->postcodeFilter;
            $query->where(function ($q) use ($postcode) {
                $q->where('availability_is_national', true)
                  ->orWhereExists(function ($subquery) use ($postcode) {
                      $subquery->select(DB::raw(1))
                               ->from('contract_postcode')
                               ->whereColumn('contract_postcode.contract_id', 'electricity_contracts.id')
                               ->where('contract_postcode.postcode', $postcode);
                  });
            });
        }

        $contracts = $query->get();

        // Apply energy source filters
        if ($this->renewableFilter) {
            $contracts = $contracts->filter(function ($contract) {
                $source = $contract->electricitySource;
                return $source && $source->renewable_total >= 50;
            });
        }

        if ($this->nuclearFilter) {
            $contracts = $contracts->filter(function ($contract) {
                $source = $contract->electricitySource;
                return $source && $source->hasNuclear();
            });
        }

        if ($this->fossilFreeFilter) {
            $contracts = $contracts->filter(function ($contract) {
                $source = $contract->electricitySource;
                return $source && $source->isFossilFree();
            });
        }

        // Filter by consumption range - only show contracts where the selected
        // consumption falls within the contract's allowed range.
        //
        // Skipped in bill mode: there the relevant consumption is the bill's
        // annualized kWh, not this annual slider (set before the bill). Capped
        // flat-fee tiers are still excluded correctly by
        // BillComparisonService::fitsConsumptionLimits() on the bill-derived
        // annual consumption, so pre-filtering with the stale slider would only
        // wrongly drop/keep capped contracts on a mismatched basis.
        if (! $this->isBillModeActive()) {
            $consumption = $this->consumption;
            $contracts = $contracts->filter(function ($contract) use ($consumption) {
                return $contract->isConsumptionInRange($consumption);
            });
        }

        // Bill ("Maksatko liikaa") mode: price the filtered set for the user's
        // actual billing period and rank by period cost instead of annual cost.
        if ($this->isBillModeActive()) {
            return $this->contractsCache = $this->buildBillModePaginator($contracts);
        }

        $consumption = $this->consumption;
        $sorted = $this->applyCachedMetricsToContracts($contracts, $consumption);

        if ($sorted === null) {
            $calculator = app(ContractPriceCalculator::class);
            $emissionsCalculator = app(CO2EmissionsCalculator::class);

            // Do not eager load full price history here. Load only the latest
            // calculation components in bulk, avoiding both N+1 queries and
            // 50k+ historical price-component models in memory.

            // Get spot price averages for calculations
            $spotPriceAvg = SpotPriceAverage::latestRolling365Days();
            $spotPriceDay = $spotPriceAvg?->day_avg_with_tax;
            $spotPriceNight = $spotPriceAvg?->night_avg_with_tax;

            $priceComponentsByContractId = ElectricityContract::getLatestPriceComponentsForCalculationByContractIds(
                $contracts->pluck('id')
            );

            // Calculate cost and emissions for each contract and sort by cost
            $contracts = $contracts->map(function ($contract) use ($calculator, $emissionsCalculator, $spotPriceDay, $spotPriceNight, $consumption, $priceComponentsByContractId) {
                $priceComponents = $priceComponentsByContractId[$contract->id] ?? [];

                $usage = new EnergyUsage(
                    total: $consumption,
                    basicLiving: $consumption,
                );

                $contractData = [
                    'contract_type' => $contract->contract_type,
                    'pricing_model' => $contract->pricing_model,
                    'metering' => $contract->metering,
                ];

                $result = $calculator->calculate($priceComponents, $contractData, $usage, $spotPriceDay, $spotPriceNight);
                $contract->calculated_cost = $result->toArray();

                // Calculate emission factor for this contract
                $contract->emission_factor = $emissionsCalculator->calculateEmissionFactor($contract->electricitySource);

                // Mark contracts where consumption exceeds their limit
                $maxConsumption = $contract->consumption_limitation_max_x_kwh_per_y;
                $contract->exceeds_consumption_limit = $maxConsumption > 0 && $consumption > $maxConsumption;

                return $contract;
            });

            // Sort by total cost (ascending), but put contracts that exceed consumption limit at the end
            $sorted = $contracts->sort(function ($a, $b) {
                // First sort by exceeds_consumption_limit (false first, true last)
                $aExceeds = $a->exceeds_consumption_limit ? 1 : 0;
                $bExceeds = $b->exceeds_consumption_limit ? 1 : 0;
                if ($aExceeds !== $bExceeds) {
                    return $aExceeds - $bExceeds;
                }
                // Then sort by total cost (ascending)
                $aCost = $a->calculated_cost['total_cost'] ?? PHP_FLOAT_MAX;
                $bCost = $b->calculated_cost['total_cost'] ?? PHP_FLOAT_MAX;
                return $aCost <=> $bCost;
            })->values();
        }

        // Cache the full sorted collection for metrics
        $this->allFilteredContractsCache = $sorted;

        // Create a manual paginator from the sorted collection
        $total = $sorted->count();
        $page = max(1, $this->page);
        $perPage = $this->perPage;

        // Calculate offset and get the slice for current page
        $offset = ($page - 1) * $perPage;
        $items = $this->loadVisibleContracts($sorted, $offset, $perPage);

        // Create and return a LengthAwarePaginator
        return $this->contractsCache = new \Illuminate\Pagination\LengthAwarePaginator(
            $items,
            $total,
            $perPage,
            $page,
            [
                'path' => url($this->basePath),
                'pageName' => 'page',
            ]
        );
    }

    /**
     * Get the latest price components for a contract.
     */
    public function getLatestPrices(ElectricityContract $contract): array
    {
        $contract->loadMissing('priceComponents');

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
     * Precomputed percentile thresholds for pricing components.
     * @var array<string, array{p15: float, p85: float}>|null
     */
    protected ?array $percentileCache = null;

    /**
     * Get precomputed percentile thresholds for pricing component callouts.
     */
    public function getPercentiles(): array
    {
        if ($this->percentileCache !== null) {
            return $this->percentileCache;
        }

        $rows = \App\Models\ContractPercentile::all()->keyBy('component');

        $this->percentileCache = [];
        foreach ($rows as $component => $row) {
            $this->percentileCache[$component] = [
                'p15' => (float) $row->p15,
                'p85' => (float) $row->p85,
            ];
        }

        return $this->percentileCache;
    }

    /**
     * Apply precomputed contract metrics when the selected consumption matches a preset cache.
     */
    protected function applyCachedMetricsToContracts(Collection $contracts, int $consumption): ?Collection
    {
        $cached = app(ContractListCacheService::class)->getCachedMetrics($consumption);

        if ($cached === null) {
            return null;
        }

        $metricsById = $cached['contracts'] ?? [];
        $contractsById = $contracts->keyBy('id');

        if ($contractsById->keys()->diff(array_keys($metricsById))->isNotEmpty()) {
            return null;
        }

        $sortedContracts = [];

        foreach ($cached['sorted_ids'] as $contractId) {
            if (! $contractsById->has($contractId)) {
                continue;
            }

            $contract = $contractsById->get($contractId);
            $metrics = $metricsById[$contractId] ?? null;

            if ($metrics === null) {
                return null;
            }

            $this->applyCachedMetricsToContract($contract, $metrics);
            $sortedContracts[] = $contract;
        }

        return new Collection($sortedContracts);
    }

    protected function applyCachedMetricsToContract(ElectricityContract $contract, array $metrics): ElectricityContract
    {
        $contract->calculated_cost = $metrics['calculated_cost'];
        $contract->emission_factor = $metrics['emission_factor'];
        $contract->exceeds_consumption_limit = $metrics['exceeds_consumption_limit'];

        return $contract;
    }

    /**
     * Load only the contracts visible on the current page with their full relations.
     */
    protected function loadVisibleContracts(Collection $sorted, int $offset, int $perPage): \Illuminate\Support\Collection
    {
        $visibleSummaries = $sorted->slice($offset, $perPage)->values();
        $visibleIds = $visibleSummaries->pluck('id')->all();

        if (empty($visibleIds)) {
            return collect();
        }

        $contractsById = ElectricityContract::query()
            ->with(['company', 'electricitySource'])
            ->whereIn('id', $visibleIds)
            ->get()
            ->keyBy('id');

        $priceComponentsByContractId = ElectricityContract::getLatestPriceComponentsForCalculationByContractIds($visibleIds);

        return $visibleSummaries->map(function (ElectricityContract $summary) use ($contractsById, $priceComponentsByContractId) {
            /** @var ElectricityContract|null $contract */
            $contract = $contractsById->get($summary->id);

            if (! $contract) {
                return null;
            }

            $contract->setRelation('priceComponents', new Collection(
                array_map(fn (array $component) => new PriceComponent($component), $priceComponentsByContractId[$contract->id] ?? [])
            ));
            $contract->calculated_cost = $summary->calculated_cost;
            $contract->emission_factor = $summary->emission_factor;
            $contract->exceeds_consumption_limit = $summary->exceeds_consumption_limit;

            return $contract;
        })->filter()->values();
    }

    /**
     * Generate canonical URL for this page.
     * Page 1 has no page param, pages > 1 include ?page=N.
     */
    public function getCanonicalUrlProperty(): string
    {
        $baseUrl = config('app.url') . $this->basePath;

        if ($this->page > 1) {
            return $baseUrl . '?page=' . $this->page;
        }

        return $baseUrl;
    }

    /**
     * Get the URL for the previous page (for rel="prev" SEO link).
     * Returns null on page 1.
     */
    public function getPrevUrlProperty(): ?string
    {
        if ($this->page <= 1) {
            return null;
        }

        $baseUrl = config('app.url') . $this->basePath;

        // Page 2 -> prev is page 1 (no page param)
        if ($this->page === 2) {
            return $baseUrl;
        }

        // Pages > 2 -> prev includes page param
        return $baseUrl . '?page=' . ($this->page - 1);
    }

    /**
     * Get the URL for the next page (for rel="next" SEO link).
     * Returns null on the last page.
     */
    public function getNextUrlProperty(): ?string
    {
        // Get total pages from contracts paginator
        $contracts = $this->contracts;
        $lastPage = $contracts->lastPage();

        if ($this->page >= $lastPage) {
            return null;
        }

        $baseUrl = config('app.url') . $this->basePath;

        return $baseUrl . '?page=' . ($this->page + 1);
    }

    /**
     * Generate WebPage JSON-LD schema for SEO.
     */
    public function getWebPageSchemaProperty(): array
    {
        return [
            '@context' => 'https://schema.org',
            '@type' => 'WebPage',
            '@id' => $this->canonicalUrl . '#webpage',
            'url' => $this->canonicalUrl,
            'name' => $this->pageTitle,
            'description' => $this->metaDescription,
            'mainEntity' => [
                '@id' => $this->canonicalUrl . '#comparison-service',
            ],
        ];
    }

    /**
     * Generate Service JSON-LD schema for the comparison page.
     */
    public function getComparisonServiceSchemaProperty(): array
    {
        return [
            '@context' => 'https://schema.org',
            '@type' => 'Service',
            '@id' => $this->canonicalUrl . '#comparison-service',
            'name' => 'Sähkösopimusten vertailu',
            'description' => 'Voltikka vertailee sähkösopimuksia, hintoja ja sopimustyyppejä Suomessa.',
            'url' => $this->canonicalUrl,
            'serviceType' => 'Electricity contract comparison',
            'provider' => [
                '@type' => 'Organization',
                'name' => 'Voltikka',
                'url' => config('app.url'),
            ],
        ];
    }

    /**
     * Generate ItemList JSON-LD schema for SEO.
     * Contains lightweight product references to contracts on the current page.
     */
    public function getItemListSchemaProperty(): array
    {
        $contracts = $this->contracts;

        if ($contracts->isEmpty()) {
            return [];
        }

        $items = [];
        $basePosition = ($this->page - 1) * $this->perPage;

        foreach ($contracts as $index => $contract) {
            $position = $basePosition + $index + 1;

            $productItem = [
                '@type' => 'Product',
                '@id' => route('contract.detail', ['contractId' => $contract->id]) . '#product',
                'name' => $contract->name,
                'url' => route('contract.detail', ['contractId' => $contract->id]),
                'category' => 'Electricity Contract',
                'description' => 'Arvioitu vuosikustannus ' . number_format($this->consumption, 0, ',', ' ') . ' kWh kulutuksella',
            ];

            if ($contract->company) {
                $brand = [
                    '@type' => 'Organization',
                    'name' => $contract->company->name,
                ];

                if ($contract->company->getLogoUrl()) {
                    $brand['logo'] = $contract->company->getLogoUrl();
                }

                $productItem['brand'] = $brand;
            }

            $items[] = [
                '@type' => 'ListItem',
                'position' => $position,
                'item' => $productItem,
            ];
        }

        return [
            '@context' => 'https://schema.org',
            '@type' => 'ItemList',
            'name' => $this->pageTitle,
            'numberOfItems' => $contracts->total(),
            'itemListElement' => $items,
        ];
    }

    /**
     * Generate BreadcrumbList JSON-LD schema for SEO.
     */
    public function getBreadcrumbSchemaProperty(): array
    {
        $breadcrumbs = [
            [
                '@type' => 'ListItem',
                'position' => 1,
                'name' => 'Etusivu',
                'item' => config('app.url'),
            ],
            [
                '@type' => 'ListItem',
                'position' => 2,
                'name' => 'Sähkösopimukset',
                'item' => config('app.url') . '/sahkosopimus',
            ],
        ];

        // Add filter-specific breadcrumb if a filter is active
        if ($this->pricingModelFilter !== '') {
            $filterLabels = [
                'Spot' => 'Pörssisähkö',
                'FixedPrice' => 'Kiinteä hinta',
                'Hybrid' => 'Hybridisähkö',
                'Quarterly' => 'Kvartaalisähkö',
                'TimeOfUse' => 'Aikasähkö',
                'Seasonal' => 'Kausisähkö',
            ];
            $breadcrumbs[] = [
                '@type' => 'ListItem',
                'position' => 3,
                'name' => $filterLabels[$this->pricingModelFilter] ?? $this->pricingModelFilter,
                'item' => $this->canonicalUrl,
            ];
        } elseif ($this->contractTypeFilter !== '') {
            $filterLabels = [
                'FixedTerm' => 'Määräaikaiset',
                'OpenEnded' => 'Toistaiseksi voimassa',
            ];
            $breadcrumbs[] = [
                '@type' => 'ListItem',
                'position' => 3,
                'name' => $filterLabels[$this->contractTypeFilter] ?? $this->contractTypeFilter,
                'item' => $this->canonicalUrl,
            ];
        }

        return [
            '@context' => 'https://schema.org',
            '@type' => 'BreadcrumbList',
            'itemListElement' => $breadcrumbs,
        ];
    }

    /**
     * Generate WebSite JSON-LD schema for SEO.
     * Only included on the main listing page (page 1, no filters).
     */
    public function getWebSiteSchemaProperty(): array
    {
        // Only show WebSite schema on the main listing page without filters
        if ($this->page > 1 || $this->hasActiveFilters()) {
            return [];
        }

        return [
            '@context' => 'https://schema.org',
            '@type' => 'WebSite',
            'name' => 'Voltikka',
            'url' => config('app.url'),
            'description' => 'Vertailussa ' . \App\Livewire\AboutPage::cachedContractCount() . ' sähkösopimusta — yksi Suomen kattavimmista riippumattomista vertailuista. Vertaile hintoja ja löydä edullisin sähkösopimus.',
            'potentialAction' => [
                '@type' => 'SearchAction',
                'target' => [
                    '@type' => 'EntryPoint',
                    'urlTemplate' => config('app.url') . '/sahkosopimus?postcodeFilter={postcode}',
                ],
                'query-input' => 'required name=postcode',
            ],
        ];
    }

    public function render()
    {
        $data = $this->contractsListViewData();

        return view('livewire.contracts-list', $data['view'])
            ->layout('layouts.app', $data['layout']);
    }

    /**
     * Cache the prepared first-page/default listing payload for canonical public landings.
     * Interactive query/filter states remain uncached so their combinations do not explode.
     *
     * @return array{view: array<string, mixed>, layout: array<string, mixed>}
     */
    protected function contractsListViewData(): array
    {
        $this->normalizePageProperty();

        if (! $this->isDefaultListingCacheable()) {
            return $this->buildContractsListViewData();
        }

        return $this->rememberDefaultListingViewData(
            $this->contractsListViewDataCacheKey(),
            fn () => $this->buildContractsListViewData(),
        );
    }

    /**
     * Cache default listing data behind a short lock so crawlers/users do not
     * stampede the expensive cold path after the daily import invalidates caches.
     * If the lock is already held, serve an uncached response rather than making
     * the request wait long enough to hit PHP's 30 second request timeout.
     *
     * @param callable(): array{view: array<string, mixed>, layout: array<string, mixed>} $callback
     * @return array{view: array<string, mixed>, layout: array<string, mixed>}
     */
    protected function rememberDefaultListingViewData(string $key, callable $callback): array
    {
        $cached = Cache::get($key);
        if ($cached !== null) {
            return $cached;
        }

        try {
            return Cache::lock($key.':lock', 30)->block(2, function () use ($key, $callback) {
                return Cache::remember($key, Carbon::tomorrow(), $callback);
            });
        } catch (\Illuminate\Contracts\Cache\LockTimeoutException) {
            return $callback();
        }
    }

    /**
     * @return array{view: array<string, mixed>, layout: array<string, mixed>}
     */
    protected function buildContractsListViewData(): array
    {
        $contracts = $this->contracts;

        $schemas = [
            $this->webPageSchema,
            $this->comparisonServiceSchema,
            $this->itemListSchema,
            $this->breadcrumbSchema,
        ];

        // Add WebSite schema only on main page
        $webSiteSchema = $this->webSiteSchema;
        if (! empty($webSiteSchema)) {
            $schemas[] = $webSiteSchema;
        }

        return [
            'view' => [
                'contracts' => $contracts,
                'postcodeSuggestions' => $this->postcodeSuggestions,
                'pageTitle' => $this->pageTitle,
                'metaDescription' => $this->metaDescription,
                'basePath' => $this->basePath,
                'schemas' => $schemas,
                'marketInsight' => $this->marketInsight,
            ],
            'layout' => [
                'title' => $this->pageTitle . ' | Voltikka',
                'metaDescription' => $this->metaDescription,
                'canonical' => $this->canonicalUrl,
                'prevUrl' => $this->prevUrl,
                'nextUrl' => $this->nextUrl,
            ],
        ];
    }

    /**
     * @return array{trend:?array<string,mixed>,forecast:?array<string,mixed>,has_items:bool}
     */
    public function getMarketInsightProperty(): array
    {
        return app(ContractMarketInsightService::class)->insight(
            $this->marketInsightSegmentKey(),
            $this->selectedConsumptionValue(),
            $this->marketInsightIncludesForecast(),
        );
    }

    protected function marketInsightSegmentKey(): ?string
    {
        return 'aggregate';
    }

    protected function marketInsightIncludesForecast(): bool
    {
        return false;
    }

    protected function isDefaultListingCacheable(): bool
    {
        return ! app()->runningUnitTests()
            && request()->isMethod('GET')
            && request()->query() === []
            && $this->page === 1
            && ! $this->hasActiveFilters()
            && ! $this->billActive
            && $this->postcodeSearch === '';
    }

    protected function contractsListViewDataCacheKey(): string
    {
        return 'contracts-list:view-data:v1:' . md5(json_encode([
            'class' => static::class,
            'base_path' => $this->basePath,
            'page' => $this->page,
            'consumption' => $this->selectedConsumptionValue(),
            'version' => app(ContractPageCacheVersion::class)->hash(),
            'market_insight_version' => app(ContractMarketInsightService::class)->fingerprint(),
        ]));
    }
}
