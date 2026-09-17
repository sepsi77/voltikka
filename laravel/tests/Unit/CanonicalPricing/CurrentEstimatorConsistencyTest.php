<?php

namespace Tests\Unit\CanonicalPricing;

use App\Enums\MeteringType;
use App\Services\CanonicalPricing\CanonicalContractPriceCalculator;
use App\Services\CanonicalPricing\CanonicalPricingParser;
use App\Services\CanonicalPricing\DTO\ContractContext;
use App\Services\CanonicalPricing\DTO\SpotAssumptions;
use App\Services\CanonicalPricing\DTO\WindowSegment;
use App\Services\CanonicalPricing\Enums\ComparisonPolicy;
use App\Services\CanonicalPricing\Enums\ComponentType;
use App\Services\CanonicalPricing\ForwardPremium\PremiumCompatibility;
use App\Services\CanonicalPricing\ForwardPremium\PremiumEstimate;
use App\Services\CanonicalPricing\ForwardPremium\PremiumFamily;
use App\Services\CanonicalPricing\ForwardPremium\PremiumObservation;
use App\Services\CanonicalPricing\ForwardPremium\PremiumSource;
use App\Services\CanonicalPricing\ForwardPremium\PremiumVatBasis;
use App\Services\CanonicalPricing\MarketReset\DTO\ResetEstimate;
use App\Services\CanonicalPricing\MarketReset\DTO\ResetEstimateRequest;
use App\Services\CanonicalPricing\MarketReset\DTO\ResetEstimatorSettings;
use App\Services\CanonicalPricing\MarketReset\Enums\ResetEstimateBasis;
use App\Services\CanonicalPricing\MarketReset\MarketReferenceCurveProvider;
use App\Services\CanonicalPricing\MarketReset\MarketResetPriceEstimator;
use App\Services\CanonicalPricing\SupplierAdjusted\DTO\PriceEpisodeAnchor;
use App\Services\CanonicalPricing\SupplierAdjusted\DTO\SupplierAdjustedCandidate;
use App\Services\CanonicalPricing\SupplierAdjusted\DTO\SupplierAdjustedEstimate;
use App\Services\CanonicalPricing\SupplierAdjusted\DTO\SupplierAdjustedEstimateRequest;
use App\Services\CanonicalPricing\SupplierAdjusted\Enums\PriceEpisodeEvidenceBasis;
use App\Services\CanonicalPricing\SupplierAdjusted\Enums\SupplierAdjustedEstimateBasis;
use App\Services\CanonicalPricing\SupplierAdjusted\SupplierAdjustedPriceEstimator;
use App\Services\ContractCard\ContractCardCopy;
use App\Services\ContractCard\DTO\PricingCategoryFacts;
use App\Services\ContractCard\Enums\PricingCategory;
use App\Services\ContractPricing\ContractPricingViewData;
use App\Services\DTO\EnergyUsage;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\TestCase;

class CurrentEstimatorConsistencyTest extends TestCase
{
    private function date(string $date = '2026-07-01'): CarbonImmutable
    {
        return CarbonImmutable::parse($date, 'Europe/Helsinki');
    }

    private function curve(?string $trade = '2026-06-30', mixed $referenceDate = '2026-05-29', float $reference = 5.0, float $forward = 7.0, ?array $index = null): MarketReferenceCurveProvider
    {
        $curve = $this->createMock(MarketReferenceCurveProvider::class);
        $curve->method('tradeDate')->willReturn($trade === null ? null : $this->date($trade));
        $curve->method('referencePrice')->willReturn(['kind' => 'month', 'price_cents_per_kwh' => $reference, 'trade_date' => $referenceDate]);
        $curve->method('forwardPriceForMonth')->willReturn(['kind' => 'month', 'price_cents_per_kwh' => $forward]);
        $curve->method('spotSeasonalIndex')->willReturn($index);

        return $curve;
    }

    private function anchor(): PriceEpisodeAnchor
    {
        return new PriceEpisodeAnchor($this->date('2026-06-01'), PriceEpisodeEvidenceBasis::CurrentSourceObservation);
    }

    private function supplier(ComparisonPolicy $policy, ?float $anchor = null): SupplierAdjustedEstimateRequest
    {
        return new SupplierAdjustedEstimateRequest($this->date(), $this->anchor(), ['2026-08'], 10.0, 4.0, ['2026-07' => 1.0, '2026-08' => 1.0], policy: $policy, seasonalAnchorEnergyPriceCentsPerKwh: $anchor);
    }

