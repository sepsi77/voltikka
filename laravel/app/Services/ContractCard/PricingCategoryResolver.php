<?php

namespace App\Services\ContractCard;

use App\Models\ElectricityContract;
use App\Services\ContractCard\DTO\PricingCategoryFacts;
use App\Services\ContractCard\Enums\PricingBucket;
use App\Services\ContractCard\Enums\PricingCategory;
use Carbon\CarbonImmutable;

/**
 * Resolves the one pricing category a contract belongs to, plus the mechanism facts the
 * card band needs to describe it.
 *
 * IMPORTANT: this must not depend on `CANONICAL_PRICING_ENABLED`. It reads
 * `electricity_contracts.canonical_pricing`, which the interpretation pipeline publishes
 * independently of the pricing feature flag, so the category stays correct even if
 * canonical costing is switched off.
 *
 * See app/Services/ContractCard/AGENTS.md for why market wins over consumption effect.
 */
class PricingCategoryResolver
{
    /** Cadences that are evidence of a real reset mechanism. */
    private const RESET_CADENCES = ['monthly', 'quarterly', 'seasonal', 'other'];

    /** `applies_to` values that make the consumption effect part of the base contract. */
    private const BASE_CONTRACT_EFFECT = ['base_contract', 'both'];

    public function resolve(ElectricityContract $contract, ?CarbonImmutable $today = null): PricingCategoryFacts
    {
        $today ??= CarbonImmutable::now('Europe/Helsinki')->startOfDay();

        $pricing = is_array($contract->canonical_pricing) ? $contract->canonical_pricing : [];
        $schedule = is_array($pricing['recurring_schedule'] ?? null) ? $pricing['recurring_schedule'] : [];
        $effect = is_array($pricing['consumption_effect'] ?? null) ? $pricing['consumption_effect'] : [];

        $isSpot = $contract->pricing_model === 'Spot';

        $cadence = is_string($schedule['cadence'] ?? null) ? $schedule['cadence'] : null;
        $isReset = ($schedule['present'] ?? null) === true
            && $cadence !== null
            && in_array($cadence, self::RESET_CADENCES, true);

        // The Hybrid fallback is required: one active contract (Helen Välkkysähkö Yritys) is
        // classified Hybrid with no consumption_effect block at all.
        $hasConsumptionEffect = $contract->pricing_model === 'Hybrid'
            || (($effect['present'] ?? null) === true
                && in_array($effect['applies_to'] ?? null, self::BASE_CONTRACT_EFFECT, true));

        // Market wins over consumption effect. The consumption-effect category is defined as
        // "fixed otherwise", so a quarterly-reset base is not that category; the effect stays
        // a footer caveat instead (Korpela Kvartaali, Vaasan Sähkö Vaikuttaja).
        $category = match (true) {
            $isSpot, $isReset => PricingCategory::Market,
            $hasConsumptionEffect => PricingCategory::ConsumptionEffect,
            default => PricingCategory::Fixed,
        };

        return new PricingCategoryFacts(
            category: $category,
            isSpot: $isSpot,
            isReset: $isReset,
            cadence: $isReset ? $cadence : null,
            nextReset: $isReset ? $this->nextReset($schedule, $today) : null,
            hasConsumptionEffect: $hasConsumptionEffect,
        );
    }

    /**
     * Constrain a query to one pricing category, using the same rules as resolve().
     *
     * SEO listing pages (kiintea-hinta, yleissahko, kulutusvaikutus) previously carried their
     * own hand-written variants of this logic, so a contract could be listed on a page whose
     * category did not match the band its card renders. Keep this method and resolve() in
     * step; a divergence is a user-visible contradiction, not an internal detail.
     *
     * @param \Illuminate\Database\Eloquent\Builder<ElectricityContract> $query
     */
    public static function scopeCategory($query, PricingCategory $category): void
    {
        $market = self::marketConstraint();
        $effect = self::effectConstraint();

        match ($category) {
            PricingCategory::Market => $query->where($market),
            // Market wins over consumption effect, so the effect category must exclude it.
            PricingCategory::ConsumptionEffect => $query->whereNot($market)->where($effect),
            PricingCategory::Fixed => $query->whereNot($market)->whereNot($effect),
        };
    }

    /**
     * Constrain a query to one pricing bucket — the granular form of scopeCategory(), used by
     * the visible pricing-type filter (`?hintatyyppi=`).
     *
     * Built from the same constraints as scopeCategory() so the filter, the SEO pages and the
     * card band cannot drift apart. The buckets partition the contract set, so the four scopes
     * are disjoint and their union is every contract.
     *
     * @param \Illuminate\Database\Eloquent\Builder<ElectricityContract> $query
     */
    public static function scopeBucket($query, PricingBucket $bucket): void
    {
        $spot = self::spotConstraint();
        $market = self::marketConstraint();
        $effect = self::effectConstraint();

        match ($bucket) {
            PricingBucket::Spot => $query->where($spot),
            // Spot wins inside the market category, so the reset bucket must exclude it.
            PricingBucket::MarketReset => $query->whereNot($spot)->where($market),
            PricingBucket::ConsumptionEffect => $query->whereNot($market)->where($effect),
            PricingBucket::Fixed => $query->whereNot($market)->whereNot($effect),
        };
    }

    /** The SQL form of `$isSpot` in resolve(). */
    private static function spotConstraint(): \Closure
    {
        return static function ($q): void {
            $q->where('pricing_model', 'Spot');
        };
    }

    /** The SQL form of `$isSpot || $isReset` in resolve(). */
    private static function marketConstraint(): \Closure
    {
        return static function ($q): void {
            $q->where(self::spotConstraint())
                ->orWhere(function ($reset): void {
                    $reset->where('canonical_pricing->recurring_schedule->present', true)
                        ->whereIn('canonical_pricing->recurring_schedule->cadence', self::RESET_CADENCES);
                });
        };
    }

    /** The SQL form of `$hasConsumptionEffect` in resolve(). */
    private static function effectConstraint(): \Closure
    {
        return static function ($q): void {
            $q->where('pricing_model', 'Hybrid')
                ->orWhere(function ($disclosed): void {
                    $disclosed->where('canonical_pricing->consumption_effect->present', true)
                        ->whereIn('canonical_pricing->consumption_effect->applies_to', self::BASE_CONTRACT_EFFECT);
                });
        };
    }

    /**
     * The first day of the next pricing period. `current_period_end` is inclusive, so the
     * next period starts the day after. A boundary that has already passed is stale data
     * from an interpretation that predates the reset, and is dropped rather than shown.
     *
     * @param array<string, mixed> $schedule
     */
    private function nextReset(array $schedule, CarbonImmutable $today): ?CarbonImmutable
    {
        $end = $schedule['current_period_end'] ?? null;
        if (! is_string($end) || $end === '') {
            return null;
        }

        try {
            $next = CarbonImmutable::parse($end, 'Europe/Helsinki')->startOfDay()->addDay();
        } catch (\Throwable) {
            return null;
        }

        return $next->greaterThan($today) ? $next : null;
    }
}
