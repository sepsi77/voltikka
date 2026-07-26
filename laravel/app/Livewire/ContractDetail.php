<?php

namespace App\Livewire;

use App\Http\Middleware\SetPublicCacheHeaders;
use App\Livewire\Concerns\BillComparisonInputs;
use App\Models\ElectricityContract;
use App\Models\SpotPriceAverage;
use App\Services\Caching\ContractPageCacheVersion;
use App\Services\CO2EmissionsCalculator;
use App\Services\ContractListCacheService;
use App\Services\ContractPriceCalculator;
use App\Services\ContractPriceHistory\PriceDevelopmentPresenter;
use App\Services\ContractRankingService;
use App\Services\DTO\EnergyUsage;
use App\Support\ContractContentSanitizer;
use App\Support\ContractInternalLinks;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Livewire\Component;

class ContractDetail extends Component
{
    use BillComparisonInputs;

    protected ?ElectricityContract $contractCache = null;

    /**
     * Derived bill-module result. Protected on purpose: it is per-user state and
     * must stay out of both the Livewire snapshot and the page's prepared
     * view-data cache.
     */
    protected ?array $billResultCache = null;

    protected ?array $latestPricesCache = null;

    protected ?array $priceHistoryCache = null;

    protected ?array $contractHistoryCache = null;

    protected ?\Illuminate\Support\Collection $historyContractsCache = null;

    protected ?ContractRankingService $rankingServiceCache = null;

    protected ?string $contractPageCacheVersionHashCache = null;

    /**
     * Request-scoped computed value cache. Livewire computed properties can be
     * read several times while preparing layout SEO data and the Blade view;
     * keep ranking lookups from repeating the same large target-group queries.
     *
     * @var array<string, mixed>
     */
    protected array $computedValueCache = [];

    /**
     * The contract ID.
     */
    public string $contractId;

    /**
     * Current consumption value in kWh.
     */
    public int $consumption = 5000;

    /**
     * The free "oma kulutus" kWh field beside the preset chips.
     *
     * Deliberately `int|string|null` for the same reason as the listing pages'
     * `directConsumption`: Livewire and mobile browsers send an empty string
     * while the visitor clears a number input, and a strict `int` property then
     * fails hydration.
     */
    public int|string|null $directConsumption = null;

    /**
     * Bounds for the free kWh field. Below the lower bound a household annual
     * total is not a plausible yearly consumption, and above the upper bound the
     * comparison stops describing a household at all (the largest preset on the
     * site is 18 000 kWh/v).
     */
    public const MIN_FREE_CONSUMPTION = 1000;

    public const MAX_FREE_CONSUMPTION = 30000;

