<?php

namespace Tests\Unit\CanonicalPricing;

use App\Services\CanonicalPricing\CanonicalContractPriceCalculator;
use App\Services\CanonicalPricing\CanonicalPricingParser;
use App\Services\CanonicalPricing\DTO\ContractContext;
use App\Services\CanonicalPricing\DTO\SpotAssumptions;
use App\Services\CanonicalPricing\Enums\ContractComparability;
use App\Services\CanonicalPricing\Enums\EstimateMethod;
use App\Services\CanonicalPricing\MarketReset\DTO\ResetEstimatorSettings;
use App\Services\CanonicalPricing\MarketReset\MarketReferenceCurveProvider;
use App\Services\CanonicalPricing\MarketReset\MarketResetPriceEstimator;
use App\Services\DTO\EnergyUsage;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\TestCase;

/**
 * Regression tests for the market-reset annualised price: the shape-only forward-curve shift
 * that replaces holding one seasonal period price flat for twelve months.
 *
 * The curve is faked so the arithmetic is exact. Synthetic flat curves are used deliberately:
 * with a flat usage profile and one offset per tail month the expected total is computable by
 * hand, which pins both the size of the correction and the fact that the current period is
 * never repriced.
 */
class MarketResetForwardShiftTest extends TestCase
{
    private CanonicalPricingParser $parser;

    private EnergyUsage $usage;

    protected function setUp(): void
    {
        parent::setUp();
        $this->parser = new CanonicalPricingParser;
        $this->usage = new EnergyUsage(total: 5000, basicLiving: 5000);
    }

    // ---------------------------------------------------------------- helpers

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

    private function resetPricing(float $energyPrice, string $cadence, array $starts = ['kind' => 'unknown', 'value' => null], array $ends = ['kind' => 'unknown', 'value' => null], ?string $periodEnd = null, ?string $periodStart = null): array
    {
        return [
            'phases' => [[
                'label' => 'recurring_period',
                'phase_kind' => 'recurring_period',
                'starts' => $starts,
                'ends' => $ends,
                'components' => [$this->component('energy_general', $energyPrice)],
                'evidence' => [],
            ]],
            'recurring_schedule' => [
                'present' => true,
                'cadence' => $cadence,
                'current_period_start' => $periodStart,
                'current_period_end' => $periodEnd,
                'future_price_known' => false,
                'description' => null,
                'evidence' => [],
            ],
            'consumption_effect' => [
                'present' => false, 'applies_to' => 'unknown', 'cadence' => 'none',
                'expected_cents_per_kwh' => null, 'typical_min_cents_per_kwh' => null, 'typical_max_cents_per_kwh' => null,
                'hard_min_cents_per_kwh' => null, 'hard_max_cents_per_kwh' => null, 'uncapped' => null,
                'description' => null, 'evidence' => [],
            ],
        ];
    }

    private function calculator(?MarketResetPriceEstimator $estimator): CanonicalContractPriceCalculator
    {
        return new CanonicalContractPriceCalculator(resetEstimator: $estimator);
    }

    private function estimator(FakeMarketCurve $curve, array $settingOverrides = []): MarketResetPriceEstimator
    {
        return new MarketResetPriceEstimator($curve, new ResetEstimatorSettings(
            enabled: $settingOverrides['enabled'] ?? true,
            beta: $settingOverrides['beta'] ?? 1.0,
            maxCurveAgeDays: $settingOverrides['maxCurveAgeDays'] ?? 14,
            seasonalIndexEnabled: $settingOverrides['seasonalIndexEnabled'] ?? true,
            absurdityFloorCentsPerKwh: $settingOverrides['absurdityFloorCentsPerKwh'] ?? 0.0,
            absurdityCeilingCentsPerKwh: $settingOverrides['absurdityCeilingCentsPerKwh'] ?? 60.0,
        ));
    }

