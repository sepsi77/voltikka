<?php

namespace Tests\Unit\CanonicalPricing;

use App\Services\CanonicalPricing\CanonicalContractPriceCalculator;
use App\Services\CanonicalPricing\CanonicalPricingParser;
use App\Services\CanonicalPricing\DTO\CanonicalPeriodPricingRequest;
use App\Services\CanonicalPricing\DTO\ContractContext;
use App\Services\CanonicalPricing\DTO\HistoricalSpotPrice;
use App\Services\CanonicalPricing\DTO\SpotAssumptions;
use App\Services\CanonicalPricing\Enums\PeriodPricingUnavailableReason;
use App\Services\CanonicalPricing\SpotForward\DTO\SpotEstimate;
use App\Services\CanonicalPricing\SpotForward\Enums\SpotEstimateBasis;
use App\Services\DTO\EnergyUsage;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\TestCase;
use Tests\Unit\CanonicalPricing\Support\HoldFlatCanonicalCalculator;

class CanonicalPeriodPricingTest extends TestCase
{
    private CanonicalPricingParser $parser;

    private CanonicalContractPriceCalculator $calculator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->parser = new CanonicalPricingParser;
        $this->calculator = HoldFlatCanonicalCalculator::make();
    }

    public function test_fixed_general_time_seasonal_and_monthly_fee_period_conventions(): void
    {
        $general = $this->evaluate([
            $this->phase('contract_start', null, 'none', null, [
                $this->component('energy_general', 5),
                $this->component('monthly_fee', 3, 'eur_per_month'),
            ]),
        ], 'General', '2026-05-01', '2026-05-30', 300);
        $this->assertEqualsWithDelta(18.0, $general->periodTotal, 0.0001);

        $time = $this->evaluate([
            $this->phase('contract_start', null, 'none', null, [
                $this->component('energy_day', 10),
                $this->component('energy_night', 2),
            ]),
        ], 'Time', '2026-05-01', '2026-05-30', 300);
        $this->assertEqualsWithDelta(26.4, $time->periodTotal, 0.0001);

        $season = $this->evaluate([
            $this->phase('contract_start', null, 'none', null, [
                $this->component('energy_seasonal_winter', 10),
                $this->component('energy_seasonal_other', 2),
            ]),
        ], 'Season', '2026-03-31', '2026-04-02', 72);
        $this->assertEqualsWithDelta(3.072, $season->periodTotal, 0.0001);

        $partialFee = $this->evaluate([
            $this->phase('contract_start', null, 'none', null, [
                $this->component('energy_general', 0),
                $this->component('monthly_fee', 30, 'eur_per_month'),
            ]),
        ], 'General', '2026-05-01', '2026-05-15', 150);
        $this->assertEqualsWithDelta(15.0, $partialFee->periodTotal, 0.0001);
    }

    public function test_spot_uses_realized_hours_and_phase_specific_margins(): void
    {
        $spot = $this->evaluate([
            $this->phase('contract_start', null, 'none', null, [$this->component('spot_margin', 1)]),
        ], 'General', '2026-05-01', '2026-05-01', 240, 'Spot', $this->spotHistory('2026-05-01', '2026-05-01', 5));
        $this->assertEqualsWithDelta(14.4, $spot->periodTotal, 0.0001);
        $this->assertSame([1.0], $spot->spotMargins);
        $this->assertNotContains('missing_spot_hours_filled_with_observed_average', $spot->assumptions);

        $changingMargin = $this->evaluate([
            $this->phase('contract_start', null, 'date', '2026-05-01', [$this->component('spot_margin', 1)]),
            $this->phase('date', '2026-05-02', 'none', null, [$this->component('spot_margin', 3)]),
        ], 'General', '2026-05-01', '2026-05-02', 480, 'Spot', $this->spotHistory('2026-05-01', '2026-05-02', 5));
        $this->assertEqualsWithDelta(33.6, $changingMargin->periodTotal, 0.0001);
        $this->assertSame([1.0, 3.0], $changingMargin->spotMargins);

        $fixedToSpot = $this->evaluate([
            $this->phase('contract_start', null, 'date', '2026-05-01', [$this->component('energy_general', 10)]),
            $this->phase('date', '2026-05-02', 'none', null, [$this->component('spot_margin', 1)]),
        ], 'General', '2026-05-01', '2026-05-02', 480, 'Spot', $this->spotHistory('2026-05-01', '2026-05-02', 5));
        $this->assertEqualsWithDelta(38.4, $fixedToSpot->periodTotal, 0.0001);
    }

    public function test_exact_period_spot_never_receives_or_uses_the_forward_assumption(): void
    {
        $data = $this->parser->parse(
            $this->pricing([
                $this->phase('contract_start', null, 'none', null, [$this->component('spot_margin', 1)]),
            ]),
            ['status' => 'estimate_required', 'missing_facts' => [], 'required_assumptions' => []],
            ['misleading_first_12_months' => 'not_detected', 'structured_pricing_status' => 'complete', 'issue_codes' => []],
        );
        $context = new ContractContext('Spot', 'OpenEnded', 'General', null, 'Household');
        $start = CarbonImmutable::parse('2026-05-01', 'Europe/Helsinki');
        $annual = $this->calculator->calculate(
            $data,
            $context,
            new EnergyUsage(5000, 5000),
            new SpotAssumptions(5, 5),
            $start,
            spotEstimate: new SpotEstimate(
                basis: SpotEstimateBasis::ForwardCurve,
                shapeOverallCentsPerKwh: 5,
                shapeDayCentsPerKwh: 5,
                shapeNightCentsPerKwh: 5,
                dayOffsetCentsPerKwh: 0,
                nightOffsetCentsPerKwh: 0,
                shapePeriodStart: '2025-05-02',
                shapePeriodEnd: '2026-05-01',
                currentCurveTradeDate: '2026-04-30',
                futureCurveTradeDate: '2026-04-30',
                months: [],
                annualEquivalentBaseCentsPerKwh: 50,
                annualEquivalentDayCentsPerKwh: 50,
                annualEquivalentNightCentsPerKwh: 50,
                confidence: 'higher',
            ),
        );

        $period = $this->calculator->calculatePeriod(
            $data,
            $context,
            new CanonicalPeriodPricingRequest(
                $start,
                $start,
                240,
                5000,
                $this->spotHistory('2026-05-01', '2026-05-01', 5),
            ),
            new SpotAssumptions(5, 5),
            $annual,
        );

        $this->assertGreaterThan(2500, $annual->totalCost);
        $this->assertEqualsWithDelta(14.4, $period->periodTotal, 0.0001);
        $this->assertNotContains('spot_forward_curve_with_rolling_365_intraday_shape', $period->assumptions);
        $this->assertContains('actual_hourly_spot_prices', $period->assumptions);
    }

    public function test_hybrid_period_keeps_the_annual_base_only_disclosure(): void
    {
        $outcome = $this->evaluate([
            $this->phase('contract_start', null, 'none', null, [
                $this->component('energy_general', 5),
            ]),
        ], 'General', '2026-05-01', '2026-05-30', 300, model: 'Hybrid', consumptionEffect: [
            'present' => true,
            'applies_to' => 'base_contract',
        ]);

        $this->assertTrue($outcome->isAvailable());
        $this->assertEqualsWithDelta(15.0, $outcome->periodTotal, 0.0001);
        $this->assertSame('base_only_hybrid', $outcome->comparability->value);
        $this->assertContains('excludes_consumption_effect', $outcome->assumptions);
    }

    public function test_market_reset_uses_the_annual_hold_fill_policy_for_an_uncovered_period_tail(): void
    {
        $outcome = $this->evaluate([
            $this->phase('period_boundary', null, 'period_boundary', null, [
                $this->component('energy_general', 8),
            ]),
        ], 'General', '2026-05-01', '2026-06-30', 600, recurring: [
            'present' => true,
            'cadence' => 'monthly',
            'current_period_start' => '2026-05-01',
            'current_period_end' => '2026-05-31',
            'future_price_known' => false,
        ]);

        $this->assertTrue($outcome->isAvailable());
        $this->assertEqualsWithDelta(48.0, $outcome->periodTotal, 0.0001);
        $this->assertContains('held_current_price_forward', $outcome->assumptions);
    }

    public function test_one_missing_spot_hour_uses_the_same_helsinki_day_average(): void
    {
        $history = array_merge(
            $this->spotHistory('2026-05-01', '2026-05-01', 5),
            $this->spotHistory('2026-05-02', '2026-05-02', 10),
        );
        array_splice($history, 12, 1);

        $outcome = $this->evaluate([
            $this->phase('contract_start', null, 'none', null, [$this->component('spot_margin', 1)]),
        ], 'General', '2026-05-01', '2026-05-02', 480, 'Spot', $history);

        $this->assertTrue($outcome->isAvailable());
        $this->assertEqualsWithDelta(40.8, $outcome->periodTotal, 0.0001);
        $this->assertContains('actual_hourly_spot_prices', $outcome->assumptions);
        $this->assertContains('missing_spot_hours_filled_with_observed_average', $outcome->assumptions);
    }

    public function test_whole_missing_helsinki_day_uses_the_observed_period_average(): void
    {
        $outcome = $this->evaluate([
            $this->phase('contract_start', null, 'none', null, [$this->component('spot_margin', 1)]),
        ], 'General', '2026-05-01', '2026-05-02', 480, 'Spot', $this->spotHistory('2026-05-01', '2026-05-01', 5));

        $this->assertTrue($outcome->isAvailable());
        $this->assertEqualsWithDelta(28.8, $outcome->periodTotal, 0.0001);
        $this->assertContains('missing_spot_hours_filled_with_observed_average', $outcome->assumptions);
    }

    public function test_completed_spot_map_is_used_by_the_normal_price_pass(): void
    {
        $history = $this->spotHistory('2026-05-01', '2026-05-02', 5);
        array_splice($history, 12, 1);

        $outcome = $this->evaluate([
            $this->phase('contract_start', null, 'date', '2026-05-01', [$this->component('energy_general', 10)]),
            $this->phase('date', '2026-05-02', 'none', null, [$this->component('spot_margin', 1)]),
        ], 'General', '2026-05-01', '2026-05-02', 480, 'Spot', $history);

        $this->assertTrue($outcome->isAvailable());
        $this->assertEqualsWithDelta(38.4, $outcome->periodTotal, 0.0001);
        $this->assertEqualsWithDelta(28.8, $outcome->normalPeriodTotal, 0.0001);
        $this->assertContains('missing_spot_hours_filled_with_observed_average', $outcome->assumptions);
    }

    public function test_missing_spot_history_has_a_stable_unavailable_reason(): void
    {
        $outcome = $this->evaluate([
            $this->phase('contract_start', null, 'none', null, [$this->component('spot_margin', 1)]),
        ], 'General', '2026-05-01', '2026-05-01', 240, 'Spot');

        $this->assertFalse($outcome->isAvailable());
        $this->assertSame(PeriodPricingUnavailableReason::NoSpotHistory, $outcome->unavailableReason);
    }

    public function test_packages_reset_allowance_by_month_and_prorate_partial_months(): void
    {
        $package = [
            'monthly_fee_eur' => 10,
            'included_kwh' => 75,
            'allowance_cadence' => 'monthly',
            'excess_rate_cents_per_kwh' => 20,
            'evidence' => [],
        ];

        $full = $this->evaluate([
            $this->phase('contract_start', null, 'none', null, [], $package),
        ], 'General', '2026-05-01', '2026-05-31', 100);
        $this->assertEqualsWithDelta(15.0, $full->periodTotal, 0.0001);
        $this->assertFalse($full->hasPromotion());

        $partial = $this->evaluate([
            $this->phase('contract_start', null, 'none', null, [], $package),
        ], 'General', '2026-05-01', '2026-05-15', 50);
        $expectedPartial = 10 * (15 / 31) + max(0, 50 - 75 * (15 / 31)) * 0.20;
        $this->assertEqualsWithDelta($expectedPartial, $partial->periodTotal, 0.0001);

        $noCarryPackage = $package;
        $noCarryPackage['included_kwh'] = 100;
        $noCarryPackage['excess_rate_cents_per_kwh'] = 10;
        $noCarry = $this->evaluate([
            $this->phase('contract_start', null, 'none', null, [], $noCarryPackage),
        ], 'General', '2026-05-01', '2026-06-30', 200);
        $mayUsage = 200 * (31 * 24) / ((31 + 30) * 24);
        $this->assertEqualsWithDelta(20 + (($mayUsage - 100) * 0.10), $noCarry->periodTotal, 0.0001);
    }

    public function test_offer_timing_anchors_relative_phases_and_preserves_absolute_dates(): void
    {
        $relative = $this->evaluate([
            $this->phase('contract_start', null, 'after_months', '1', [
                $this->component('energy_general', 5, 'cents_per_kwh', 10),
            ]),
            $this->phase('after_months', '1', 'none', null, [$this->component('energy_general', 10)]),
        ], 'General', '2026-05-01', '2026-05-31', 310);
        $this->assertEqualsWithDelta(15.5, $relative->periodTotal, 0.0001);
        $this->assertEqualsWithDelta(15.5, $relative->measuredDiscountSavings, 0.0001);
        $this->assertTrue($relative->hasPromotion());

        $absolute = $this->evaluate([
            $this->phase('contract_start', null, 'date', '2026-05-15', [
                $this->component('energy_general', 5, 'cents_per_kwh', 10),
            ]),
            $this->phase('date', '2026-05-16', 'none', null, [$this->component('energy_general', 10)]),
        ], 'General', '2026-05-01', '2026-05-30', 300);
        $this->assertEqualsWithDelta(22.5, $absolute->periodTotal, 0.0001);
        $this->assertEqualsWithDelta(7.5, $absolute->measuredDiscountSavings, 0.0001);
    }

    public function test_uniform_hourly_weights_match_the_flat_path_for_general_season_spot_and_package(): void
    {
        $uniform = fn (string $start, string $end): array => $this->weights($start, $end, static fn (): float => 1.0);
        $cases = [
            'general' => [[$this->phase('contract_start', null, 'none', null, [
                $this->component('energy_general', 5),
                $this->component('monthly_fee', 3, 'eur_per_month'),
            ])], 'General', '2026-05-01', '2026-05-30', 300, 'FixedPrice', []],
            'season' => [[$this->phase('contract_start', null, 'none', null, [
                $this->component('energy_seasonal_winter', 10),
                $this->component('energy_seasonal_other', 2),
            ])], 'Season', '2026-03-31', '2026-04-02', 72, 'FixedPrice', []],
            'spot' => [[$this->phase('contract_start', null, 'none', null, [$this->component('spot_margin', 1)])],
                'General', '2026-05-01', '2026-05-02', 480, 'Spot', $this->spotHistory('2026-05-01', '2026-05-02', 5)],
            'package' => [[$this->phase('contract_start', null, 'none', null, [], [
                'monthly_fee_eur' => 10,
                'included_kwh' => 100,
                'allowance_cadence' => 'monthly',
                'excess_rate_cents_per_kwh' => 10,
                'evidence' => [],
            ])], 'General', '2026-05-01', '2026-06-30', 200, 'FixedPrice', []],
        ];

        foreach ($cases as $name => [$phases, $metering, $start, $end, $kwh, $model, $history]) {
            $flat = $this->evaluate($phases, $metering, $start, $end, $kwh, $model, $history);
            $weighted = $this->evaluate($phases, $metering, $start, $end, $kwh, $model, $history, weights: $uniform($start, $end));

            $this->assertTrue($weighted->isAvailable(), $name);
            $this->assertEqualsWithDelta($flat->periodTotal, $weighted->periodTotal, 1e-9, $name);
            $this->assertContains('period_consumption_hourly_profile', $weighted->assumptions, $name);
            $this->assertNotContains('period_consumption_flat_by_actual_hour', $weighted->assumptions, $name);
            $this->assertContains('period_consumption_flat_by_actual_hour', $flat->assumptions, $name);
        }
    }

    public function test_weights_are_scaled_so_the_period_total_stays_period_kwh(): void
    {
        $phases = [$this->phase('contract_start', null, 'none', null, [$this->component('energy_general', 5)])];
        $weighted = $this->evaluate($phases, 'General', '2026-05-01', '2026-05-30', 300, weights: $this->weights(
            '2026-05-01',
            '2026-05-30',
            static fn (CarbonImmutable $hour): float => $hour->hour + 0.5,
        ));

        $this->assertEqualsWithDelta(15.0, $weighted->periodTotal, 1e-9);
    }

    public function test_uniform_time_weights_split_by_actual_helsinki_day_and_night_hours(): void
    {
        $phases = [$this->phase('contract_start', null, 'none', null, [
            $this->component('energy_day', 10),
            $this->component('energy_night', 2),
        ])];

        $weighted = $this->evaluate($phases, 'Time', '2026-05-01', '2026-05-30', 300, weights: $this->weights(
            '2026-05-01',
            '2026-05-30',
            static fn (): float => 1.0,
        ));

        // 15 day hours (07-22) and 9 night hours per Helsinki day instead of 85/15.
        $this->assertEqualsWithDelta(300 * ((15 / 24) * 10 + (9 / 24) * 2) / 100, $weighted->periodTotal, 1e-9);
    }

    public function test_time_profile_concentrated_in_night_hours_uses_the_night_rate(): void
    {
        $phases = [$this->phase('contract_start', null, 'none', null, [
            $this->component('energy_day', 10),
            $this->component('energy_night', 2),
        ])];
        $nightOnly = $this->weights('2026-05-01', '2026-05-30', static fn (CarbonImmutable $hour): float => ($hour->hour >= 22 || $hour->hour < 7) ? 1.0 : 0.0);
        $mostlyNight = $this->weights('2026-05-01', '2026-05-30', static fn (CarbonImmutable $hour): float => ($hour->hour >= 22 || $hour->hour < 7) ? 9.0 : 1.0);

        $night = $this->evaluate($phases, 'Time', '2026-05-01', '2026-05-30', 300, weights: $nightOnly);
        $mostly = $this->evaluate($phases, 'Time', '2026-05-01', '2026-05-30', 300, weights: $mostlyNight);
        $flat = $this->evaluate($phases, 'Time', '2026-05-01', '2026-05-30', 300);

        $this->assertEqualsWithDelta(6.0, $night->periodTotal, 1e-9);
        $nightShare = (9 * 9) / (9 * 9 + 15 * 1);
        $this->assertEqualsWithDelta(300 * ($nightShare * 2 + (1 - $nightShare) * 10) / 100, $mostly->periodTotal, 1e-9);
        $this->assertLessThan($flat->periodTotal, $mostly->periodTotal);
    }

    public function test_non_uniform_spot_weights_give_the_exact_weighted_hourly_sum(): void
    {
        $start = CarbonImmutable::parse('2026-05-01', 'Europe/Helsinki')->startOfDay();
        $history = [];
        $weights = [];
        $expectedCents = 0.0;
        $weightSum = (24 * 25) / 2;
        for ($hour = 0; $hour < 24; $hour++) {
            $utc = $start->addHours($hour)->utc();
            $price = $hour - 3.0; // Early hours are negative so the per-hour floor applies.
            $history[] = new HistoricalSpotPrice($utc, $price);
            $weights[$utc->getTimestamp()] = $hour + 1;
            $expectedCents += (48 * ($hour + 1) / $weightSum) * max(0.0, $price + 1);
        }

        $outcome = $this->evaluate([
            $this->phase('contract_start', null, 'none', null, [$this->component('spot_margin', 1)]),
        ], 'General', '2026-05-01', '2026-05-01', 48, 'Spot', $history, weights: $weights);

        $this->assertTrue($outcome->isAvailable());
        $this->assertEqualsWithDelta($expectedCents / 100, $outcome->periodTotal, 1e-9);
    }

    public function test_season_profile_moves_kwh_between_winter_and_other_months(): void
    {
        $phases = [$this->phase('contract_start', null, 'none', null, [
            $this->component('energy_seasonal_winter', 10),
            $this->component('energy_seasonal_other', 2),
        ])];
        // 2026-03-31 is winter; 2026-04-01..02 are other months. Only March 31 has weight.
        $marchOnly = $this->weights('2026-03-31', '2026-04-02', static fn (CarbonImmutable $hour): float => $hour->month === 3 ? 1.0 : 0.0);

        $outcome = $this->evaluate($phases, 'Season', '2026-03-31', '2026-04-02', 72, weights: $marchOnly);

        // All 72 kWh fall in winter, where the existing 85/15 winter-day/other split stays.
        $this->assertEqualsWithDelta(72 * (0.85 * 10 + 0.15 * 2) / 100, $outcome->periodTotal, 1e-9);
    }

    public function test_package_and_normal_pass_use_the_same_weighted_kwh(): void
    {
        $package = [
            'monthly_fee_eur' => 10,
            'included_kwh' => 100,
            'allowance_cadence' => 'monthly',
            'excess_rate_cents_per_kwh' => 10,
            'evidence' => [],
        ];
        $mayOnly = $this->weights('2026-05-01', '2026-06-30', static fn (CarbonImmutable $hour): float => $hour->month === 5 ? 1.0 : 0.0);
        $packageOutcome = $this->evaluate([
            $this->phase('contract_start', null, 'none', null, [], $package),
        ], 'General', '2026-05-01', '2026-06-30', 200, weights: $mayOnly);
        $this->assertEqualsWithDelta(20 + (200 - 100) * 0.10, $packageOutcome->periodTotal, 1e-9);

        $offerPhases = [
            $this->phase('contract_start', null, 'date', '2026-05-15', [
                $this->component('energy_general', 5, 'cents_per_kwh', 10),
            ]),
            $this->phase('date', '2026-05-16', 'none', null, [$this->component('energy_general', 10)]),
        ];
        $firstHalf = $this->weights('2026-05-01', '2026-05-30', static fn (CarbonImmutable $hour): float => $hour->day <= 15 ? 3.0 : 1.0);
        $offer = $this->evaluate($offerPhases, 'General', '2026-05-01', '2026-05-30', 300, weights: $firstHalf);

        // 225 kWh fall in the discounted first 15 days and 75 kWh after it.
        $this->assertEqualsWithDelta((225 * 5 + 75 * 10) / 100, $offer->periodTotal, 1e-9);
        $this->assertEqualsWithDelta(30.0, $offer->normalPeriodTotal, 1e-9);
        $this->assertEqualsWithDelta(11.25, $offer->measuredDiscountSavings, 1e-9);
    }

    public function test_malformed_hourly_weights_fail_closed_as_a_caller_error(): void
    {
        $phases = [$this->phase('contract_start', null, 'none', null, [$this->component('energy_general', 5)])];
        $valid = $this->weights('2026-05-01', '2026-05-02', static fn (): float => 1.0);
        $firstKey = array_key_first($valid);

        $missing = $valid;
        unset($missing[$firstKey]);
        $negative = $valid;
        $negative[$firstKey] = -0.1;
        $notFinite = $valid;
        $notFinite[$firstKey] = NAN;
        $zero = array_map(static fn (): float => 0.0, $valid);

        foreach (['missing' => $missing, 'negative' => $negative, 'not_finite' => $notFinite, 'zero_sum' => $zero] as $name => $weights) {
            try {
                $this->evaluate($phases, 'General', '2026-05-01', '2026-05-02', 48, weights: $weights);
                $this->fail("Expected InvalidArgumentException for {$name} weights.");
            } catch (\InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }

        // Keys outside the period window are ignored.
        $extra = $valid + [$firstKey - 3600 => -5.0];
        $this->assertEqualsWithDelta(2.4, $this->evaluate($phases, 'General', '2026-05-01', '2026-05-02', 48, weights: $extra)->periodTotal, 1e-9);
    }

    /**
     * @param  list<array<string, mixed>>  $phases
     * @param  list<HistoricalSpotPrice>  $history
     */
    private function evaluate(
        array $phases,
        string $metering,
        string $start,
        string $end,
        float $kwh,
        string $model = 'FixedPrice',
        array $history = [],
        array $recurring = [],
        array $consumptionEffect = [],
        ?array $weights = null,
    ) {
        $data = $this->parser->parse(
            $this->pricing($phases, $recurring, $consumptionEffect),
            ['status' => match ($model) {
                'Spot' => 'estimate_required',
                'Hybrid' => 'unsupported',
                default => 'exact',
            }, 'missing_facts' => [], 'required_assumptions' => []],
            ['misleading_first_12_months' => 'not_detected', 'structured_pricing_status' => 'complete', 'issue_codes' => []],
        );
        $context = new ContractContext($model, 'OpenEnded', $metering, null, 'Household');
        $startDate = CarbonImmutable::parse($start, 'Europe/Helsinki');
        $annualSpot = new SpotAssumptions(5, 5);
        $annual = $this->calculator->calculate($data, $context, new EnergyUsage(5000, 5000), $annualSpot, $startDate);

        return $this->calculator->calculatePeriod(
            $data,
            $context,
            new CanonicalPeriodPricingRequest(
                $startDate,
                CarbonImmutable::parse($end, 'Europe/Helsinki'),
                $kwh,
                5000,
                $history,
                $weights,
            ),
            $annualSpot,
            $annual,
        );
    }

    /**
     * UTC hour-start timestamp => weight for every hour of the Helsinki date range.
     *
     * @param  callable(CarbonImmutable): float  $weight  receives the Helsinki-local hour start
     * @return array<int, float>
     */
    private function weights(string $start, string $end, callable $weight): array
    {
        $cursor = CarbonImmutable::parse($start, 'Europe/Helsinki')->startOfDay()->utc();
        $limit = CarbonImmutable::parse($end, 'Europe/Helsinki')->startOfDay()->addDay()->utc();
        $weights = [];

        while ($cursor->lessThan($limit)) {
            $weights[$cursor->getTimestamp()] = $weight($cursor->setTimezone('Europe/Helsinki'));
            $cursor = $cursor->addHour();
        }

        return $weights;
    }

    /** @return list<HistoricalSpotPrice> */
    private function spotHistory(string $start, string $end, float $price): array
    {
        $cursor = CarbonImmutable::parse($start, 'Europe/Helsinki')->startOfDay()->utc();
        $limit = CarbonImmutable::parse($end, 'Europe/Helsinki')->startOfDay()->addDay()->utc();
        $rows = [];

        while ($cursor->lessThan($limit)) {
            $rows[] = new HistoricalSpotPrice($cursor, $price);
            $cursor = $cursor->addHour();
        }

        return $rows;
    }

    private function component(string $type, float $amount, string $unit = 'cents_per_kwh', ?float $normal = null): array
    {
        return [
            'component_type' => $type,
            'amount' => $amount,
            'normal_amount' => $normal,
            'unit' => $unit,
            'vat_status' => 'included',
            'price_role' => 'current',
            'source_kind' => 'both',
            'evidence' => [],
        ];
    }

    private function phase(string $startKind, ?string $startValue, string $endKind, ?string $endValue, array $components, ?array $package = null): array
    {
        return [
            'label' => 'phase',
            'phase_kind' => 'normal',
            'starts' => ['kind' => $startKind, 'value' => $startValue],
            'ends' => ['kind' => $endKind, 'value' => $endValue],
            'components' => $components,
            'package' => $package,
            'evidence' => [],
        ];
    }

    private function pricing(array $phases, array $recurring = [], array $consumptionEffect = []): array
    {
        return [
            'phases' => $phases,
            'recurring_schedule' => array_merge([
                'present' => false,
                'cadence' => 'none',
                'current_period_start' => null,
                'current_period_end' => null,
                'future_price_known' => null,
                'description' => null,
                'evidence' => [],
            ], $recurring),
            'consumption_effect' => array_merge([
                'present' => false,
                'applies_to' => 'unknown',
                'cadence' => 'none',
                'expected_cents_per_kwh' => null,
                'typical_min_cents_per_kwh' => null,
                'typical_max_cents_per_kwh' => null,
                'hard_min_cents_per_kwh' => null,
                'hard_max_cents_per_kwh' => null,
                'uncapped' => null,
                'description' => null,
                'evidence' => [],
            ], $consumptionEffect),
        ];
    }
}