    /**
     * Above this a seller's consumption cap cannot bind a household, so the terms
     * grid does not print it. Same threshold as
     * `ContractCard\CardFooterItems::CAP_RELEVANCE_THRESHOLD_KWH`, so the card
     * warning and the terms row agree about which caps matter.
     */
    protected const CAP_RELEVANCE_THRESHOLD_KWH = 30000;

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
     * The consumptions the static crawlable cost table prices, with the plain
     * Finnish household each one describes.
     *
     * @var array<int, string>
     */
    public const COST_TABLE_CONSUMPTIONS = [
        2000 => 'yksiö',
        5000 => 'kerrostalo',
        10000 => 'pieni omakotitalo',
        18000 => 'sähkölämmitteinen talo',
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

            // Honor an optional ?kulutus= deep link so the price shown matches
            // the consumption the visitor was using on the listing they came
            // from. The canonical URL stays param-free (getCanonicalUrlProperty),
            // so these query-string variants are not indexed.
            $requestedConsumption = (int) request()->query('kulutus', 0);
            if ($requestedConsumption > 0) {
                $this->consumption = $requestedConsumption;
            }

            $this->consumption = $this->clampConsumption($this->consumption, $contract);
            $this->directConsumption = $this->consumption;

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
     * Lifecycle hook (runs every request after mount/hydrate). Keeps the bill
     * module's period preset labels current and seeds its default dates.
     */
    public function booted(): void
    {
        $this->syncBillInputDefaults();
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
        $this->directConsumption = $value;
    }

    /**
     * Commit the free kWh field (Enter or blur).
     *
     * A blank or zero value is ignored so a cleared field never zeroes the
     * consumption, exactly as `ContractsList::updatedDirectConsumption()` does.
     * A real value is clamped into the supported range and then into the
     * contract's own limits, and the field is written back so the input shows
     * the value that is actually in effect.
     */
    public function updatedDirectConsumption(mixed $value = null): void
    {
        $raw = $value ?? $this->directConsumption;

        if ($raw === null || $raw === '' || ! is_numeric($raw)) {
            $this->directConsumption = $this->consumption;

            return;
        }

        $requested = (int) round((float) $raw);

        if ($requested <= 0) {
            $this->directConsumption = $this->consumption;

            return;
        }

        $clamped = max(self::MIN_FREE_CONSUMPTION, min(self::MAX_FREE_CONSUMPTION, $requested));

        $contract = $this->contract;
        if ($contract) {
            $clamped = $this->clampConsumption($clamped, $contract);
        }

        $this->consumption = $clamped;
        $this->directConsumption = $clamped;
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
     * Why a consumption chip is missing.
     *
     * A capped contract silently lost chips, so the picker looked broken rather
     * than restricted. The cap is a fact about the product, so it is stated.
     */
    public function getPresetNoticeProperty(): ?string
    {
        $contract = $this->contract;

        if (! $contract || count($this->presets) === count($this->defaultPresets)) {
            return null;
        }

        $min = $contract->consumption_limitation_min_x_kwh_per_y;
        $max = $contract->consumption_limitation_max_x_kwh_per_y;

        $range = match (true) {
            $min > 0 && $max > 0 => $this->formatKwh((int) $min).' ja '.$this->formatKwh((int) $max).' välillä',
            $max > 0 => 'enintään '.$this->formatKwh((int) $max),
            $min > 0 => 'vähintään '.$this->formatKwh((int) $min),
            default => null,
        };

        if ($range === null) {
            return null;
        }

        return "Osa kulutusvaihtoehdoista puuttuu, koska myyjä myy tätä sopimusta vain, kun vuosikulutus on {$range}.";
    }

    /**
     * The consumption the rank, the cheaper-contract list and the counterfactual
     * are calculated at.
     *
     * Those figures need every active contract priced at the same consumption,
     * which `ContractListCacheService` only precomputes for its preset
     * consumptions. Building that market-wide payload for an arbitrary number a
     * visitor types would put an uncached full-market calculation behind a text
     * field and give the cache unbounded cardinality, so a free value snaps to
     * the nearest supported preset instead. The contract's own price, receipt,
     * cost-table highlight and emissions still use the exact number, and
     * `rankBasisNotice` states the snap whenever it happens.
     */
    protected function rankConsumption(): int
    {
        if (array_key_exists('rankConsumption', $this->computedValueCache)) {
            return $this->computedValueCache['rankConsumption'];
        }

        $listCache = app(ContractListCacheService::class);

        if ($listCache->supportsConsumption($this->consumption)) {
            return $this->computedValueCache['rankConsumption'] = $this->consumption;
        }

        $nearest = $this->consumption;
        $bestDistance = null;

        foreach (ContractListCacheService::PRESET_CONSUMPTIONS as $preset) {
            $distance = abs($preset - $this->consumption);

            if ($bestDistance === null || $distance < $bestDistance) {
                $bestDistance = $distance;
                $nearest = $preset;
            }
        }

        return $this->computedValueCache['rankConsumption'] = $nearest;
    }

    /**
     * One sentence naming the comparison consumption when it is not the selected
     * one. Null when the two agree, which is every preset chip.
     */
    public function getRankBasisNoticeProperty(): ?string
    {
        $basis = $this->rankConsumption();

        if ($basis === $this->consumption) {
            return null;
        }

        return 'Sijoitus ja vaihtoehtojen hinnat on laskettu lähimmällä vertailukulutuksella '
            .$this->formatKwh($basis).'/v. Sopimuksen oma hinta-arvio käyttää tarkkaa kulutustasi '
            .$this->formatKwh($this->consumption).'/v.';
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

        $latestReplacement = $this->resolveLatestReplacementForRedirect($contract);

        if (! $latestReplacement || ! $latestReplacement->isActive()) {
            return;
        }

        $url = route('contract.detail', ['contractId' => $latestReplacement->id]);
        $response = new \Illuminate\Http\RedirectResponse($url, 301);

        throw new \Illuminate\Http\Exceptions\HttpResponseException($response);
    }

    /**
     * Resolve the latest forward replacement for redirects without lazy-loading
     * `replacedBy` / `activeContract` one link at a time. Bots commonly hit old
     * contract URLs, so keep this path bounded to fixed bulk queries.
     */
    protected function resolveLatestReplacementForRedirect(ElectricityContract $contract): ?ElectricityContract
    {
        $replacementIds = $this->getForwardReplacementChainIds($contract->id);

        if ($replacementIds->isEmpty()) {
            return null;
        }

        $depthById = $replacementIds->pluck('depth', 'id');

        return ElectricityContract::query()
            ->with('activeContract')
            ->whereIn('id', $replacementIds->pluck('id'))
            ->get()
            ->sortByDesc(fn (ElectricityContract $replacement) => $depthById[$replacement->id] ?? 0)
            ->first();
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
            ->with(['company', 'priceComponents', 'electricitySource', 'activeContract'])
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
            'version' => $this->contractPageCacheVersionHash(),
        ]));
    }

    protected function contractPageCacheVersionHash(): string
    {
        return $this->contractPageCacheVersionHashCache ??= app(ContractPageCacheVersion::class)->hash();
    }

    /**
     * Check if the contract is currently active (present in active_contracts table).
     */
    public function getIsActiveProperty(): bool
    {
        return $this->contract?->isActive() ?? false;
    }

    /**
     * The consumption the SEO surfaces (title, OG title, meta description) rank
     * at. Fixed so the title does not change when a visitor moves the
     * consumption chip.
     */
    protected const SEO_RANK_CONSUMPTION = 5000;

    /**
     * Get the contract's price rank for the SEO surfaces.
     */
    public function getPriceRankProperty(): ?int
    {
        return $this->seoRankSummary()['rank'];
    }

    /**
     * Get the size of the comparison the SEO surfaces quote.
     */
    public function getTotalContractsProperty(): int
    {
        return $this->seoRankSummary()['total'];
    }

    /**
     * Rank plus comparison size for the SEO surfaces, read from the SAME
     * eligible universe as the hero verdict box (`liveRank`/`liveTotalContracts`),
     * only pinned to the default consumption instead of the selected one.
     *
     * The two used to come from different scopes: the global 5 000 kWh rankings
     * count every active household contract whose limits allow 5 000 kWh, while
     * the hero universe is scoped to the viewed contract's own audience. On one
     * page the title said 291 contracts and the hero said 299. The global
     * rankings also always count HOUSEHOLD contracts, so a business contract's
     * title quoted a market its own hero was not ranked in.
     *
     * @return array{rank: ?int, total: int}
     */
    protected function seoRankSummary(): array
    {
        if (array_key_exists('seoRankSummary', $this->computedValueCache)) {
            return $this->computedValueCache['seoRankSummary'];
        }

        $contract = $this->contract;
        if (! $contract) {
            return $this->computedValueCache['seoRankSummary'] = ['rank' => null, 'total' => 0];
        }

        $consumption = $this->clampConsumption(self::SEO_RANK_CONSUMPTION, $contract);
        $rank = $this->rankingService()->getRankForConsumption($contract->id, $consumption);
        $total = $this->rankingService()->getTotalContractsForConsumption($contract->id, $consumption);

        if ($rank !== null && $total !== null) {
            return $this->computedValueCache['seoRankSummary'] = ['rank' => $rank, 'total' => $total];
        }

        // The per-consumption universe is unavailable, typically because the
        // contract's own limits push the basis off the cached preset
        // consumptions. Take both numbers from the global rankings together so
        // the pair still describes one comparison.
        return $this->computedValueCache['seoRankSummary'] = [
            'rank' => $this->rankingService()->getContractRank($contract->id),
            'total' => $this->rankingService()->getTotalActiveContracts(),
        ];
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
        $basis = $this->rankConsumption();
        $cacheKey = 'liveRank:' . $contract->id . ':' . $basis;
        if (array_key_exists($cacheKey, $this->computedValueCache)) {
            return $this->computedValueCache[$cacheKey];
        }

        return $this->computedValueCache[$cacheKey] = $this->rankingService()
            ->getRankForConsumption($contract->id, $basis);
    }

    public function getLiveTotalContractsProperty(): ?int
    {
        $contract = $this->contract;
        if (! $contract) {
            return null;
        }
        $basis = $this->rankConsumption();
        $cacheKey = 'liveTotalContracts:' . $contract->id . ':' . $basis;
        if (array_key_exists($cacheKey, $this->computedValueCache)) {
            return $this->computedValueCache[$cacheKey];
        }

        return $this->computedValueCache[$cacheKey] = $this->rankingService()
            ->getTotalContractsForConsumption($contract->id, $basis);
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
        $basis = $this->rankConsumption();
        $cacheKey = 'cheaperContracts:' . $contract->id . ':' . $basis;
        if (array_key_exists($cacheKey, $this->computedValueCache)) {
            return $this->computedValueCache[$cacheKey];
        }

        return $this->computedValueCache[$cacheKey] = $this->rankingService()
            ->getCheaperContracts($contract->id, $basis, 4);
    }

    /**
     * The contract directly behind this one in the ranking.
     *
     * The rank-1 hero verdict has no cheaper contract to compare against, so it
     * states the gap to the runner-up by name instead of an empty state.
     *
     * @return array{contract: ElectricityContract, total_cost: float, extra_cost: float}|null
     */
    public function getNextCheapestContractProperty(): ?array
    {
        $contract = $this->contract;
        if (! $contract) {
            return null;
        }

        $basis = $this->rankConsumption();
        $cacheKey = 'nextCheapestContract:' . $contract->id . ':' . $basis;
        if (array_key_exists($cacheKey, $this->computedValueCache)) {
            return $this->computedValueCache[$cacheKey];
        }

        return $this->computedValueCache[$cacheKey] = $this->rankingService()
            ->getNextCheapestContract($contract->id, $basis);
    }

    protected function rankingService(): ContractRankingService
    {
        return $this->rankingServiceCache ??= app(ContractRankingService::class);
    }

    /**
     * One plain-Finnish sentence under the hero price stating what that figure
     * is, per pricing category: the spot estimate basis, the market-reset
     * current-price-plus-estimate split, or fixed-price certainty.
     *
     * It lives here and not in the Blade template for the same reason card copy
     * lives in `ContractCard\ContractCardCopy`: a sentence written in a template
     * drifts away from the numbers beside it. Phase 2 of the detail-page
     * overhaul moves this onto `ContractCardPresenter`; until then keep every
     * sentence generated from typed fields only, never from seller or LLM text.
     */
    public function getPriceQualifierProperty(): ?string
    {
        $contract = $this->contract;

        if (! $contract || $this->isPricingExcluded) {
            return null;
        }

        if (array_key_exists('priceQualifier', $this->computedValueCache)) {
            return $this->computedValueCache['priceQualifier'];
        }

        $cost = $this->calculatedCost;
        $facts = $this->pricingFacts();

        $qualifier = match (true) {
            $facts->isSpot => $this->spotPriceQualifier($cost),
            // Market wins over the consumption effect, exactly as the card
            // category does, so a reset contract that also has an effect is
            // described by its reset mechanism.
            $facts->isReset => $this->resetPriceQualifier($cost, $facts),
            $facts->hasConsumptionEffect => $this->consumptionEffectPriceQualifier($cost),
            default => $this->fixedPriceQualifier($cost, $contract),
        };

        return $this->computedValueCache['priceQualifier'] = $qualifier;
    }

    /**
     * @param  array<string, mixed>  $cost
     */
    protected function spotPriceQualifier(array $cost): string
    {
        $spotAverage = $this->qualifierCents($cost['spot_price_day_avg'] ?? null);
        $margin = $this->qualifierCents($cost['spot_price_margin'] ?? null);

        if ($spotAverage === null) {
            return 'Pörssisähkössä maksat sähkön tuntihinnan, joten vuosihinta on arvio, joka perustuu viimeisen 12 kuukauden toteutuneeseen pörssikeskihintaan ja sopimuksen marginaaliin.';
        }

        $basis = $margin !== null
            ? "pörssikeskihintaan {$spotAverage} c/kWh ja sopimuksen marginaaliin {$margin} c/kWh"
            : "pörssikeskihintaan {$spotAverage} c/kWh ja sopimuksen marginaaliin";

        return "Pörssisähkössä maksat sähkön tuntihinnan, joten vuosihinta on arvio, joka perustuu viimeisen 12 kuukauden toteutuneeseen {$basis}.";
    }

    /**
     * @param  array<string, mixed>  $cost
     */
    protected function resetPriceQualifier(array $cost, \App\Services\ContractCard\DTO\PricingCategoryFacts $facts): string
    {
        $reset = is_array($cost['reset_estimate'] ?? null) ? $cost['reset_estimate'] : [];
        $current = $this->qualifierCents($reset['current_period_energy_price'] ?? $cost['general_kwh_price'] ?? null);
        $annual = $this->qualifierCents($reset['annual_equivalent_energy_price'] ?? null);
        $until = $facts->nextReset?->subDay();

        $head = $current !== null
            ? "Nykyinen energianhinta {$current} c/kWh"
            : 'Nykyisen hintajakson energianhinta';
        $head .= $until !== null
            ? ' on voimassa ' . $until->format('j.n.Y') . ' asti'
            : ' on tiedossa';

        // "Sähköfutuurit" never appears without its plain-language gloss.
        $basis = match ($reset['basis'] ?? null) {
            'forward_curve_shift' => 'tukkumarkkinan ennakkohinnoista eli sähköfutuureista',
            'spot_seasonal_index' => 'pörssisähkön usean vuoden kausivaihtelusta',
            default => null,
        };

        if ($basis === null) {
            $cadence = \App\Services\ContractCard\ContractCardCopy::cadenceAdverb($facts->cadence);

            return "{$head}, ja koska myyjä tarkistaa hinnan {$cadence}, koko vuoden hinta on arvio.";
        }

        $estimate = $annual !== null ? ", jolloin koko vuoden keskihinnaksi tulee noin {$annual} c/kWh" : '';

        return "{$head}, ja seuraavien jaksojen hinnat ovat arvio {$basis}{$estimate}.";
    }

    /**
     * @param  array<string, mixed>  $cost
     */
    protected function consumptionEffectPriceQualifier(array $cost): string
    {
        $base = $this->qualifierCents(
            $cost['general_kwh_price'] ?? $cost['daytime_kwh_price'] ?? $cost['seasonal_winter_day_kwh_price'] ?? null
        );

        $head = $base !== null
            ? "Arvio on laskettu sopimuksen kiinteällä perushinnalla {$base} c/kWh"
            : 'Arvio on laskettu sopimuksen kiinteällä perushinnalla';

        return "{$head}, ja lopullista hintaa nostaa tai laskee kulutusvaikutus, jonka suuruutta myyjä ei julkaise etukäteen.";
    }

    /**
     * @param  array<string, mixed>  $cost
     */
    protected function fixedPriceQualifier(array $cost, ElectricityContract $contract): string
    {
        $price = $this->qualifierCents($cost['general_kwh_price'] ?? null);
        $subject = $price !== null ? "Energian hinta {$price} c/kWh" : 'Energian hinta';

        $termMonths = is_numeric($cost['term_months'] ?? null) ? (int) $cost['term_months'] : null;

        // A term shorter than the compared year is fixed only for that term, so
        // the 12-month figure is an estimate and has to say so.
        if ($termMonths !== null && $termMonths > 0 && $termMonths < 12) {
            return "{$subject} ei muutu {$termMonths} kuukauden sopimusjakson aikana, mutta myyjä ei ole kertonut hintaa sen jälkeen, joten vuosihinta on arvio.";
        }

        if (in_array($contract->contract_type, ['FixedTerm', 'Fixed'], true)) {
            return "{$subject} ei muutu määräaikaisen sopimuksen aikana.";
        }

        return "{$subject} ei seuraa markkinahintaa, ja myyjän on ilmoitettava hinnanmuutoksesta etukäteen.";
    }

    protected function qualifierCents(mixed $value): ?string
    {
        return is_numeric($value) ? $this->formatCents((float) $value) : null;
    }

    /**
     * The contract name as Voltikka prints it.
     *
     * Sellers submit shouted names ("Hehku KIINTEÄ 12 kk - 0€ KUUKAUSIMAKSU ENSIMMÄISET
     * 3 KK!"). Printing that verbatim in the H1 and the title tag reads as spam, so
     * every user-visible surface goes through the shared sanitizer. The stored
     * `$contract->name` is untouched, because matching, imports and the price history
     * key off it.
     */
    public function getDisplayNameProperty(): string
    {
        $name = (string) ($this->contract?->name ?? '');

        return ContractContentSanitizer::displayName($name) ?: $name;
    }

    /**
     * Any contract name as Voltikka prints it, for the alternative-contract tiles and the
     * runner-up named in the verdict box. Same rule as the H1 and as both card templates.
     */
    public function displayNameFor(?string $name): string
    {
        return ContractContentSanitizer::displayName($name) ?: (string) $name;
    }

    /**
     * The shared contract-card view model for this page's pricing surfaces.
     *
     * The detail page is the third consumer of `ContractCard\ContractCardPresenter`, after
     * the two card templates. It exists so the page cannot show less truth than the listing
     * card that links to it: the page used to print "Energiahinta 0,00 c/kWh" on a Hybrid
     * with no consumption-effect row, label a spot contract's flat intro price "Marginaali",
     * and compute an energy price from a null margin as if it were zero. Category band,
     * receipt rows, warning pills and the seller CTA all come from here now.
     *
     * `calculated_cost` / `pricing_integrity` are set on the model because that is the shape
     * the presenter reads on listings, where the batch metric cache attaches them. Neither is
     * a database column, so nothing is persisted.
     */
    public function getCardProperty(): ?\App\Services\ContractCard\DTO\ContractCardView
    {
        $contract = $this->contract;

        if (! $contract) {
            return null;
        }

        $cacheKey = 'card:'.$this->consumption;
        if (array_key_exists($cacheKey, $this->computedValueCache)) {
            return $this->computedValueCache[$cacheKey];
        }

        $contract->calculated_cost = $this->calculatedCost;
        $contract->pricing_integrity = $this->pricingIntegrity;
        $contract->comparability = $this->pricingComparability;
        $contract->exceeds_consumption_limit = ! $contract->isConsumptionInRange($this->consumption);

        return $this->computedValueCache[$cacheKey] = app(\App\Services\ContractCard\ContractCardPresenter::class)
            ->present(
                contract: $contract,
                prices: $this->latestPrices,
                consumption: $this->consumption,
                detailed: true,
            );
    }

    /**
     * The seller's own description, cleaned for display. Null when nothing readable is
     * left, so the section is dropped rather than rendered with an empty body.
     */
    public function getDescriptionHtmlProperty(): ?string
    {
        return ContractContentSanitizer::descriptionHtml($this->contract?->extra_information_fi);
    }

    public function getDescriptionTextProperty(): ?string
    {
        return ContractContentSanitizer::descriptionText($this->contract?->long_description);
    }

    /**
     * Billing intervals with the upstream language duplicates collapsed.
     *
     * `billing_frequency` is stored as the raw localized map (`EN`/`FI`/`SV`/`Default`),
     * and every observed contract repeats the same Finnish string in all three language
     * slots, so the terms row printed "1 kk, 1 kk, 1 kk, ". A bare "12" is expanded and
     * an explicit "Ei ilmoitettu" is dropped, so the terms grid never carries a row that
     * says nothing.
     *
     * @return list<string>
     */
    public function getBillingFrequencyLabelsProperty(): array
    {
        return ContractContentSanitizer::billingFrequencyLabels($this->contract?->billing_frequency);
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

        $name = $this->truncateName($this->displayName);

        if (! $this->isActive) {
            return "{$name} ei ole enää saatavilla | Voltikka";
        }

        $rank = $this->priceRank;
        $total = $this->totalContracts;

        $comparisonTitle = $this->comparisonPageTitle($contract, $name, $rank, $total);
        if ($comparisonTitle !== null) {
            return $comparisonTitle;
        }

        // Fallback for company-only contracts without ranking
        $companyName = $contract->company?->name ?? '';

        return "{$name} — {$companyName} | Voltikka";
    }

    protected function comparisonPageTitle(ElectricityContract $contract, string $fallbackName, ?int $rank, int $total): ?string
    {
        if (! $rank || ! $total) {
            return null;
        }

        $pricePhrase = $this->titlePricePhrase($contract);
        $savings = $this->metaCheapestSavings();

        if ($rank > 25 && $savings !== null && $savings > 0) {
            return $this->buildBudgetedTitle($this->formatEuro($savings) . ' kalliimpi kuin halvin', $this->displayName);
        }

        if ($pricePhrase === null) {
            return null;
        }

        $change = $this->generalPriceHistoryChange();
        if ($change !== null && abs($change['percent']) >= 25 && $rank > 25) {
            $subject = $contract->pricing_model === 'Spot' ? 'Marginaali' : 'Hinta';
            $direction = $change['percent'] < 0 ? 'laskenut' : 'noussut';
            $percent = number_format(abs($change['percent']), 0, ',', ' ') . ' %';

            return $this->buildBudgetedTitle("{$subject} {$direction} {$percent}", $this->displayName);
        }

        return $this->buildBudgetedTitle("Sija {$rank}/{$total} · {$pricePhrase}", $this->displayName);
    }

    protected function titlePricePhrase(ElectricityContract $contract): ?string
    {
        $latest = $this->latestPrices;

        if ($contract->pricing_model === 'Spot' && isset($latest['General']['price'])) {
            return 'Marg. ' . $this->formatCents((float) $latest['General']['price']) . ' c/kWh';
        }

        if (isset($latest['General']['price'])) {
            return $this->formatCents((float) $latest['General']['price']) . ' c/kWh';
        }

        if (isset($latest['DayTime']['price'])) {
            return 'Päivä ' . $this->formatCents((float) $latest['DayTime']['price']) . ' c/kWh';
        }

        if (isset($latest['SeasonalWinter']['price'])) {
            return 'Talvi ' . $this->formatCents((float) $latest['SeasonalWinter']['price']) . ' c/kWh';
        }

        if (isset($latest['SeasonalWinterDay']['price'])) {
            return 'Talvi ' . $this->formatCents((float) $latest['SeasonalWinterDay']['price']) . ' c/kWh';
        }

        return null;
    }

    protected function buildBudgetedTitle(string $prefix, string $name): string
    {
        $suffix = ' | Voltikka';
        $separator = ' | ';
        $targetLength = 75;
        $minimumNameBudget = 24;
        $availableNameLength = $targetLength - mb_strlen($prefix) - mb_strlen($separator) - mb_strlen($suffix);
        $nameBudget = max($minimumNameBudget, $availableNameLength);
        $titleName = $this->truncateName($name, $nameBudget);

        return "{$prefix}{$separator}{$titleName}{$suffix}";
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

        $name = $this->truncateName($this->displayName);

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
            return "{$this->displayName} ei ole enää tarjolla. Katso ajantasaiset sähkösopimukset ja vaihtoehdot Voltikasta.";
        }

        $intro = $this->contractMetaIntro($contract);
        $rank = $this->priceRank;
        $total = $this->totalContracts;
        $consumption = $this->formatKwh($this->consumption);

        $historyDescription = $this->priceHistoryMetaDescription($contract, $rank, $total, $consumption);
        if ($historyDescription !== null) {
            return $historyDescription;
        }

        $totalCost = $this->metaAnnualCost();
        $cheapestSavings = $this->metaCheapestSavings();

        if ($contract->pricing_model !== 'Spot' && $totalCost !== null && $cheapestSavings !== null && $cheapestSavings > 0) {
            return $this->limitMetaDescription(
                "{$intro}. Voltikan vertailussa sen arvioitu hinta on {$this->formatEuro($totalCost)} ensimmäisen 12 kk aikana {$consumption} kulutuksella, ja se on {$this->formatEuro($cheapestSavings)} kalliimpi kuin halvin vaihtoehto."
            );
        }

        if ($rank && $total) {
            return $this->limitMetaDescription(
                "{$intro}. Voltikan vertailussa se on sijalla {$rank} / {$total}, kun vuosikulutus on {$consumption}. Katso hinta, sijoitus ja halvemmat vaihtoehdot."
            );
        }

        if ($totalCost !== null) {
            return $this->limitMetaDescription(
                "{$intro}. Arvioitu hinta on {$this->formatEuro($totalCost)} ensimmäisen 12 kk aikana {$consumption} kulutuksella. Katso hinta, ehdot ja vaihtoehdot Voltikassa."
            );
        }

        return $this->limitMetaDescription(
            "{$intro}. Katso hinta, ehdot, sijoitus ja halvemmat vaihtoehdot Voltikassa."
        );
    }

    protected function contractMetaIntro(ElectricityContract $contract): string
    {
        $phrase = $this->contractTypePhrase($contract);
        $company = trim((string) ($contract->company?->name ?? $contract->company_name ?? ''));

        if ($company !== '') {
            return "{$this->displayName} on {$phrase} yhtiöltä {$company}";
        }

        return "{$this->displayName} on {$phrase}";
    }

    protected function contractTypePhrase(ElectricityContract $contract): string
    {
        $duration = $this->durationMonthsPhrase($contract);
        $prefix = $duration ? $duration . ' ' : '';

        return match ($contract->pricing_model) {
            'Spot' => 'pörssisähkösopimus',
            'Hybrid' => $prefix . 'hybridisähkösopimus',
            'FixedPrice' => $prefix . 'kiinteähintainen sähkösopimus',
            default => match ($contract->metering) {
                'Time' => $prefix . 'aikasähkösopimus',
                'Season' => $prefix . 'kausisähkösopimus',
                default => $prefix . 'sähkösopimus',
            },
        };
    }

    protected function durationMonthsPhrase(ElectricityContract $contract): ?string
    {
        if (! in_array($contract->contract_type, ['FixedTerm', 'Fixed'], true)) {
            return null;
        }

        $value = (string) ($contract->fixed_time_range ?? '');
        $months = match ($value) {
            'Fixed6' => 6,
            'Fixed12' => 12,
            'Fixed24' => 24,
            default => null,
        };

        if ($months === null && preg_match('/(?<!\d)(6|12|24)(?!\d)/', $value, $matches)) {
            $months = (int) $matches[1];
        }

        return $months ? "{$months} kuukauden" : null;
    }

    protected function priceHistoryMetaDescription(ElectricityContract $contract, ?int $rank, int $total, string $consumption): ?string
    {
        if (! $rank || ! $total) {
            return null;
        }

        $change = $this->generalPriceHistoryChange();
        if ($change === null || abs($change['percent']) < 3) {
            return null;
        }

        $currentPrice = $this->formatCents($change['latest']);
        $monthlyFee = $this->metaMonthlyFeePhrase();
        $priceNow = $monthlyFee
            ? "{$this->displayName} maksaa nyt {$currentPrice} c/kWh + {$monthlyFee}"
            : "{$this->displayName} maksaa nyt {$currentPrice} c/kWh";

        $subject = $contract->pricing_model === 'Spot' ? 'Marginaali' : 'Energiahinta';
        $direction = $change['percent'] < 0 ? 'laskenut' : 'noussut';
        $percent = number_format(abs($change['percent']), 0, ',', ' ');
        $rankConnector = $rank > 25 ? 'mutta sopimus on silti' : 'ja sopimus on';

        return $this->limitMetaDescription(
            "{$priceNow}. {$subject} on {$direction} {$percent} % Voltikan seurannassa, {$rankConnector} sijalla {$rank} / {$total} vertailussa {$consumption} vuosikulutuksella."
        );
    }

    /**
     * @return array{latest: float, earliest: float, percent: float}|null
     */
    protected function generalPriceHistoryChange(): ?array
    {
        $rows = collect($this->priceHistory['General'] ?? [])
            ->filter(fn (array $row) => is_numeric($row['price'] ?? null) && (float) $row['price'] > 0 && ! empty($row['date']))
            ->sortBy(fn (array $row) => $row['date'])
            ->values();

        if ($rows->count() < 2) {
            return null;
        }

        $earliest = $rows->first();
        $latest = $rows->last();

        if (($earliest['date'] ?? null) === ($latest['date'] ?? null)) {
            return null;
        }

        $earliestPrice = (float) $earliest['price'];
        $latestPrice = (float) $latest['price'];

        if ($earliestPrice <= 0 || $latestPrice <= 0 || abs($latestPrice - $earliestPrice) < 0.0001) {
            return null;
        }

        return [
            'latest' => $latestPrice,
            'earliest' => $earliestPrice,
            'percent' => (($latestPrice - $earliestPrice) / $earliestPrice) * 100,
        ];
    }

    protected function metaMonthlyFeePhrase(): ?string
    {
        $monthly = $this->latestPrices['Monthly']['price'] ?? null;

        if (! is_numeric($monthly) || (float) $monthly < 0) {
            return null;
        }

        return $this->formatEurosPerMonth((float) $monthly);
    }

    protected function metaAnnualCost(): ?float
    {
        $cost = $this->calculatedCost['total_cost'] ?? null;

        return is_numeric($cost) && is_finite((float) $cost) ? (float) $cost : null;
    }

    protected function metaCheapestSavings(): ?float
    {
        $cheaperContracts = $this->cheaperContracts;
        if ($cheaperContracts->isEmpty()) {
            return null;
        }

        $savings = $cheaperContracts->first()['savings'] ?? null;

        return is_numeric($savings) ? (float) $savings : null;
    }

    protected function formatEuro(float $value): string
    {
        return number_format((int) round($value), 0, ',', ' ') . ' €';
    }

    protected function formatCents(float $value): string
    {
        return number_format($value, 2, ',', ' ');
    }

    protected function formatEurosPerMonth(float $value): string
    {
        return number_format($value, 2, ',', ' ') . ' €/kk';
    }

    protected function formatKwh(int $value): string
    {
        return number_format($value, 0, ',', ' ') . ' kWh';
    }

    protected function limitMetaDescription(string $description, int $maxLength = 260): string
    {
        $description = trim(preg_replace('/\s+/', ' ', $description) ?? $description);

        if (mb_strlen($description) <= $maxLength) {
            return $description;
        }

        $cut = mb_substr($description, 0, $maxLength - 1);
        $lastSpace = mb_strrpos($cut, ' ');
        if ($lastSpace !== false && $lastSpace > 80) {
            $cut = mb_substr($cut, 0, $lastSpace);
        }

        return rtrim($cut, ' .,;:-') . '…';
    }

    /**
     * Get the calculated cost for the contract.
     */
    public function getCalculatedCostProperty(): array
    {
        return $this->calculatedCostFor($this->consumption);
    }

    /**
     * The same calculation path as the hero price, at any consumption.
     *
     * The static per-consumption cost table reads it for 2 000 / 5 000 / 10 000 /
     * 18 000 kWh, so the table can never disagree with the figure above it.
     *
     * @return array<string, mixed>
     */
    protected function calculatedCostFor(int $consumption): array
    {
        $contract = $this->contract;

        if (! $contract) {
            return [];
        }

        $memoKey = 'calculatedCost:'.$consumption;
        if (array_key_exists($memoKey, $this->computedValueCache)) {
            return $this->computedValueCache[$memoKey];
        }

        return $this->computedValueCache[$memoKey] = $this->buildCalculatedCost($contract, $consumption);
    }

    /**
     * @return array<string, mixed>
     */
    protected function buildCalculatedCost(ElectricityContract $contract, int $consumption): array
    {
        /** @var ContractListCacheService $contractListCache */
        $contractListCache = app(ContractListCacheService::class);
        $cachedMetrics = $contractListCache->getCachedMetrics($consumption);
        $cachedContract = $cachedMetrics['contracts'][$contract->id] ?? null;

        if ($cachedContract) {
            return $cachedContract['calculated_cost'];
        }

        $usage = new EnergyUsage(
            total: $consumption,
            basicLiving: $consumption,
        );

        $canonicalPricing = app(\App\Services\CanonicalPricing\CanonicalContractPricingService::class);
        if ($canonicalPricing->enabled()) {
            return $canonicalPricing->evaluate($contract, $usage)['outcome']->toCalculatedCostArray();
        }

        $calculator = app(ContractPriceCalculator::class);
        $priceComponents = $this->getNormalizedPriceComponents($contract);

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
     * The deterministic pricing-integrity verdict for this contract, or null when
     * canonical pricing is disabled or the contract is not assessed.
     *
     * @return array<string, mixed>|null
     */
    public function getPricingIntegrityProperty(): ?array
    {
        $contract = $this->contract;
        $canonicalPricing = app(\App\Services\CanonicalPricing\CanonicalContractPricingService::class);

        if (! $contract || ! $canonicalPricing->enabled()) {
            return null;
        }

        $cachedMetrics = app(ContractListCacheService::class)->getCachedMetrics($this->consumption);
        $cachedContract = $cachedMetrics['contracts'][$contract->id] ?? null;
        if ($cachedContract !== null && array_key_exists('pricing_integrity', $cachedContract)) {
            return $cachedContract['pricing_integrity'];
        }

        $usage = new EnergyUsage(total: $this->consumption, basicLiving: $this->consumption);

        return $canonicalPricing->evaluate($contract, $usage)['integrity']->toArray();
    }

    /**
     * The comparison verdict (comparability enum value) for this contract, or null
     * when canonical pricing is disabled.
     */
    public function getPricingComparabilityProperty(): ?string
    {
        $contract = $this->contract;
        $canonicalPricing = app(\App\Services\CanonicalPricing\CanonicalContractPricingService::class);

        if (! $contract || ! $canonicalPricing->enabled()) {
            return null;
        }

        $cachedMetrics = app(ContractListCacheService::class)->getCachedMetrics($this->consumption);
        $cachedContract = $cachedMetrics['contracts'][$contract->id] ?? null;
        if ($cachedContract !== null && array_key_exists('comparability', $cachedContract)) {
            return $cachedContract['comparability'];
        }

        return $this->getCalculatedCostProperty()['comparability'] ?? null;
    }

    /**
     * Whether this contract is excluded from comparison (no reliable annual total).
     */
    public function getIsPricingExcludedProperty(): bool
    {
        $comparability = $this->pricingComparability;

        return in_array($comparability, ['excluded_incomplete', 'excluded_unknown_future'], true);
    }

    /**
     * The static per-consumption cost table.
     *
     * It renders server-side for every visitor regardless of the interactive
     * selection, because the query it answers ("paljonko tämä sopimus maksaa
     * 18 000 kWh kulutuksella?") is a search query and the answer has to be in
     * the initial HTML. It does not depend on the selected consumption, only the
     * highlighted row does, so it stays inside the cached canonical payload.
     *
     * A row outside the contract's own consumption limits is kept and marked
     * unavailable rather than dropped: a missing row reads as missing data.
     *
     * @return list<array{consumption: int, hint: string, in_range: bool, total_cost: ?float, monthly_cost: ?float}>
     */
    public function getConsumptionCostTableProperty(): array
    {
        $contract = $this->contract;

        if (! $contract || $this->isPricingExcluded) {
            return [];
        }

        $rows = [];

        foreach (self::COST_TABLE_CONSUMPTIONS as $consumption => $hint) {
            $inRange = $contract->isConsumptionInRange($consumption);
            $total = null;

            if ($inRange) {
                $cost = $this->calculatedCostFor($consumption)['total_cost'] ?? null;
                $total = is_numeric($cost) && is_finite((float) $cost) ? (float) $cost : null;
            }

            $rows[] = [
                'consumption' => $consumption,
                'hint' => $hint,
                'in_range' => $inRange,
                'total_cost' => $total,
                'monthly_cost' => $total !== null ? $total / 12 : null,
            ];
        }

        return $rows;
    }

    /**
     * The one line under the cost table that prices the alternative the visitor
     * is really deciding against: a fixed or market-reset contract is compared
     * with a typical pörssisähkö contract, a spot contract with the cheapest
     * fully fixed one. It is the "price of certainty" both ways round.
     *
     * The sentence lives in PHP, generated from typed fields only, for the same
     * reason as `getPriceQualifierProperty()`: a Finnish sentence written in a
     * template drifts away from the numbers beside it.
     *
     * Both sides are read at `rankConsumption()`, so the figure it quotes and
     * the figure it compares against are always priced at the same consumption,
     * and the sentence names that consumption itself.
     *
     * @return array{text: string, url: string, label: string}|null
     */
    public function getSpotCounterfactualProperty(): ?array
    {
        $contract = $this->contract;

        if (! $contract || $this->isPricingExcluded) {
            return null;
        }

        $basis = $this->rankConsumption();
        $memoKey = 'spotCounterfactual:'.$basis;
        if (array_key_exists($memoKey, $this->computedValueCache)) {
            return $this->computedValueCache[$memoKey];
        }

        return $this->computedValueCache[$memoKey] = $this->buildSpotCounterfactual($contract, $basis);
    }

    /**
     * @return array{text: string, url: string, label: string}|null
     */
    protected function buildSpotCounterfactual(ElectricityContract $contract, int $basis): ?array
    {
        $selfCost = $this->calculatedCostFor($basis)['total_cost'] ?? null;

        if (! is_numeric($selfCost) || ! is_finite((float) $selfCost)) {
            return null;
        }

        $selfCost = (float) $selfCost;
        $bucket = \App\Services\ContractCard\Enums\PricingBucket::fromFacts($this->pricingFacts());
        $isSpot = $bucket === \App\Services\ContractCard\Enums\PricingBucket::Spot;

        $reference = $isSpot
            ? \App\Services\ContractCard\Enums\PricingBucket::Fixed
            : \App\Services\ContractCard\Enums\PricingBucket::Spot;

        $summary = $this->rankingService()->getBucketCostSummary($contract->id, $basis, $reference);

        // A spot contract is compared with the CHEAPEST fixed price, because
        // certainty is something the visitor buys deliberately and they would
        // buy the cheapest of it. A fixed or reset contract is compared with the
        // MEDIAN spot contract, because "what if I had taken pörssisähkö" is a
        // question about the typical outcome, not the best one on the market.
        $referenceCost = $isSpot ? ($summary['cheapest_cost'] ?? null) : ($summary['median_cost'] ?? null);

        if (! is_numeric($referenceCost) || ! is_finite((float) $referenceCost)) {
            return null;
        }

        $referenceCost = (float) $referenceCost;
        $difference = abs($referenceCost - $selfCost);
        $consumptionLabel = $this->formatKwh($basis);
        $referenceEuro = $this->formatEuro($referenceCost);

        if ($isSpot) {
            $head = "Vertailun vuoksi: halvin kiinteähintainen sopimus maksaisi samalla {$consumptionLabel} kulutuksella arviolta {$referenceEuro} vuodessa.";

            $tail = match (true) {
                $difference < 1 => 'Ero tämän sopimuksen 12 kuukauden arvioon on alle euron vuodessa.',
                $referenceCost > $selfCost => 'Hintavarmuudesta maksaisit siis arviolta '.$this->formatEuro($difference).' vuodessa.',
                default => 'Kiinteä hinta olisi siis arviolta '.$this->formatEuro($difference).' vuodessa edullisempi.',
            };

            return [
                'text' => "{$head} {$tail}",
                'url' => '/sahkosopimus/kiintea-hinta',
                'label' => 'Katso kiinteähintaiset sopimukset',
            ];
        }

        $head = "Vertailun vuoksi: tyypillinen pörssisähkösopimus maksaisi samalla {$consumptionLabel} kulutuksella arviolta {$referenceEuro} vuodessa, kun laskennassa käytetään viimeisen 12 kuukauden toteutunutta pörssikeskihintaa.";

        $tail = match (true) {
            $difference < 1 => 'Ero tämän sopimuksen 12 kuukauden arvioon on alle euron vuodessa.',
            $selfCost > $referenceCost => 'Hintavarmuudesta maksat siis arviolta '.$this->formatEuro($difference).' vuodessa.',
            default => 'Tämä sopimus on siis arviolta '.$this->formatEuro($difference).' vuodessa edullisempi.',
        };

        return [
            'text' => "{$head} {$tail}",
            'url' => '/sahkosopimus/porssisahko',
            'label' => 'Katso pörssisopimukset',
        ];
    }

    /**
     * The cheapest contract of the same pricing type, for the alternatives
     * module.
     *
     * The two cheapest contracts on the page are almost always pörssisähkö,
     * because ranking puts spot on top. A visitor who came for a fixed price is
     * then offered nothing they would actually buy, so one same-type option sits
     * beside them.
     *
     * @return array{contract: ElectricityContract, total_cost: float, savings: float, label: string}|null
     */
    public function getSameTypeAlternativeProperty(): ?array
    {
        $contract = $this->contract;

        if (! $contract || $this->isPricingExcluded) {
            return null;
        }

        $basis = $this->rankConsumption();
        $memoKey = 'sameTypeAlternative:'.$basis;
        if (array_key_exists($memoKey, $this->computedValueCache)) {
            return $this->computedValueCache[$memoKey];
        }

        $facts = $this->pricingFacts();
        $bucket = \App\Services\ContractCard\Enums\PricingBucket::fromFacts($facts);
        $summary = $this->rankingService()->getBucketCostSummary($contract->id, $basis, $bucket);

        $alternativeId = $summary['cheapest_id'] ?? null;
        $alternativeCost = $summary['cheapest_cost'] ?? null;

        if ($alternativeId === null || ! is_numeric($alternativeCost)) {
            return $this->computedValueCache[$memoKey] = null;
        }

        $alternative = ElectricityContract::query()->with('company')->find($alternativeId);

        if (! $alternative) {
            return $this->computedValueCache[$memoKey] = null;
        }

        $selfCost = $this->calculatedCostFor($basis)['total_cost'] ?? null;

        return $this->computedValueCache[$memoKey] = [
            'contract' => $alternative,
            'total_cost' => (float) $alternativeCost,
            'savings' => is_numeric($selfCost) ? (float) $selfCost - (float) $alternativeCost : 0.0,
            'label' => $facts->category->label(),
        ];
    }

    /**
     * The hero's fused price + verdict statement.
     *
     * Phase 4 dissolved the boxed "verdict card" (a tier strip plus two labelled
     * cells) into one sentence beside the price, because the box competed with the
     * price it was supposed to qualify and its tier strip carried emerald/amber/red
     * price semantics that DESIGN.md reserves for measured emissions.
     *
     * Every string here is generated from typed fields, for the same reason as
     * `getPriceQualifierProperty()`: a Finnish sentence written in Blade drifts away
     * from the numbers beside it. Every figure is read at `rankConsumption()`, so
     * the whole statement moves with the consumption picker.
     *
     * @return array{rank: int, total: int, marker_percent: float, comparison: ?string, show_cheaper_link: bool, note: string}|null
     */
    public function getHeroVerdictProperty(): ?array
    {
        $contract = $this->contract;

        if (! $contract || $this->isPricingExcluded) {
            return null;
        }

        $basis = $this->rankConsumption();
        $memoKey = 'heroVerdict:'.$basis;
        if (array_key_exists($memoKey, $this->computedValueCache)) {
            return $this->computedValueCache[$memoKey];
        }

        $rank = $this->liveRank;
        $total = $this->liveTotalContracts;

        if ($rank === null || $total === null || $total < 1) {
            return $this->computedValueCache[$memoKey] = null;
        }

        // Keep the marker inside the rail so rank 1 and the last rank are both visible.
        $markerPercent = $total > 1 ? (($rank - 1) / ($total - 1)) * 100 : 0.0;
        $markerPercent = max(2.0, min(98.0, $markerPercent));

        return $this->computedValueCache[$memoKey] = [
            'rank' => $rank,
            'total' => $total,
            'marker_percent' => round($markerPercent, 1),
            'comparison' => $this->heroVerdictComparison($rank),
            'show_cheaper_link' => $rank > 1 && $this->cheaperContracts->isNotEmpty(),
            'note' => $this->heroVerdictNote($basis),
        ];
    }

    /**
     * The money half of the verdict line.
     *
     * Rank 1 compares against the runner-up by name, never against nothing: the page
     * with the strongest claim to make must not fall silent, and `cheaperContracts`
     * is empty by definition at rank 1.
     */
    protected function heroVerdictComparison(int $rank): ?string
    {
        if ($rank === 1) {
            $next = $this->nextCheapestContract;

            if ($next === null) {
                // A standalone statement rather than a comparison clause, so it keeps
                // its capital after the separator.
                return 'Ainoa vertailukelpoinen sopimus tällä kulutuksella';
            }

            $name = $this->displayNameFor($next['contract']->name ?? null);
            $gap = (float) ($next['extra_cost'] ?? 0);

            return $gap >= 1
                ? 'n. '.$this->nonBreakingMoney($this->formatEuro($gap).'/v').' halvempi kuin seuraavaksi halvin ('.$name.')'
                : 'yhtä edullinen kuin seuraavaksi halvin ('.$name.')';
        }

        $cheapest = $this->cheaperContracts->first();

        if ($cheapest === null) {
            return null;
        }

        $gap = (float) ($cheapest['savings'] ?? 0);
        $cheapestCost = $cheapest['total_cost'] ?? null;

        if ($gap < 1) {
            return 'yhtä edullinen kuin vertailun halvin sopimus';
        }

        $line = $this->nonBreakingMoney($this->formatEuro($gap).'/v').' kalliimpi kuin halvin';

        return is_numeric($cheapestCost)
            ? $line.' ('.$this->nonBreakingMoney($this->formatEurosPerMonth((float) $cheapestCost / 12)).')'
            : $line;
    }

    /**
     * Glue a figure to its unit with a non-breaking space.
     *
     * The verdict line is the one place on the page where a money figure sits inside
     * parentheses in running text, and at 390 px a normal space let "(34,05" and
     * "€/kk)" land on two lines. Mid-parenthetical wraps were a Phase 4 defect.
     */
    protected function nonBreakingMoney(string $value): string
    {
        return str_replace(' €', "\u{00A0}€", $value);
    }

    /**
     * The small print under the verdict line: the date, the basis, and why the
     * contracts at the top of the comparison are what they are.
     *
     * The spot sentence is measured, not assumed: it counts the loaded cheapest
     * contracts, and it says "vertailun halvimmat", which is exactly the set it
     * counted. Claiming something about every contract ahead would need a query
     * this page does not run.
     */
    protected function heroVerdictNote(int $basis): string
    {
        $note = 'Sijoitus '.now()->format('j.n.Y').', perustuu 12 kuukauden hinta-arvioon '
            .$this->formatKwh($basis).' vuosikulutuksella.';

        if ($this->pricingFacts()->isSpot) {
            return $note;
        }

        $cheapest = $this->cheaperContracts;

        if ($cheapest->isEmpty()) {
            return $note;
        }

        $spotCount = $cheapest->filter(fn (array $row): bool => ($row['contract']->pricing_model ?? null) === 'Spot')->count();

        if ($spotCount * 2 <= $cheapest->count()) {
            return $note;
        }

        return $note.' Vertailun halvimmat sopimukset ovat pääosin pörssisähköä, jonka vuosihinta on arvio.';
    }

    /**
     * Quiet notes under the itemised price rows.
     *
     * Both replace a block that used to duplicate something else on the page: the
     * market-reset facts repeated the hero qualifier inside a boxed notice, and the
     * promotion had a "TARJOUS" mini-hero with its own price, strikethrough normal
     * price and green savings chip, which restated the hero price in sales-page
     * visual language.
     *
     * @return list<string>
     */
    public function getReceiptNotesProperty(): array
    {
        if (! $this->contract || $this->isPricingExcluded) {
            return [];
        }

        $cost = $this->calculatedCost;
        $notes = [];

        $reset = \App\Services\CanonicalPricing\MarketReset\ResetEstimateCopy::receiptNote(
            is_array($cost['reset_estimate'] ?? null) ? $cost['reset_estimate'] : null
        );

        if ($reset !== null) {
            $notes[] = $reset;
        }

        $savings = $cost['discount_savings_total'] ?? null;

        if (($cost['includes_discounts'] ?? false) && is_numeric($savings) && (float) $savings > 0) {
            $notes[] = 'Tarjous on huomioitu arviossa vain voimassaoloajaltaan: säästät noin '
                .$this->formatEuro((float) $savings).' ensimmäisenä vuonna verrattuna normaalihintaan.';
        }

        return $notes;
    }

    /**
     * Whether the hero's pricing-category label can link to the FAQ item that
     * explains the mechanism. The item is dropped when its facts are missing, and a
     * link to an anchor that does not exist is worse than a plain label.
     */
    public function getHasPricingMechanismFaqProperty(): bool
    {
        foreach ($this->faqItems as $item) {
            if (($item['id'] ?? null) === 'faq-miten') {
                return true;
            }
        }

        return false;
    }

    /**
     * The "Kannattaako X?" verdict paragraphs.
     *
     * Two generated paragraphs: where the contract sits in the comparison at the
     * selected consumption, and what its pricing type means for the buyer. Both
     * are assembled from typed fields only, never from seller or LLM text, for
     * the same reason as `getPriceQualifierProperty()` and the counterfactual.
     *
     * Every figure in it is read at `rankConsumption()`, so the paragraph moves
     * with the consumption picker instead of quoting a frozen 5 000 kWh market.
     *
     * @return array{heading: string, paragraphs: list<string>, basis: string, show_cheaper_link: bool}|null
     */
    public function getVerdictProperty(): ?array
    {
        $contract = $this->contract;

        if (! $contract || $this->isPricingExcluded) {
            return null;
        }

        $basis = $this->rankConsumption();
        $memoKey = 'verdict:'.$basis;
        if (array_key_exists($memoKey, $this->computedValueCache)) {
            return $this->computedValueCache[$memoKey];
        }

        $rank = $this->liveRank;
        $total = $this->liveTotalContracts;

        if ($rank === null || $total === null || $total < 1) {
            return $this->computedValueCache[$memoKey] = null;
        }

        return $this->computedValueCache[$memoKey] = [
            'heading' => 'Kannattaako '.$this->displayName.'?',
            'paragraphs' => [
                $this->verdictPositionParagraph($rank, $total, $basis),
                $this->verdictCharacterParagraph($contract),
            ],
            'basis' => 'Arvio perustuu sopimuksen hintasijoitukseen, hintatyyppiin ja 12 kuukauden hinta-arvioon '
                .$this->formatKwh($basis).' vuosikulutuksella.',
            'show_cheaper_link' => $rank > 1 && $this->cheaperContracts->isNotEmpty(),
        ];
    }

    /**
     * Where the contract sits in the comparison, and how big the money gap is.
     *
     * Rank 1 states the lead over the runner-up, exactly as the hero verdict box
     * does, because `cheaperContracts` is empty by definition at rank 1 and the
     * page with the strongest claim must not fall silent.
     */
    protected function verdictPositionParagraph(int $rank, int $total, int $basis): string
    {
        $name = $this->displayName;
        $consumption = $this->formatKwh($basis);
        $totalLabel = number_format($total, 0, ',', ' ');

        if ($rank === 1) {
            $head = "{$name} on 12 kuukauden hinta-arviolla vertailun halvin: {$totalLabel} vertaillusta sopimuksesta "
                ."yksikään ei ole edullisempi {$consumption} vuosikulutuksella.";

            $next = $this->nextCheapestContract;

            if ($next === null) {
                return $head.' Tällä kulutuksella vertailussa ei ole muita sopimuksia.';
            }

            $nextName = $this->displayNameFor($next['contract']->name ?? null);
            $gap = (float) ($next['extra_cost'] ?? 0);

            return $gap >= 1
                ? $head." Seuraavaksi halvin sopimus ({$nextName}) maksaa arviolta ".$this->formatEuro($gap).' vuodessa enemmän.'
                : $head." Seuraavaksi halvin sopimus ({$nextName}) maksaa arviolta saman verran.";
        }

        $cheaper = $rank - 1;
        $pricier = max(0, $total - $rank);
        $percentile = $rank / $total;

        // The absolute top-25 tier also needs the percentile guard the hero verdict
        // does not: in a small universe rank 2 of 2 is the most expensive contract
        // there is, and calling it "kärkipäässä" would contradict the counts in the
        // same sentence.
        $tier = match (true) {
            $rank <= 25 && $percentile <= 0.33 => 'vertailun kärkipäässä',
            $percentile <= 0.33 => 'vertailun edullisemmassa kolmanneksessa',
            $percentile <= 0.66 => 'markkinoiden keskitasoa',
            default => 'vertailun kalliimmassa päässä',
        };

        $counts = $cheaper === 1 ? '1 on halvempi' : number_format($cheaper, 0, ',', ' ').' on halvempia';
        if ($pricier > 0) {
            $counts .= $pricier === 1 ? ' ja 1 kalliimpi' : ' ja '.number_format($pricier, 0, ',', ' ').' kalliimpia';
        }

        $head = "{$name} on hinnaltaan {$tier}: {$totalLabel} vertaillusta sopimuksesta {$counts} {$consumption} vuosikulutuksella.";

        $cheapest = $this->cheaperContracts->first();

        if ($cheapest === null) {
            return $head;
        }

        $gap = (float) ($cheapest['savings'] ?? 0);

        if ($gap < 1) {
            return $head.' Ero vertailun halvimpaan sopimukseen on alle euron vuodessa.';
        }

        return $head.' Vertailun halvimpaan sopimukseen verrattuna maksat arviolta '.$this->formatEuro($gap)
            .' vuodessa enemmän, eli noin '.$this->formatEurosPerMonth($gap / 12).'.';
    }

    /**
     * What the pricing type means for the buyer, and who the contract suits.
     *
     * Same decision order as the card band and the hero qualifier: spot, then
     * market reset, then consumption effect, then fixed.
     */
    /**
     * The contract's fixed-term length in months, when it is an exact number.
     *
     * `calculated_cost.term_months` is the primary source, but it is null whenever
     * another branch of the calculator claimed the contract first (a Hybrid is costed
     * base-only and reports no term), so `fixed_time_range` is the fallback. Only its
     * exact buckets are used; a range like `Between711` is not a number.
     */
    protected function termMonths(): ?int
    {
        $months = $this->calculatedCost['term_months'] ?? null;

        if (is_numeric($months) && (int) $months > 0) {
            return (int) $months;
        }

        return match ($this->contract?->fixed_time_range) {
            'Fixed6' => 6,
            'Fixed12' => 12,
            'Fixed24' => 24,
            default => null,
        };
    }

    protected function verdictCharacterParagraph(ElectricityContract $contract): string
    {
        $facts = $this->pricingFacts();

        if ($facts->isSpot) {
            return 'Pörssisähkössä hinta muuttuu joka tunti, joten vuosihinta on aina arvio. Sopimus sopii, jos siedät '
                .'hintavaihtelua tai voit siirtää kulutustasi halvoille tunneille. Jos haluat tasaisen ja ennustettavan '
                .'laskun, kiinteähintainen sopimus on turvallisempi valinta.';
        }

        if ($facts->isReset) {
            $cadence = \App\Services\ContractCard\ContractCardCopy::cadenceAdverb($facts->cadence);

            return "Hinta tarkistetaan {$cadence}, joten se on pörssisähköä tasaisempi mutta seuraa markkinaa kiinteää "
                .'hintaa nopeammin. Sopimus sopii, jos haluat pörssisähköä tasaisemman hinnan sitoutumatta kiinteään '
                .'vuosihintaan. Jos haluat tietää vuosihinnan tarkasti etukäteen, valitse kiinteähintainen sopimus.';
        }

        if ($facts->hasConsumptionEffect) {
            return 'Perushinta on kiinteä, mutta lopullista hintaa nostaa tai laskee kulutusvaikutus, jonka suuruutta '
                .'myyjä ei julkaise etukäteen. Sopimus sopii, jos hyväksyt, että lopullinen hinta riippuu siitä, mihin '
                .'aikaan käytät sähköä. Jos haluat tietää hinnan tarkasti etukäteen, valitse sopimus ilman '
                .'kulutusvaikutusta.';
        }

        $termMonths = $this->termMonths();

        if ($termMonths !== null) {
            return "Energian hinta ei muutu {$termMonths} kuukauden sopimusjakson aikana, joten lasku on ennustettava. "
                .'Sopimus sopii, jos haluat tietää hinnan etukäteen etkä halua seurata markkinahintaa. Vastineeksi et '
                .'hyödy siitä, jos markkinahinta laskee sopimuskauden aikana.';
        }

        return 'Energian hinta ei seuraa markkinaa, mutta myyjä voi muuttaa sitä ilmoittamalla siitä etukäteen. Sopimus '
            .'sopii, jos haluat tasaisen laskun ilman määräaikaa. Vastineeksi hinta ei laske automaattisesti, vaikka '
            .'markkinahinta laskisi.';
    }

    /**
     * The "Usein kysyttyä" items, and the only source for the FAQPage JSON-LD.
     *
     * One list drives both the visible block and the schema, exactly as
     * `ConsumptionCalculator::getFaqItemsProperty()` does, so the two can never
     * drift. Every answer is generated from typed fields; an item whose facts
     * are missing is dropped rather than answered with "ei tietoa".
     *
     * @return list<array{id: string, question: string, answer: string}>
     */
    public function getFaqItemsProperty(): array
    {
        $contract = $this->contract;

        if (! $contract) {
            return [];
        }

        $memoKey = 'faqItems:'.$this->consumption;
        if (array_key_exists($memoKey, $this->computedValueCache)) {
            return $this->computedValueCache[$memoKey];
        }

        $facts = $this->pricingFacts();
        $cost = $this->calculatedCost;

        return $this->computedValueCache[$memoKey] = array_values(array_filter([
            $this->faqCostItem($cost, $facts),
            $this->faqMechanismItem($contract, $cost, $facts),
            $facts->isSpot ? $this->faqSpotVariationItem($cost) : null,
            $this->faqCancellationItem($contract),
            $this->faqMethodItem(),
        ]));
    }

    /**
     * FAQPage JSON-LD carrying exactly the rendered question/answer pairs.
     */
    public function getFaqSchemaProperty(): array
    {
        if (! $this->contract || ! $this->isActive) {
            return [];
        }

        $items = $this->faqItems;

        if (empty($items)) {
            return [];
        }

        return [
            '@context' => 'https://schema.org',
            '@type' => 'FAQPage',
            '@id' => $this->canonicalUrl.'#faq',
            'mainEntity' => array_map(fn (array $item): array => [
                '@type' => 'Question',
                'name' => $item['question'],
                'acceptedAnswer' => [
                    '@type' => 'Answer',
                    'text' => $item['answer'],
                ],
            ], $items),
        ];
    }

    /**
     * @param  array<string, mixed>  $cost
     * @return array{id: string, question: string, answer: string}|null
     */
    protected function faqCostItem(array $cost, \App\Services\ContractCard\DTO\PricingCategoryFacts $facts): ?array
    {
        if ($this->isPricingExcluded) {
            return null;
        }

        $total = $cost['total_cost'] ?? null;

        if (! is_numeric($total) || ! is_finite((float) $total)) {
            return null;
        }

        $total = (float) $total;

        return [
            'id' => 'faq-hinta',
            'question' => 'Paljonko '.$this->displayName.' maksaa '.$this->formatKwh($this->consumption).' vuosikulutuksella?',
            'answer' => 'Arviolta '.$this->formatEurosPerMonth($total / 12).' eli '.$this->formatEuro($total)
                .' vuodessa. Luku on sähköenergian hinta, joka sisältää arvonlisäveron 25,5 % mutta ei sähkön siirtoa. '
                .$this->faqCostBasisSentence($cost, $facts),
        ];
    }

    /**
     * @param  array<string, mixed>  $cost
     */
    protected function faqCostBasisSentence(array $cost, \App\Services\ContractCard\DTO\PricingCategoryFacts $facts): string
    {
        if ($facts->isSpot) {
            return 'Arvio perustuu viimeisen 12 kuukauden toteutuneeseen pörssikeskihintaan ja sopimuksen marginaaliin, '
                .'joten toteutunut vuosihinta voi poiketa arviosta.';
        }

        if ($facts->isReset) {
            $cadence = \App\Services\ContractCard\ContractCardCopy::cadenceAdverb($facts->cadence);

            return "Nykyisen hintajakson energianhinta on tiedossa, ja tulevat jaksot ovat arvio, koska myyjä tarkistaa "
                ."hinnan {$cadence}.";
        }

        if ($facts->hasConsumptionEffect) {
            return 'Arviossa ei ole mukana kulutusvaikutusta, jonka suuruutta myyjä ei julkaise etukäteen.';
        }

        $termMonths = is_numeric($cost['term_months'] ?? null) ? (int) $cost['term_months'] : null;

        if ($termMonths !== null && $termMonths > 0 && $termMonths < 12) {
            return "Energian hinta on kiinteä {$termMonths} kuukauden ajan, joten loppuvuoden osuus luvusta on arvio.";
        }

        return 'Energian hinta on kiinteä, joten luku muuttuu vain jos vuosikulutuksesi poikkeaa vertailussa käytetystä.';
    }

    /**
     * The pricing-mechanism explainer. It carries a stable anchor because the
     * hero's pricing-category label links here: the mechanism is the single most
     * important thing a visitor needs explained, and it used to be a dead label.
     *
     * @param  array<string, mixed>  $cost
     * @return array{id: string, question: string, answer: string}|null
     */
    protected function faqMechanismItem(
        ElectricityContract $contract,
        array $cost,
        \App\Services\ContractCard\DTO\PricingCategoryFacts $facts,
    ): ?array {
        $margin = $this->qualifierCents($cost['spot_price_margin'] ?? null);
        $fee = is_numeric($cost['monthly_fixed_fee'] ?? null) ? (float) $cost['monthly_fixed_fee'] : null;
        $feeAmount = $fee !== null && $fee > 0 ? $this->formatEurosPerMonth($fee) : null;

        if ($facts->isSpot) {
            $marginPhrase = $margin !== null
                ? "myyjän oman lisän eli marginaalin {$margin} c/kWh"
                : 'myyjän oman lisän eli marginaalin';
            $feePhrase = $feeAmount !== null ? " sekä perusmaksun {$feeAmount}" : '';

            return [
                'id' => 'faq-miten',
                'question' => 'Miten pörssisähkön hinta muodostuu?',
                'answer' => "Maksat sähköstä pörssin tuntihinnan sellaisenaan ja sen päälle {$marginPhrase}{$feePhrase}. "
                    .'Hinta vaihtelee joka tunti: yöllä ja tuulisina päivinä sähkö on usein halpaa, kylminä ja tyyninä '
                    .'talvipäivinä kallista. Siksi vuosihinta on aina arvio, joka perustuu toteutuneeseen keskihintaan.',
            ];
        }

        if ($facts->isReset) {
            $cadence = \App\Services\ContractCard\ContractCardCopy::cadenceAdverb($facts->cadence);
            $until = $facts->nextReset?->subDay();
            $periodPhrase = $until !== null
                ? ' Nykyinen hintajakso päättyy '.$until->format('j.n.Y').'.'
                : '';

            return [
                'id' => 'faq-miten',
                'question' => 'Miten tämän sopimuksen hinta määräytyy?',
                'answer' => "Myyjä tarkistaa energianhinnan {$cadence} tukkumarkkinan hintatason perusteella, ja hinta "
                    ."pysyy samana yhden jakson ajan.{$periodPhrase} Hinta on siis pörssisähköä tasaisempi mutta seuraa "
                    .'markkinaa kiinteää hintaa nopeammin. Tulevien jaksojen hintoja ei tiedetä etukäteen, joten koko '
                    .'vuoden hinta on arvio.',
            ];
        }

        if ($facts->hasConsumptionEffect) {
            $base = $this->qualifierCents(
                $cost['general_kwh_price'] ?? $cost['daytime_kwh_price'] ?? $cost['seasonal_winter_day_kwh_price'] ?? null
            );
            $basePhrase = $base !== null ? " {$base} c/kWh" : '';

            return [
                'id' => 'faq-miten',
                'question' => 'Mikä kulutusvaikutus on?',
                'answer' => "Sopimuksessa on kiinteä perushinta{$basePhrase}, jonka päälle lisätään tai josta vähennetään "
                    .'kulutusvaikutus. Kulutusvaikutus riippuu siitä, mihin aikaan käytät sähköä verrattuna muihin '
                    .'asiakkaisiin. Myyjä ei julkaise vaikutuksen suuruutta etukäteen, joten vertailuhinta lasketaan '
                    .'ilman sitä.',
            ];
        }

        $price = $this->qualifierCents($cost['general_kwh_price'] ?? null);
        $pricePhrase = $price !== null ? " {$price} c/kWh" : '';
        $termMonths = $this->termMonths();

        $feeSentence = $feeAmount !== null ? " Lisäksi maksat perusmaksun {$feeAmount}." : '';

        $tail = $termMonths !== null
            ? "Hinta on voimassa koko {$termMonths} kuukauden sopimuskauden ajan."
            : 'Sopimus on voimassa toistaiseksi, joten myyjä voi muuttaa hintaa ilmoittamalla siitä etukäteen.';

        return [
            'id' => 'faq-miten',
            'question' => 'Miten kiinteä hinta toimii?',
            'answer' => "Energian hinta on sovittu etukäteen{$pricePhrase} eikä se seuraa pörssin tai tukkumarkkinan "
                ."liikkeitä.{$feeSentence} {$tail}",
        ];
    }

    /**
     * How much a spot price actually moves. The realized monthly averages are
     * the honest answer; without them the item is dropped rather than guessed.
     *
     * @param  array<string, mixed>  $cost
     * @return array{id: string, question: string, answer: string}|null
     */
    protected function faqSpotVariationItem(array $cost): ?array
    {
        $range = $this->spotMonthlyPriceRange();

        if ($range === null) {
            return null;
        }

        $margin = $this->qualifierCents($cost['spot_price_margin'] ?? null);
        $marginSentence = $margin !== null
            ? " Näiden päälle tulee sopimuksen marginaali {$margin} c/kWh."
            : '';

        return [
            'id' => 'faq-vaihtelu',
            'question' => 'Kuinka paljon pörssisähkön hinta vaihtelee?',
            'answer' => 'Viimeisen 12 kuukauden aikana pörssisähkön kuukausikeskihinta on vaihdellut välillä '
                .$this->formatCents($range['min']).' ja '.$this->formatCents($range['max']).' c/kWh.'.$marginSentence
                .' Yksittäisten tuntien vaihtelu on paljon suurempaa. Jos voit siirtää kulutusta halvoille tunneille, '
                .'oma keskihintasi voi jäädä markkinan keskiarvoa matalammaksi.',
        ];
    }

    /**
     * Realized monthly spot averages for the last twelve months, incl. VAT.
     *
     * @return array{min: float, max: float}|null
     */
    protected function spotMonthlyPriceRange(): ?array
    {
        if (array_key_exists('spotMonthlyPriceRange', $this->computedValueCache)) {
            return $this->computedValueCache['spotMonthlyPriceRange'];
        }

        $averages = SpotPriceAverage::query()
            ->forRegion('FI')
            ->ofType(SpotPriceAverage::PERIOD_MONTHLY)
            ->whereNotNull('avg_price_with_tax')
            ->orderByDesc('period_start')
            ->limit(12)
            ->pluck('avg_price_with_tax')
            ->map(fn ($value) => (float) $value)
            ->all();

        // Fewer than three months is not a variation range, it is two data points.
        if (count($averages) < 3) {
            return $this->computedValueCache['spotMonthlyPriceRange'] = null;
        }

        return $this->computedValueCache['spotMonthlyPriceRange'] = [
            'min' => min($averages),
            'max' => max($averages),
        ];
    }

    /**
     * @return array{id: string, question: string, answer: string}|null
     */
    protected function faqCancellationItem(ElectricityContract $contract): ?array
    {
        $terms = $this->cancellationTerm($contract);

        if ($terms === null) {
            return null;
        }

        if ($contract->contract_type === 'OpenEnded') {
            return [
                'id' => 'faq-irtisanominen',
                'question' => 'Voiko sopimuksen irtisanoa milloin vain?',
                'answer' => 'Kyllä. Sopimus on voimassa toistaiseksi, ja kuluttajan irtisanomisaika on kaksi viikkoa. '
                    .'Käytännössä uusi sähköyhtiö hoitaa irtisanomisen puolestasi, kun teet uuden sopimuksen. Tarkista '
                    .'silti ehdot myyjän sivuilta ennen tilausta.',
            ];
        }

        $termMonths = $this->termMonths();
        $termPhrase = $termMonths !== null ? " {$termMonths} kuukauden" : ' sovitun';

        $afterTerm = $this->pricingComparability === 'term_price_only'
            ? ' Myyjä ei ole julkaissut hintaa määräajan jälkeen, joten kysy jatkohinta myyjältä ennen kauden loppua.'
            : '';

        return [
            'id' => 'faq-irtisanominen',
            'question' => 'Voiko määräaikaisen sopimuksen irtisanoa kesken kauden?',
            'answer' => "Määräaikainen sopimus sitoo molempia osapuolia{$termPhrase} sopimuskauden ajan, eikä sitä yleensä "
                ."voi irtisanoa kesken kauden.{$afterTerm} Tarkista ehdot myyjän sivuilta ennen tilausta.",
        ];
    }

    /**
     * @return array{id: string, question: string, answer: string}
     */
    protected function faqMethodItem(): array
    {
        return [
            'id' => 'faq-menetelma',
            'question' => 'Mistä Voltikan hinta-arvio tulee?',
            'answer' => 'Laskemme kaikille sopimuksille vertailukelpoisen 12 kuukauden hinnan samalla menetelmällä: '
                .'tiedossa olevat hinnat käytetään sellaisenaan ja tuntemattomat hintajaksot merkitään arvioksi. '
                .'Luvussa on mukana energian hinta ja perusmaksu valitulla vuosikulutuksella, mutta ei sähkön siirtoa. '
                .'Sama menetelmä koskee kaikkia myyjiä.',
        ];
    }

    /**
     * The structured "Sopimusehdot lyhyesti" grid.
     *
     * Only rows whose data exists are returned; a terms grid full of "ei tietoa"
     * teaches the visitor nothing and reads as missing data. Everything here
     * used to be scattered across the old "Laskutus ja ehdot" box, which printed
     * "Alueellinen" for a NULL availability flag because it tested truthiness.
     *
     * @return list<array{label: string, value: string}>
     */
    public function getContractTermsProperty(): array
    {
        $contract = $this->contract;

        if (! $contract) {
            return [];
        }

        $rows = [];

        $duration = \App\Services\ContractCard\ContractCardCopy::durationLabel(
            $contract->contract_type,
            $contract->fixed_time_range,
        );

        if ($duration !== null) {
            $rows[] = ['label' => 'Sopimuskausi', 'value' => $duration];
        }

        $cancellation = $this->cancellationTerm($contract);

        if ($cancellation !== null) {
            $rows[] = $cancellation;
        }

        // A fixed term whose only unpriced gap is the time after the term is a
        // typed verdict, not an absence of data, so it earns a row.
        if ($this->pricingComparability === 'term_price_only') {
            $rows[] = ['label' => 'Hinta määräajan jälkeen', 'value' => 'Myyjä ei ole julkaissut sitä'];
        }

        if ($this->billingFrequencyLabels) {
            $rows[] = ['label' => 'Laskutusväli', 'value' => implode(', ', $this->billingFrequencyLabels)];
        }

        $limits = $this->consumptionLimitTerm($contract);

        if ($limits !== null) {
            $rows[] = ['label' => 'Vuosikulutusrajat', 'value' => $limits];
        }

        if ($contract->availability_is_national !== null) {
            $rows[] = [
                'label' => 'Saatavuus',
                'value' => $contract->availability_is_national ? 'Koko Suomi' : 'Alueellinen',
            ];
        }

        if ($contract->available_for_existing_users !== null) {
            $rows[] = [
                'label' => 'Nykyisille asiakkaille',
                'value' => $contract->available_for_existing_users ? 'Kyllä' : 'Ei',
            ];
        }

        return $rows;
    }

    /**
     * The cancellation row, derived from `contract_type` only.
     *
     * The two-week consumer notice period on an open-ended electricity contract
     * is a market-wide fact the site already states in its editorial pages; it
     * is not a per-contract field, so it is stated only for the contract type
     * that has it and the grid tells the visitor to check the seller's terms.
     *
     * @return array{label: string, value: string}|null
     */
    protected function cancellationTerm(ElectricityContract $contract): ?array
    {
        if ($contract->contract_type === 'OpenEnded') {
            return ['label' => 'Irtisanomisaika', 'value' => '14 vrk'];
        }

        if (in_array($contract->contract_type, ['FixedTerm', 'Fixed'], true)) {
            return ['label' => 'Irtisanominen', 'value' => 'Sitoo sopimuskauden loppuun'];
        }

        return null;
    }

    /**
     * The consumption-limit row.
     *
     * A cap far above any household is noise, so the same relevance threshold the
     * card footer uses applies here: a maximum is stated only when it could bind a
     * household, or when the selected consumption actually exceeds it. Every real
     * minimum is stated, because a minimum can exclude a small apartment.
     */
    protected function consumptionLimitTerm(ElectricityContract $contract): ?string
    {
        $min = $contract->consumption_limitation_min_x_kwh_per_y;
        $max = $contract->consumption_limitation_max_x_kwh_per_y;
        $format = fn (float $value): string => number_format($value, 0, ',', ' ');

        if ($max > self::CAP_RELEVANCE_THRESHOLD_KWH && $this->consumption <= $max) {
            $max = null;
        }

        return match (true) {
            $min > 0 && $max > 0 => $format((float) $min).'–'.$format((float) $max).' kWh/v',
            $max > 0 => 'Enintään '.$format((float) $max).' kWh/v',
            $min > 0 => 'Vähintään '.$format((float) $min).' kWh/v',
            default => null,
        };
    }

    // ===== "Vertaa nykyiseen sähkölaskuusi" (per-user, never cached) =====

    /**
     * Whether the bill module renders at all.
     *
     * Only for a contract a visitor can actually buy: an inactive historical
     * page would answer "what would this have cost you" about a product that is
     * no longer on sale, and an excluded contract has no trustworthy price.
     */
    public function getShowBillComparisonProperty(): bool
    {
        return $this->contract !== null
            && $this->isActive
            && ! $this->isPricingExcluded;
    }

    protected function billInputsEnabled(): bool
    {
        return $this->showBillComparison;
    }

    /**
     * Drop the derived result and re-evaluate whether a usable bill is present.
     * Mirrors `ContractsList::recomputeBill()`, including the one-shot analytics
     * event on the inactive → active transition.
     */
    protected function recomputeBill(): void
    {
        $wasActive = $this->billActive;

        $this->billActive = $this->isBillInputValid();
        $this->billResultCache = null;

        if (! $wasActive && $this->billActive) {
            $this->dispatch('track',
                eventName: 'Bill Comparison Completed',
                props: [
                    'source' => 'contract_detail',
                    'contract_id' => $this->contractId,
                    'period_preset' => $this->billPeriodPreset,
                    'includes_vat' => $this->billIncludesVat,
                ]
            );
        }
    }

    public function clearBill(): void
    {
        $this->billActive = false;
        $this->billKwh = null;
        $this->billTotalEur = null;
        $this->billResultCache = null;
    }

    /**
     * The module's answer, or null while the inputs are incomplete.
     *
     * Deliberately derived per request and kept in a `protected` cache: it is a
     * per-user calculation that must never enter the page's prepared view-data
     * cache (see `buildContractDetailViewData()`), and syncing it into the
     * Livewire snapshot would buy nothing because it is rebuilt from the inputs
     * on every request anyway.
     *
     * @return array<string, mixed>|null
     */
    public function getBillComparisonProperty(): ?array
    {
        if ($this->billResultCache !== null) {
            return $this->billResultCache;
        }

        return $this->billResultCache = $this->buildBillComparison();
    }

    /**
     * @return array<string, mixed>|null
     */
    protected function buildBillComparison(): ?array
    {
        $contract = $this->contract;

        if ($contract === null || ! $this->isBillInputValid()) {
            return null;
        }

        $request = $this->buildBillRequest();

        // One-contract set through the same entry point the listing uses, so the
        // detail answer and the listing card can never disagree about the period.
        $data = app(\App\Services\BillComparison\BillComparisonService::class)
            ->periodRowsForContracts([$contract], $request);

        $result = [
            'period_label' => $this->billPeriodLabel(),
            'kwh' => $this->billKwhValue(),
            'user_total' => (float) $data['user_period_cost'],
            'annual_kwh' => (int) $data['annual_kwh'],
            'contract_name' => $this->displayName,
        ];

        $row = $data['rows'][$contract->id] ?? null;

        if ($row === null) {
            $reason = $data['unavailable'][$contract->id] ?? 'no_pricing';

            return $result + [
                'available' => false,
                'reason' => $reason,
                'message' => $this->billUnavailableMessage($reason, $contract, (int) $data['annual_kwh']),
            ];
        }

        $contractCost = round($row->periodCostEur, 2);
        // Period basis only. The bill total is the anchor and the annualization
        // of one bill's implied unit rate is biased for spot/seasonal/time
        // contracts, so no annual figure is derived here (same rule as the
        // in-listing mode).
        $delta = round($result['user_total'] - $contractCost, 2);

        return $result + [
            'available' => true,
            'contract_cost' => $contractCost,
            'delta' => $delta,
            'verdict' => match (true) {
                $delta >= 0.5 => 'saves',
                $delta <= -0.5 => 'costs_more',
                default => 'about_the_same',
            },
            'delta_label' => match (true) {
                $delta >= 0.5 => 'Olisit säästänyt n. '.$this->formatEuro(abs($delta)),
                $delta <= -0.5 => 'Olisit maksanut n. '.$this->formatEuro(abs($delta)).' enemmän',
                default => 'Kustannus olisi ollut suunnilleen sama',
            },
            'implied_cents' => round($row->impliedCentsPerKwh, 2),
            'is_spot' => $row->isSpot,
            'basis' => $this->billBasisSentence($row->isSpot, $data['spot_avg_cents_per_kwh']),
        ];
    }

    /**
     * "1.6.–30.6.2026" for the entered period.
     */
    protected function billPeriodLabel(): string
    {
        $start = $this->billParseDate($this->billStartDate);
        $end = $this->billParseDate($this->billEndDate);

        if ($start === null || $end === null) {
            return '';
        }

        return $start->format('j.n.').'–'.$end->format('j.n.Y');
    }

    /**
     * Why the period cost cannot be calculated. Every branch states a fact; the
     * module never renders an empty result.
     */
    protected function billUnavailableMessage(string $reason, ElectricityContract $contract, int $annualKwh): string
    {
        if ($reason === 'no_spot_history') {
            return 'Tälle jaksolle ei ole vielä pörssihintatietoja, joten vertailua ei voi laskea tälle sopimukselle.';
        }

        if ($reason === 'consumption_cap') {
            $min = $contract->consumption_limitation_min_x_kwh_per_y;
            $max = $contract->consumption_limitation_max_x_kwh_per_y;

            $range = match (true) {
                $min > 0 && $max > 0 => 'vuosikulutuksen ollessa '.$this->formatKwh((int) $min).' ja '.$this->formatKwh((int) $max).' välillä',
                $max > 0 => 'vuosikulutuksen ollessa enintään '.$this->formatKwh((int) $max),
                $min > 0 => 'vuosikulutuksen ollessa vähintään '.$this->formatKwh((int) $min),
                default => null,
            };

            $basis = 'Laskusi vastaa noin '.$this->formatKwh($annualKwh).' vuosikulutusta.';

            return $range === null
                ? $basis.' Myyjä ei myy tätä sopimusta sillä kulutuksella, joten jakson hintaa ei voi laskea.'
                : $basis.' Myyjä myy tätä sopimusta vain '.$range.', joten jakson hintaa ei voi laskea.';
        }

        if ($reason === 'not_comparable') {
            return 'Tämän sopimuksen hintaa ei voi laskea luotettavasti, joten laskujaksosi vertailua ei näytetä.';
        }

        return 'Tämän sopimuksen hinnoittelutiedoista ei voi laskea jakson hintaa.';
    }

    protected function billBasisSentence(bool $isSpot, ?float $spotAvgCents): string
    {
        if ($isSpot) {
            $sentence = 'Laskettu jakson toteutuneilla tuntihinnoilla, ilman siirtomaksuja.';

            if ($spotAvgCents !== null) {
                $sentence .= ' Jakson toteutunut pörssin keskihinta oli '
                    .number_format($spotAvgCents, 2, ',', ' ').' c/kWh.';
            }

            return $sentence.' Hinnat sisältävät alv 25,5 %.';
        }

        return 'Laskettu tämän sopimuksen voimassa olevilla hinnoilla samalle jaksolle ja kulutukselle, '
            .'ilman siirtomaksuja. Hinnat sisältävät alv 25,5 %.';
    }

    /**
     * The bill module's view data, passed to the view **beside** the prepared
     * payload so it can never be written into the shared page cache.
     *
     * @return array<string, mixed>
     */
    protected function billModuleViewData(): array
    {
        return [
            'showBillComparison' => $this->showBillComparison,
            'billComparison' => $this->showBillComparison ? $this->billComparison : null,
        ];
    }

    /**
     * The pricing-mechanism facts for the viewed contract, resolved once.
     */
    protected function pricingFacts(): \App\Services\ContractCard\DTO\PricingCategoryFacts
    {
        return $this->computedValueCache['pricingFacts'] ??= app(\App\Services\ContractCard\PricingCategoryResolver::class)
            ->resolve($this->contract);
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
                        'contract_name' => ContractContentSanitizer::displayName($historyContract->name),
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
     *     last_seen_on_sale_date: ?\Carbon\Carbon,
     *     prices: array<int, array{type: string, label: string, price: float, unit: string}>,
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

                // This is the last import date on which Voltikka observed this
                // exact contract. It is not an exact removal/expiry date.
                $lastSeenOnSaleDate = $historyContract->priceComponents
                    ->pluck('price_date')
                    ->filter()
                    ->sortByDesc(fn ($date) => $date instanceof \Carbon\Carbon ? $date->timestamp : \Carbon\Carbon::parse($date)->timestamp)
                    ->first();

                return [
                    'id' => $historyContract->id,
                    'name' => ContractContentSanitizer::displayName($historyContract->name),
                    'company' => $historyContract->company?->name,
                    'is_current' => $historyContract->id === $this->contractId,
                    'is_active' => $historyContract->isActive(),
                    'latest_price_date' => $latestPriceDate,
                    'last_seen_on_sale_date' => $lastSeenOnSaleDate,
                    'prices' => $this->formatContractHistoryPrices($historyContract, $latestPriceComponents->all()),
                    'promotion' => $this->formatHistoricalPromotionText($historyContract, $latestPriceComponents->all()),
                ];
            })
            ->values()
            ->toArray();

        return $this->contractHistoryCache = $history;
    }

    /**
     * "Näin hinta on kehittynyt": the chart payload, the seller-behaviour fact
     * tags and the copy that scopes both.
     *
     * Deliberately consumption-independent. The module describes the contract's
     * own observed c/kWh prices and the market it sits in, so nothing in it
     * reacts to the consumption selector and nothing in it needs a scope
     * sentence about consumption. That is also what makes it safe inside the
     * prepared view-data cache.
     *
     * @return array<string, mixed>
     */
    public function getPriceDevelopmentProperty(): array
    {
        if (isset($this->computedValueCache['priceDevelopment'])) {
            return $this->computedValueCache['priceDevelopment'];
        }

        $contract = $this->contract;

        if (! $contract) {
            return [];
        }

        return $this->computedValueCache['priceDevelopment'] = app(PriceDevelopmentPresenter::class)
            ->present($contract, $this->priceHistory, $this->calculatedCost);
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

        $historyContractIds = $this->getBackwardReplacementChainIds($contract->id)
            ->pluck('id')
            ->push($contract->id)
            ->unique()
            ->values();

        $historyContracts = ElectricityContract::query()
            ->with(['company', 'priceComponents', 'activeContract'])
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
     * Return predecessor contract IDs for the replacement history in one
     * recursive query instead of querying each replacement depth and then
     * re-querying all versions. Depth is capped defensively in case bad data
     * creates a cycle.
     *
     * @return \Illuminate\Support\Collection<int, object{id: string, depth: int}>
     */
    protected function getBackwardReplacementChainIds(string $contractId): \Illuminate\Support\Collection
    {
        return collect(DB::select(<<<'SQL'
            WITH RECURSIVE replacement_chain(id, replaced_by_contract_id, depth) AS (
                SELECT id, replaced_by_contract_id, 1
                FROM electricity_contracts
                WHERE replaced_by_contract_id = ?

                UNION ALL

                SELECT ec.id, ec.replaced_by_contract_id, replacement_chain.depth + 1
                FROM electricity_contracts ec
                INNER JOIN replacement_chain ON ec.replaced_by_contract_id = replacement_chain.id
                WHERE replacement_chain.depth < 25
            )
            SELECT id, depth FROM replacement_chain
        SQL, [$contractId]));
    }

    /**
     * Return forward replacement IDs in chain order using one recursive query.
     *
     * @return \Illuminate\Support\Collection<int, object{id: string, depth: int}>
     */
    protected function getForwardReplacementChainIds(string $contractId): \Illuminate\Support\Collection
    {
        return collect(DB::select(<<<'SQL'
            WITH RECURSIVE replacement_chain(id, replaced_by_contract_id, depth) AS (
                SELECT replacement.id, replacement.replaced_by_contract_id, 1
                FROM electricity_contracts current_contract
                INNER JOIN electricity_contracts replacement ON replacement.id = current_contract.replaced_by_contract_id
                WHERE current_contract.id = ?

                UNION ALL

                SELECT replacement.id, replacement.replaced_by_contract_id, replacement_chain.depth + 1
                FROM replacement_chain
                INNER JOIN electricity_contracts replacement ON replacement.id = replacement_chain.replaced_by_contract_id
                WHERE replacement_chain.depth < 25
            )
            SELECT id, depth FROM replacement_chain
        SQL, [$contractId]));
    }

    /**
     * Display order for price component types in the history timeline and its
     * trend chart. Earlier entries are also preferred as the charted series.
     *
     * `price_component_type` is written verbatim from the upstream API payload
     * (`CanonicalPriceComponentWriter`), so this list can never be exhaustive by
     * construction. Types outside it are appended under their raw name rather
     * than dropped — the old hardcoded whitelist silently hid the `Spot` margin
     * component from the history of the Hybrid contract that carries it.
     */
    public const PRICE_TYPE_ORDER = [
        'General',
        'Spot',
        'DayTime',
        'NightTime',
        'SeasonalWinter',
        'SeasonalWinterDay',
        'SeasonalOther',
        'Monthly',
    ];

    /**
     * Display labels per price component type for the rendered contract.
     *
     * @return array<string, string>
     */
    public function getPriceTypeLabelsProperty(): array
    {
        return $this->priceTypeLabelsFor($this->contract);
    }

    /**
     * A spot contract stores the supplier margin in its `General` component, not
     * the energy price the customer pays. The hero pricing block already calls
     * that row "Marginaali", so the history and its trend chart must agree;
     * calling it "Energiahinta" claimed a 0,60 c/kWh margin was the whole price.
     *
     * A `Spot` component is a margin whatever the contract's pricing model is,
     * so it does not depend on the model the way `General` does. Both winter
     * spellings are mapped because upstream has used both.
     *
     * @return array<string, string>
     */
    protected function priceTypeLabelsFor(?ElectricityContract $contract): array
    {
        return [
            'General' => $contract?->pricing_model === 'Spot' ? 'Marginaali' : 'Energiahinta',
            'Spot' => 'Marginaali',
            'Monthly' => 'Perusmaksu',
            'DayTime' => 'Päiväsähkö',
            'NightTime' => 'Yösähkö',
            'SeasonalWinter' => 'Talvihinta',
            'SeasonalWinterDay' => 'Talvihinta',
            'SeasonalOther' => 'Muu aika',
        ];
    }

    /**
     * @param  array<string, \App\Models\PriceComponent>  $latestPriceComponents
     * @return array<int, array{type: string, label: string, price: float, unit: string}>
     */
    protected function formatContractHistoryPrices(ElectricityContract $contract, array $latestPriceComponents): array
    {
        $priceTypeLabels = $this->priceTypeLabelsFor($contract);

        return collect($this->orderPriceTypes(array_keys($latestPriceComponents)))
            ->map(function (string $type) use ($latestPriceComponents, $priceTypeLabels) {
                $component = $latestPriceComponents[$type] ?? null;

                if (! $component) {
                    return null;
                }

                return [
                    'type' => $type,
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
     * Known types in display order, then any unrecognized upstream type.
     *
     * @param  array<int, string>  $types
     * @return array<int, string>
     */
    public function orderPriceTypes(array $types): array
    {
        return array_values(array_merge(
            array_intersect(self::PRICE_TYPE_ORDER, $types),
            array_diff($types, self::PRICE_TYPE_ORDER),
        ));
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
            'name' => $this->displayName,
            'description' => $this->metaDescription,
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

        // Do not advertise price offers for contracts whose pricing we cannot verify/compute.
        if (! empty($offers) && ! $this->isPricingExcluded) {
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
                'name' => $this->displayName,
                'item' => $this->canonicalUrl,
            ];
        } else {
            $breadcrumbs[] = [
                '@type' => 'ListItem',
                'position' => 3,
                'name' => $this->displayName,
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
                'displayName' => $this->displayName,
                'descriptionHtml' => $this->descriptionHtml,
                'descriptionText' => $this->descriptionText,
                'schemas' => array_values(array_filter([
                    $this->webPageSchema,
                    $this->productSchema,
                    $this->breadcrumbSchema,
                    $this->faqSchema,
                ])),
                'card' => $this->card,
                'calculatedCost' => $this->calculatedCost,
                'pricingIntegrity' => $this->pricingIntegrity,
                'pricingComparability' => $this->pricingComparability,
                'isPricingExcluded' => $this->isPricingExcluded,
                'priceHistory' => $this->priceHistory,
                'contractHistory' => $this->contractHistory,
                'priceDevelopment' => $this->priceDevelopment,
                'priceTypeLabels' => $this->priceTypeLabels,
                'priceTypeOrder' => $this->orderPriceTypes(array_keys($this->priceHistory)),
                'isActive' => $isActive,
                'presets' => $this->presets,
                'presetNotice' => $this->presetNotice,
                'rankBasisNotice' => $this->rankBasisNotice,
                'comparisonConsumption' => $this->rankConsumption(),
                'consumptionCostTable' => $this->consumptionCostTable,
                'spotCounterfactual' => $this->spotCounterfactual,
                'sameTypeAlternative' => $this->sameTypeAlternative,
                'verdict' => $this->verdict,
                'heroVerdict' => $this->heroVerdict,
                'receiptNotes' => $this->receiptNotes,
                'hasPricingMechanismFaq' => $this->hasPricingMechanismFaq,
                'faqItems' => $this->faqItems,
                'contractTerms' => $this->contractTerms,
                'co2Emissions' => $this->co2Emissions,
                'emissionFactorSources' => $this->emissionFactorSources,
                'cheaperContracts' => $this->cheaperContracts,
                'nextCheapestContract' => $this->nextCheapestContract,
                'priceQualifier' => $this->priceQualifier,
                'liveRank' => $this->liveRank,
                'liveTotalContracts' => $this->liveTotalContracts,
                'companyInternalUrl' => ContractInternalLinks::companyUrl($contract?->company),
                'heroBadgeLinks' => $contract ? ContractInternalLinks::heroBadgeLinks($contract) : [],
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
            // A Livewire update is a POST, so bill state cannot reach this cache
            // anyway; the guard is kept explicit because the bill module is
            // per-user compute and must never be shared between visitors.
            && ! $this->billActive
            && $contract !== null
            && $this->consumption === $this->clampConsumption(5000, $contract);
    }

    protected function contractDetailViewDataCacheKey(): string
    {
        // v15: the Phase 4 composition pass changed the payload shape. It now
        // carries `heroVerdict` / `receiptNotes` / `hasPricingMechanismFaq` and no
        // longer carries `latestPrices`, `discountedComponents` or `priceChangeInfo`,
        // which no template read after the editorial restructure.
        return 'contract-detail:view-data:v15:' . md5(json_encode([
            'contract_id' => $this->contractId,
            'consumption' => $this->consumption,
            'version' => $this->contractPageCacheVersionHash(),
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

        // The bill module is merged in here, outside `contractDetailViewData()`,
        // so per-user bill state can never be written into the shared prepared
        // payload cache.
        return view('livewire.contract-detail', array_merge($data['view'], $this->billModuleViewData()))
            ->layout('layouts.app', $data['layout'])
            ->response(function ($response) {
                app(SetPublicCacheHeaders::class)->applyCacheHeaders($response);
            });
    }
}