    private function evaluate(array $pricing, ?MarketResetPriceEstimator $estimator, string $start = '2026-07-01', string $status = 'estimate_required', string $model = 'FixedPrice')
    {
        $data = $this->parser->parse(
            $pricing,
            ['status' => $status, 'missing_facts' => [], 'required_assumptions' => []],
            ['misleading_first_12_months' => 'not_detected', 'structured_pricing_status' => 'complete', 'issue_codes' => ['recurring_reset_requires_estimate']],
        );

        return $this->calculator($estimator)->calculate(
            $data,
            new ContractContext($model, 'OpenEnded', 'General', null, 'Household'),
            $this->usage,
            new SpotAssumptions(null, null),
            CarbonImmutable::parse($start, 'Europe/Helsinki'),
        );
    }

    // ---------------------------------------------------------------- tests

    public function test_monthly_reset_in_summer_raises_the_annual_estimate(): void
    {
        // July anchor 7,00 c/kWh; reference (July month contract) 5,00; every later delivery
        // month 9,00. Offset +4,00 for the eleven tail months, current period untouched.
        // Expected annual equivalent = 7 + 4 * 11/12 = 10,6667 c/kWh -> 533,33 EUR at 5000 kWh.
        $curve = new FakeMarketCurve(
            reference: ['month' => 5.0],
            forward: $this->flatForward(9.0),
        );

        $outcome = $this->evaluate($this->resetPricing(7.0, 'monthly'), $this->estimator($curve));

        $this->assertSame(ContractComparability::ComparableEstimate, $outcome->comparability);
        $this->assertSame(EstimateMethod::RecurringForwardCurveShift, $outcome->estimateMethod);
        $this->assertEqualsWithDelta(533.33, $outcome->totalCost, 0.05);
        $this->assertGreaterThan(350.0, $outcome->totalCost, 'hold-flat would have been 350 EUR');
        $this->assertEqualsWithDelta(10.6667, $outcome->resetEstimate['annual_equivalent_energy_price'], 0.001);
        $this->assertEqualsWithDelta(7.0, $outcome->resetEstimate['current_period_energy_price'], 0.0001);
        $this->assertSame('month', $outcome->resetEstimate['reference_kind']);
        $this->assertSame('2026-07', $outcome->resetEstimate['anchor_period']);
        $this->assertSame('2026-08', $outcome->resetEstimate['tail_starts']);
    }

    public function test_monthly_reset_in_winter_lowers_the_annual_estimate(): void
    {
        // A January anchor of 12,00 against a January reference of 12,00 and a 6,00 forward for
        // every later month: offset -6,00 on eleven months -> 12 - 6 * 11/12 = 6,50 c/kWh.
        $curve = new FakeMarketCurve(
            tradeDate: '2026-12-31',
            reference: ['month' => 12.0],
            forward: $this->flatForward(6.0),
        );

        $outcome = $this->evaluate($this->resetPricing(12.0, 'monthly'), $this->estimator($curve), start: '2027-01-01');

        $this->assertSame(EstimateMethod::RecurringForwardCurveShift, $outcome->estimateMethod);
        $this->assertEqualsWithDelta(325.0, $outcome->totalCost, 0.05);
        $this->assertLessThan(600.0, $outcome->totalCost, 'hold-flat would have been 600 EUR');
        $this->assertEqualsWithDelta(6.5, $outcome->resetEstimate['annual_equivalent_energy_price'], 0.001);
    }

    public function test_quarterly_reset_uses_the_direct_quarter_contract_when_published(): void
    {
        // Quarterly cadence keeps the whole current calendar quarter exact, so the tail is the
        // nine months from October: 8 + 4 * 9/12 = 11,00 c/kWh.
        $curve = new FakeMarketCurve(
            reference: ['quarter' => 5.0, 'quarter_month_average' => 5.9],
            forward: $this->flatForward(9.0),
        );

        $outcome = $this->evaluate($this->resetPricing(8.0, 'quarterly'), $this->estimator($curve));

        $this->assertSame('quarter', $outcome->resetEstimate['reference_kind']);
        $this->assertSame('2026-Q3', $outcome->resetEstimate['anchor_period']);
        $this->assertSame('2026-10', $outcome->resetEstimate['tail_starts']);
        $this->assertEqualsWithDelta(11.0, $outcome->resetEstimate['annual_equivalent_energy_price'], 0.001);
        $this->assertEqualsWithDelta(550.0, $outcome->totalCost, 0.05);
    }

