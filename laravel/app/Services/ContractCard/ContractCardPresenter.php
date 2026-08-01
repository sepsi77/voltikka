<?php

namespace App\Services\ContractCard;

use App\Models\Company;
use App\Models\ElectricityContract;
use App\Services\CanonicalPricing\DTO\ContractPricingIntegrity;
use App\Services\CanonicalPricing\PricingMode;
use App\Services\ContractCard\DTO\CardSellerCta;
use App\Services\ContractCard\DTO\ContractCardView;
use App\Services\ContractPricing\ContractPricingViewData;
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
        private readonly PricingMode $pricingMode,
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
        $rawCost = $contract->calculated_cost ?? null;
        $pricing = is_array($rawCost) && $rawCost !== []
            ? ContractPricingViewData::fromArray($rawCost)
            : null;
        $rawIntegrity = $contract->pricing_integrity ?? null;
        $integrity = is_array($rawIntegrity) ? ContractPricingIntegrity::fromArray($rawIntegrity) : null;
        $useCanonical = $this->pricingMode->enabled();

        // In canonical mode, accept only a listed payload that identifies the canonical
        // calculator as its source. A legacy payload can remain attached for transport, but it
        // cannot become a second current-price source after the feature flag changes.
        $publicPricing = ! $useCanonical
            || ($pricing?->pricingBasis() === 'canonical' && $pricing->comparability()?->isListed() === true);

        $facts = $this->categories->resolve($contract, $today)
            ->withNextReset($this->tailStart($publicPricing ? $pricing : null));
        $rates = $useCanonical
            ? $this->canonicalRates($publicPricing ? $pricing : null)
            : $this->legacyRates($contract, $pricing, $prices);

        $totalCost = $publicPricing ? $pricing?->total() : null;
        $exceeds = (bool) ($contract->exceeds_consumption_limit ?? false);

        // A fixed contract with a pre-published later price keeps a truthful fixed band; the
        // increase is a footer warning and two dated receipt rows, never a band warning.
        $hasScheduledChange = $integrity?->cardLabel !== null
            && $integrity->normalRateCents !== null;

        $footer = $this->footerItems->build($contract, $publicPricing ? $pricing : null, $integrity, $facts, $exceeds, $useCanonical);
        $discount = $this->discountDisplay($publicPricing ? $pricing : null);

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
            receiptLines: $this->receiptLines->build($rates, $publicPricing ? $pricing : null, $integrity, $facts, $contract->metering, $detailed, $useCanonical),
            totalCost: $totalCost,
            monthlyCost: $totalCost !== null ? $totalCost / 12 : null,
            estimate: $billMode ? null : ContractCardCopy::estimate($publicPricing ? $pricing : null, $facts),
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
     */
    private function tailStart(?ContractPricingViewData $pricing): ?CarbonImmutable
    {
        $tail = $pricing?->resetEstimate()?->string('tail_starts');

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
     * @return array<string, float|null>
     */
    private function canonicalRates(?ContractPricingViewData $pricing): array
    {
        return [
            'general' => $pricing?->generalKwhPrice(),
            'day' => $pricing?->daytimeKwhPrice(),
            'night' => $pricing?->nighttimeKwhPrice(),
            'winter' => $pricing?->seasonalWinterDayKwhPrice(),
            'other' => $pricing?->seasonalOtherKwhPrice(),
            'margin' => $pricing?->spotPriceMargin(),
            'fee' => $pricing?->monthlyFixedFee(),
        ];
    }

    /**
     * Feature-off compatibility path. It keeps the old calculated-cost-first fallback chain.
     *
     * @param  array<string, array{price: float|null, unit?: string|null}>  $prices
     * @return array<string, float|null>
     */
    private function legacyRates(ElectricityContract $contract, ?ContractPricingViewData $pricing, array $prices): array
    {
        if ($prices === [] && $contract->relationLoaded('priceComponents')) {
            $prices = $this->pricesFromRelation($contract);
        }

        $fromPrices = static function (string $type) use ($prices): ?float {
            $price = $prices[$type]['price'] ?? null;

            return is_numeric($price) ? (float) $price : null;
        };

        return [
            'general' => $pricing?->generalKwhPrice() ?? $fromPrices('General'),
            'day' => $pricing?->daytimeKwhPrice() ?? $fromPrices('DayTime'),
            'night' => $pricing?->nighttimeKwhPrice() ?? $fromPrices('NightTime'),
            'winter' => $pricing?->seasonalWinterDayKwhPrice() ?? $fromPrices('SeasonalWinterDay'),
            'other' => $pricing?->seasonalOtherKwhPrice() ?? $fromPrices('SeasonalOther'),
            'margin' => $pricing?->spotPriceMargin(),
            'fee' => $pricing?->monthlyFixedFee() ?? $fromPrices('Monthly') ?? 0.0,
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
     * @return array{saving: ?float, base_total: ?float, term_months: ?int}
     */
    private function discountDisplay(?ContractPricingViewData $pricing): array
    {
        if ($pricing === null || ! $pricing->includesDiscounts()) {
            return ['saving' => null, 'base_total' => null, 'term_months' => null];
        }

        $term = $pricing->contractTerm();
        $termSaving = $term?->number('discount_savings_total');
        $termBase = $term?->number('base_total_cost');
        $termMonths = $term?->integer('months');

        if ($termSaving !== null && $termBase !== null && $termMonths !== null
            && $termSaving >= self::MIN_DISPLAYED_SAVING_EUR) {
            return [
                'saving' => $termSaving,
                'base_total' => $termBase,
                'term_months' => $termMonths,
            ];
        }

        $savings = $pricing->discountSaving();
        if ($savings < self::MIN_DISPLAYED_SAVING_EUR) {
            return ['saving' => null, 'base_total' => null, 'term_months' => null];
        }

        return [
            'saving' => $savings,
            'base_total' => $pricing->baseTotal(),
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
