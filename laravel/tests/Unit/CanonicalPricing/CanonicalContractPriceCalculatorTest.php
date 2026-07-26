<?php

namespace Tests\Unit\CanonicalPricing;

use App\Services\CanonicalPricing\CanonicalContractPriceCalculator;
use App\Services\CanonicalPricing\CanonicalPricingParser;
use App\Services\CanonicalPricing\DTO\ContractContext;
use App\Services\CanonicalPricing\DTO\SpotAssumptions;
use App\Services\CanonicalPricing\Enums\ContractComparability;
use App\Services\CanonicalPricing\Enums\EstimateMethod;
use App\Services\DTO\EnergyUsage;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\TestCase;

class CanonicalContractPriceCalculatorTest extends TestCase
{
    private CanonicalPricingParser $parser;
    private CanonicalContractPriceCalculator $calculator;
    private CarbonImmutable $start;
    private EnergyUsage $usage;

    protected function setUp(): void
    {
        parent::setUp();
        $this->parser = new CanonicalPricingParser();
        $this->calculator = new CanonicalContractPriceCalculator();
        $this->start = CarbonImmutable::parse('2026-07-24', 'Europe/Helsinki');
        $this->usage = new EnergyUsage(total: 5000, basicLiving: 5000);
    }

    private function component(string $type, ?float $amount, string $unit = 'cents_per_kwh', string $role = 'current'): array
    {
        return [
            'component_type' => $type,
            'amount' => $amount,
            'normal_amount' => null,
            'unit' => $unit,
            'vat_status' => 'included',
            'price_role' => $role,
            'source_kind' => 'both',
            'evidence' => [],
        ];
    }

    private function phase(string $kind, array $starts, array $ends, array $components): array
    {
        return [
            'label' => $kind,
            'phase_kind' => $kind,
            'starts' => $starts,
            'ends' => $ends,
            'components' => $components,
            'evidence' => [],
        ];
    }

    private function pricing(array $phases, array $recurring = [], array $consumptionEffect = []): array
    {
        return [
            'phases' => $phases,
            'recurring_schedule' => array_merge([
                'present' => false, 'cadence' => 'none', 'current_period_start' => null,
                'current_period_end' => null, 'future_price_known' => null, 'description' => null, 'evidence' => [],
            ], $recurring),
            'consumption_effect' => array_merge([
                'present' => false, 'applies_to' => 'unknown', 'cadence' => 'none',
                'expected_cents_per_kwh' => null, 'typical_min_cents_per_kwh' => null, 'typical_max_cents_per_kwh' => null,
                'hard_min_cents_per_kwh' => null, 'hard_max_cents_per_kwh' => null, 'uncapped' => null,
                'description' => null, 'evidence' => [],
            ], $consumptionEffect),
        ];
    }

    private function evaluate(array $pricing, string $status, array $sourceConsistency, ContractContext $context, ?SpotAssumptions $spot = null)
    {
        $data = $this->parser->parse($pricing, ['status' => $status, 'missing_facts' => [], 'required_assumptions' => []], $sourceConsistency);

        return $this->calculator->calculate(
            $data,
            $context,
            $this->usage,
            $spot ?? new SpotAssumptions(null, null),
            $this->start,
        );
    }

    private function context(string $model = 'FixedPrice', string $type = 'OpenEnded', ?string $fixedRange = null): ContractContext
    {
        return new ContractContext($model, $type, 'General', $fixedRange, 'Household');
    }

    private function cs(string $misleading = 'not_detected', array $issues = []): array
    {
        return [
            'misleading_first_12_months' => $misleading,
            'structured_pricing_status' => 'complete',
            'issue_codes' => $issues,
        ];
    }