    public function test_quarterly_reset_falls_back_to_the_quarter_month_average(): void
    {
        // Once a quarter enters delivery EEX stops publishing that quarter contract, so the
        // day-weighted average of its three month contracts is the only quarter-shaped
        // reference left. This is the normal mid-quarter case, not an edge case.
        $curve = new FakeMarketCurve(
            reference: ['quarter_month_average' => 6.0],
            forward: $this->flatForward(9.0),
        );

        $outcome = $this->evaluate($this->resetPricing(8.0, 'quarterly'), $this->estimator($curve));

        $this->assertSame('quarter_month_average', $outcome->resetEstimate['reference_kind']);
        $this->assertEqualsWithDelta(6.0, $outcome->resetEstimate['reference_price'], 0.001);
        // Offset +3,00 across nine tail months: 8 + 3 * 9/12 = 10,25 c/kWh.
        $this->assertEqualsWithDelta(10.25, $outcome->resetEstimate['annual_equivalent_energy_price'], 0.001);
    }

    public function test_other_cadence_uses_the_quarterly_proxy_and_q3_to_q4_tail_boundary(): void
    {
        $pricing = $this->resetPricing(8.0, 'other');
        $directQuarter = new FakeMarketCurve(
            reference: ['month' => 2.0, 'quarter' => 5.0, 'quarter_month_average' => 6.0],
            forward: $this->flatForward(9.0),
        );

        $direct = $this->evaluate($pricing, $this->estimator($directQuarter));

        $this->assertSame(EstimateMethod::RecurringForwardCurveShift, $direct->estimateMethod);
        $this->assertSame('quarter', $direct->resetEstimate['reference_kind']);
        $this->assertSame('2026-Q3', $direct->resetEstimate['anchor_period']);
        $this->assertSame('2026-10', $direct->resetEstimate['tail_starts']);
        $this->assertEqualsWithDelta(11.0, $direct->resetEstimate['annual_equivalent_energy_price'], 0.001);

        $quarterAverage = new FakeMarketCurve(
            reference: ['month' => 2.0, 'quarter_month_average' => 6.0],
            forward: $this->flatForward(9.0),
        );
        $fallback = $this->evaluate($pricing, $this->estimator($quarterAverage));

        $this->assertSame('quarter_month_average', $fallback->resetEstimate['reference_kind']);
        $this->assertEqualsWithDelta(10.25, $fallback->resetEstimate['annual_equivalent_energy_price'], 0.001);
    }

    public function test_missing_curve_falls_back_to_the_spot_seasonal_index(): void
    {
        // No curve at all, but a usable multi-year seasonal index: July index 0,5 against 1,0
        // for every other month doubles the tail price. 6 + 6 * 11/12 = 11,50 c/kWh.
        $index = array_fill(1, 12, 1.0);
        $index[7] = 0.5;

        $curve = new FakeMarketCurve(tradeDate: null, seasonalIndex: $index);

        $outcome = $this->evaluate($this->resetPricing(6.0, 'monthly'), $this->estimator($curve));

        $this->assertSame(EstimateMethod::RecurringSpotSeasonalIndex, $outcome->estimateMethod);
        $this->assertSame('spot_seasonal_index', $outcome->resetEstimate['reference_kind']);
        $this->assertFalse($outcome->resetEstimate['higher_confidence']);
        $this->assertContains('lower_confidence_seasonal_index', $outcome->resetEstimate['flags']);
        $this->assertEqualsWithDelta(11.5, $outcome->resetEstimate['annual_equivalent_energy_price'], 0.001);
    }

    public function test_a_stale_curve_is_rejected_and_drops_to_the_seasonal_index(): void
    {
        $index = array_fill(1, 12, 1.0);
        $index[7] = 0.5;

        $curve = new FakeMarketCurve(
            tradeDate: '2026-05-22',
            reference: ['month' => 5.0],
            forward: $this->flatForward(9.0),
            seasonalIndex: $index,
        );

        $outcome = $this->evaluate($this->resetPricing(6.0, 'monthly'), $this->estimator($curve));

        $this->assertSame(EstimateMethod::RecurringSpotSeasonalIndex, $outcome->estimateMethod);
    }

