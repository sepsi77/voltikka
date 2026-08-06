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

    public function test_sulaketariffi_fixture_accepts_five_identical_fees_and_rejects_conflicts(): void
    {
        $data = $this->data($this->fixture);
        $candidate = (new SupplierAdjustedEligibility)->candidate('sulake', $data, $this->context());

        $this->assertNotNull($candidate);
        $this->assertSame(7.4, $candidate->currentEnergyPriceCentsPerKwh);
        $this->assertSame(4.2, $candidate->monthlyFeeEur);

        $conflicting = $this->fixture;
        $conflicting['pricing']['phases'][0]['components'][5]['amount'] = 5.2;
        $this->assertNull((new SupplierAdjustedEligibility)->candidate(
            'conflict',
            $this->data($conflicting),
            $this->context(),
        ));
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
        return $this->calculator($curve)->calculate(
            $this->data($this->fixture),
            $this->context(),
            new EnergyUsage(total: 5000, basicLiving: 5000),
            new SpotAssumptions(null, null),
            CarbonImmutable::parse('2026-07-01', 'Europe/Helsinki'),
            $anchor,
        );
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