    public function test_1_promo_with_known_later_price_ranks_by_true_cost(): void
    {
        $pricing = $this->pricing([
            $this->phase('introductory', ['kind' => 'contract_start', 'value' => null], ['kind' => 'date', 'value' => '2026-07-31'], [
                $this->component('energy_general', 5.49, 'cents_per_kwh', 'introductory'),
                $this->component('monthly_fee', 2.99, 'eur_per_month', 'introductory'),
            ]),
            $this->phase('normal', ['kind' => 'date', 'value' => '2026-08-01'], ['kind' => 'none', 'value' => null], [
                $this->component('energy_general', 13.65, 'cents_per_kwh', 'normal'),
                $this->component('monthly_fee', 5.99, 'eur_per_month', 'normal'),
            ]),
        ]);

        $outcome = $this->evaluate($pricing, 'exact', $this->cs('detected', ['structured_matches_intro_only', 'future_price_omitted']), $this->context());

        $this->assertSame(ContractComparability::ComparableExact, $outcome->comparability);
        // Structured-only (promo held 12 months) would be ~310 EUR.
        $this->assertEqualsWithDelta(310.38, $outcome->structuredOnlyTotal, 1.0);
        // The true cost is dominated by the normal price and must be far above the promo-only figure.
        $this->assertGreaterThan(700, $outcome->totalCost);
        $this->assertLessThan(755, $outcome->totalCost);
        // Card display shows the signup (promo) price.
        $this->assertEqualsWithDelta(5.49, $outcome->generalKwhPrice, 0.001);
    }

    public function test_2_open_ended_promo_with_unknown_later_price_is_excluded(): void
    {
        $pricing = $this->pricing([
            $this->phase('introductory', ['kind' => 'contract_start', 'value' => null], ['kind' => 'after_months', 'value' => '1'], [
                $this->component('energy_general', 4.0, 'cents_per_kwh', 'introductory'),
            ]),
        ]);

        $outcome = $this->evaluate($pricing, 'estimate_required', $this->cs('detected', ['promotion_metadata_missing', 'structured_matches_intro_only', 'future_price_unknown']), $this->context());

        $this->assertSame(ContractComparability::ExcludedUnknownFuture, $outcome->comparability);
        $this->assertNull($outcome->totalCost);
        $this->assertFalse($outcome->isListed());
    }

    public function test_3_correct_single_price_contract_is_comparable_exact(): void
    {
        $pricing = $this->pricing([
            $this->phase('current_structured', ['kind' => 'contract_start', 'value' => null], ['kind' => 'none', 'value' => null], [
                $this->component('energy_general', 6.0),
                $this->component('monthly_fee', 3.0, 'eur_per_month'),
            ]),
        ]);

        $outcome = $this->evaluate($pricing, 'exact', $this->cs('not_detected'), $this->context());

        $this->assertSame(ContractComparability::ComparableExact, $outcome->comparability);
        $this->assertEqualsWithDelta(5000 * 6.0 / 100 + 3.0 * 12, $outcome->totalCost, 0.5);
    }

    public function test_4_recurring_quarterly_is_a_listed_estimate(): void
    {
        $pricing = $this->pricing(
            [$this->phase('recurring_period', ['kind' => 'contract_start', 'value' => null], ['kind' => 'none', 'value' => null], [
                $this->component('energy_general', 7.0),
            ])],
            ['present' => true, 'cadence' => 'quarterly'],
        );

        $outcome = $this->evaluate($pricing, 'estimate_required', $this->cs('not_detected', ['recurring_reset_requires_estimate']), $this->context());

        $this->assertSame(ContractComparability::ComparableEstimate, $outcome->comparability);
        $this->assertTrue($outcome->isEstimate());
        $this->assertEqualsWithDelta(350.0, $outcome->totalCost, 0.5);
    }

    public function test_5_fixed_twelve_month_exact(): void
    {
        $pricing = $this->pricing([
            $this->phase('current_structured', ['kind' => 'contract_start', 'value' => null], ['kind' => 'after_months', 'value' => '12'], [
                $this->component('energy_general', 8.0),
            ]),
        ]);

        $outcome = $this->evaluate($pricing, 'exact', $this->cs('not_detected'), $this->context('FixedPrice', 'FixedTerm', 'Fixed12'));

        $this->assertSame(ContractComparability::ComparableExact, $outcome->comparability);
        $this->assertEqualsWithDelta(400.0, $outcome->totalCost, 0.5);
    }