    private function reset(ComparisonPolicy $policy, float $anchor = 10.0): ResetEstimateRequest
    {
        return new ResetEstimateRequest('monthly', $this->date(), $this->date('2026-06-01'), $this->date('2026-06-01'), ['2026-08'], $anchor, ['2026-07' => 1.0, '2026-08' => 1.0], policy: $policy);
    }

    public function test_current_guards_reject_bad_dates_and_nonfinite_points_without_exceptions(): void
    {
        $curves = [
            $this->curve(trade: '2026-07-01'), $this->curve(trade: '2026-07-02'),
            $this->curve(referenceDate: null), $this->curve(referenceDate: ''),
            $this->curve(referenceDate: 'bad'), $this->curve(referenceDate: '2026-02-30'),
            $this->curve(referenceDate: '2026-06-01'), $this->curve(referenceDate: '2026-06-02'),
            $this->curve(reference: INF), $this->curve(forward: NAN), $this->curve(forward: -INF),
            $this->curve(reference: -PHP_FLOAT_MAX, forward: PHP_FLOAT_MAX),
        ];
        foreach ($curves as $curve) {
            $supplier = (new SupplierAdjustedPriceEstimator($curve))->estimate($this->supplier(ComparisonPolicy::Current));
            $reset = (new MarketResetPriceEstimator($curve, new ResetEstimatorSettings(enabled: true)))->estimate($this->reset(ComparisonPolicy::Current));
            $this->assertSame('hold_flat', $supplier->basis->value);
            $this->assertSame('hold_flat', $reset->basis->value);
            $this->assertSame(0.0, $supplier->beta);
            $this->assertSame(0.0, $reset->beta);
        }
    }

    public function test_negative_wholesale_is_valid_and_reset_enable_flag_does_not_disable_supplier(): void
    {
        $curve = $this->curve(reference: -2.0, forward: -1.0);
        $settings = new ResetEstimatorSettings(enabled: false);
        $supplier = (new SupplierAdjustedPriceEstimator($curve, $settings))->estimate($this->supplier(ComparisonPolicy::Current));
        $this->assertSame(1.0, $supplier->offsetForMonthKey('2026-08'));
        $reset = (new MarketResetPriceEstimator($curve, $settings))->estimate($this->reset(ComparisonPolicy::Current));
        $this->assertSame('hold_flat', $reset->basis->value);
        $this->assertSame(0.0, $reset->beta);
    }

    public function test_historical_keeps_legacy_vintage_anchor_and_hold_beta(): void
    {
        $curve = $this->curve(referenceDate: 'bad');
        $historical = (new SupplierAdjustedPriceEstimator($curve))->estimate($this->supplier(ComparisonPolicy::Historical));
        $this->assertSame('forward_curve_shift', $historical->basis->value);
        $settings = new ResetEstimatorSettings(enabled: true, beta: 0.7);
        $missing = $this->curve(trade: null);
        $this->assertSame(0.7, (new SupplierAdjustedPriceEstimator($missing, $settings))->estimate($this->supplier(ComparisonPolicy::Historical))->beta);
        $this->assertSame(1.0, (new MarketResetPriceEstimator($missing, $settings))->estimate($this->reset(ComparisonPolicy::Historical))->beta);
        $this->assertSame(ComparisonPolicy::Historical, (new SupplierAdjustedEstimateRequest($this->date(), $this->anchor(), [], 10, 4, []))->policy);
    }

    public function test_current_seasonal_rejects_nonpositive_and_nonfinite_indices(): void
    {
        foreach ([0.0, -1.0, INF, NAN] as $bad) {
            foreach ([6, 8] as $month) {
                $index = array_fill(1, 12, 1.0);
                $index[$month] = $bad;
                $curve = $this->curve(trade: null, index: $index);
                $this->assertSame('hold_flat', (new SupplierAdjustedPriceEstimator($curve))->estimate($this->supplier(ComparisonPolicy::Current))->basis->value);
                $this->assertSame('hold_flat', (new MarketResetPriceEstimator($curve, new ResetEstimatorSettings(enabled: true)))->estimate($this->reset(ComparisonPolicy::Current))->basis->value);
            }
        }
    }

