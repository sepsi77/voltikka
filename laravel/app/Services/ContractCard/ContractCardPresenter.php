<?php

namespace App\Services\ContractCard;

use App\Models\Company;
use App\Models\ElectricityContract;
use App\Services\ContractCard\DTO\CardSellerCta;
use App\Services\ContractCard\DTO\ContractCardView;
use App\Support\ContractContentSanitizer;
use App\Support\ContractInternalLinks;
use Carbon\CarbonImmutable;

/**
 * The single server-side derivation behind both contract-card templates.
 *
 * Before this class, `contract-card.blade.php` and `featured-contract-card.blade.php` each
 * held ~120 lines of the same PHP and had drifted apart: the featured card, which is the
 * #1 slot on the homepage, /sahkosopimus, every SEO listing page and
 * /halvin-sahkosopimus, showed no integrity warning, no market-reset figures and no
 * estimate marker at all. Add card facts here, never in a template.
 *
 * NEVER lazy-load a relation from here. Listing pages batch-load `company`,
 * `electricitySource` and the latest `priceComponents`; a lazy load inside a card turns
 * every row of a 20-item list into an N+1 query. Callers that cannot batch-load pass the
 * rates in through `prices`.
 */
class ContractCardPresenter
{
    /** Below this the promo saving is rounding noise and the block is suppressed. */
    private const MIN_DISPLAYED_SAVING_EUR = 5.0;

    public function __construct(
        private readonly PricingCategoryResolver $categories = new PricingCategoryResolver,
        private readonly CardReceiptLines $receiptLines = new CardReceiptLines,
        private readonly CardFooterItems $footerItems = new CardFooterItems,
    ) {}

    /**
     * @param  array<string, array{price: float|null, unit?: string|null}>  $prices  Latest relational
     *                                                                               components keyed by `price_component_type`. Used only by the feature-off legacy path.
     *                                                                               Canonical mode never reads this array or the loaded component relation.
     * @param  int|null  $consumption  The visitor's selected annual kWh, deep-linked to the detail page.
     * @param  bool  $billMode  Suppresses the annual Arvio chip; the billing-period figures keep
     *                          their own "laskutusjaksollasi" disclosure.
     * @param  bool  $detailed  Contract detail page mode: the receipt may run longer, because the
     *                          page shows one contract instead of a scannable list.
     */
    public function present(
        ElectricityContract $contract,
        array $prices = [],
        ?int $consumption = null,
        ?int $detailConsumption = null,
        bool $billMode = false,
        ?CarbonImmutable $today = null,
        bool $detailed = false,
    ): ContractCardView {
        $rawCost = is_array($contract->calculated_cost ?? null) ? $contract->calculated_cost : [];
        $integrity = is_array($contract->pricing_integrity ?? null) ? $contract->pricing_integrity : null;
        $useCanonical = (bool) config('canonical_pricing.enabled', false);

        // In canonical mode, accept only a payload that identifies the canonical calculator as
        // its source. This prevents a legacy result left on a model from becoming a second
        // current-price source after the feature flag changes.
        $canonicalComparability = $rawCost['comparability'] ?? $contract->comparability ?? null;
        $canonicalExcluded = in_array($canonicalComparability, ['excluded_unknown_future', 'excluded_incomplete'], true);
        $cost = $useCanonical
            && (($rawCost['pricing_basis'] ?? null) !== 'canonical' || $canonicalExcluded)
                ? []
                : $rawCost;

        $facts = $this->categories->resolve($contract, $today)
            ->withNextReset($this->tailStart($cost));
        $rates = $useCanonical
            ? $this->canonicalRates($cost)
            : $this->legacyRates($contract, $cost, $prices);

        $totalCost = is_numeric($cost['total_cost'] ?? null) ? (float) $cost['total_cost'] : null;
        $exceeds = (bool) ($contract->exceeds_consumption_limit ?? false);

        // A fixed contract with a pre-published later price keeps a truthful fixed band; the
        // increase is a footer warning and two dated receipt rows, never a band warning.
        $hasScheduledChange = is_string($integrity['card_label'] ?? null)
            && is_numeric($integrity['normal_rate_cents'] ?? null);

        $footer = $this->footerItems->build($contract, $cost, $integrity, $facts, $exceeds, $useCanonical);
        $discount = $this->discountDisplay($cost);

        $company = $contract->relationLoaded('company') ? $contract->company : null;

        return new ContractCardView(
            category: $facts->category,
            band: ContractCardCopy::band($facts, $contract->contract_type, $contract->fixed_time_range, $hasScheduledChange),
            detailUrl: $this->detailUrl($contract, $consumption, $detailConsumption),
            company: $company,
            companyName: $company?->name ?? $contract->company_name ?? '',
            // Sellers submit shouted names ("... 0€ KUUKAUSIMAKSU ENSIMMÄISET 3 KK!"). One
            // normalizer for every surface: the two card templates and the detail page's H1
            // all read the same rule, so a name cannot be shouted on a card and calm on the
            // page it links to. The stored `name` is never rewritten.
            contractName: ContractContentSanitizer::displayName($contract->name) ?: (string) ($contract->name ?? ''),
            metaParts: $this->metaParts($contract, $company),
            receiptLines: $this->receiptLines->build($rates, $cost, $integrity, $facts, $contract->metering, $detailed, $useCanonical),
            totalCost: $totalCost,
            monthlyCost: $totalCost !== null ? $totalCost / 12 : null,
            estimate: $billMode ? null : ContractCardCopy::estimate($cost, $facts),
            warnings: $footer['warnings'],
            facts: $footer['facts'],
            discountSavings: $discount['saving'],
            baseTotalCost: $discount['base_total'],
            discountTermMonths: $discount['term_months'],
            discountPeriodLabel: $discount['saving'] !== null
                ? ($discount['term_months'] !== null ? $discount['term_months'].' kk' : '12 kk')
                : null,
            discountExplanation: $discount['saving'] !== null
                ? $this->discountExplanation($discount['term_months'])
                : null,
            exceedsConsumptionLimit: $exceeds,
            sellerCta: $this->sellerCta($contract, $company),
        );
    }