    public function test_6_six_month_fixed_term_is_term_price_only(): void
    {
        $pricing = $this->pricing([
            $this->phase('current_structured', ['kind' => 'contract_start', 'value' => null], ['kind' => 'after_months', 'value' => '6'], [
                $this->component('energy_general', 5.0),
            ]),
        ]);

        $outcome = $this->evaluate($pricing, 'incomplete', $this->cs('detected', ['future_price_omitted', 'future_price_unknown']), $this->context('FixedPrice', 'FixedTerm', 'Fixed6'));

        $this->assertSame(ContractComparability::TermPriceOnly, $outcome->comparability);
        $this->assertSame(6, $outcome->termMonths);
        $this->assertEqualsWithDelta(250.0, $outcome->totalCost, 0.5);
        $this->assertSame(EstimateMethod::TermPriceAnnualized, $outcome->estimateMethod);
    }

    public function test_7_spot_with_margin_and_monthly_fee_is_estimate(): void
    {
        $pricing = $this->pricing([
            $this->phase('current_structured', ['kind' => 'contract_start', 'value' => null], ['kind' => 'none', 'value' => null], [
                $this->component('spot_margin', 0.5),
                $this->component('monthly_fee', 3.6, 'eur_per_month'),
            ]),
        ]);

        $outcome = $this->evaluate($pricing, 'estimate_required', $this->cs('not_detected'), $this->context('Spot'), new SpotAssumptions(8.0, 5.0));

        $this->assertSame(ContractComparability::ComparableEstimate, $outcome->comparability);
        $this->assertTrue($outcome->isSpotContract);
        $this->assertSame(EstimateMethod::Rolling365Spot, $outcome->estimateMethod);
        $this->assertGreaterThan(0, $outcome->totalCost);
        $this->assertEqualsWithDelta(0.5, $outcome->spotPriceMargin, 0.001);
    }

    public function test_8_time_of_use_day_night(): void
    {
        $pricing = $this->pricing([
            $this->phase('current_structured', ['kind' => 'contract_start', 'value' => null], ['kind' => 'none', 'value' => null], [
                $this->component('energy_day', 10.0),
                $this->component('energy_night', 5.0),
            ]),
        ]);

        $outcome = $this->evaluate($pricing, 'exact', $this->cs('not_detected'), $this->context('FixedPrice', 'OpenEnded'));

        $this->assertSame(ContractComparability::ComparableExact, $outcome->comparability);
        // 85% day at 10, 15% night at 5 → 5000*(0.85*10+0.15*5)/100 = 462.5
        $this->assertEqualsWithDelta(462.5, $outcome->totalCost, 1.0);
    }

    public function test_9_seasonal(): void
    {
        $pricing = $this->pricing([
            $this->phase('current_structured', ['kind' => 'contract_start', 'value' => null], ['kind' => 'none', 'value' => null], [
                $this->component('energy_seasonal_winter', 12.0),
                $this->component('energy_seasonal_other', 6.0),
            ]),
        ]);

        $outcome = $this->evaluate($pricing, 'exact', $this->cs('not_detected'), $this->context());

        $this->assertSame(ContractComparability::ComparableExact, $outcome->comparability);
        $this->assertGreaterThan(300, $outcome->totalCost);
    }

    public function test_10_incomplete_package_is_excluded(): void
    {
        $pricing = $this->pricing([
            $this->phase('current_structured', ['kind' => 'contract_start', 'value' => null], ['kind' => 'none', 'value' => null], [
                $this->component('flat_fee', 25.0, 'eur_per_month'),
            ]),
        ]);

        $outcome = $this->evaluate($pricing, 'incomplete', $this->cs('detected', ['future_price_omitted']), $this->context());

        $this->assertSame(ContractComparability::ExcludedIncomplete, $outcome->comparability);
        $this->assertNull($outcome->totalCost);
    }