    public function test_selected_full_profile_anchors_seasonal_shift_without_changing_representative_or_vat(): void
    {
        $index = array_fill(1, 12, 1.0);
        $index[6] = 0.5;
        $curve = $this->curve(trade: null, index: $index);
        $settings = new ResetEstimatorSettings(enabled: true);
        $calculator = new CanonicalContractPriceCalculator(new MarketResetPriceEstimator($curve, $settings), new SupplierAdjustedPriceEstimator($curve, $settings));
        $resolve = new \ReflectionMethod($calculator, 'resolveSupplierAdjustedEstimate');
        foreach ([true, false] as $vat) {
            $factor = $vat ? 1.0 : 1 / 1.255;
            foreach ([['DayTime', 'NightTime', 'energy_day', 'energy_night', 'Time'], ['SeasonalWinterDay', 'SeasonalOther', 'energy_seasonal_winter', 'energy_seasonal_other', 'Season']] as [$first, $second, $firstRate, $secondRate, $metering]) {
                $candidate = new SupplierAdjustedCandidate('test', 10 * $factor, 4 * $factor, [$firstRate => 15 * $factor, $secondRate => 5 * $factor], $metering, $vat);
                $profile = array_fill(0, 12, [$first => 10.0, $second => 90.0]);
                $segments = [new WindowSegment($this->date('2026-08-01'), $this->date('2026-09-01'), 7, 0)];
                $context = new ContractContext('FixedPrice', 'OpenEnded', $metering, null, $vat ? 'Household' : 'Company');
                $current = $resolve->invoke($calculator, $candidate, $profile, $this->date(), $segments, $this->anchor(), $context, null, true);
                $historical = $resolve->invoke($calculator, $candidate, $profile, $this->date(), $segments, $this->anchor(), $context, null, false);
                $reset = (new MarketResetPriceEstimator($curve, $settings))->estimate($this->reset(ComparisonPolicy::Current, 6 * $factor));
                $this->assertEqualsWithDelta($reset->offsetForMonthKey('2026-08'), $current->offsetForMonthKey('2026-08'), 0.00001);
                $this->assertEqualsWithDelta(10 * $factor, $current->currentEnergyPriceCentsPerKwh, 0.00001);
                $this->assertEqualsWithDelta(10 * $factor, $historical->offsetForMonthKey('2026-08'), 0.00001);
            }
        }
    }

    public function test_floor_uses_costed_buckets_and_exact_reset_tail_for_scalar_and_premium_offsets(): void
    {
        $curve = $this->curve();
        $calculator = new CanonicalContractPriceCalculator(new MarketResetPriceEstimator($curve), new SupplierAdjustedPriceEstimator($curve));
        $cost = new \ReflectionMethod($calculator, 'costSegment');
        $rates = ['buckets' => ['DayTime' => 15.0, 'NightTime' => 1.0], 'uses_spot' => false, 'monthly_fee' => 4.0];
        $segment = new WindowSegment($this->date('2026-08-01'), $this->date('2026-08-16'), 0, 0);
        foreach ([false, true] as $premium) {
            $supplier = new SupplierAdjustedEstimate(
                basis: $premium ? SupplierAdjustedEstimateBasis::ForwardPremium : SupplierAdjustedEstimateBasis::ForwardCurveShift,
                offsetsByMonthKey: $premium ? [] : ['2026-08' => -5.0], beta: 1.0,
                currentEnergyPriceCentsPerKwh: 10.0, monthlyFeeEur: 4.0,
                annualEquivalentEnergyPriceCentsPerKwh: 5.0, referenceKind: null, referencePriceCentsPerKwh: null,
                curveTradeDate: null, referenceTradeDate: null, tailStartsMonthKey: '2026-08', priceEpisodeAnchor: $this->anchor(),
                bucketOffsetsByMonthKey: $premium ? ['2026-08' => ['energy_day' => -5.0, 'energy_night' => -5.0]] : [],
            );
            $reset = new ResetEstimate(
                basis: $premium ? ResetEstimateBasis::ForwardPremium : ResetEstimateBasis::ForwardCurveShift,
                offsetsByMonthKey: $supplier->offsetsByMonthKey, beta: 1.0, cadence: 'monthly', currentPeriodEnergyPriceCentsPerKwh: 10.0,
                tailStartsOn: '2026-08-01', bucketOffsetsByMonthKey: $supplier->bucketOffsetsByMonthKey,
            );
            foreach ([[$supplier, null], [null, $reset], [null, $reset->withTailStart('2026-08-16')]] as [$supplierEstimate, $resetEstimate]) {
                foreach ([0.0, 100.0] as $nightKwh) {
                    $flat = [];
                    $energy = 0.0;
                    $floor = false;
                    $cost->invokeArgs($calculator, [$segment, [['DayTime' => 100.0, 'NightTime' => $nightKwh]], $rates, &$flat, 0, $resetEstimate, $supplierEstimate, null, &$energy, &$floor]);
                    $this->assertSame($nightKwh > 0 && $resetEstimate?->tailStartsOn !== '2026-08-16', $floor);
                }
            }
        }
    }

