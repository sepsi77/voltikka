<?php

namespace Tests\Unit\CanonicalPricing;

use App\Services\CanonicalPricing\CanonicalContractPriceCalculator;
use App\Services\CanonicalPricing\CanonicalOfferFacts;
use App\Services\CanonicalPricing\CanonicalPricingParser;
use App\Services\CanonicalPricing\DTO\ContractContext;
use App\Services\CanonicalPricing\DTO\SpotAssumptions;
use App\Services\CanonicalPricing\Enums\ContractComparability;
use App\Services\CanonicalPricing\Enums\EstimateMethod;
use App\Services\DTO\EnergyUsage;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\TestCase;
use Tests\Unit\CanonicalPricing\Support\HoldFlatCanonicalCalculator;

class CanonicalContractPriceCalculatorTest extends TestCase
{
    private CanonicalPricingParser $parser;

    private CanonicalContractPriceCalculator $calculator;

    private CarbonImmutable $start;

    private EnergyUsage $usage;

    protected function setUp(): void
    {
        parent::setUp();
        $this->parser = new CanonicalPricingParser;
        $this->calculator = HoldFlatCanonicalCalculator::make();
        $this->start = CarbonImmutable::parse('2026-07-24', 'Europe/Helsinki');
        $this->usage = new EnergyUsage(total: 5000, basicLiving: 5000);
    }

    private function component(string $type, ?float $amount, string $unit = 'cents_per_kwh', string $role = 'current', ?float $normalAmount = null): array
    {
        return [
            'component_type' => $type,
            'amount' => $amount,
            'normal_amount' => $normalAmount,
            'unit' => $unit,
            'vat_status' => 'included',
            'price_role' => $role,
            'source_kind' => 'both',
            'evidence' => [],
        ];
    }

    private function phase(string $kind, array $starts, array $ends, array $components, ?array $package = null): array
    {
        return [
            'label' => $kind,
            'phase_kind' => $kind,
            'starts' => $starts,
            'ends' => $ends,
            'components' => $components,
            'package' => $package,
            'evidence' => [],
        ];
    }