    public function test_no_market_data_holds_flat_and_is_identical_to_the_pre_shift_behaviour(): void
    {
        $curve = new FakeMarketCurve(tradeDate: null, seasonalIndex: null);
        $pricing = $this->resetPricing(7.0, 'monthly');

        $withEstimator = $this->evaluate($pricing, $this->estimator($curve));
        $withoutEstimator = $this->evaluate($pricing, null);

        $this->assertSame(EstimateMethod::HoldCurrentRecurringPrice, $withEstimator->estimateMethod);
        $this->assertNull($withEstimator->resetEstimate);
        $this->assertSame($withoutEstimator->totalCost, $withEstimator->totalCost);
        $this->assertEqualsWithDelta(350.0, $withEstimator->totalCost, 0.05);
    }

    public function test_flag_off_is_byte_identical_to_the_pre_shift_behaviour(): void
    {
        $curve = new FakeMarketCurve(
            reference: ['month' => 5.0],
            forward: $this->flatForward(9.0),
        );
        $pricing = $this->resetPricing(7.0, 'monthly');

        $off = $this->evaluate($pricing, $this->estimator($curve, ['enabled' => false]));
        $none = $this->evaluate($pricing, null);

        $this->assertSame($none->totalCost, $off->totalCost);
        $this->assertSame($none->baseTotalCost, $off->baseTotalCost);
        $this->assertSame($none->structuredOnlyTotal, $off->structuredOnlyTotal);
        $this->assertSame($none->monthlyCosts, $off->monthlyCosts);
        $this->assertSame($none->estimateMethod, $off->estimateMethod);
        $this->assertSame($none->assumptions, $off->assumptions);
        $this->assertNull($off->resetEstimate);
        $this->assertSame(0, $curve->calls, 'a disabled estimator must not touch market data at all');
    }

    public function test_an_adjusted_energy_price_never_goes_below_zero(): void
    {
        // A 12,00 reference against a 0,00 forward would imply -7,00 c/kWh on a 5,00 anchor.
        // The floor keeps each tail month at 0,00 instead: (5 * 1 + 0 * 11) / 12 = 0,4167.
        $curve = new FakeMarketCurve(
            reference: ['month' => 12.0],
            forward: $this->flatForward(0.0),
            fixedTermMedian: null,
        );

        $outcome = $this->evaluate($this->resetPricing(5.0, 'monthly'), $this->estimator($curve));

        $this->assertSame(EstimateMethod::RecurringForwardCurveShift, $outcome->estimateMethod);
        $this->assertEqualsWithDelta(0.41667, $outcome->resetEstimate['annual_equivalent_energy_price'], 0.0005);
        $this->assertEqualsWithDelta(20.83, $outcome->totalCost, 0.05);
        $this->assertGreaterThanOrEqual(0.0, $outcome->totalCost);
        foreach ($outcome->monthlyCosts as $monthCost) {
            $this->assertGreaterThanOrEqual(0.0, $monthCost);
        }
    }

    public function test_an_absurd_annual_equivalent_falls_back_and_is_flagged(): void
    {
        // A 300 c/kWh forward against a 5 c/kWh reference implies about 277 c/kWh for the year.
        // That is a broken reference or a bad print, not a price. No seasonal index is available
        // either, so the estimate must degrade to hold flat rather than publish it.
        $curve = new FakeMarketCurve(
            reference: ['month' => 5.0],
            forward: $this->flatForward(300.0),
            seasonalIndex: null,
        );

        $outcome = $this->evaluate($this->resetPricing(7.0, 'monthly'), $this->estimator($curve));

        $this->assertSame(EstimateMethod::HoldCurrentRecurringPrice, $outcome->estimateMethod);
        $this->assertNull($outcome->resetEstimate);
        $this->assertEqualsWithDelta(350.0, $outcome->totalCost, 0.05);
    }