    public function test_10b_complete_flat_package_is_comparable(): void
    {
        $pricing = $this->pricing([
            $this->phase('current_structured', ['kind' => 'contract_start', 'value' => null], ['kind' => 'none', 'value' => null], [
                $this->component('flat_fee', 30.0, 'eur_per_month'),
            ]),
        ]);

        $outcome = $this->evaluate($pricing, 'exact', $this->cs('not_detected'), $this->context());

        $this->assertSame(ContractComparability::ComparableExact, $outcome->comparability);
        $this->assertEqualsWithDelta(360.0, $outcome->totalCost, 0.5);
    }

    public function test_11_hybrid_is_base_only_with_disclosure(): void
    {
        $pricing = $this->pricing(
            [$this->phase('current_structured', ['kind' => 'contract_start', 'value' => null], ['kind' => 'none', 'value' => null], [
                $this->component('energy_general', 9.0),
                $this->component('consumption_effect', null, 'unknown', 'unknown'),
            ])],
            [],
            ['present' => true, 'applies_to' => 'base_contract', 'typical_min_cents_per_kwh' => -1.5, 'typical_max_cents_per_kwh' => 1.5],
        );

        $outcome = $this->evaluate($pricing, 'unsupported', $this->cs('uncertain', ['unsupported_consumption_effect']), $this->context('Hybrid'));

        $this->assertSame(ContractComparability::BaseOnlyHybrid, $outcome->comparability);
        $this->assertEqualsWithDelta(450.0, $outcome->totalCost, 0.5);
        $this->assertNotNull($outcome->consumptionEffect);
        $this->assertTrue($outcome->consumptionEffect->hasDisclosedBounds());
    }

    public function test_12_expired_promo_phase_is_dropped(): void
    {
        $pricing = $this->pricing([
            $this->phase('introductory', ['kind' => 'contract_start', 'value' => null], ['kind' => 'date', 'value' => '2026-06-30'], [
                $this->component('energy_general', 3.0, 'cents_per_kwh', 'introductory'),
            ]),
            $this->phase('normal', ['kind' => 'date', 'value' => '2026-07-01'], ['kind' => 'none', 'value' => null], [
                $this->component('energy_general', 10.0, 'cents_per_kwh', 'normal'),
            ]),
        ]);

        $outcome = $this->evaluate($pricing, 'exact', $this->cs('not_detected'), $this->context());

        $this->assertSame(ContractComparability::ComparableExact, $outcome->comparability);
        // Only the 10 c/kWh phase applies within the window → 500 EUR.
        $this->assertEqualsWithDelta(500.0, $outcome->totalCost, 1.0);
        $this->assertEqualsWithDelta(10.0, $outcome->generalKwhPrice, 0.001);
    }

    public function test_14_unknown_start_current_price_covers_the_window(): void
    {
        // A single current_structured phase with an unknown start but a resolvable end is the
        // already-running current price and must cover the whole window, not be excluded.
        $pricing = $this->pricing([
            $this->phase('current_structured', ['kind' => 'unknown', 'value' => null], ['kind' => 'after_months', 'value' => '12'], [
                $this->component('energy_general', 8.0),
            ]),
        ]);

        $outcome = $this->evaluate($pricing, 'exact', $this->cs('not_detected'), $this->context('FixedPrice', 'FixedTerm', 'Fixed12'));

        $this->assertSame(ContractComparability::ComparableExact, $outcome->comparability);
        $this->assertEqualsWithDelta(400.0, $outcome->totalCost, 0.5);
    }

