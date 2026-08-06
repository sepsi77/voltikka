<?php

namespace Tests\Unit\CanonicalPricing;

use App\Services\CanonicalPricing\CanonicalContractPriceCalculator;
use App\Services\CanonicalPricing\CanonicalPricingParser;
use App\Services\CanonicalPricing\DTO\CanonicalPeriodPricingRequest;
use App\Services\CanonicalPricing\DTO\ContractContext;
use App\Services\CanonicalPricing\DTO\SpotAssumptions;
use App\Services\CanonicalPricing\Enums\ContractComparability;
use App\Services\CanonicalPricing\Enums\EstimateMethod;
use App\Services\CanonicalPricing\MarketReset\DTO\ResetEstimatorSettings;
use App\Services\CanonicalPricing\MarketReset\MarketReferenceCurveProvider;
use App\Services\CanonicalPricing\MarketReset\MarketResetPriceEstimator;
use App\Services\CanonicalPricing\SupplierAdjusted\DTO\PriceEpisodeAnchor;
use App\Services\CanonicalPricing\SupplierAdjusted\Enums\PriceEpisodeEvidenceBasis;
use App\Services\CanonicalPricing\SupplierAdjusted\SupplierAdjustedEligibility;
use App\Services\CanonicalPricing\SupplierAdjusted\SupplierAdjustedPriceEstimator;
use App\Services\DTO\EnergyUsage;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\TestCase;

class SupplierAdjustedPricingTest extends TestCase
{
    private CanonicalPricingParser $parser;

    private array $fixture;

    protected function setUp(): void
    {
        parent::setUp();
        $this->parser = new CanonicalPricingParser;
        $this->fixture = json_decode(
            file_get_contents(__DIR__.'/../../Fixtures/sulaketariffi-five-duplicate-monthly-fees.json'),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );
    }

    public function test_forward_shift_keeps_current_month_exact_and_applies_to_all_annual_totals(): void
    {
        $curve = new SupplierAdjustedFakeCurve(
            reference: ['month' => 5.0],
            forward: $this->flatForward(9.0),
        );
        $outcome = $this->calculate($curve, new PriceEpisodeAnchor(
            CarbonImmutable::parse('2026-06-01', 'Europe/Helsinki'),
            PriceEpisodeEvidenceBasis::ObservedSellerSnapshotRun,
            ['price_snapshot_episode_proxy'],
        ));

        $this->assertSame(ContractComparability::ComparableEstimate, $outcome->comparability);
        $this->assertSame(EstimateMethod::SupplierAdjustedForwardCurveShift, $outcome->estimateMethod);
        $this->assertEqualsWithDelta(35.03, $outcome->monthlyCosts[0], 0.02);
        $this->assertEqualsWithDelta(51.70, $outcome->monthlyCosts[1], 0.02);
        $this->assertEqualsWithDelta(603.73, $outcome->totalCost, 0.05);
        $this->assertEqualsWithDelta($outcome->totalCost, $outcome->baseTotalCost, 0.001);
        $this->assertEqualsWithDelta($outcome->totalCost, $outcome->structuredOnlyTotal, 0.001);
        $this->assertNull($outcome->resetEstimate);
        $this->assertSame('2026-08', $outcome->supplierAdjustedEstimate['tail_starts']);
        $this->assertSame('held_flat', $outcome->supplierAdjustedEstimate['monthly_fee_assumption']);
        $this->assertEqualsWithDelta(11.0667, $outcome->supplierAdjustedEstimate['annual_equivalent_energy_price'], 0.001);
    }

    public function test_missing_curve_uses_the_spot_seasonal_index(): void
    {
        $index = array_fill(1, 12, 1.0);
        $index[6] = 0.5;
        $curve = new SupplierAdjustedFakeCurve(tradeDate: null, seasonalIndex: $index);

        $outcome = $this->calculate($curve, new PriceEpisodeAnchor(
            CarbonImmutable::parse('2026-06-01', 'Europe/Helsinki'),
            PriceEpisodeEvidenceBasis::CurrentSourceObservation,
        ));

        $this->assertSame(EstimateMethod::SupplierAdjustedSpotSeasonalIndex, $outcome->estimateMethod);
        $this->assertSame('spot_seasonal_index', $outcome->supplierAdjustedEstimate['basis']);
        $this->assertFalse($outcome->supplierAdjustedEstimate['higher_confidence']);
        $this->assertGreaterThan(7.4, $outcome->supplierAdjustedEstimate['annual_equivalent_energy_price']);
    }

