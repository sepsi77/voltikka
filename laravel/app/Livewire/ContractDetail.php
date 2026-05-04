<?php

namespace App\Livewire;

use App\Http\Middleware\SetPublicCacheHeaders;
use App\Models\ElectricityContract;
use App\Models\SpotPriceAverage;
use App\Services\Caching\ContractPageCacheVersion;
use App\Services\CO2EmissionsCalculator;
use App\Services\ContractListCacheService;
use App\Services\ContractPriceCalculator;
use App\Services\ContractRankingService;
use App\Services\DTO\EnergyUsage;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Livewire\Component;

class ContractDetail extends Component
{
    protected ?ElectricityContract $contractCache = null;

    protected ?array $latestPricesCache = null;

    protected ?array $priceHistoryCache = null;

    protected ?array $contractHistoryCache = null;

    protected ?\Illuminate\Support\Collection $historyContractsCache = null;

    /**
     * The contract ID.
     */
    public string $contractId;

    /**
     * Current consumption value in kWh.
     */
    public int $consumption = 5000;

    /**
     * Default consumption presets (before filtering).
     *
     * @var array<string, int>
     */
    protected array $defaultPresets = [
        'Yksiö' => 2000,
        'Kerrostalo' => 5000,
        'Pieni talo' => 10000,
        'Suuri talo' => 18000,
    ];

    /**
     * Mount the component.
     */
    public function mount(string $contractId): void
    {
        $this->contractId = $contractId;

        // Handle legacy UUID redirect
        if ($this->shouldRedirectLegacyUuid($contractId)) {
            return; // Redirect was initiated, stop further processing
        }

        // Adjust default consumption if it falls outside the contract's limits
        $contract = $this->contract;
        if ($contract) {
            $this->redirectToLatestReplacementIfAvailable($contract);

            $this->consumption = $this->clampConsumption($this->consumption, $contract);

            // Track contract view
            $this->dispatch('track',
                eventName: 'Contract Viewed',
                props: [
                    'contract_id' => $contract->id,
                    'company' => $contract->company?->name,
                    'pricing_model' => $contract->pricing_model,
                ]
            );
        }
    }

    /**
     * Set the consumption to a preset value.
     * Clamps the value to be within the contract's limits.
     */
    public function setConsumption(int $value): void
    {
        $contract = $this->contract;
        if ($contract) {
            $value = $this->clampConsumption($value, $contract);
        }
        $this->consumption = $value;
    }

    /**
     * Clamp consumption value to be within the contract's allowed range.
     */
    protected function clampConsumption(int $value, ElectricityContract $contract): int
    {
        $min = $contract->consumption_limitation_min_x_kwh_per_y;
        $max = $contract->consumption_limitation_max_x_kwh_per_y;

        if ($min !== null && $value < $min) {
            return (int) $min;
        }

        if ($max !== null && $value > $max) {
            return (int) $max;
        }

        return $value;
    }

    /**
     * Get filtered consumption presets based on contract limits.
     *
     * @return array<string, int>
     */
    public function getPresetsProperty(): array
    {
        $contract = $this->contract;

        if (! $contract || ! $contract->hasConsumptionLimits()) {
            return $this->defaultPresets;
        }

        return array_filter(
            $this->defaultPresets,
            fn (int $value) => $contract->isConsumptionInRange($value)
        );
    }

    /**
     * Check if this is a legacy UUID and redirect if so.
     * Returns true if redirect was initiated.
     */
    protected function shouldRedirectLegacyUuid(string $contractId): bool
    {
        // Check if this looks like a legacy UUID (36 chars with hyphens)
        if (!preg_match('/^[a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12}$/i', $contractId)) {
            return false;
        }

        // Look up by api_id
        $contract = ElectricityContract::where('api_id', $contractId)->first();

        if ($contract) {
            // Create a proper Laravel redirect response (not Livewire's redirector)
            $url = route('contract.detail', ['contractId' => $contract->id]);
            $response = new \Illuminate\Http\RedirectResponse($url, 301);

            throw new \Illuminate\Http\Exceptions\HttpResponseException($response);
        }

        return false;
    }

    /**
     * Redirect inactive contracts to the latest known replacement when available.
     */
    protected function redirectToLatestReplacementIfAvailable(ElectricityContract $contract): void
    {
        if ($contract->isActive()) {
            return;
        }

        $latestReplacement = $contract->resolveLatestReplacement();

        if (! $latestReplacement || ! $latestReplacement->isActive()) {
            return;
        }

        $url = route('contract.detail', ['contractId' => $latestReplacement->id]);
        $response = new \Illuminate\Http\RedirectResponse($url, 301);

        throw new \Illuminate\Http\Exceptions\HttpResponseException($response);
    }