    public function test_15_recurring_phase_with_unknown_boundaries_still_costs(): void
    {
        // A quarterly product whose current-price phase has unknown start AND end boundaries
        // (no dates disclosed). It must be held forward as the current price, not costed at 0.
        $pricing = $this->pricing(
            [$this->phase('recurring_period', ['kind' => 'unknown', 'value' => null], ['kind' => 'unknown', 'value' => null], [
                $this->component('energy_general', 11.5),
                $this->component('monthly_fee', 3.99, 'eur_per_month'),
            ])],
            ['present' => true, 'cadence' => 'quarterly'],
        );

        $outcome = $this->evaluate($pricing, 'estimate_required', $this->cs('not_detected', ['recurring_reset_requires_estimate']), $this->context());

        $this->assertSame(ContractComparability::ComparableEstimate, $outcome->comparability);
        $this->assertEqualsWithDelta(5000 * 11.5 / 100 + 3.99 * 12, $outcome->totalCost, 0.5);
        $this->assertEqualsWithDelta(11.5, $outcome->generalKwhPrice, 0.001);
    }

    public function test_16_duplicate_zero_energy_component_does_not_win(): void
    {
        // A phase carrying a real 9.89 rate plus a spurious 0 duplicate of the same type.
        // The non-zero rate must win, not the placeholder 0.
        $pricing = $this->pricing([
            $this->phase('current_structured', ['kind' => 'contract_start', 'value' => null], ['kind' => 'none', 'value' => null], [
                $this->component('energy_general', 9.89),
                $this->component('energy_general', 0.0),
                $this->component('monthly_fee', 5.9, 'eur_per_month'),
            ]),
        ]);

        $outcome = $this->evaluate($pricing, 'exact', $this->cs('not_detected'), $this->context());

        $this->assertEqualsWithDelta(5000 * 9.89 / 100 + 5.9 * 12, $outcome->totalCost, 0.5);
    }

    public function test_17_promo_phase_inherits_unchanged_energy_price_from_base(): void
    {
        // A "0 € perusmaksu ensimmäinen kuukausi" promo: the intro phase lists only the changed
        // monthly fee (0) and omits the unchanged energy price, which lives in the normal phase.
        // The intro month must inherit the 8,98 energy price, not be read as free energy.
        $pricing = $this->pricing([
            $this->phase('introductory', ['kind' => 'contract_start', 'value' => null], ['kind' => 'after_months', 'value' => '1'], [
                $this->component('monthly_fee', 0.0, 'eur_per_month', 'introductory'),
            ]),
            $this->phase('normal', ['kind' => 'after_months', 'value' => '1'], ['kind' => 'none', 'value' => null], [
                $this->component('energy_general', 8.98, 'cents_per_kwh', 'normal'),
                $this->component('monthly_fee', 5.95, 'eur_per_month', 'normal'),
            ]),
        ]);

        $outcome = $this->evaluate($pricing, 'estimate_required', $this->cs('not_detected', ['recurring_reset_requires_estimate']), $this->context());

        $this->assertTrue($outcome->isListed());
        // Energy 8,98 all 12 months + fee only for months 1-11 (month 0 is free) ≈ 449 + 65 ≈ 514.
        $this->assertEqualsWithDelta(5000 * 8.98 / 100 + 5.95 * 11, $outcome->totalCost, 3.0);
        $this->assertGreaterThan(490, $outcome->totalCost);
    }

    public function test_18_recurring_product_with_intro_is_listed_and_annualised_on_recurring_price(): void
    {
        // Cheap Kvartaalisähkö shape: a 1-month intro (7,49) + a locked continuation (9,95) then an
        // uncovered quarterly tail, flagged detected. It is a legitimate recurring market product:
        // listed as an estimate, annualised on the recurring price, NOT excluded.
        $pricing = $this->pricing(
            [
                $this->phase('introductory', ['kind' => 'contract_start', 'value' => null], ['kind' => 'after_months', 'value' => '1'], [
                    $this->component('energy_general', 7.49, 'cents_per_kwh', 'introductory'),
                    $this->component('monthly_fee', 0.0, 'eur_per_month', 'introductory'),
                ]),
                $this->phase('continuation', ['kind' => 'after_months', 'value' => '1'], ['kind' => 'date', 'value' => now()->addMonths(3)->format('Y-m-d')], [
                    $this->component('energy_general', 9.95, 'cents_per_kwh', 'normal'),
                    $this->component('monthly_fee', 4.9, 'eur_per_month', 'normal'),
                ]),
            ],
            ['present' => true, 'cadence' => 'quarterly'],
        );

        $outcome = $this->evaluate($pricing, 'estimate_required', $this->cs('detected', ['structured_matches_intro_only', 'future_price_omitted', 'recurring_reset_requires_estimate']), $this->context());

        $this->assertSame(ContractComparability::ComparableEstimate, $outcome->comparability);
        $this->assertTrue($outcome->isEstimate());
        // Dominated by the 9,95 recurring price (not the 7,49 intro): well above a promo-only ~375 total.
        $this->assertGreaterThan(480, $outcome->totalCost);
    }