    public function test_missing_anchor_and_market_data_hold_flat_as_a_typed_estimate(): void
    {
        $outcome = $this->calculate(new SupplierAdjustedFakeCurve(tradeDate: null), PriceEpisodeAnchor::missing());

        $this->assertSame(EstimateMethod::HoldCurrentSupplierPrice, $outcome->estimateMethod);
        $this->assertSame('hold_flat', $outcome->supplierAdjustedEstimate['basis']);
        $this->assertSame('missing', $outcome->supplierAdjustedEstimate['price_episode_evidence_basis']);
        $this->assertContains('missing_price_episode_anchor', $outcome->supplierAdjustedEstimate['flags']);
        $this->assertEqualsWithDelta(420.4, $outcome->totalCost, 0.05);
    }

    public function test_negative_tail_prices_are_floored_and_absurd_results_fall_back(): void
    {
        $anchor = new PriceEpisodeAnchor(
            CarbonImmutable::parse('2026-06-01', 'Europe/Helsinki'),
            PriceEpisodeEvidenceBasis::ObservedSellerSnapshotRun,
        );
        $floored = $this->calculate(new SupplierAdjustedFakeCurve(
            reference: ['month' => 12.0],
            forward: $this->flatForward(0.0),
        ), $anchor);

        $this->assertSame(EstimateMethod::SupplierAdjustedForwardCurveShift, $floored->estimateMethod);
        $this->assertEqualsWithDelta(0.6167, $floored->supplierAdjustedEstimate['annual_equivalent_energy_price'], 0.001);
        foreach ($floored->monthlyCosts as $cost) {
            $this->assertGreaterThanOrEqual(4.2, $cost);
        }

        $absurd = $this->calculate(new SupplierAdjustedFakeCurve(
            reference: ['month' => 5.0],
            forward: $this->flatForward(300.0),
        ), $anchor);
        $this->assertSame(EstimateMethod::HoldCurrentSupplierPrice, $absurd->estimateMethod);
        $this->assertSame('hold_flat', $absurd->supplierAdjustedEstimate['basis']);
        $this->assertContains('forward_shift_outside_plausibility_band', $absurd->supplierAdjustedEstimate['flags']);
    }

    public function test_exact_period_pricing_does_not_project_supplier_changes(): void
    {
        $annual = $this->calculate(new SupplierAdjustedFakeCurve(
            reference: ['month' => 5.0],
            forward: $this->flatForward(9.0),
        ), new PriceEpisodeAnchor(
            CarbonImmutable::parse('2026-06-01', 'Europe/Helsinki'),
            PriceEpisodeEvidenceBasis::ObservedSellerSnapshotRun,
        ));
        $data = $this->data($this->fixture);
        $calculator = $this->calculator(new SupplierAdjustedFakeCurve);
        $period = $calculator->calculatePeriod(
            $data,
            $this->context(),
            new CanonicalPeriodPricingRequest(
                startDate: CarbonImmutable::parse('2026-07-01', 'Europe/Helsinki'),
                endDate: CarbonImmutable::parse('2026-07-31', 'Europe/Helsinki'),
                periodKwh: 1000,
                annualizedKwh: 12000,
                historicalSpotPrices: [],
            ),
            new SpotAssumptions(null, null),
            $annual,
        );

        $this->assertEqualsWithDelta(78.34, $period->periodTotal, 0.02);
        $this->assertNotContains('supplier_adjusted_tail_shifted_on_forward_curve', $period->assumptions);
    }

    public function test_multiple_monthly_fee_variants_use_the_same_conservative_maximum_as_the_calculator(): void
    {
        $data = $this->data($this->fixture);
        $candidate = (new SupplierAdjustedEligibility)->candidate('sulake', $data, $this->context());

        $this->assertNotNull($candidate);
        $this->assertSame(7.4, $candidate->currentEnergyPriceCentsPerKwh);
        $this->assertSame(4.2, $candidate->monthlyFeeEur);

        $feeVariants = $this->fixture;
        $feeVariants['pricing']['phases'][0]['components'][5]['amount'] = 5.2;
        $variantCandidate = (new SupplierAdjustedEligibility)->candidate(
            'fee-variants',
            $this->data($feeVariants),
            $this->context(),
        );

        $this->assertNotNull($variantCandidate);
        $this->assertSame(5.2, $variantCandidate->monthlyFeeEur);
    }