    /**
     * Where "Siirry myyjän sivuille" goes.
     *
     * The ladder exists because one live contract had neither an order link nor a product
     * link, and the detail page then rendered no call to action at all. It falls back to
     * the seller's own site and finally to their Voltikka page, and the label changes with
     * the destination so the button never promises an order form it does not have.
     */
    private function sellerCta(ElectricityContract $contract, ?object $company): ?CardSellerCta
    {
        foreach ([$contract->order_link, $contract->product_link, $company?->company_url] as $url) {
            if (is_string($url) && trim($url) !== '') {
                return new CardSellerCta(trim($url), 'Siirry myyjän sivuille', external: true);
            }
        }

        $companyPage = $company instanceof Company ? ContractInternalLinks::companyUrl($company) : null;

        return $companyPage !== null
            ? new CardSellerCta($companyPage, 'Katso myyjän tiedot', external: false)
            : null;
    }

    /**
     * The first day of the estimated tail, as the market-reset estimator derived it from the
     * cadence calendar. Used only when the seller disclosed no period end of their own.
     *
     * @param  array<string, mixed>  $cost
     */
    private function tailStart(array $cost): ?CarbonImmutable
    {
        $tail = $cost['reset_estimate']['tail_starts'] ?? null;

        if (! is_string($tail) || ! preg_match('/^\d{4}-\d{2}$/', $tail)) {
            return null;
        }

        try {
            return CarbonImmutable::parse($tail.'-01', 'Europe/Helsinki')->startOfDay();
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * The meta line under the contract name: company, duration, and the metering word when
     * there is one. Metering stays here on purpose. "Aikasähkö" and "Kausisähkö" describe
     * when consumption is measured, not whether the price moves, so they are not categories
     * and must not compete with the band.
     *
     * @return list<string>
     */
    private function metaParts(ElectricityContract $contract, ?object $company): array
    {
        $parts = [$company?->name ?? $contract->company_name ?? ''];

        $metering = match ($contract->metering) {
            'Time' => 'Aikasähkö',
            'Season' => 'Kausisähkö',
            default => null,
        };
        if ($metering !== null) {
            $parts[] = $metering;
        }

        $duration = ContractCardCopy::durationLabel($contract->contract_type, $contract->fixed_time_range);
        if ($duration !== null) {
            $parts[] = $duration;
        }

        return array_values(array_filter($parts, static fn (string $part) => $part !== ''));
    }

    /**
     * Current canonical display rates. Missing means unavailable. This branch does not inspect
     * relational prices, even when the relation is already loaded for a historical surface.
     *
     * @param  array<string, mixed>  $cost
     * @return array<string, float|null>
     */
    private function canonicalRates(array $cost): array
    {
        $fromCost = static function (string $key) use ($cost): ?float {
            return is_numeric($cost[$key] ?? null) ? (float) $cost[$key] : null;
        };

        return [
            'general' => $fromCost('general_kwh_price'),
            'day' => $fromCost('daytime_kwh_price'),
            'night' => $fromCost('nighttime_kwh_price'),
            'winter' => $fromCost('seasonal_winter_day_kwh_price'),
            'other' => $fromCost('seasonal_other_kwh_price'),
            'margin' => $fromCost('spot_price_margin'),
            'fee' => $fromCost('monthly_fixed_fee'),
        ];
    }

    /**
     * Feature-off compatibility path. It keeps the old calculated-cost-first fallback chain.
     *
     * @param  array<string, mixed>  $cost
     * @param  array<string, array{price: float|null, unit?: string|null}>  $prices
     * @return array<string, float|null>
     */
    private function legacyRates(ElectricityContract $contract, array $cost, array $prices): array
    {
        if ($prices === [] && $contract->relationLoaded('priceComponents')) {
            $prices = $this->pricesFromRelation($contract);
        }

        $fromPrices = static function (string $type) use ($prices): ?float {
            $price = $prices[$type]['price'] ?? null;

            return is_numeric($price) ? (float) $price : null;
        };
        $fromCost = static function (string $key) use ($cost): ?float {
            return is_numeric($cost[$key] ?? null) ? (float) $cost[$key] : null;
        };

        return [
            'general' => $fromCost('general_kwh_price') ?? $fromPrices('General'),
            'day' => $fromCost('daytime_kwh_price') ?? $fromPrices('DayTime'),
            'night' => $fromCost('nighttime_kwh_price') ?? $fromPrices('NightTime'),
            'winter' => $fromCost('seasonal_winter_day_kwh_price') ?? $fromPrices('SeasonalWinterDay'),
            'other' => $fromCost('seasonal_other_kwh_price') ?? $fromPrices('SeasonalOther'),
            'margin' => $fromCost('spot_price_margin'),
            'fee' => $fromCost('monthly_fixed_fee') ?? $fromPrices('Monthly') ?? 0.0,
        ];
    }

    /**
     * @return array<string, array{price: float|null}>
     */
    private function pricesFromRelation(ElectricityContract $contract): array
    {
        $prices = [];

        foreach ($contract->priceComponents->groupBy('price_component_type') as $type => $components) {
            $sorted = $components->sortByDesc('price_date');
            $latest = $sorted->first(fn ($component) => $component->price > 0) ?? $sorted->first();
            $prices[$type] = ['price' => $latest?->price];
        }

        return $prices;
    }

    /**
     * Promo values worth stating. A short fixed term uses its real customer benefit and normal
     * total for that term. The annualized top-level values remain the comparison basis only.
     *
     * @param  array<string, mixed>  $cost
     * @return array{saving: ?float, base_total: ?float, term_months: ?int}
     */
    private function discountDisplay(array $cost): array
    {
        if (($cost['includes_discounts'] ?? false) !== true) {
            return ['saving' => null, 'base_total' => null, 'term_months' => null];
        }

        $term = is_array($cost['contract_term'] ?? null) ? $cost['contract_term'] : null;
        $termSaving = $term['discount_savings_total'] ?? null;
        $termBase = $term['base_total_cost'] ?? null;
        $termMonths = $term['months'] ?? null;

        if (is_numeric($termSaving) && is_numeric($termBase) && is_numeric($termMonths)
            && (float) $termSaving >= self::MIN_DISPLAYED_SAVING_EUR) {
            return [
                'saving' => (float) $termSaving,
                'base_total' => (float) $termBase,
                'term_months' => (int) $termMonths,
            ];
        }

        $savings = $cost['discount_savings_total'] ?? null;
        if (! is_numeric($savings) || (float) $savings < self::MIN_DISPLAYED_SAVING_EUR) {
            return ['saving' => null, 'base_total' => null, 'term_months' => null];
        }

        $base = $cost['base_total_cost'] ?? null;

        return [
            'saving' => (float) $savings,
            'base_total' => is_numeric($base) ? (float) $base : null,
            'term_months' => null,
        ];
    }

    private function discountExplanation(?int $termMonths): string
    {
        if ($termMonths !== null) {
            return 'Säästö = tarjouksen tuoma alennus verrattuna saman sopimuksen normaalihintaan koko '
                .$termMonths.' kuukauden sopimuskauden aikana.';
        }

        return 'Säästö = tarjouksen tuoma alennus verrattuna saman sopimuksen normaalihintaan ensimmäisen 12 kuukauden aikana.';
    }

    /**
     * Deep-link the visitor's consumption so the detail price matches the listing. Only when
     * it differs from the detail page's 5000 kWh default, to keep the common URL clean. The
     * detail canonical is param-free, so these variants stay non-indexable.
     */
    private function detailUrl(ElectricityContract $contract, ?int $consumption, ?int $detailConsumption): string
    {
        $linked = (int) ($detailConsumption ?? $consumption ?? 0);

        return ($linked > 0 && $linked !== 5000)
            ? route('contract.detail', ['contractId' => $contract->id, 'kulutus' => $linked])
            : route('contract.detail', $contract->id);
    }
}
