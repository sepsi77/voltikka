<?php

namespace App\Services\CanonicalPricing\MarketReset;

use Carbon\CarbonImmutable;

/**
 * Market data the reset estimator needs, behind a seam so the estimator's arithmetic and
 * guards can be tested without a database.
 *
 * Every method that reads the forward curve takes the same `$asOfDate` and MUST resolve the
 * same single vintage from it (latest `trade_date < asOfDate`). See the one-vintage rule in
 * AGENTS.md: mixing vintages reintroduces the level drift the shape-only shift cancels.
 */
interface MarketReferenceCurveProvider
{
    /**
     * The single curve vintage used for every lookup at this `asOfDate`, or null when no
     * curve exists at all.
     */
    public function tradeDate(CarbonImmutable $asOfDate): ?CarbonImmutable;

    /**
     * The reference settlement price for the delivery period a reset price was set for.
     *
     * @param  list<string>  $kindPreference  Reference kinds to try in order, e.g.
     *                                        `['quarter', 'quarter_month_average']`.
     * @return array{kind: string, price_cents_per_kwh: float}|null
     */
    public function referencePrice(CarbonImmutable $asOfDate, CarbonImmutable $anchorMonth, array $kindPreference): ?array;

    /**
     * Forward price for one delivery month, month -> quarter -> year fallback ladder.
     *
     * @return array{kind: string, price_cents_per_kwh: float}|null
     */
    public function forwardPriceForMonth(CarbonImmutable $asOfDate, CarbonImmutable $deliveryMonth): ?array;

    /**
     * Multiplicative seasonal index from multi-year realized spot, keyed by calendar month
     * number (1-12). Null when there is not enough history.
     *
     * @return array<int, float>|null
     */
    public function spotSeasonalIndex(): ?array;

    /**
     * Median energy price (c/kWh incl. VAT) of the fully-fixed 12-month retail market, used
     * only as the centre of the plausibility band. Null when unavailable.
     */
    public function fixedTermMedianEnergyPrice(): ?float;
}
