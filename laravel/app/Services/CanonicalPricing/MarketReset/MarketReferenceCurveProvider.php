<?php

namespace App\Services\CanonicalPricing\MarketReset;

use Carbon\CarbonImmutable;

/**
 * Market data the reset estimator needs, behind a seam so the estimator's arithmetic and
 * guards can be tested without a database.
 *
 * Every method resolves its vintage as the latest `trade_date < $asOfDate` (no same-day
 * leakage). The estimator deliberately passes **two different** `$asOfDate` values: today for
 * the forward months `F_m`, and the current period's start for `F_reference`. See the
 * two-vintage rule in AGENTS.md.
 */
interface MarketReferenceCurveProvider
{
    /**
     * The curve vintage that applies at this `asOfDate`, or null when no curve exists before it.
     */
    public function tradeDate(CarbonImmutable $asOfDate): ?CarbonImmutable;

    /**
     * The reference settlement price for the delivery period a reset price was set for.
     *
     * `$asOfDate` is the **pricing vintage anchor** — the current period's start date — not
     * today.
     *
     * @param  list<string>  $kindPreference  Reference kinds to try in order, e.g.
     *                                        `['quarter', 'quarter_month_average']`.
     * @return array{kind: string, price_cents_per_kwh: float, trade_date: string}|null
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
     * Median energy price (c/kWh incl. VAT) of the fully-fixed 12-month retail market.
     * **Reported context only** — it must never gate an estimate. Null when unavailable.
     */
    public function fixedTermMedianEnergyPrice(): ?float;
}