    public function test_19_incomplete_spot_with_margin_is_listed_as_estimate(): void
    {
        // Porvoo SPOT shape: a spot margin + monthly fee, marked incomplete only because the
        // description calls the margin a "toimitusmaksu". A disclosed margin is fully costable.
        $pricing = $this->pricing([
            $this->phase('current_structured', ['kind' => 'contract_start', 'value' => null], ['kind' => 'none', 'value' => null], [
                $this->component('spot_margin', 0.41),
                $this->component('monthly_fee', 3.6, 'eur_per_month'),
            ]),
        ]);

        $outcome = $this->evaluate($pricing, 'incomplete', $this->cs('detected', ['insufficient_evidence']), $this->context('Spot'), new SpotAssumptions(8.0, 5.0));

        $this->assertSame(ContractComparability::ComparableEstimate, $outcome->comparability);
        $this->assertGreaterThan(0, $outcome->totalCost);
    }

    public function test_20_incomplete_duplicate_monthly_fee_lists_with_higher_fee(): void
    {
        // Vattenfall Täysvesi shape: two monthly fees (3,95 and 4,90), incomplete only because it's
        // unclear which applies. Fully covered and costable → listed with the conservative higher fee.
        $pricing = $this->pricing([
            $this->phase('current_structured', ['kind' => 'contract_start', 'value' => null], ['kind' => 'none', 'value' => null], [
                $this->component('monthly_fee', 3.95, 'eur_per_month'),
                $this->component('monthly_fee', 4.9, 'eur_per_month'),
                $this->component('energy_general', 10.09),
            ]),
        ]);

        $outcome = $this->evaluate($pricing, 'incomplete', $this->cs('not_detected', ['component_mismatch']), $this->context());

        $this->assertTrue($outcome->isListed());
        $this->assertEqualsWithDelta(4.9, $outcome->monthlyFixedFee, 0.001);
        $this->assertEqualsWithDelta(5000 * 10.09 / 100 + 4.9 * 12, $outcome->totalCost, 1.0);
    }

    public function test_13_phase_starting_at_month_twelve_is_ignored(): void
    {
        $pricing = $this->pricing([
            $this->phase('current_structured', ['kind' => 'contract_start', 'value' => null], ['kind' => 'none', 'value' => null], [
                $this->component('energy_general', 6.0),
            ]),
            $this->phase('future', ['kind' => 'after_months', 'value' => '12'], ['kind' => 'none', 'value' => null], [
                $this->component('energy_general', 20.0, 'cents_per_kwh', 'future'),
            ]),
        ]);

        $outcome = $this->evaluate($pricing, 'exact', $this->cs('not_detected'), $this->context());

        $this->assertSame(ContractComparability::ComparableExact, $outcome->comparability);
        $this->assertEqualsWithDelta(300.0, $outcome->totalCost, 1.0);
    }