    public function test_named_production_shapes_are_eligible_with_stable_representative_rates(): void
    {
        $examples = [
            [
                'akhbwv-parikkalan-valo-oy-q-valo',
                $this->tariffFixture('unknown', ['energy_general' => 7.53], 4.65),
                new ContractContext('FixedPrice', 'OpenEnded', 'General', null, 'Household'),
                7.53,
            ],
            [
                'gxeryx-parikkalan-valo-oy-kesto-valo-kanta-asiakas',
                $this->tariffFixture('contract_start', ['energy_day' => 8.0, 'energy_night' => 4.0], 4.65),
                new ContractContext('FixedPrice', 'OpenEnded', 'Time', null, 'Household'),
                6.5,
            ],
            [
                'jrrlvh-parikkalan-valo-oy-kesto-valo-kanta-asiakas',
                $this->tariffFixture('date', ['energy_seasonal_winter' => 12.0, 'energy_seasonal_other' => 4.0], 4.65),
                new ContractContext('FixedPrice', 'OpenEnded', 'Season', null, 'Household'),
                22 / 3,
            ],
        ];

        foreach ($examples as [$id, $fixture, $context, $expectedRate]) {
            $candidate = (new SupplierAdjustedEligibility)->candidate($id, $this->data($fixture), $context);

            $this->assertNotNull($candidate, $id);
            $this->assertSame($id, $candidate->contractId);
            $this->assertEqualsWithDelta($expectedRate, $candidate->currentEnergyPriceCentsPerKwh, 0.0001);
            $this->assertSame(4.65, $candidate->monthlyFeeEur);
        }
    }

    public function test_time_and_season_offsets_are_additive_and_exact_rates_stay_unchanged(): void
    {
        $anchor = new PriceEpisodeAnchor(
            CarbonImmutable::parse('2026-06-01', 'Europe/Helsinki'),
            PriceEpisodeEvidenceBasis::ObservedSellerSnapshotRun,
        );
        $shiftedCurve = new SupplierAdjustedFakeCurve(
            reference: ['month' => 5.0],
            forward: $this->flatForward(9.0),
        );

        $cases = [
            [
                $this->tariffFixture('contract_start', ['energy_day' => 8.0, 'energy_night' => 4.0], 4.65),
                new ContractContext('FixedPrice', 'OpenEnded', 'Time', null, 'Household'),
                6.5,
                5000 - (5000 / 12),
            ],
            [
                $this->tariffFixture('contract_start', ['energy_seasonal_winter' => 12.0, 'energy_seasonal_other' => 4.0], 4.65),
                new ContractContext('FixedPrice', 'OpenEnded', 'Season', null, 'Household'),
                22 / 3,
                5000 - ((5000 / 12) * (12 / 13.5)),
            ],
        ];

        foreach ($cases as [$fixture, $context, $representative, $tailKwh]) {
            $shifted = $this->calculateFixture($fixture, $context, $shiftedCurve, $anchor);
            $held = $this->calculateFixture($fixture, $context, new SupplierAdjustedFakeCurve(tradeDate: null), $anchor);

            $this->assertSame(ContractComparability::ComparableEstimate, $shifted->comparability);
            $this->assertEqualsWithDelta($representative + (4 * $tailKwh / 5000), $shifted->supplierAdjustedEstimate['annual_equivalent_energy_price'], 0.001);
            $this->assertEqualsWithDelta($tailKwh * 4 / 100, $shifted->totalCost - $held->totalCost, 0.001);
            $this->assertEqualsWithDelta($held->monthlyCosts[0], $shifted->monthlyCosts[0], 0.001);
            $this->assertEqualsWithDelta($shifted->totalCost, $shifted->baseTotalCost, 0.001);
            $this->assertEqualsWithDelta($shifted->totalCost, $shifted->structuredOnlyTotal, 0.001);
        }

        $time = $this->calculateFixture($cases[0][0], $cases[0][1], $shiftedCurve, $anchor);
        $this->assertSame(8.0, $time->daytimeKwhPrice);
        $this->assertSame(4.0, $time->nighttimeKwhPrice);

        $season = $this->calculateFixture($cases[1][0], $cases[1][1], $shiftedCurve, $anchor);
        $this->assertSame(12.0, $season->seasonalWinterDayKwhPrice);
        $this->assertSame(4.0, $season->seasonalOtherKwhPrice);
    }