    public function test_the_guard_does_not_suppress_a_reset_that_annualises_above_the_fixed_market(): void
    {
        // Helen's shape: 7,59 c/kWh set against a 4,03 c/kWh forward for the same month implies a
        // ~3,6 c/kWh spread, which annualises well above a 10,47 c/kWh fully-fixed median. That is
        // a true and useful finding about an incumbent's near-default product, so the guard must
        // NOT band it away. Re-introducing a market-relative band would break this test, which is
        // the point of the test.
        $curve = new FakeMarketCurve(
            reference: ['month' => 4.03],
            forward: $this->flatForward(9.0),
            fixedTermMedian: 10.47,
        );

        $outcome = $this->evaluate($this->resetPricing(7.59, 'monthly'), $this->estimator($curve));

        $this->assertSame(EstimateMethod::RecurringForwardCurveShift, $outcome->estimateMethod);
        // 7,59 + (9,00 - 4,03) * 11/12 = 12,145 c/kWh, i.e. above the fixed market. Kept.
        $this->assertEqualsWithDelta(12.145, $outcome->resetEstimate['annual_equivalent_energy_price'], 0.005);
        $this->assertGreaterThan(10.47, $outcome->resetEstimate['annual_equivalent_energy_price']);
    }

    public function test_the_reference_uses_the_pricing_vintage_and_the_forward_months_use_today(): void
    {
        // Window starts mid-period (25 July) with a monthly cadence, so the period started on
        // 1 July and its pricing vintage is the last trade date before that. The reference must be
        // read there (4,03 c/kWh, the July contract before it converged), NOT at today's vintage
        // where the same contract has fallen to 2,45. Reading it at today's vintage would inflate
        // the implied spread by 1,58 c/kWh, about 79 EUR/yr at 5000 kWh, as a pure artifact.
        $curve = new FakeMarketCurve(
            tradeDate: '2026-07-24',
            reference: ['month' => 2.45],
            forward: $this->flatForward(9.0),
            today: '2026-07-25',
            pricingVintageReference: ['month' => 4.03],
            pricingVintageTradeDate: '2026-06-30',
        );

        $outcome = $this->evaluate($this->resetPricing(7.59, 'monthly'), $this->estimator($curve), start: '2026-07-25');

        $this->assertSame(EstimateMethod::RecurringForwardCurveShift, $outcome->estimateMethod);
        $this->assertEqualsWithDelta(4.03, $outcome->resetEstimate['reference_price'], 0.001);
        $this->assertSame('2026-06-30', $outcome->resetEstimate['reference_trade_date']);
        $this->assertSame('2026-07-24', $outcome->resetEstimate['curve_trade_date']);
        $this->assertSame(['2026-07-01'], $curve->referenceAsOfDates);
        $this->assertNotContains('reference_vintage_fallback_today', $outcome->resetEstimate['flags']);
    }

    public function test_the_pricing_vintage_makes_the_estimate_lower_than_todays_vintage_would(): void
    {
        $args = [
            'tradeDate' => '2026-07-24',
            'reference' => ['month' => 2.45],
            'forward' => $this->flatForward(9.0),
            'today' => '2026-07-25',
        ];

        $todaysVintage = new FakeMarketCurve(...$args);
        $pricingVintage = new FakeMarketCurve(...$args, pricingVintageReference: ['month' => 4.03], pricingVintageTradeDate: '2026-06-30');

        $wrong = $this->evaluate($this->resetPricing(7.59, 'monthly'), $this->estimator($todaysVintage), start: '2026-07-25');
        $right = $this->evaluate($this->resetPricing(7.59, 'monthly'), $this->estimator($pricingVintage), start: '2026-07-25');

        $convergenceArtifact = 4.03 - 2.45;
        $this->assertGreaterThan($right->totalCost, $wrong->totalCost);
        $this->assertEqualsWithDelta(
            $convergenceArtifact,
            $wrong->resetEstimate['annual_equivalent_energy_price'] - $right->resetEstimate['annual_equivalent_energy_price'],
            0.15,
            'the whole difference between the two vintages is the front-month convergence artifact',
        );
    }