    /**
     * Get the contract with all relations.
     */
    public function getContractProperty(): ?ElectricityContract
    {
        if ($this->contractCache !== null) {
            return $this->contractCache;
        }

        if ($this->isDefaultContractLookupCacheable()) {
            return $this->contractCache = Cache::remember(
                $this->contractLookupCacheKey(),
                Carbon::tomorrow(),
                fn () => $this->loadContract(),
            );
        }

        return $this->contractCache = $this->loadContract();
    }

    protected function loadContract(): ?ElectricityContract
    {
        return ElectricityContract::query()
            ->with(['company', 'priceComponents', 'electricitySource'])
            ->find($this->contractId);
    }

    protected function isDefaultContractLookupCacheable(): bool
    {
        return ! app()->runningUnitTests()
            && request()->isMethod('GET')
            && request()->query() === [];
    }

    protected function contractLookupCacheKey(): string
    {
        return 'contract-detail:contract:v1:' . md5(json_encode([
            'contract_id' => $this->contractId,
            'version' => app(ContractPageCacheVersion::class)->hash(),
        ]));
    }

    /**
     * Check if the contract is currently active (present in active_contracts table).
     */
    public function getIsActiveProperty(): bool
    {
        return $this->contract?->isActive() ?? false;
    }

    /**
     * Get the contract's price rank among all active household contracts.
     */
    public function getPriceRankProperty(): ?int
    {
        $contract = $this->contract;
        if (! $contract) {
            return null;
        }

        return app(ContractRankingService::class)->getContractRank($contract->id);
    }

    /**
     * Get total number of active household contracts.
     */
    public function getTotalContractsProperty(): int
    {
        return app(ContractRankingService::class)->getTotalActiveContracts();
    }

    /**
     * Rank at the currently-selected consumption. Updates live when user
     * changes the consumption chip, unlike priceRank which is fixed at 5000 kWh
     * for SEO title stability.
     */
    public function getLiveRankProperty(): ?int
    {
        $contract = $this->contract;
        if (! $contract) {
            return null;
        }
        return app(ContractRankingService::class)
            ->getRankForConsumption($contract->id, $this->consumption);
    }

    public function getLiveTotalContractsProperty(): ?int
    {
        $contract = $this->contract;
        if (! $contract) {
            return null;
        }
        return app(ContractRankingService::class)
            ->getTotalContractsForConsumption($contract->id, $this->consumption);
    }

    /**
     * Cheaper alternatives at current consumption. Empty if the contract
     * is #1 (nothing is cheaper) or if consumption isn't cache-supported.
     */
    public function getCheaperContractsProperty(): \Illuminate\Support\Collection
    {
        $contract = $this->contract;
        if (! $contract) {
            return collect();
        }
        return app(ContractRankingService::class)
            ->getCheaperContracts($contract->id, $this->consumption, 4);
    }

    /**
     * Price-change summary for the collapsed "Hintakehitys" teaser.
     *
     * Counts distinct price transitions per component type (consecutive equal
     * prices collapse), sums across types, and reports the earliest tracked date.
     *
     * @return array{changes: int, since: ?\Carbon\Carbon, latest: ?array{type: string, from: float, to: float, date: \Carbon\Carbon}}
     */
    public function getPriceChangeInfoProperty(): array
    {
        if (empty($this->priceHistory)) {
            return ['changes' => 0, 'since' => null, 'latest' => null];
        }

        $changes = 0;
        $earliest = null;
        $latestChange = null;
        $latestChangeTimestamp = null;

        foreach ($this->priceHistory as $type => $history) {
            $sorted = collect($history)
                ->sortBy(fn (array $record) => $record['date'])
                ->values();

            $previous = null;

            foreach ($sorted as $record) {
                $date = \Carbon\Carbon::parse($record['date']);

                if ($earliest === null || $date->lt($earliest)) {
                    $earliest = $date->copy();
                }

                if ($previous !== null && (float) $record['price'] !== (float) $previous['price']) {
                    $changes++;
                    $ts = $date->timestamp;

                    if ($latestChangeTimestamp === null || $ts > $latestChangeTimestamp) {
                        $latestChangeTimestamp = $ts;
                        $latestChange = [
                            'type' => (string) $type,
                            'from' => (float) $previous['price'],
                            'to' => (float) $record['price'],
                            'date' => $date->copy(),
                            'contract_name' => $record['contract_name'] ?? null,
                        ];
                    }
                }

                $previous = $record;
            }
        }

        return [
            'changes' => $changes,
            'since' => $earliest,
            'latest' => $latestChange,
        ];
    }