    public function test_exact_period_time_and_season_prices_stay_factual(): void
    {
        $cases = [
            [
                $this->tariffFixture('contract_start', ['energy_day' => 8.0, 'energy_night' => 4.0], 4.65),
                new ContractContext('FixedPrice', 'OpenEnded', 'Time', null, 'Household'),
                78.805,
            ],
            [
                $this->tariffFixture('contract_start', ['energy_seasonal_winter' => 12.0, 'energy_seasonal_other' => 4.0], 4.65),
                new ContractContext('FixedPrice', 'OpenEnded', 'Season', null, 'Household'),
                44.805,
            ],
        ];
        $anchor = new PriceEpisodeAnchor(
            CarbonImmutable::parse('2026-06-01', 'Europe/Helsinki'),
            PriceEpisodeEvidenceBasis::ObservedSellerSnapshotRun,
        );
        $curve = new SupplierAdjustedFakeCurve(reference: ['month' => 5.0], forward: $this->flatForward(9.0));

        foreach ($cases as [$fixture, $context, $expectedPeriodTotal]) {
            $data = $this->data($fixture);
            $annual = $this->calculateFixture($fixture, $context, $curve, $anchor);
            $period = $this->calculator($curve)->calculatePeriod(
                $data,
                $context,
                new CanonicalPeriodPricingRequest(
                    startDate: CarbonImmutable::parse('2026-07-01', 'Europe/Helsinki'),
                    endDate: CarbonImmutable::parse('2026-07-31', 'Europe/Helsinki'),
                    periodKwh: 1000,
                    annualizedKwh: 12000,
                    historicalSpotPrices: [],
                ),
                new SpotAssumptions(null, null),
                $annual,
            );

            $this->assertEqualsWithDelta($expectedPeriodTotal, $period->periodTotal, 0.001);
            $this->assertNotContains('supplier_adjusted_tail_shifted_on_forward_curve', $period->assumptions);
        }
    }

    public function test_identical_duplicate_energy_values_are_allowed_but_conflicts_are_excluded(): void
    {
        $fixture = $this->tariffFixture('contract_start', ['energy_day' => 8.0, 'energy_night' => 4.0], 4.65);
        $fixture['pricing']['phases'][0]['components'][] = $fixture['pricing']['phases'][0]['components'][0];
        $context = new ContractContext('FixedPrice', 'OpenEnded', 'Time', null, 'Household');

        $this->assertNotNull((new SupplierAdjustedEligibility)->candidate('duplicate', $this->data($fixture), $context));

        $fixture['pricing']['phases'][0]['components'][3]['amount'] = 8.5;
        $this->assertNull((new SupplierAdjustedEligibility)->candidate('conflict', $this->data($fixture), $context));
    }

    public function test_eligibility_excludes_other_contract_semantics(): void
    {
        $eligibility = new SupplierAdjustedEligibility;
        $baseData = $this->data($this->fixture);
        foreach ([
            new ContractContext('FixedPrice', 'FixedTerm', 'General', 'Fixed12', 'Household'),
            new ContractContext('Spot', 'OpenEnded', 'General', null, 'Household'),
            new ContractContext('Hybrid', 'OpenEnded', 'General', null, 'Household'),
            new ContractContext('FixedPrice', 'OpenEnded', 'Time', null, 'Household'),
        ] as $context) {
            $this->assertNull($eligibility->candidate('excluded', $baseData, $context));
        }

        foreach (['recurring', 'consumption', 'future', 'package', 'normal_amount', 'spot_margin'] as $case) {
            $fixture = $this->fixture;
            if ($case === 'recurring') {
                $fixture['pricing']['recurring_schedule']['present'] = true;
                $fixture['pricing']['recurring_schedule']['cadence'] = 'monthly';
            } elseif ($case === 'consumption') {
                $fixture['pricing']['consumption_effect']['present'] = true;
            } elseif ($case === 'future') {
                $future = $fixture['pricing']['phases'][0];
                $future['phase_kind'] = 'future';
                $fixture['pricing']['phases'][] = $future;
            } elseif ($case === 'package') {
                $fixture['pricing']['phases'][0]['package'] = [
                    'monthly_fee_eur' => 20.0,
                    'included_kwh' => 100.0,
                    'allowance_cadence' => 'monthly',
                    'excess_rate_cents_per_kwh' => 10.0,
                    'evidence' => [],
                ];
                $fixture['pricing']['phases'][0]['components'] = [];
            } elseif ($case === 'normal_amount') {
                $fixture['pricing']['phases'][0]['components'][0]['normal_amount'] = 8.0;
            } else {
                $fixture['pricing']['phases'][0]['components'][0]['component_type'] = 'spot_margin';
            }
            $this->assertNull($eligibility->candidate($case, $this->data($fixture), $this->context()), $case);
        }
    }