    public function test_a_period_that_began_before_the_curve_history_falls_back_to_todays_vintage(): void
    {
        // A quarterly period that started before 2026-04-08 has no pricing vintage and never will:
        // EEX serves an approximately 45-day rolling window. Fall back to today's vintage and flag
        // it, rather than dropping to the much weaker spot seasonal index.
        $curve = new FakeMarketCurve(
            tradeDate: '2026-07-24',
            reference: ['quarter_month_average' => 6.0],
            forward: $this->flatForward(9.0),
            today: '2026-07-25',
            pricingVintageReference: ['quarter_month_average' => 4.0],
            hasPricingVintage: false,
        );

        $outcome = $this->evaluate($this->resetPricing(8.0, 'quarterly'), $this->estimator($curve), start: '2026-07-25');

        $this->assertSame(EstimateMethod::RecurringForwardCurveShift, $outcome->estimateMethod);
        $this->assertContains('reference_vintage_fallback_today', $outcome->resetEstimate['flags']);
        $this->assertEqualsWithDelta(6.0, $outcome->resetEstimate['reference_price'], 0.001);
        $this->assertSame(['2026-07-25'], $curve->referenceAsOfDates);
    }

    public function test_a_disclosed_non_calendar_period_start_sets_the_pricing_vintage(): void
    {
        // Some sellers reset off calendar boundaries (observed: 16 April, 21 May, 21 July). When the
        // source discloses such a start inside the anchor period, that date is what the seller
        // priced from, so it is the vintage anchor.
        $curve = new FakeMarketCurve(
            tradeDate: '2026-07-24',
            reference: ['month' => 2.45],
            forward: $this->flatForward(9.0),
            today: '2026-07-25',
            pricingVintageReference: ['month' => 3.0],
        );

        $pricing = $this->resetPricing(7.0, 'monthly', periodStart: '2026-07-21');
        $outcome = $this->evaluate($pricing, $this->estimator($curve), start: '2026-07-25');

        $this->assertSame(['2026-07-21'], $curve->referenceAsOfDates);
        $this->assertEqualsWithDelta(3.0, $outcome->resetEstimate['reference_price'], 0.001);
    }

    public function test_a_disclosed_dated_period_stays_exact_and_only_the_later_tail_shifts(): void
    {
        // The provider discloses the current price through 2026-09-30. Those three months are
        // contractual, so only October onwards is repriced: 8 + 4 * 9/12 = 11,00 c/kWh.
        $curve = new FakeMarketCurve(
            reference: ['month' => 5.0],
            forward: $this->flatForward(9.0),
        );

        $pricing = $this->resetPricing(
            8.0,
            'monthly',
            starts: ['kind' => 'contract_start', 'value' => null],
            ends: ['kind' => 'date', 'value' => '2026-09-30'],
        );

        $outcome = $this->evaluate($pricing, $this->estimator($curve));

        $this->assertSame('2026-10', $outcome->resetEstimate['tail_starts']);
        $this->assertSame('2026-09', $outcome->resetEstimate['anchor_period']);
        $this->assertEqualsWithDelta(11.0, $outcome->resetEstimate['annual_equivalent_energy_price'], 0.001);
    }

    public function test_an_open_ended_phase_is_not_a_credible_reset_boundary(): void
    {
        // The most common shape of the live defect: a quarterly product whose phase claims
        // `ends: none`, so the old calculator saw a fully covered window and held one seasonal
        // price for twelve months without even marking an estimate fill.
        $curve = new FakeMarketCurve(
            reference: ['quarter_month_average' => 6.0],
            forward: $this->flatForward(9.0),
        );

        $pricing = $this->resetPricing(
            8.0,
            'quarterly',
            starts: ['kind' => 'contract_start', 'value' => null],
            ends: ['kind' => 'none', 'value' => null],
        );

        $holdFlat = $this->evaluate($pricing, null);
        $shifted = $this->evaluate($pricing, $this->estimator($curve));

        $this->assertEqualsWithDelta(400.0, $holdFlat->totalCost, 0.05);
        $this->assertSame('2026-10', $shifted->resetEstimate['tail_starts']);
        $this->assertEqualsWithDelta(512.5, $shifted->totalCost, 0.05);
    }