    /**
     * Truncate a contract name to fit within title limits.
     * Cuts at word boundary and appends ellipsis if truncated.
     */
    protected function truncateName(string $name, int $maxLength = 40): string
    {
        if (mb_strlen($name) <= $maxLength) {
            return $name;
        }

        $cut = mb_substr($name, 0, $maxLength);
        $lastSpace = mb_strrpos($cut, ' ');
        if ($lastSpace > 20) {
            $cut = mb_substr($cut, 0, $lastSpace);
        }

        return rtrim($cut, ' -') . '…';
    }

    /**
     * Get SEO page title.
     */
    public function getPageTitleProperty(): string
    {
        $contract = $this->contract;
        if (! $contract) {
            return 'Sähkösopimus | Voltikka';
        }

        $name = $this->truncateName($contract->name);

        if (! $this->isActive) {
            return "{$name} ei ole enää saatavilla | Voltikka";
        }

        $rank = $this->priceRank;
        $total = $this->totalContracts;

        if ($rank && $total) {
            return "{$name} | #{$rank} halvin — Vertaa {$total} sopimuksessa | Voltikka";
        }

        // Fallback for company-only contracts without ranking
        $companyName = $contract->company?->name ?? '';

        return "{$name} — {$companyName} | Voltikka";
    }

    /**
     * Get OG title (shorter version for social sharing).
     */
    public function getOgTitleProperty(): string
    {
        $contract = $this->contract;
        if (! $contract) {
            return 'Sähkösopimus | Voltikka';
        }

        $name = $this->truncateName($contract->name);

        if (! $this->isActive) {
            return "{$name} ei ole enää saatavilla | Voltikka";
        }

        $rank = $this->priceRank;

        if ($rank) {
            return "{$name} | #{$rank} halvin | Voltikka";
        }

        $companyName = $contract->company?->name ?? '';

        return "{$name} — {$companyName} | Voltikka";
    }

    /**
     * Get SEO meta description.
     */
    public function getMetaDescriptionProperty(): string
    {
        $contract = $this->contract;
        if (! $contract) {
            return '';
        }

        if (! $this->isActive) {
            return "{$contract->name} ei ole enää tarjolla. Katso ajantasaiset sähkösopimukset ja vaihtoehdot Voltikasta.";
        }

        $total = $this->totalContracts;

        return "Vertaa {$contract->name} hinta ja CO₂-tiedot {$total} muuhun sopimukseen. Katso hintahistoria, sijoitus ja löydä omaan kulutukseesi paras vaihtoehto.";
    }

    /**
     * Get the calculated cost for the contract.
     */
    public function getCalculatedCostProperty(): array
    {
        $contract = $this->contract;

        if (! $contract) {
            return [];
        }

        /** @var ContractListCacheService $contractListCache */
        $contractListCache = app(ContractListCacheService::class);
        $cachedMetrics = $contractListCache->getCachedMetrics($this->consumption);
        $cachedContract = $cachedMetrics['contracts'][$contract->id] ?? null;

        if ($cachedContract) {
            return $cachedContract['calculated_cost'];
        }

        $calculator = app(ContractPriceCalculator::class);
        $priceComponents = $this->getNormalizedPriceComponents($contract);

        $usage = new EnergyUsage(
            total: $this->consumption,
            basicLiving: $this->consumption,
        );

        $contractData = [
            'contract_type' => $contract->contract_type,
            'pricing_model' => $contract->pricing_model,
            'metering' => $contract->metering,
        ];

        $spotPriceAvg = SpotPriceAverage::latestRolling365Days();
        $spotPriceDay = $spotPriceAvg?->day_avg_with_tax;
        $spotPriceNight = $spotPriceAvg?->night_avg_with_tax;

        return $calculator->calculate($priceComponents, $contractData, $usage, $spotPriceDay, $spotPriceNight)->toArray();
    }

