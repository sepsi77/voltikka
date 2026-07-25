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
        $this->parser = new CanonicalPricingParser();
        $this->usage = new EnergyUsage(total: 5000, basicLiving: 5000);
    }

    // ---------------------------------------------------------------- helpers

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

    private function resetPricing(float $energyPrice, string $cadence, array $starts = ['kind' => 'unknown', 'value' => null], array $ends = ['kind' => 'unknown', 'value' => null], ?string $periodEnd = null): array
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
                'current_period_start' => null,
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
            plausibilityMinMultiple: $settingOverrides['plausibilityMinMultiple'] ?? 0.25,
            plausibilityMaxMultiple: $settingOverrides['plausibilityMaxMultiple'] ?? 2.5,
            plausibilityAbsoluteMinCentsPerKwh: $settingOverrides['plausibilityAbsoluteMinCentsPerKwh'] ?? 0.0,
            plausibilityAbsoluteMaxCentsPerKwh: $settingOverrides['plausibilityAbsoluteMaxCentsPerKwh'] ?? 45.0,
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

    public function test_an_implausible_annual_equivalent_falls_back_and_is_flagged(): void
    {
        // A 100 c/kWh forward against a 5 c/kWh reference implies about 94 c/kWh for the year,
        // far outside the band around a 10,48 c/kWh fixed-term median. No seasonal index is
        // available either, so the estimate must degrade to hold flat rather than publish it.
        $curve = new FakeMarketCurve(
            reference: ['month' => 5.0],
            forward: $this->flatForward(100.0),
            seasonalIndex: null,
            fixedTermMedian: 10.48,
        );

        $outcome = $this->evaluate($this->resetPricing(7.0, 'monthly'), $this->estimator($curve));

        $this->assertSame(EstimateMethod::HoldCurrentRecurringPrice, $outcome->estimateMethod);
        $this->assertNull($outcome->resetEstimate);
        $this->assertEqualsWithDelta(350.0, $outcome->totalCost, 0.05);
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
 * In-memory forward curve. Records whether it was consulted at all, so the flag-off test can
 * prove the disabled path does no market work.
 */
class FakeMarketCurve implements MarketReferenceCurveProvider
{
    public int $calls = 0;

    /**
     * @param  array<string, float>  $reference  reference kind => c/kWh incl. VAT
     * @param  array<string, float>  $forward  `Y-m` => c/kWh incl. VAT
     * @param  array<int, float>|null  $seasonalIndex
     */
    public function __construct(
        private readonly ?string $tradeDate = '2026-06-30',
        private readonly array $reference = [],
        private readonly array $forward = [],
        private readonly ?array $seasonalIndex = null,
        private readonly ?float $fixedTermMedian = 10.48,
    ) {
    }

    public function tradeDate(CarbonImmutable $asOfDate): ?CarbonImmutable
    {
        $this->calls++;

        return $this->tradeDate !== null ? CarbonImmutable::parse($this->tradeDate) : null;
    }

    public function referencePrice(CarbonImmutable $asOfDate, CarbonImmutable $anchorMonth, array $kindPreference): ?array
    {
        $this->calls++;

        foreach ($kindPreference as $kind) {
            if (isset($this->reference[$kind])) {
                return ['kind' => $kind, 'price_cents_per_kwh' => $this->reference[$kind]];
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
}