    private function package(float $monthlyFee, float $includedKwh, float $excessRate, string $cadence = 'monthly'): array
    {
        return [
            'monthly_fee_eur' => $monthlyFee,
            'included_kwh' => $includedKwh,
            'allowance_cadence' => $cadence,
            'excess_rate_cents_per_kwh' => $excessRate,
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

    private function evaluate(array $pricing, string $status, array $sourceConsistency, ContractContext $context, ?SpotAssumptions $spot = null, ?CarbonImmutable $start = null, ?EnergyUsage $usage = null)
    {
        $data = $this->parser->parse($pricing, ['status' => $status, 'missing_facts' => [], 'required_assumptions' => []], $sourceConsistency);

        return $this->calculator->calculate(
            $data,
            $context,
            $usage ?? $this->usage,
            $spot ?? new SpotAssumptions(null, null),
            $start ?? $this->start,
        );
    }

    private function context(string $model = 'FixedPrice', string $type = 'OpenEnded', ?string $fixedRange = null, string $metering = 'General'): ContractContext
    {
        return new ContractContext($model, $type, $metering, $fixedRange, 'Household');
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
        $this->assertNull($outcome->toCalculatedCostArray()['contract_term']);
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
        $this->assertNull($outcome->toCalculatedCostArray()['contract_term']);
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
        $this->assertEqualsWithDelta(250.0, $outcome->baseTotalCost, 0.5);
        $this->assertSame(0.0, $outcome->discountSavingsTotal());
        $this->assertSame(EstimateMethod::TermPriceAnnualized, $outcome->estimateMethod);

        $term = $outcome->toCalculatedCostArray()['contract_term'];
        $this->assertSame(6, $term['months']);
        $this->assertEqualsWithDelta(125.0, $term['total_cost'], 0.5);
        $this->assertEqualsWithDelta(125.0, $term['base_total_cost'], 0.5);
        $this->assertSame(0.0, $term['discount_savings_total']);
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

    public function test_10c_monthly_package_costs_below_equal_and_above_allowance_without_offer_savings(): void
    {
        $pricing = $this->pricing([
            $this->phase(
                'current_structured',
                ['kind' => 'contract_start', 'value' => null],
                ['kind' => 'none', 'value' => null],
                [],
                $this->package(25.0, 150.0, 16.6),
            ),
        ]);
        $start = CarbonImmutable::parse('2026-07-01', 'Europe/Helsinki');

        foreach ([
            1200 => 300.0,
            1800 => 300.0,
            5000 => 300.0 + ((5000 - 1800) * 16.6 / 100),
        ] as $annualUsage => $expected) {
            $outcome = $this->evaluate(
                $pricing,
                'exact',
                $this->cs(),
                $this->context(),
                start: $start,
                usage: new EnergyUsage(total: $annualUsage, basicLiving: $annualUsage),
            );
            $cost = $outcome->toCalculatedCostArray();

            $this->assertEqualsWithDelta($expected, $outcome->totalCost, 0.001);
            $this->assertEqualsWithDelta($expected, $outcome->baseTotalCost, 0.001);
            $this->assertSame(0.0, $outcome->discountSavingsTotal());
            $this->assertFalse($cost['includes_discounts']);
            $this->assertSame(array_fill(0, 12, 0.0), $cost['monthly_discount_savings']);
        }
    }

    public function test_10d_monthly_package_does_not_pool_or_carry_unused_allowance(): void
    {
        $pricing = $this->pricing([
            $this->phase(
                'current_structured',
                ['kind' => 'contract_start', 'value' => null],
                ['kind' => 'none', 'value' => null],
                [],
                $this->package(25.0, 150.0, 16.6),
            ),
        ]);
        $usage = new EnergyUsage(
            total: 1800,
            roomHeating: 1800,
            heatingElectricityUseByMonth: [300, 300, 300, 300, 300, 300, 0, 0, 0, 0, 0, 0],
        );

        $outcome = $this->evaluate(
            $pricing,
            'exact',
            $this->cs(),
            $this->context(metering: 'Time'),
            start: CarbonImmutable::parse('2026-01-01', 'Europe/Helsinki'),
            usage: $usage,
        );

        $this->assertEqualsWithDelta(449.4, $outcome->totalCost, 0.001);
        $this->assertEqualsWithDelta(49.9, $outcome->monthlyCosts[0], 0.001);
        $this->assertEqualsWithDelta(25.0, $outcome->monthlyCosts[6], 0.001);
        $this->assertEqualsWithDelta(1800.0, array_sum($usage->heatingElectricityUseByMonth), 0.001);
    }

    public function test_10e_vaasan_xs_s_m_l_package_shapes_have_expected_annual_totals(): void
    {
        $expected = [
            [10.5, 75.0, 806.6],
            [21.0, 150.0, 783.2],
            [35.0, 250.0, 752.0],
            [49.0, 350.0, 720.8],
        ];

        foreach ($expected as [$fee, $allowance, $total]) {
            $pricing = $this->pricing([
                $this->phase(
                    'current_structured',
                    ['kind' => 'contract_start', 'value' => null],
                    ['kind' => 'none', 'value' => null],
                    [],
                    $this->package($fee, $allowance, 16.6),
                ),
            ]);
            $outcome = $this->evaluate(
                $pricing,
                'exact',
                $this->cs(),
                $this->context(),
                start: CarbonImmutable::parse('2026-01-01', 'Europe/Helsinki'),
            );

            $this->assertEqualsWithDelta($total, $outcome->totalCost, 0.001);
            $this->assertEqualsWithDelta($total, array_sum($outcome->monthlyCosts), 0.001);
            $this->assertSame($fee, $outcome->monthlyFixedFee);
            $this->assertSame($allowance, $outcome->energyPackage?->includedKwh);
            $this->assertSame(16.6, $outcome->generalKwhPrice);
        }
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
        $this->assertEqualsWithDelta(450.0, $outcome->baseTotalCost, 0.5);
        $this->assertSame(0.0, $outcome->discountSavingsTotal());
        $this->assertNotNull($outcome->consumptionEffect);
        $this->assertTrue($outcome->consumptionEffect->hasDisclosedBounds());
    }

    public function test_11b_vattenfall_shaped_twelve_month_fee_discount_reports_measured_savings(): void
    {
        $pricing = $this->pricing([
            $this->phase('current_structured', ['kind' => 'contract_start', 'value' => null], ['kind' => 'after_months', 'value' => '12'], [
                $this->component('energy_general', 6.0),
                $this->component('monthly_fee', 2.37, 'eur_per_month', 'introductory', 4.74),
            ]),
        ]);

        $outcome = $this->evaluate($pricing, 'exact', $this->cs('not_detected'), $this->context('FixedPrice', 'FixedTerm', 'Fixed12'));
        $cost = $outcome->toCalculatedCostArray();

        $this->assertEqualsWithDelta(328.44, $outcome->totalCost, 0.01);
        $this->assertEqualsWithDelta(356.88, $outcome->baseTotalCost, 0.01);
        $this->assertEqualsWithDelta(28.44, $outcome->discountSavingsTotal(), 0.01);
        $this->assertEqualsWithDelta(28.44, $cost['discount_savings_total'], 0.01);
        $this->assertCount(12, $cost['monthly_discount_savings']);
        $this->assertEqualsWithDelta(28.44, array_sum($cost['monthly_discount_savings']), 0.01);
        foreach ($cost['monthly_discount_savings'] as $month => $saving) {
            $this->assertEqualsWithDelta($outcome->baseMonthlyCosts[$month] - $outcome->monthlyCosts[$month], $saving, 0.0001);
        }
        $this->assertEqualsWithDelta($outcome->baseTotalCost, array_sum($outcome->baseMonthlyCosts), 0.001);
        $this->assertSame('after_months', $cost['offer_terms'][0]['end_kind']);
        $this->assertSame(12, $cost['offer_terms'][0]['duration_months']);
        $this->assertSame('monthly_fee', $cost['offer_terms'][0]['components'][0]['component_type']);
        $this->assertSame(2.37, $cost['offer_terms'][0]['components'][0]['amount']);
        $this->assertSame(4.74, $cost['offer_terms'][0]['components'][0]['normal_amount']);
    }

    public function test_11bb_multiple_changed_components_produce_one_controlled_offer_term(): void
    {
        $intro = $this->phase('introductory', ['kind' => 'contract_start', 'value' => null], ['kind' => 'after_months', 'value' => '3'], [
            $this->component('energy_general', 7.0, 'cents_per_kwh', 'introductory', 8.0),
            $this->component('monthly_fee', 2.0, 'eur_per_month', 'introductory', 4.0),
        ]);
        $intro['label'] = 'HOSTILE RAW PHASE LABEL';

        $pricing = $this->pricing([
            $intro,
            $this->phase('normal', ['kind' => 'after_months', 'value' => '3'], ['kind' => 'none', 'value' => null], [
                $this->component('energy_general', 8.0, 'cents_per_kwh', 'normal'),
                $this->component('monthly_fee', 4.0, 'eur_per_month', 'normal'),
            ]),
        ]);

        $cost = $this->evaluate(
            $pricing,
            'exact',
            $this->cs('not_detected'),
            $this->context(),
            start: CarbonImmutable::parse('2026-07-01', 'Europe/Helsinki'),
        )->toCalculatedCostArray();
        $facts = CanonicalOfferFacts::fromArray($cost);

        $this->assertCount(1, $cost['offer_terms']);
        $this->assertCount(2, $cost['offer_terms'][0]['components']);
        $this->assertSame('Energiahinta 7,00 c/kWh ja perusmaksu 2 €/kk ensimmäiset 3 kk', $facts['label']);
        $this->assertStringNotContainsString('HOSTILE RAW PHASE LABEL', $facts['label']);
    }

    public function test_11c_short_offer_phase_uses_normal_amount_only_during_that_phase(): void
    {
        $pricing = $this->pricing([
            $this->phase('introductory', ['kind' => 'contract_start', 'value' => null], ['kind' => 'after_months', 'value' => '3'], [
                $this->component('energy_general', 6.0),
                $this->component('monthly_fee', 2.0, 'eur_per_month', 'introductory', 4.0),
            ]),
            $this->phase('normal', ['kind' => 'after_months', 'value' => '3'], ['kind' => 'none', 'value' => null], [
                $this->component('energy_general', 6.0, 'cents_per_kwh', 'normal'),
                $this->component('monthly_fee', 4.0, 'eur_per_month', 'normal'),
            ]),
        ]);

        $outcome = $this->evaluate(
            $pricing,
            'exact',
            $this->cs('not_detected'),
            $this->context(),
            start: CarbonImmutable::parse('2026-07-01', 'Europe/Helsinki'),
        );
        $cost = $outcome->toCalculatedCostArray();

        $this->assertEqualsWithDelta(342.0, $outcome->totalCost, 0.01);
        $this->assertEqualsWithDelta(348.0, $outcome->baseTotalCost, 0.01);
        $this->assertEqualsWithDelta(6.0, $outcome->discountSavingsTotal(), 0.01);
        $this->assertEqualsWithDelta([2.0, 2.0, 2.0], array_slice($cost['monthly_discount_savings'], 0, 3), 0.001);
        $this->assertSame(array_fill(0, 9, 0.0), array_slice($cost['monthly_discount_savings'], 3));
    }

    public function test_11d_hybrid_reports_discount_only_for_billed_base_components(): void
    {
        $pricing = $this->pricing(
            [$this->phase('current_structured', ['kind' => 'contract_start', 'value' => null], ['kind' => 'none', 'value' => null], [
                $this->component('energy_general', 9.0),
                $this->component('monthly_fee', 2.0, 'eur_per_month', 'introductory', 4.0),
                $this->component('consumption_effect', null, 'unknown', 'unknown'),
            ])],
            [],
            ['present' => true, 'applies_to' => 'base_contract', 'typical_min_cents_per_kwh' => -1.5, 'typical_max_cents_per_kwh' => 1.5],
        );

        $outcome = $this->evaluate($pricing, 'unsupported', $this->cs('uncertain', ['unsupported_consumption_effect']), $this->context('Hybrid'));

        $this->assertSame(ContractComparability::BaseOnlyHybrid, $outcome->comparability);
        $this->assertEqualsWithDelta(474.0, $outcome->totalCost, 0.01);
        $this->assertEqualsWithDelta(498.0, $outcome->baseTotalCost, 0.01);
        $this->assertEqualsWithDelta(24.0, $outcome->discountSavingsTotal(), 0.01);
        $this->assertContains('excludes_consumption_effect', $outcome->assumptions);
    }

    public function test_11da_held_forward_hybrid_keeps_the_typed_first_month_base_offer(): void
    {
        $pricing = $this->pricing(
            [
                $this->phase('introductory', ['kind' => 'contract_start', 'value' => null], ['kind' => 'after_months', 'value' => '1'], [
                    $this->component('energy_general', 11.26),
                    $this->component('monthly_fee', 0.0, 'eur_per_month', 'introductory', 5.9),
                    $this->component('consumption_effect', null, 'unknown', 'unknown'),
                ]),
                $this->phase('continuation', ['kind' => 'after_months', 'value' => '1'], ['kind' => 'after_months', 'value' => '6'], [
                    $this->component('energy_general', 11.26),
                    $this->component('monthly_fee', 5.9, 'eur_per_month'),
                    $this->component('consumption_effect', null, 'unknown', 'unknown'),
                ]),
            ],
            [],
            ['present' => true, 'applies_to' => 'base_contract', 'typical_min_cents_per_kwh' => -1.5, 'typical_max_cents_per_kwh' => 1.5],
        );

        $cost = $this->evaluate(
            $pricing,
            'unsupported',
            $this->cs('uncertain', ['unsupported_consumption_effect']),
            $this->context('Hybrid', 'FixedTerm', 'Fixed6'),
            start: CarbonImmutable::parse('2026-07-01', 'Europe/Helsinki'),
        )->toCalculatedCostArray();
        $facts = CanonicalOfferFacts::fromArray($cost);

        $this->assertSame(ContractComparability::BaseOnlyHybrid->value, $cost['comparability']);
        $this->assertSame(EstimateMethod::HybridBaseOnly->value, $cost['estimate_method']);
        $this->assertSame(6, $cost['term_months']);
        $this->assertSame(6, $cost['contract_term']['months']);
        $this->assertEqualsWithDelta(5.9, $cost['contract_term']['discount_savings_total'], 0.001);
        $this->assertEqualsWithDelta(11.8, $cost['discount_savings_total'], 0.001);
        $this->assertCount(1, $cost['offer_terms']);
        $this->assertSame('monthly_fee', $cost['offer_terms'][0]['components'][0]['component_type']);
        $this->assertSame('Perusmaksu 0 €/kk ensimmäisen kuukauden', $facts['label']);
        $this->assertSame('6 kuukauden sopimuskaudella', $facts['basis_label']);
        $this->assertEqualsWithDelta(5.9, $facts['benefit_eur'], 0.001);
        $this->assertContains('excludes_consumption_effect', $cost['assumptions']);
        $this->assertContains('term_price_annualized', $cost['assumptions']);
    }

    public function test_11daa_fully_covered_short_hybrid_keeps_real_term_offer_totals(): void
    {
        $pricing = $this->pricing(
            [$this->phase('introductory', ['kind' => 'contract_start', 'value' => null], ['kind' => 'none', 'value' => null], [
                $this->component('energy_general', 9.0),
                $this->component('monthly_fee', 2.0, 'eur_per_month', 'introductory', 4.0),
                $this->component('consumption_effect', null, 'unknown', 'unknown'),
            ])],
            [],
            ['present' => true, 'applies_to' => 'base_contract', 'typical_min_cents_per_kwh' => -1.5, 'typical_max_cents_per_kwh' => 1.5],
        );

        $cost = $this->evaluate(
            $pricing,
            'unsupported',
            $this->cs('uncertain', ['unsupported_consumption_effect']),
            $this->context('Hybrid', 'FixedTerm', 'Fixed6'),
            start: CarbonImmutable::parse('2026-07-01', 'Europe/Helsinki'),
        )->toCalculatedCostArray();

        $this->assertSame(ContractComparability::BaseOnlyHybrid->value, $cost['comparability']);
        $this->assertSame(EstimateMethod::HybridBaseOnly->value, $cost['estimate_method']);
        $this->assertSame(6, $cost['term_months']);
        $this->assertEqualsWithDelta(12.0, $cost['contract_term']['discount_savings_total'], 0.001);
        $this->assertEqualsWithDelta(24.0, $cost['discount_savings_total'], 0.001);
        $this->assertContains('excludes_consumption_effect', $cost['assumptions']);
        $this->assertContains('term_price_annualized', $cost['assumptions']);
    }

    public function test_11dab_twelve_and_twenty_four_month_hybrids_keep_the_twelve_month_offer_basis(): void
    {
        $pricing = $this->pricing(
            [$this->phase('introductory', ['kind' => 'contract_start', 'value' => null], ['kind' => 'after_months', 'value' => '12'], [
                $this->component('energy_general', 9.0),
                $this->component('monthly_fee', 2.0, 'eur_per_month', 'introductory', 4.0),
                $this->component('consumption_effect', null, 'unknown', 'unknown'),
            ])],
            [],
            ['present' => true, 'applies_to' => 'base_contract', 'typical_min_cents_per_kwh' => -1.5, 'typical_max_cents_per_kwh' => 1.5],
        );

        foreach (['Fixed12', 'Fixed24'] as $fixedTimeRange) {
            $cost = $this->evaluate(
                $pricing,
                'unsupported',
                $this->cs('uncertain', ['unsupported_consumption_effect']),
                $this->context('Hybrid', 'FixedTerm', $fixedTimeRange),
                start: CarbonImmutable::parse('2026-07-01', 'Europe/Helsinki'),
            )->toCalculatedCostArray();
            $facts = CanonicalOfferFacts::fromArray($cost);

            $this->assertNull($cost['term_months']);
            $this->assertNull($cost['contract_term']);
            $this->assertSame('12 kuukauden vertailussa', $facts['basis_label']);
            $this->assertNotContains('term_price_annualized', $cost['assumptions']);
        }
    }

    public function test_11db_phase_only_spot_offer_compares_introductory_slices_with_the_typed_normal_phase(): void
    {
        $pricing = $this->pricing([
            $this->phase('introductory', ['kind' => 'contract_start', 'value' => null], ['kind' => 'after_months', 'value' => '1'], [
                $this->component('spot_margin', 0.38),
                $this->component('monthly_fee', 0.0, 'eur_per_month'),
            ]),
            $this->phase('introductory', ['kind' => 'after_months', 'value' => '1'], ['kind' => 'after_months', 'value' => '6'], [
                $this->component('spot_margin', 0.38),
                $this->component('monthly_fee', 2.99, 'eur_per_month'),
            ]),
            $this->phase('normal', ['kind' => 'after_months', 'value' => '6'], ['kind' => 'none', 'value' => null], [
                $this->component('spot_margin', 0.59),
                $this->component('monthly_fee', 4.99, 'eur_per_month'),
            ]),
        ]);

        $cost = $this->evaluate(
            $pricing,
            'estimate_required',
            $this->cs('not_detected'),
            $this->context('Spot'),
            new SpotAssumptions(7.0, 5.0),
            CarbonImmutable::parse('2026-07-01', 'Europe/Helsinki'),
        )->toCalculatedCostArray();
        $facts = CanonicalOfferFacts::fromArray($cost);

        $this->assertCount(2, $cost['offer_terms']);
        $this->assertStringContainsString('Marginaali 0,38 c/kWh ja perusmaksu 0 €/kk ensimmäisen kuukauden', $facts['label']);
        $this->assertStringContainsString('Marginaali 0,38 c/kWh ja perusmaksu 2,99 €/kk kuukaudet 2–6', $facts['label']);
    }

    public function test_11dc_recurring_market_price_change_does_not_become_a_phase_only_offer(): void
    {
        $pricing = $this->pricing(
            [
                $this->phase('introductory', ['kind' => 'contract_start', 'value' => null], ['kind' => 'after_months', 'value' => '1'], [
                    $this->component('energy_general', 5.0),
                    $this->component('monthly_fee', 3.0, 'eur_per_month'),
                ]),
                $this->phase('normal', ['kind' => 'after_months', 'value' => '1'], ['kind' => 'none', 'value' => null], [
                    $this->component('energy_general', 8.0),
                    $this->component('monthly_fee', 3.0, 'eur_per_month'),
                ]),
            ],
            ['present' => true, 'cadence' => 'monthly', 'future_price_known' => false],
        );

        $cost = $this->evaluate(
            $pricing,
            'estimate_required',
            $this->cs('not_detected', ['recurring_reset_requires_estimate']),
            $this->context(),
            start: CarbonImmutable::parse('2026-07-01', 'Europe/Helsinki'),
        )->toCalculatedCostArray();

        $this->assertGreaterThan(0, $cost['discount_savings_total']);
        $this->assertSame([], $cost['offer_terms']);
        $this->assertNull(CanonicalOfferFacts::fromArray($cost));
    }

    public function test_11e_six_month_term_keeps_one_month_offer_benefit_before_annualization(): void
    {
        $pricing = $this->pricing([
            $this->phase('introductory', ['kind' => 'contract_start', 'value' => null], ['kind' => 'after_months', 'value' => '1'], [
                $this->component('energy_general', 5.0),
                $this->component('monthly_fee', 0.0, 'eur_per_month', 'introductory', 5.9),
            ]),
            $this->phase('normal', ['kind' => 'after_months', 'value' => '1'], ['kind' => 'after_months', 'value' => '6'], [
                $this->component('energy_general', 5.0, 'cents_per_kwh', 'normal'),
                $this->component('monthly_fee', 5.9, 'eur_per_month', 'normal'),
            ]),
        ]);

        $outcome = $this->evaluate(
            $pricing,
            'incomplete',
            $this->cs('detected', ['future_price_omitted', 'future_price_unknown']),
            $this->context('FixedPrice', 'FixedTerm', 'Fixed6'),
            start: CarbonImmutable::parse('2026-07-01', 'Europe/Helsinki'),
        );
        $cost = $outcome->toCalculatedCostArray();
        $term = $cost['contract_term'];

        $this->assertSame(ContractComparability::TermPriceOnly, $outcome->comparability);
        $this->assertSame(6, $term['months']);
        $this->assertEqualsWithDelta(154.5, $term['total_cost'], 0.01);
        $this->assertEqualsWithDelta(160.4, $term['base_total_cost'], 0.01);
        $this->assertEqualsWithDelta(5.9, $term['discount_savings_total'], 0.01);
        $this->assertEqualsWithDelta(5.9, $term['base_total_cost'] - $term['total_cost'], 0.001);
        $this->assertEqualsWithDelta(309.0, $cost['total_cost'], 0.01);
        $this->assertEqualsWithDelta(320.8, $cost['base_total_cost'], 0.01);
        $this->assertEqualsWithDelta(11.8, $cost['discount_savings_total'], 0.01);
        $this->assertEqualsWithDelta($cost['discount_savings_total'] / 2, $term['discount_savings_total'], 0.001);
    }

    public function test_11eb_six_month_offer_covering_the_term_keeps_unannualized_benefit(): void
    {
        $pricing = $this->pricing([
            $this->phase('introductory', ['kind' => 'contract_start', 'value' => null], ['kind' => 'after_months', 'value' => '6'], [
                $this->component('energy_general', 5.0),
                $this->component('monthly_fee', 0.0, 'eur_per_month', 'introductory', 5.9),
            ]),
        ]);

        $cost = $this->evaluate(
            $pricing,
            'incomplete',
            $this->cs('detected', ['future_price_omitted', 'future_price_unknown']),
            $this->context('FixedPrice', 'FixedTerm', 'Fixed6'),
            start: CarbonImmutable::parse('2026-07-01', 'Europe/Helsinki'),
        )->toCalculatedCostArray();
        $term = $cost['contract_term'];

        $this->assertEqualsWithDelta(125.0, $term['total_cost'], 0.01);
        $this->assertEqualsWithDelta(160.4, $term['base_total_cost'], 0.01);
        $this->assertEqualsWithDelta(35.4, $term['discount_savings_total'], 0.01);
        $this->assertEqualsWithDelta($term['base_total_cost'] - $term['total_cost'], $term['discount_savings_total'], 0.001);
        $this->assertEqualsWithDelta(70.8, $cost['discount_savings_total'], 0.01);
        $this->assertEqualsWithDelta($cost['discount_savings_total'] / 2, $term['discount_savings_total'], 0.001);
    }

    public function test_11ea_hybrid_costs_discounted_and_normal_base_phases_on_the_timeline(): void
    {
        $pricing = $this->pricing(
            [
                $this->phase('introductory', ['kind' => 'contract_start', 'value' => null], ['kind' => 'after_months', 'value' => '6'], [
                    $this->component('energy_general', 6.11),
                    $this->component('monthly_fee', 4.5, 'eur_per_month', 'introductory', 9.0),
                    $this->component('consumption_effect', null, 'unknown', 'unknown'),
                ]),
                $this->phase('normal', ['kind' => 'after_months', 'value' => '6'], ['kind' => 'none', 'value' => null], [
                    $this->component('energy_general', 6.11, 'cents_per_kwh', 'normal'),
                    $this->component('monthly_fee', 9.0, 'eur_per_month', 'normal'),
                    $this->component('consumption_effect', null, 'unknown', 'unknown'),
                ]),
            ],
            [],
            ['present' => true, 'applies_to' => 'base_contract', 'typical_min_cents_per_kwh' => -1.5, 'typical_max_cents_per_kwh' => 1.5],
        );

        $outcome = $this->evaluate(
            $pricing,
            'unsupported',
            $this->cs('uncertain', ['unsupported_consumption_effect']),
            $this->context('Hybrid', 'FixedTerm', 'Fixed24'),
            start: CarbonImmutable::parse('2026-07-01', 'Europe/Helsinki'),
        );

        $this->assertSame(ContractComparability::BaseOnlyHybrid, $outcome->comparability);
        $this->assertEqualsWithDelta(386.50, $outcome->totalCost, 0.01);
        $this->assertEqualsWithDelta(413.50, $outcome->baseTotalCost, 0.01);
        $this->assertEqualsWithDelta(27.0, $outcome->discountSavingsTotal(), 0.01);
        $this->assertEqualsWithDelta($outcome->baseTotalCost - $outcome->totalCost, $outcome->discountSavingsTotal(), 0.001);
        $this->assertEqualsWithDelta(27.0, array_sum($outcome->monthlyDiscountSavings), 0.001);
        $this->assertNotNull($outcome->consumptionEffect);
        $this->assertContains('excludes_consumption_effect', $outcome->assumptions);
    }

    public function test_11f_spot_assumptions_do_not_create_offer_savings(): void
    {
        $pricing = $this->pricing([
            $this->phase('current_structured', ['kind' => 'contract_start', 'value' => null], ['kind' => 'none', 'value' => null], [
                $this->component('spot_margin', 0.5),
                $this->component('monthly_fee', 3.6, 'eur_per_month'),
            ]),
        ]);

        $outcome = $this->evaluate($pricing, 'estimate_required', $this->cs('not_detected'), $this->context('Spot'), new SpotAssumptions(8.0, 5.0));

        $this->assertEqualsWithDelta($outcome->totalCost, $outcome->baseTotalCost, 0.001);
        $this->assertSame(0.0, $outcome->discountSavingsTotal());
        $this->assertSame(array_fill(0, 12, 0.0), $outcome->toCalculatedCostArray()['monthly_discount_savings']);
    }

    public function test_11g_spot_normal_amount_keeps_the_actual_spot_mechanism(): void
    {
        $pricing = $this->pricing([
            $this->phase('current_structured', ['kind' => 'contract_start', 'value' => null], ['kind' => 'none', 'value' => null], [
                // The actual amount is below the Spot safety ceiling and is therefore a margin.
                // The higher normal amount must stay a margin too, not switch to fixed energy.
                $this->component('energy_general', 0.5, 'cents_per_kwh', 'introductory', 3.0),
                $this->component('monthly_fee', 3.6, 'eur_per_month'),
            ]),
        ]);

        $outcome = $this->evaluate($pricing, 'estimate_required', $this->cs('not_detected'), $this->context('Spot'), new SpotAssumptions(8.0, 5.0));

        $this->assertEqualsWithDelta(0.5, $outcome->spotPriceMargin, 0.001);
        $this->assertEqualsWithDelta(125.0, $outcome->discountSavingsTotal(), 0.01);
        $this->assertEqualsWithDelta(125.0, $outcome->baseTotalCost - $outcome->totalCost, 0.01);
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

    public function test_15b_other_cadence_with_unknown_boundaries_is_a_listed_estimate(): void
    {
        // Lumme Energia Perussähkö shape: the current price and fee are complete, but the
        // seller gives only a 2-4 times per year reset cadence and no exact phase boundaries.
        $pricing = $this->pricing(
            [$this->phase('recurring_period', ['kind' => 'unknown', 'value' => null], ['kind' => 'unknown', 'value' => null], [
                $this->component('energy_general', 12.9),
                $this->component('monthly_fee', 5.56, 'eur_per_month'),
            ])],
            ['present' => true, 'cadence' => 'other'],
        );

        $outcome = $this->evaluate($pricing, 'estimate_required', $this->cs('not_detected', ['recurring_reset_requires_estimate']), $this->context());

        $this->assertSame(ContractComparability::ComparableEstimate, $outcome->comparability);
        $this->assertTrue($outcome->isListed());
        $this->assertTrue($outcome->isEstimate());
        $this->assertEqualsWithDelta(5000 * 12.9 / 100 + 5.56 * 12, $outcome->totalCost, 0.5);
    }

    public function test_15c_incomplete_other_cadence_remains_excluded(): void
    {
        $pricing = $this->pricing(
            [$this->phase('recurring_period', ['kind' => 'unknown', 'value' => null], ['kind' => 'unknown', 'value' => null], [
                $this->component('energy_general', 12.9),
                $this->component('monthly_fee', 5.56, 'eur_per_month'),
            ])],
            ['present' => true, 'cadence' => 'other'],
        );

        $outcome = $this->evaluate($pricing, 'incomplete', $this->cs('not_detected', ['component_mismatch']), $this->context());

        $this->assertSame(ContractComparability::ExcludedIncomplete, $outcome->comparability);
        $this->assertFalse($outcome->isListed());
        $this->assertNull($outcome->totalCost);
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