    public function test_21_spot_contract_margin_misclassified_as_fixed_energy_uses_spot_base(): void
    {
        // Spot Valo: a Spot contract whose margin was tagged as energy_day/energy_night (0.33)
        // instead of spot_margin. On a Spot contract a sub-ceiling per-kWh rate is the margin,
        // so the spot base must be added rather than 0.33 c/kWh being read as the whole price.
        $pricing = $this->pricing([
            $this->phase('current_structured', ['kind' => 'contract_start', 'value' => null], ['kind' => 'none', 'value' => null], [
                $this->component('energy_day', 0.33),
                $this->component('energy_night', 0.33),
                $this->component('monthly_fee', 4.65, 'eur_per_month'),
            ]),
        ]);

        $outcome = $this->evaluate($pricing, 'estimate_required', $this->cs('not_detected'), $this->context('Spot'), new SpotAssumptions(8.0, 5.0));

        $this->assertSame(ContractComparability::ComparableEstimate, $outcome->comparability);
        $this->assertTrue($outcome->isSpotContract);
        $this->assertSame(EstimateMethod::Rolling365Spot, $outcome->estimateMethod);
        $this->assertEqualsWithDelta(0.33, $outcome->spotPriceMargin, 0.001);
        // energy = spot base + 0.33 margin, not a flat 0.33 c/kWh → far above the fee-only ~56 EUR
        $this->assertGreaterThan(350, $outcome->totalCost);
    }

    public function test_22_spot_contract_all_in_market_price_above_ceiling_stays_fixed(): void
    {
        // Cheap Markkinahintasähkö: a Spot contract with a genuine all-in market price of
        // 6.99 c/kWh (above the margin ceiling). It must NOT be treated as a margin (which would
        // double-count the spot base); it stays a fixed energy rate.
        $pricing = $this->pricing([
            $this->phase('current_structured', ['kind' => 'contract_start', 'value' => null], ['kind' => 'none', 'value' => null], [
                $this->component('energy_general', 6.99),
                $this->component('monthly_fee', 3.0, 'eur_per_month'),
            ]),
        ]);

        $outcome = $this->evaluate($pricing, 'estimate_required', $this->cs('not_detected'), $this->context('Spot'), new SpotAssumptions(8.0, 5.0));

        $this->assertNull($outcome->spotPriceMargin);
        // 5000 kWh * 6.99 c/kWh = 349.5 EUR energy + 36 EUR fee = ~385.5, not ~700 (base + 6.99)
        $this->assertEqualsWithDelta(385.5, $outcome->totalCost, 1.0);
    }

    public function test_23_spot_continuation_phase_does_not_inherit_the_intro_fixed_energy_price(): void
    {
        // Cheap Markkinahintasähkö: one month at a flat 6,99 c/kWh, then Nord Pool monthly
        // average + 1,29 c/kWh. The continuation phase states only its margin, so component
        // inheritance used to hand it the intro phase's energy_general 6,99, and
        // resolvePhaseRates prefers a fixed rate over the spot base. The whole year was then
        // priced at the one-month promo rate. spot_margin and energy_* are two ways of pricing
        // the same kWh, so a phase that states one must not inherit the other.
        $pricing = $this->pricing([
            $this->phase('introductory', ['kind' => 'contract_start', 'value' => null], ['kind' => 'after_months', 'value' => '1'], [
                $this->component('energy_general', 6.99, 'cents_per_kwh', 'introductory'),
                $this->component('monthly_fee', 0.0, 'eur_per_month', 'introductory'),
            ]),
            $this->phase('continuation', ['kind' => 'after_months', 'value' => '1'], ['kind' => 'none', 'value' => null], [
                $this->component('spot_margin', 1.29, 'cents_per_kwh', 'normal'),
                $this->component('monthly_fee', 4.99, 'eur_per_month', 'normal'),
            ]),
        ]);

        $outcome = $this->evaluate($pricing, 'estimate_required', $this->cs('not_detected'), $this->context('Spot'), new SpotAssumptions(8.0, 5.0));

        $this->assertTrue($outcome->isListed());
        $this->assertTrue($outcome->isSpotContract);
        // Month 1 at a flat 6,99 with no fee; months 2-12 at the spot base plus the 1,29 margin,
        // blended over the 15 % default night share, plus 4,99/kk.
        $intro = 5000 / 12 * 6.99 / 100;
        $tailRate = 0.85 * (8.0 + 1.29) + 0.15 * (5.0 + 1.29);
        $tail = 5000 * 11 / 12 * $tailRate / 100 + 4.99 * 11;
        $this->assertEqualsWithDelta($intro + $tail, $outcome->totalCost, 2.0);
        // The old behaviour held the intro rate for the year: 349,5 + 11 * 4,99 = 404,4.
        $this->assertGreaterThan(450, $outcome->totalCost);
    }