    public function test_different_reference_periods_intentionally_have_different_seasonal_offsets(): void
    {
        $index = array_fill(1, 12, 1.0);
        $index[6] = 0.5;
        $curve = $this->curve(trade: null, index: $index);
        $request = $this->reset(ComparisonPolicy::Current);
        $quarter = new ResetEstimateRequest(...array_replace(get_object_vars($request), ['cadence' => 'quarterly']));
        $reset = (new MarketResetPriceEstimator($curve, new ResetEstimatorSettings(enabled: true)))->estimate($quarter);
        $supplier = (new SupplierAdjustedPriceEstimator($curve))->estimate($this->supplier(ComparisonPolicy::Current));
        $this->assertNotEquals($reset->offsetForMonthKey('2026-08'), $supplier->offsetForMonthKey('2026-08'));
    }

    public function test_current_reset_floor_reaches_the_outcome_and_estimate_flags(): void
    {
        $fixture = json_decode(file_get_contents(__DIR__.'/../../Fixtures/sulaketariffi-five-duplicate-monthly-fees.json'), true, flags: JSON_THROW_ON_ERROR);
        $fixture['pricing']['recurring_schedule'] = array_replace($fixture['pricing']['recurring_schedule'], [
            'present' => true, 'cadence' => 'monthly', 'current_period_start' => '2026-07-01', 'current_period_end' => '2026-07-31', 'future_price_known' => false,
        ]);
        $data = (new CanonicalPricingParser)->parse($fixture['pricing'], $fixture['calculation'], $fixture['source_consistency']);
        foreach ([1.0 => true, 40 => false] as $forward => $expectedFloor) {
            $curve = $this->curve(reference: 30.0, forward: (float) $forward);
            $calculator = new CanonicalContractPriceCalculator(new MarketResetPriceEstimator($curve, new ResetEstimatorSettings(enabled: true)), new SupplierAdjustedPriceEstimator($curve));
            $outcome = $calculator->calculate($data, new ContractContext('FixedPrice', 'OpenEnded', 'General', null, 'Household'), new EnergyUsage(total: 5000, basicLiving: 5000), new SpotAssumptions(null, null), $this->date());
            $this->assertSame($expectedFloor, in_array('estimated_energy_nonnegative_model_floor_applied', $outcome->assumptions, true));
            $this->assertSame($expectedFloor, in_array('estimated_energy_nonnegative_model_floor_applied', $outcome->resetEstimate['flags'], true));
        }
    }

