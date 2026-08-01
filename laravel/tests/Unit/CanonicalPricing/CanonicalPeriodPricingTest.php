<?php

namespace Tests\Unit\CanonicalPricing;

use App\Services\CanonicalPricing\CanonicalContractPriceCalculator;
use App\Services\CanonicalPricing\CanonicalPricingParser;
use App\Services\CanonicalPricing\DTO\CanonicalPeriodPricingRequest;
use App\Services\CanonicalPricing\DTO\ContractContext;
use App\Services\CanonicalPricing\DTO\HistoricalSpotPrice;
use App\Services\CanonicalPricing\DTO\SpotAssumptions;
use App\Services\CanonicalPricing\Enums\PeriodPricingUnavailableReason;
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
            ),
            $annualSpot,
            $annual,
        );
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