    /**
     * Get the latest price components for the contract.
     *
     * @return array<string, array{price: float, unit: string}>
     */
    public function getLatestPricesProperty(): array
    {
        if ($this->latestPricesCache !== null) {
            return $this->latestPricesCache;
        }

        $contract = $this->contract;

        if (! $contract) {
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

        return $this->latestPricesCache = $prices;
    }

    /**
     * Get price components enriched with discount metadata for display.
     *
     * @return array<string, array{
     *     price: float,
     *     unit: string,
     *     has_discount: bool,
     *     discount_value: float|null,
     *     discount_is_percentage: bool,
     *     discount_type: string|null,
     *     discount_n_first_months: int|null,
     *     discount_until_date: \Carbon\Carbon|null,
     *     discount_n_first_kwh: float|null,
     *     discounted_price: float|null,
     *     discount_label: string|null,
     * }>
     */
    public function getDiscountedComponentsProperty(): array
    {
        $contract = $this->contract;

        if (! $contract) {
            return [];
        }

        $components = [];

        foreach ($contract->getLatestPriceComponentsForCalculation() as $comp) {
            $type = $comp['price_component_type'];
            $hasDiscount = $comp['has_discount'] ?? false;
            $discountValue = $comp['discount_value'] ?? null;
            $isPercentage = $comp['discount_is_percentage'] ?? false;
            $discountType = $comp['discount_type'] ?? null;

            $unit = match ($comp['payment_unit'] ?? null) {
                'EurPerMonth' => 'EUR/kk',
                'CentPerKiwattHour' => 'c/kWh',
                default => $type === 'Monthly' ? 'EUR/kk' : 'c/kWh',
            };

            $discountedPrice = null;
            $discountLabel = null;

            if ($hasDiscount && $discountValue) {
                $price = (float) $comp['price'];

                if ($isPercentage) {
                    $discountedPrice = max(0, $price * (1 - ((float) $discountValue / 100)));
                    $discountLabel = '-' . number_format($discountValue, 0, ',', ' ') . '% alennus';
                } else {
                    $discountedPrice = max(0, $price - (float) $discountValue);
                    $unitLabel = $unit === 'EUR/kk' ? '€/kk' : 'c/kWh';
                    $discountLabel = '-' . number_format($discountValue, 2, ',', ' ') . ' ' . $unitLabel . ' alennus';
                }

                if ($discountType === 'NFirstMonth' && ($comp['discount_discount_n_first_months'] ?? 0) > 0) {
                    $n = (int) $comp['discount_discount_n_first_months'];
                    $discountLabel .= ' · ' . $n . ' ensimmäistä kuukautta';
                } elseif ($discountType === 'UntilDate' && ! empty($comp['discount_discount_until_date'])) {
                    $until = $comp['discount_discount_until_date'] instanceof \Carbon\CarbonInterface
                        ? $comp['discount_discount_until_date']
                        : \Carbon\Carbon::parse($comp['discount_discount_until_date']);
                    $discountLabel .= ' · voimassa ' . $until->format('d.m.Y') . ' asti';
                } elseif ($discountType === 'NFirstKwh' && ($comp['discount_discount_n_first_kwh'] ?? 0) > 0) {
                    $discountLabel = 'Ensimmäisille ' . number_format((float) $comp['discount_discount_n_first_kwh'], 0, ',', ' ') . ' kWh alennus -' . number_format($discountValue, 2, ',', ' ') . ' ' . ($unit === 'EUR/kk' ? '€/kk' : 'c/kWh');
                }
            }

            $components[$type] = [
                'price' => (float) $comp['price'],
                'unit' => $unit,
                'has_discount' => $hasDiscount,
                'discount_value' => $discountValue,
                'discount_is_percentage' => $isPercentage,
                'discount_type' => $discountType,
                'discount_n_first_months' => $comp['discount_discount_n_first_months'] ?? null,
                'discount_until_date' => $comp['discount_discount_until_date'] ?? null,
                'discount_n_first_kwh' => $comp['discount_discount_n_first_kwh'] ?? null,
                'discounted_price' => $discountedPrice,
                'discount_label' => $discountLabel,
            ];
        }

        return $components;
    }

    /**
     * Get the price history for the contract.
     *
     * @return array<string, array<array{date: string, price: float}>>
     */
    public function getPriceHistoryProperty(): array
    {
        if ($this->priceHistoryCache !== null) {
            return $this->priceHistoryCache;
        }

        $contract = $this->contract;

        if (! $contract) {
            return [];
        }

        $history = [];

        foreach ($this->getHistoryContracts($contract) as $historyContract) {
            foreach ($historyContract->priceComponents->sortByDesc('price_date')->groupBy('price_component_type') as $type => $components) {
                foreach ($components as $pc) {
                    $history[$type][] = [
                        'date' => $pc->price_date->format('Y-m-d'),
                        'price' => $pc->price,
                        'contract_id' => $historyContract->id,
                        'contract_name' => $historyContract->name,
                    ];
                }
            }
        }

        foreach ($history as $type => $rows) {
            $history[$type] = collect($rows)
                ->sortByDesc(fn (array $row) => $row['date'])
                ->values()
                ->toArray();
        }

        return $this->priceHistoryCache = $history;
    }

    /**
     * Get the contract version history using the replacement chain.
     *
     * @return array<int, array{
     *     id: string,
     *     name: string,
     *     company: string|null,
     *     is_current: bool,
     *     is_active: bool,
     *     latest_price_date: ?\Carbon\Carbon,
     *     prices: array<int, array{label: string, price: float, unit: string}>,
     *     promotion: ?string
     * }>
     */
    public function getContractHistoryProperty(): array
    {
        if ($this->contractHistoryCache !== null) {
            return $this->contractHistoryCache;
        }

        $contract = $this->contract;

        if (! $contract) {
            return [];
        }

        $history = $this->getHistoryContracts($contract)
            ->map(function (ElectricityContract $historyContract): array {
                $latestPriceComponents = $historyContract->priceComponents
                    ->sortByDesc('price_date')
                    ->groupBy('price_component_type')
                    ->map(fn ($group) => $group->sortByDesc('price_date')->first(fn ($item) => $item->price > 0) ?? $group->sortByDesc('price_date')->first());

                $latestPriceDate = $latestPriceComponents
                    ->pluck('price_date')
                    ->filter()
                    ->sortByDesc(fn ($date) => $date instanceof \Carbon\Carbon ? $date->timestamp : \Carbon\Carbon::parse($date)->timestamp)
                    ->first();

                return [
                    'id' => $historyContract->id,
                    'name' => $historyContract->name,
                    'company' => $historyContract->company?->name,
                    'is_current' => $historyContract->id === $this->contractId,
                    'is_active' => $historyContract->isActive(),
                    'latest_price_date' => $latestPriceDate,
                    'prices' => $this->formatContractHistoryPrices($historyContract, $latestPriceComponents->all()),
                    'promotion' => $this->formatHistoricalPromotionText($historyContract, $latestPriceComponents->all()),
                ];
            })
            ->values()
            ->toArray();

        return $this->contractHistoryCache = $history;
    }

    /**
     * Get the CO2 emissions calculation for the contract.
     */
    public function getCo2EmissionsProperty(): array
    {
        $contract = $this->contract;

        if (! $contract) {
            return [];
        }

        $calculator = app(CO2EmissionsCalculator::class);
        $result = $calculator->calculate($contract->electricitySource, $this->consumption);

        return $result->toArray();
    }

    /**
     * Get the emission factor sources for display.
     */
    public function getEmissionFactorSourcesProperty(): array
    {
        return CO2EmissionsCalculator::EMISSION_FACTOR_SOURCES;
    }

    /**
     * @return array<int, array{price_component_type: string, price: float|int|null}>
     */
    protected function getNormalizedPriceComponents(ElectricityContract $contract): array
    {
        return $contract->getLatestPriceComponentsForCalculation();
    }

    /**
     * @return \Illuminate\Support\Collection<int, ElectricityContract>
     */
    protected function getHistoryContracts(ElectricityContract $contract): \Illuminate\Support\Collection
    {
        if ($this->historyContractsCache !== null) {
            return $this->historyContractsCache;
        }

        $historyContractIds = $contract->getReplacementChainBackward()
            ->push($contract)
            ->pluck('id')
            ->unique()
            ->values();

        $historyContracts = ElectricityContract::query()
            ->with(['company', 'priceComponents'])
            ->whereIn('id', $historyContractIds)
            ->get()
            ->sortByDesc(function (ElectricityContract $historyContract) {
                $latestPriceDate = $historyContract->priceComponents
                    ->pluck('price_date')
                    ->filter()
                    ->sortByDesc(fn ($date) => $date instanceof \Carbon\Carbon ? $date->timestamp : \Carbon\Carbon::parse($date)->timestamp)
                    ->first();

                return $latestPriceDate instanceof \Carbon\Carbon
                    ? $latestPriceDate->timestamp
                    : ($latestPriceDate ? \Carbon\Carbon::parse($latestPriceDate)->timestamp : 0);
            })
            ->values();

        return $this->historyContractsCache = $historyContracts;
    }

    /**
     * @param  array<string, \App\Models\PriceComponent>  $latestPriceComponents
     * @return array<int, array{label: string, price: float, unit: string}>
     */
    protected function formatContractHistoryPrices(ElectricityContract $contract, array $latestPriceComponents): array
    {
        $priceTypeLabels = [
            'General' => 'Energiahinta',
            'Monthly' => 'Perusmaksu',
            'DayTime' => 'Päiväsähkö',
            'NightTime' => 'Yösähkö',
            'SeasonalWinterDay' => 'Talvihinta',
            'SeasonalOther' => 'Muu aika',
        ];

        $priceTypeOrder = ['General', 'DayTime', 'NightTime', 'SeasonalWinterDay', 'SeasonalOther', 'Monthly'];

        return collect($priceTypeOrder)
            ->map(function (string $type) use ($latestPriceComponents, $priceTypeLabels) {
                $component = $latestPriceComponents[$type] ?? null;

                if (! $component) {
                    return null;
                }

                return [
                    'label' => $priceTypeLabels[$type] ?? $type,
                    'price' => (float) $component->price,
                    'unit' => $type === 'Monthly' ? 'EUR/kk' : 'c/kWh',
                ];
            })
            ->filter()
            ->values()
            ->toArray();
    }

    /**
     * @param  array<string, \App\Models\PriceComponent>  $latestPriceComponents
     */
    protected function formatHistoricalPromotionText(ElectricityContract $contract, array $latestPriceComponents): ?string
    {
        $discountedComponent = collect($latestPriceComponents)
            ->filter(fn ($component) => $component?->has_discount)
            ->sortByDesc('price_date')
            ->first();

        if (! $discountedComponent) {
            return $contract->pricing_has_discounts ? 'Tarjoussopimus' : null;
        }

        $parts = [];

        if ($discountedComponent->discount_discount_n_first_months) {
            $parts[] = $discountedComponent->discount_discount_n_first_months . ' ensimmäistä kuukautta';
        }

        if ($discountedComponent->discount_value) {
            $parts[] = $contract->formatActiveDiscountValue([
                'value' => $discountedComponent->discount_value,
                'is_percentage' => $discountedComponent->discount_is_percentage,
                'price_component_type' => $discountedComponent->price_component_type,
                'payment_unit' => $discountedComponent->payment_unit,
            ]);
        }

        if ($discountedComponent->discount_discount_until_date) {
            $parts[] = 'voimassa ' . $discountedComponent->discount_discount_until_date->format('d.m.Y') . ' asti';
        }

        if (empty($parts)) {
            return 'Tarjoussopimus';
        }

        return implode(' · ', $parts);
    }

    /**
     * Get the canonical URL for this page.
     */
    public function getCanonicalUrlProperty(): string
    {
        return route('contract.detail', ['contractId' => $this->contractId]);
    }

    /**
     * Generate WebPage JSON-LD schema for SEO.
     */
    public function getWebPageSchemaProperty(): array
    {
        $contract = $this->contract;

        if (! $contract || ! $this->isActive) {
            return [];
        }

        return [
            '@context' => 'https://schema.org',
            '@type' => 'WebPage',
            '@id' => $this->canonicalUrl . '#webpage',
            'url' => $this->canonicalUrl,
            'name' => $this->pageTitle,
            'description' => $this->metaDescription,
            'mainEntity' => [
                '@id' => $this->canonicalUrl . '#product',
            ],
        ];
    }

    /**
     * Generate Product JSON-LD schema for SEO.
     */
    public function getProductSchemaProperty(): array
    {
        $contract = $this->contract;

        if (! $contract || ! $this->isActive) {
            return [];
        }

        $providerId = $this->canonicalUrl . '#provider';
        $offerUrl = $contract->order_link ?: $contract->product_link;

        $schema = [
            '@context' => 'https://schema.org',
            '@type' => 'Product',
            '@id' => $this->canonicalUrl . '#product',
            'name' => $contract->name,
            'description' => $contract->short_description ?? $contract->long_description ?? "Sähkösopimus: {$contract->name}",
            'url' => $this->canonicalUrl,
            'category' => 'Electricity Contract',
        ];

        if ($contract->company) {
            $brand = [
                '@type' => 'Organization',
                '@id' => $providerId,
                'name' => $contract->company->name,
            ];

            if ($contract->company->getLogoUrl()) {
                $brand['logo'] = $contract->company->getLogoUrl();
            }

            if ($contract->company->company_url) {
                $brand['url'] = $contract->company->company_url;
            }

            $schema['brand'] = $brand;
        }

        $offers = [];
        $latestPrices = $this->latestPrices;

        $buildOffer = function (string $suffix, string $name, array $priceSpecification) use ($contract, $providerId, $offerUrl): array {
            $offer = [
                '@type' => 'Offer',
                '@id' => $this->canonicalUrl . '#offer-' . $suffix,
                'name' => $name,
                'priceSpecification' => $priceSpecification,
            ];

            if ($offerUrl) {
                $offer['url'] = $offerUrl;
            }

            if ($contract->company) {
                $provider = [
                    '@id' => $providerId,
                ];
                $offer['offeredBy'] = $provider;
                $offer['seller'] = $provider;
            }

            return $offer;
        };

        if ($contract->pricing_model === 'Spot' && isset($latestPrices['General'])) {
            $offers[] = $buildOffer('spot-margin', 'Spot-marginaali', [
                '@type' => 'UnitPriceSpecification',
                'price' => $latestPrices['General']['price'],
                'priceCurrency' => 'EUR',
                'unitCode' => 'KWH',
                'unitText' => 'c/kWh',
            ]);
        }

        if (isset($latestPrices['Monthly'])) {
            $offers[] = $buildOffer('monthly-fee', 'Perusmaksu', [
                '@type' => 'UnitPriceSpecification',
                'price' => $latestPrices['Monthly']['price'],
                'priceCurrency' => 'EUR',
                'unitCode' => 'MON',
                'unitText' => 'EUR/kk',
            ]);
        }

        if ($contract->pricing_model !== 'Spot' && isset($latestPrices['General'])) {
            $offers[] = $buildOffer('energy-price', 'Energiahinta', [
                '@type' => 'UnitPriceSpecification',
                'price' => $latestPrices['General']['price'],
                'priceCurrency' => 'EUR',
                'unitCode' => 'KWH',
                'unitText' => 'c/kWh',
            ]);
        }

        if (isset($latestPrices['DayTime'])) {
            $offers[] = $buildOffer('daytime', 'Päiväsähkö (07:00-22:00)', [
                '@type' => 'UnitPriceSpecification',
                'price' => $latestPrices['DayTime']['price'],
                'priceCurrency' => 'EUR',
                'unitCode' => 'KWH',
                'unitText' => 'c/kWh',
            ]);
        }

        if (isset($latestPrices['NightTime'])) {
            $offers[] = $buildOffer('nighttime', 'Yösähkö (22:00-07:00)', [
                '@type' => 'UnitPriceSpecification',
                'price' => $latestPrices['NightTime']['price'],
                'priceCurrency' => 'EUR',
                'unitCode' => 'KWH',
                'unitText' => 'c/kWh',
            ]);
        }

        if (isset($latestPrices['SeasonalWinterDay'])) {
            $offers[] = $buildOffer('seasonal-winter', 'Talvihinta (marras-maaliskuu)', [
                '@type' => 'UnitPriceSpecification',
                'price' => $latestPrices['SeasonalWinterDay']['price'],
                'priceCurrency' => 'EUR',
                'unitCode' => 'KWH',
                'unitText' => 'c/kWh',
            ]);
        }

        if (isset($latestPrices['SeasonalOther'])) {
            $offers[] = $buildOffer('seasonal-other', 'Muu aika', [
                '@type' => 'UnitPriceSpecification',
                'price' => $latestPrices['SeasonalOther']['price'],
                'priceCurrency' => 'EUR',
                'unitCode' => 'KWH',
                'unitText' => 'c/kWh',
            ]);
        }

        if (! empty($offers)) {
            $schema['offers'] = $offers;
        }

        $additionalProperties = [];

        $pricingModelLabels = [
            'Spot' => 'Pörssisähkö',
            'FixedPrice' => 'Kiinteä hinta',
            'Hybrid' => 'Hybridisähkö',
        ];
        $additionalProperties[] = [
            '@type' => 'PropertyValue',
            'name' => 'pricingModel',
            'value' => $pricingModelLabels[$contract->pricing_model] ?? $contract->pricing_model,
        ];

        $contractTypeLabels = [
            'OpenEnded' => 'Toistaiseksi voimassa',
            'FixedTerm' => 'Määräaikainen',
        ];
        $additionalProperties[] = [
            '@type' => 'PropertyValue',
            'name' => 'contractType',
            'value' => $contractTypeLabels[$contract->contract_type] ?? $contract->contract_type,
        ];

        $meteringLabels = [
            'General' => 'Yleissähkö',
            'Time' => 'Aikasähkö',
            'Season' => 'Kausisähkö',
        ];
        $additionalProperties[] = [
            '@type' => 'PropertyValue',
            'name' => 'meteringType',
            'value' => $meteringLabels[$contract->metering] ?? $contract->metering,
        ];

        $source = $contract->electricitySource;
        if ($source) {
            if ($source->renewable_total !== null) {
                $additionalProperties[] = [
                    '@type' => 'PropertyValue',
                    'name' => 'renewablePercentage',
                    'value' => $source->renewable_total,
                    'unitCode' => 'P1',
                    'unitText' => '%',
                ];
            }

            if ($source->nuclear_total !== null) {
                $additionalProperties[] = [
                    '@type' => 'PropertyValue',
                    'name' => 'nuclearPercentage',
                    'value' => $source->nuclear_total,
                    'unitCode' => 'P1',
                    'unitText' => '%',
                ];
            }

            if ($source->fossil_total !== null) {
                $additionalProperties[] = [
                    '@type' => 'PropertyValue',
                    'name' => 'fossilPercentage',
                    'value' => $source->fossil_total,
                    'unitCode' => 'P1',
                    'unitText' => '%',
                ];
            }

            if ($source->renewable_wind !== null && $source->renewable_wind > 0) {
                $additionalProperties[] = [
                    '@type' => 'PropertyValue',
                    'name' => 'windPowerPercentage',
                    'value' => $source->renewable_wind,
                    'unitCode' => 'P1',
                    'unitText' => '%',
                ];
            }

            if ($source->renewable_hydro !== null && $source->renewable_hydro > 0) {
                $additionalProperties[] = [
                    '@type' => 'PropertyValue',
                    'name' => 'hydroPowerPercentage',
                    'value' => $source->renewable_hydro,
                    'unitCode' => 'P1',
                    'unitText' => '%',
                ];
            }
        }

        $co2Emissions = $this->co2Emissions;
        if (! empty($co2Emissions) && isset($co2Emissions['emission_factor_g_per_kwh'])) {
            $additionalProperties[] = [
                '@type' => 'PropertyValue',
                'name' => 'emissionFactor',
                'value' => $co2Emissions['emission_factor_g_per_kwh'],
                'unitCode' => 'GRM',
                'unitText' => 'gCO2/kWh',
            ];
        }

        if (! empty($additionalProperties)) {
            $schema['additionalProperty'] = $additionalProperties;
        }

        return $schema;
    }

    /**
     * Generate BreadcrumbList JSON-LD schema for SEO.
     */
    public function getBreadcrumbSchemaProperty(): array
    {
        $contract = $this->contract;

        if (! $contract || ! $this->isActive) {
            return [];
        }

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

        // Add company if available
        if ($contract->company) {
            $breadcrumbs[] = [
                '@type' => 'ListItem',
                'position' => 3,
                'name' => $contract->company->name,
                'item' => config('app.url') . '/sahkosopimus/sahkoyhtiot/' . $contract->company->name_slug,
            ];
            $breadcrumbs[] = [
                '@type' => 'ListItem',
                'position' => 4,
                'name' => $contract->name,
                'item' => $this->canonicalUrl,
            ];
        } else {
            $breadcrumbs[] = [
                '@type' => 'ListItem',
                'position' => 3,
                'name' => $contract->name,
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
     * @return array{view: array<string, mixed>, layout: array<string, mixed>}
     */
    protected function contractDetailViewData(): array
    {
        if (! $this->isDefaultContractDetailCacheable()) {
            return $this->buildContractDetailViewData();
        }

        return Cache::remember(
            $this->contractDetailViewDataCacheKey(),
            Carbon::tomorrow(),
            fn () => $this->buildContractDetailViewData(),
        );
    }

    /**
     * @return array{view: array<string, mixed>, layout: array<string, mixed>}
     */
    protected function buildContractDetailViewData(): array
    {
        $contract = $this->contract;
        $isActive = $this->isActive;

        return [
            'view' => [
                'contract' => $contract,
                'schemas' => array_values(array_filter([
                    $this->webPageSchema,
                    $this->productSchema,
                    $this->breadcrumbSchema,
                ])),
                'latestPrices' => $this->latestPrices,
                'discountedComponents' => $this->discountedComponents,
                'calculatedCost' => $this->calculatedCost,
                'priceHistory' => $this->priceHistory,
                'contractHistory' => $this->contractHistory,
                'presets' => $this->presets,
                'co2Emissions' => $this->co2Emissions,
                'emissionFactorSources' => $this->emissionFactorSources,
                'cheaperContracts' => $this->cheaperContracts,
                'liveRank' => $this->liveRank,
                'liveTotalContracts' => $this->liveTotalContracts,
                'priceChangeInfo' => $this->priceChangeInfo,
            ],
            'layout' => [
                'title' => $this->pageTitle,
                'ogTitle' => $this->ogTitle,
                'metaDescription' => $this->metaDescription,
                'canonical' => $this->canonicalUrl,
                'robots' => $isActive ? null : 'noindex, follow',
            ],
        ];
    }

    protected function isDefaultContractDetailCacheable(): bool
    {
        $contract = $this->contract;

        return ! app()->runningUnitTests()
            && request()->isMethod('GET')
            && request()->query() === []
            && $contract !== null
            && $this->consumption === $this->clampConsumption(5000, $contract);
    }

    protected function contractDetailViewDataCacheKey(): string
    {
        return 'contract-detail:view-data:v1:' . md5(json_encode([
            'contract_id' => $this->contractId,
            'consumption' => $this->consumption,
            'version' => app(ContractPageCacheVersion::class)->hash(),
        ]));
    }

    public function render()
    {
        $this->enableBackButtonCache();

        $contract = $this->contract;

        if (! $contract) {
            abort(404);
        }

        $data = $this->contractDetailViewData();

        return view('livewire.contract-detail', $data['view'])
            ->layout('layouts.app', $data['layout'])
            ->response(function ($response) {
                app(SetPublicCacheHeaders::class)->applyCacheHeaders($response);
            });
    }
}