    public function test_spot_contracts_are_never_shifted(): void
    {
        $curve = new FakeMarketCurve(
            reference: ['month' => 5.0],
            forward: $this->flatForward(9.0),
        );

        $pricing = $this->resetPricing(7.0, 'monthly');
        $outcome = $this->evaluate($pricing, $this->estimator($curve), model: 'Spot');

        $this->assertNull($outcome->resetEstimate);
        $this->assertSame(0, $curve->calls);
    }

    public function test_base_and_structured_totals_carry_the_same_shift_so_no_false_discount_appears(): void
    {
        // baseTotalCost drives the card's "Säästö"/"ilman tarjousta" copy. If only the total
        // were shifted, a winter reset would show a fabricated discount.
        $curve = new FakeMarketCurve(
            tradeDate: '2026-12-31',
            reference: ['month' => 12.0],
            forward: $this->flatForward(6.0),
        );

        $pricing = $this->resetPricing(
            12.0,
            'monthly',
            starts: ['kind' => 'contract_start', 'value' => null],
            ends: ['kind' => 'none', 'value' => null],
        );

        $outcome = $this->evaluate($pricing, $this->estimator($curve), start: '2027-01-01');

        $this->assertNotNull($outcome->baseTotalCost);
        $this->assertNotNull($outcome->structuredOnlyTotal);
        $this->assertEqualsWithDelta(325.0, $outcome->totalCost, 0.05);
        $this->assertEqualsWithDelta($outcome->totalCost, $outcome->baseTotalCost, 0.01);
        $this->assertEqualsWithDelta($outcome->totalCost, $outcome->structuredOnlyTotal, 0.01);
        $this->assertSame(0.0, $outcome->discountSavingsTotal());
    }

    public function test_component_savings_keep_the_same_forward_shift_in_both_prices(): void
    {
        $curve = new FakeMarketCurve(
            reference: ['month' => 5.0],
            forward: $this->flatForward(9.0),
        );
        $pricing = $this->resetPricing(
            7.0,
            'monthly',
            starts: ['kind' => 'contract_start', 'value' => null],
            ends: ['kind' => 'none', 'value' => null],
        );
        $pricing['phases'][0]['components'][] = $this->component('monthly_fee', 2.0, 'eur_per_month', 'introductory', 4.0);

        $outcome = $this->evaluate($pricing, $this->estimator($curve));

        $this->assertEqualsWithDelta(557.33, $outcome->totalCost, 0.05);
        $this->assertEqualsWithDelta(581.33, $outcome->baseTotalCost, 0.05);
        $this->assertEqualsWithDelta(24.0, $outcome->discountSavingsTotal(), 0.01);
        $this->assertEqualsWithDelta(24.0, array_sum($outcome->monthlyDiscountSavings), 0.01);
    }

    public function test_beta_scales_the_correction_linearly(): void
    {
        $curve = new FakeMarketCurve(
            reference: ['month' => 5.0],
            forward: $this->flatForward(9.0),
        );

        $full = $this->evaluate($this->resetPricing(7.0, 'monthly'), $this->estimator($curve, ['beta' => 1.0]));
        $half = $this->evaluate($this->resetPricing(7.0, 'monthly'), $this->estimator($curve, ['beta' => 0.5]));

        $this->assertEqualsWithDelta(10.6667, $full->resetEstimate['annual_equivalent_energy_price'], 0.001);
        $this->assertEqualsWithDelta(8.8333, $half->resetEstimate['annual_equivalent_energy_price'], 0.001);
        $this->assertSame(0.5, $half->resetEstimate['beta']);
    }

    /**
     * @return array<string, float> every delivery month at one price
     */
    private function flatForward(float $price): array
    {
        $forward = [];
        $month = CarbonImmutable::parse('2026-01-01');

        for ($offset = 0; $offset < 36; $offset++) {
            $forward[$month->addMonthsNoOverflow($offset)->format('Y-m')] = $price;
        }

        return $forward;
    }
}