    private function calculate(SupplierAdjustedFakeCurve $curve, PriceEpisodeAnchor $anchor)
    {
        return $this->calculateFixture($this->fixture, $this->context(), $curve, $anchor);
    }

    private function calculateFixture(
        array $fixture,
        ContractContext $context,
        SupplierAdjustedFakeCurve $curve,
        PriceEpisodeAnchor $anchor,
    ) {
        return $this->calculator($curve)->calculate(
            $this->data($fixture),
            $context,
            new EnergyUsage(total: 5000, basicLiving: 5000),
            new SpotAssumptions(null, null),
            CarbonImmutable::parse('2026-07-01', 'Europe/Helsinki'),
            $anchor,
        );
    }

    /** @param array<string, float> $energyRates */
    private function tariffFixture(string $startsKind, array $energyRates, float $monthlyFee): array
    {
        $fixture = $this->fixture;
        $phase = &$fixture['pricing']['phases'][0];
        $phase['starts'] = [
            'kind' => $startsKind,
            'value' => $startsKind === 'date' ? '2026-06-01' : null,
        ];

        $energyTemplate = $phase['components'][0];
        $feeTemplate = $phase['components'][1];
        $components = [];
        foreach ($energyRates as $type => $amount) {
            $component = $energyTemplate;
            $component['component_type'] = $type;
            $component['amount'] = $amount;
            $components[] = $component;
        }
        $feeTemplate['amount'] = $monthlyFee;
        $components[] = $feeTemplate;
        $phase['components'] = $components;

        return $fixture;
    }

    private function calculator(MarketReferenceCurveProvider $curve): CanonicalContractPriceCalculator
    {
        $settings = new ResetEstimatorSettings(enabled: false);

        return new CanonicalContractPriceCalculator(
            new MarketResetPriceEstimator($curve, $settings),
            new SupplierAdjustedPriceEstimator($curve, $settings),
        );
    }

    private function data(array $fixture)
    {
        return $this->parser->parse($fixture['pricing'], $fixture['calculation'], $fixture['source_consistency']);
    }

    private function context(): ContractContext
    {
        return new ContractContext('FixedPrice', 'OpenEnded', 'General', null, 'Household');
    }

    /** @return array<string, float> */
    private function flatForward(float $price): array
    {
        $prices = [];
        $month = CarbonImmutable::parse('2026-01-01', 'Europe/Helsinki');
        for ($offset = 0; $offset < 24; $offset++) {
            $prices[$month->addMonthsNoOverflow($offset)->format('Y-m')] = $price;
        }

        return $prices;
    }
}

class SupplierAdjustedFakeCurve implements MarketReferenceCurveProvider
{
    public function __construct(
        private readonly ?string $tradeDate = '2026-06-30',
        private readonly array $reference = [],
        private readonly array $forward = [],
        private readonly ?array $seasonalIndex = null,
    ) {}

    public function tradeDate(CarbonImmutable $asOfDate): ?CarbonImmutable
    {
        return $this->tradeDate === null ? null : CarbonImmutable::parse($this->tradeDate, 'Europe/Helsinki');
    }

    public function referencePrice(CarbonImmutable $asOfDate, CarbonImmutable $anchorMonth, array $kindPreference): ?array
    {
        foreach ($kindPreference as $kind) {
            if (isset($this->reference[$kind])) {
                return [
                    'kind' => $kind,
                    'price_cents_per_kwh' => $this->reference[$kind],
                    'trade_date' => $this->tradeDate ?? '',
                ];
            }
        }

        return null;
    }

    public function forwardPriceForMonth(CarbonImmutable $asOfDate, CarbonImmutable $deliveryMonth): ?array
    {
        $price = $this->forward[$deliveryMonth->format('Y-m')] ?? null;

        return $price === null ? null : ['kind' => 'month', 'price_cents_per_kwh' => $price];
    }

    public function spotSeasonalIndex(): ?array
    {
        return $this->seasonalIndex;
    }

    public function fixedTermMedianEnergyPrice(): ?float
    {
        return null;
    }
}