    public function test_23b_the_phase_breakdown_records_the_resolved_dates_and_rates(): void
    {
        // The detail page states a mid-window mechanism change as two dated receipt rows. It
        // reads them from this breakdown instead of re-deriving the phase timeline, so the
        // resolved coverage and the rates each phase was costed at have to travel with the
        // cost payload.
        $pricing = $this->pricing([
            $this->phase('introductory', ['kind' => 'contract_start', 'value' => null], ['kind' => 'after_months', 'value' => '1'], [
                $this->component('energy_general', 6.99, 'cents_per_kwh', 'introductory'),
                $this->component('monthly_fee', 0.0, 'eur_per_month', 'introductory'),
            ]),
            $this->phase('continuation', ['kind' => 'after_months', 'value' => '1'], ['kind' => 'none', 'value' => null], [
                $this->component('spot_margin', 1.29, 'cents_per_kwh', 'normal'),
                $this->component('monthly_fee', 4.99, 'eur_per_month', 'normal'),
            ]),
        ]);

        $breakdown = $this->evaluate($pricing, 'estimate_required', $this->cs('not_detected'), $this->context('Spot'), new SpotAssumptions(8.0, 5.0))
            ->phaseBreakdown;

        $this->assertCount(2, $breakdown);

        $this->assertFalse($breakdown[0]['uses_spot']);
        $this->assertSame('2026-07-24', $breakdown[0]['window_start']);
        $this->assertSame('2026-08-23', $breakdown[0]['window_end']);
        $this->assertEqualsWithDelta(6.99, $breakdown[0]['energy_cents'], 0.001);
        $this->assertNull($breakdown[0]['spot_margin_cents']);
        $this->assertEqualsWithDelta(0.0, $breakdown[0]['monthly_fee'], 0.001);

        $this->assertTrue($breakdown[1]['uses_spot']);
        $this->assertSame('2026-08-24', $breakdown[1]['window_start']);
        $this->assertSame('2027-07-23', $breakdown[1]['window_end']);
        $this->assertNull($breakdown[1]['energy_cents']);
        $this->assertEqualsWithDelta(1.29, $breakdown[1]['spot_margin_cents'], 0.001);
        $this->assertEqualsWithDelta(4.99, $breakdown[1]['monthly_fee'], 0.001);
    }

    public function test_24_fixed_term_phase_still_inherits_within_the_same_energy_mechanism(): void
    {
        // The mechanism guard must not break ordinary inheritance: a Time-metered phase that
        // restates only the day rate still inherits the unchanged night rate from the base phase.
        $pricing = $this->pricing([
            $this->phase('normal', ['kind' => 'contract_start', 'value' => null], ['kind' => 'after_months', 'value' => '6'], [
                $this->component('energy_day', 9.0),
                $this->component('energy_night', 6.0),
                $this->component('monthly_fee', 4.0, 'eur_per_month'),
            ]),
            $this->phase('continuation', ['kind' => 'after_months', 'value' => '6'], ['kind' => 'none', 'value' => null], [
                $this->component('energy_day', 10.0),
            ]),
        ]);

        $context = new ContractContext('FixedPrice', 'OpenEnded', 'Time', null, 'Household');
        $outcome = $this->evaluate($pricing, 'exact', $this->cs('not_detected'), $context);

        $this->assertTrue($outcome->isListed());
        // The second phase keeps night 6,0 and fee 4,0 by inheritance; only the day rate changes.
        $this->assertEqualsWithDelta(6.0, $outcome->nighttimeKwhPrice, 0.001);
        $this->assertEqualsWithDelta(4.0, $outcome->monthlyFixedFee, 0.001);
    }
}