/**
 * In-memory forward curve.
 *
 * It models the estimator's TWO vintages: `$tradeDate` / `$reference` answer a lookup at today's
 * window start, while `$pricingVintageTradeDate` / `$pricingVintageReference` answer a lookup
 * anchored earlier than that (the current period's start). Setting
 * `$pricingVintageTradeDate` to null simulates a period that began before the curve history does.
 *
 * It also records whether it was consulted at all, so the flag-off test can prove the disabled
 * path does no market work.
 */
class FakeMarketCurve implements MarketReferenceCurveProvider
{
    public int $calls = 0;

    /** @var list<string> asOf dates referencePrice() was asked for, so tests can pin the vintage */
    public array $referenceAsOfDates = [];

    /**
     * @param  string  $today  the window start the estimator is called with
     * @param  array<string, float>  $reference  reference kind => c/kWh incl. VAT at today's vintage
     * @param  array<string, float>  $pricingVintageReference  same, at the pre-period vintage
     * @param  array<string, float>  $forward  `Y-m` => c/kWh incl. VAT
     * @param  array<int, float>|null  $seasonalIndex
     */
    public function __construct(
        private readonly ?string $tradeDate = '2026-06-30',
        private readonly array $reference = [],
        private readonly array $forward = [],
        private readonly ?array $seasonalIndex = null,
        private readonly ?float $fixedTermMedian = 10.48,
        private readonly string $today = '2026-07-01',
        private readonly array $pricingVintageReference = [],
        private readonly ?string $pricingVintageTradeDate = '2026-06-30',
        private readonly bool $hasPricingVintage = true,
    ) {}

    public function tradeDate(CarbonImmutable $asOfDate): ?CarbonImmutable
    {
        $this->calls++;

        if ($this->isPricingVintageLookup($asOfDate)) {
            return ($this->hasPricingVintage && $this->pricingVintageTradeDate !== null)
                ? CarbonImmutable::parse($this->pricingVintageTradeDate)
                : null;
        }

        return $this->tradeDate !== null ? CarbonImmutable::parse($this->tradeDate) : null;
    }

    public function referencePrice(CarbonImmutable $asOfDate, CarbonImmutable $anchorMonth, array $kindPreference): ?array
    {
        $this->calls++;
        $this->referenceAsOfDates[] = $asOfDate->toDateString();

        $prices = ($this->isPricingVintageLookup($asOfDate) && $this->pricingVintageReference !== [])
            ? $this->pricingVintageReference
            : $this->reference;

        foreach ($kindPreference as $kind) {
            if (isset($prices[$kind])) {
                return [
                    'kind' => $kind,
                    'price_cents_per_kwh' => $prices[$kind],
                    'trade_date' => $this->tradeDateFor($asOfDate) ?? '',
                ];
            }
        }

        return null;
    }

    public function forwardPriceForMonth(CarbonImmutable $asOfDate, CarbonImmutable $deliveryMonth): ?array
    {
        $this->calls++;
        $key = $deliveryMonth->format('Y-m');

        return isset($this->forward[$key])
            ? ['kind' => 'month', 'price_cents_per_kwh' => $this->forward[$key]]
            : null;
    }

    public function spotSeasonalIndex(): ?array
    {
        $this->calls++;

        return $this->seasonalIndex;
    }

    public function fixedTermMedianEnergyPrice(): ?float
    {
        $this->calls++;

        return $this->fixedTermMedian;
    }

    /**
     * Compared as `Y-m-d` strings on purpose: the calculator builds the window start in
     * Europe/Helsinki, so comparing instants would make a same-date lookup look earlier than
     * a UTC-parsed "today" and silently misclassify the vintage.
     */
    private function isPricingVintageLookup(CarbonImmutable $asOfDate): bool
    {
        return $asOfDate->toDateString() < $this->today;
    }

    private function tradeDateFor(CarbonImmutable $asOfDate): ?string
    {
        if ($this->isPricingVintageLookup($asOfDate)) {
            return $this->hasPricingVintage ? $this->pricingVintageTradeDate : $this->tradeDate;
        }

        return $this->tradeDate;
    }
}