    public function test_serialized_anchor_and_estimator_flags_survive_current_and_historical_costing(): void
    {
        $fixture = json_decode(file_get_contents(__DIR__.'/../../Fixtures/sulaketariffi-five-duplicate-monthly-fees.json'), true, flags: JSON_THROW_ON_ERROR);
        $data = (new CanonicalPricingParser)->parse($fixture['pricing'], $fixture['calculation'], $fixture['source_consistency']);
        $anchor = new PriceEpisodeAnchor($this->date('2026-06-01'), PriceEpisodeEvidenceBasis::CurrentSourceObservation, [
            'price_episode_observed_proxy', 'price_episode_observation_gap', 'price_episode_left_censored',
        ]);
        foreach ([false, true] as $premium) {
            foreach ([2.0, -100.0] as $offset) {
                $serialized = null;
                $estimator = $this->createMock(SupplierAdjustedPriceEstimator::class);
                $estimator->method('estimate')->willReturnCallback(function (SupplierAdjustedEstimateRequest $request) use ($premium, $offset, &$serialized) {
                    $offsets = array_fill_keys($request->tailMonthKeys, $offset);
                    $estimate = new SupplierAdjustedEstimate(
                        basis: $premium ? SupplierAdjustedEstimateBasis::ForwardPremium : SupplierAdjustedEstimateBasis::ForwardCurveShift,
                        offsetsByMonthKey: $premium ? [] : $offsets, beta: 1.0,
                        currentEnergyPriceCentsPerKwh: $request->currentEnergyPriceCentsPerKwh, monthlyFeeEur: $request->monthlyFeeEur,
                        annualEquivalentEnergyPriceCentsPerKwh: 0, referenceKind: null, referencePriceCentsPerKwh: null,
                        curveTradeDate: '2026-06-30', referenceTradeDate: null, tailStartsMonthKey: $request->tailMonthKeys[0],
                        priceEpisodeAnchor: $request->priceEpisodeAnchor, flags: ['lower_confidence_energy_episode_proxy'],
                        bucketOffsetsByMonthKey: $premium ? array_map(fn () => ['energy_general' => $offset], $offsets) : [],
                        premium: $premium ? new PremiumEstimate(PremiumSource::Market, ['energy_general' => 2.0], [new PremiumObservation(
                            'test-lineage', 'Test Company', new PremiumCompatibility(PremiumFamily::SupplierAdjusted, MeteringType::General, PremiumVatBasis::Included, [ComponentType::EnergyGeneral]),
                            ['energy_general' => 2.0], 'test-signature', $this->date('2026-06-01'), $this->date('2026-05-29'),
                            $this->date('2026-06-01'), $this->date('2026-06-30'), $this->date('2026-06-01'), $this->date('2026-06-30'), 'test',
                        )], 1, []) : null,
                    );
                    $serialized = $estimate->toArray();

                    return $estimate;
                });
                $calculator = new CanonicalContractPriceCalculator(new MarketResetPriceEstimator($this->curve()), $estimator);
                $totals = [];
                foreach ([ComparisonPolicy::Current, ComparisonPolicy::Historical] as $policy) {
                    $outcome = $calculator->calculate($data, new ContractContext('FixedPrice', 'OpenEnded', 'General', null, 'Household'), new EnergyUsage(total: 5000, basicLiving: 5000), new SpotAssumptions(null, null), $this->date(), $anchor, policy: $policy);
                    $expected = $serialized;
                    $expected['annual_equivalent_energy_price'] = $outcome->supplierAdjustedEstimate['annual_equivalent_energy_price'];
                    if ($policy === ComparisonPolicy::Current && $offset < 0) {
                        $expected['flags'][] = 'estimated_energy_nonnegative_model_floor_applied';
                    }
                    $this->assertSame(json_encode($expected), json_encode($outcome->supplierAdjustedEstimate));
                    $pricing = ContractPricingViewData::fromCanonicalOutcome($outcome);
                    $this->assertSame($outcome->toCalculatedCostArray(), $pricing->toArray());
                    $floored = $policy === ComparisonPolicy::Current && $offset < 0;
                    $this->assertSame($floored, ContractCardCopy::modelFloorNote($pricing) !== null);
                    $popover = ContractCardCopy::estimate($pricing, new PricingCategoryFacts(PricingCategory::Fixed));
                    $this->assertSame($floored ? 1 : 0, substr_count($popover->body, 'Voltikan laskentamalli rajasi'));
                    $this->assertStringContainsString('Nykyiset energiahinnat ovat myyjän julkaisemia hintoja', $popover->body);
                    $totals[] = $outcome->totalCost;
                }
                $this->assertSame($totals[0], $totals[1]);
            }
        }
    }

    public function test_billed_floor_is_current_only_and_does_not_change_costs(): void
    {
        $fixture = json_decode(file_get_contents(__DIR__.'/../../Fixtures/sulaketariffi-five-duplicate-monthly-fees.json'), true, flags: JSON_THROW_ON_ERROR);
        $data = (new CanonicalPricingParser)->parse($fixture['pricing'], $fixture['calculation'], $fixture['source_consistency']);
        $curve = $this->curve(reference: 30.0, forward: 1.0);
        $calculator = new CanonicalContractPriceCalculator(new MarketResetPriceEstimator($curve), new SupplierAdjustedPriceEstimator($curve));
        $outcomes = [];
        foreach ([ComparisonPolicy::Current, ComparisonPolicy::Historical] as $policy) {
            $outcomes[] = $calculator->calculate($data, new ContractContext('FixedPrice', 'OpenEnded', 'General', null, 'Household'), new EnergyUsage(total: 5000, basicLiving: 5000), new SpotAssumptions(null, null), $this->date(), $this->anchor(), policy: $policy);
        }
        [$current, $historical] = $outcomes;
        $flag = 'estimated_energy_nonnegative_model_floor_applied';
        $this->assertContains($flag, $current->assumptions);
        $this->assertContains($flag, $current->supplierAdjustedEstimate['flags']);
        $this->assertNotContains($flag, $historical->assumptions);
        $this->assertNotContains($flag, $historical->supplierAdjustedEstimate['flags']);
        $this->assertSame($historical->totalCost, $current->totalCost);
    }
}
