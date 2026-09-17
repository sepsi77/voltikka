<?php

namespace Tests\Unit\CanonicalPricing;

use App\Services\CanonicalPricing\CanonicalContractPriceCalculator;
use App\Services\CanonicalPricing\CanonicalPricingParser;
use App\Services\CanonicalPricing\DTO\CanonicalPeriodPricingRequest;
use App\Services\CanonicalPricing\DTO\ContractContext;
use App\Services\CanonicalPricing\DTO\HistoricalSpotPrice;
use App\Services\CanonicalPricing\DTO\SpotAssumptions;
use App\Services\CanonicalPricing\MarketReset\DTO\ResetEstimatorSettings;
use App\Services\CanonicalPricing\MarketReset\MarketReferenceCurveProvider;
use App\Services\CanonicalPricing\MarketReset\MarketResetPriceEstimator;
use App\Services\CanonicalPricing\SpotForward\DTO\SpotEstimate;
use App\Services\CanonicalPricing\SpotForward\Enums\SpotEstimateBasis;
use App\Services\CanonicalPricing\SupplierAdjusted\DTO\PriceEpisodeAnchor;
use App\Services\CanonicalPricing\SupplierAdjusted\Enums\PriceEpisodeEvidenceBasis;
use App\Services\CanonicalPricing\SupplierAdjusted\SupplierAdjustedPriceEstimator;
use App\Services\DTO\EnergyUsage;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\TestCase;

class VatIntegrationTest extends TestCase
{
    private function calculator(): CanonicalContractPriceCalculator
    {
        $curve = $this->createMock(MarketReferenceCurveProvider::class);
        $curve->method('tradeDate')->willReturn(CarbonImmutable::parse('2026-06-30'));
        $curve->method('referencePrice')->willReturn(['kind' => 'month', 'price_cents_per_kwh' => 6.275, 'trade_date' => '2026-05-31']);
        $curve->method('forwardPriceForMonth')->willReturn(['kind' => 'month', 'price_cents_per_kwh' => 11.295]);
        $settings = new ResetEstimatorSettings(enabled: true);

        return new CanonicalContractPriceCalculator(new MarketResetPriceEstimator($curve, $settings), new SupplierAdjustedPriceEstimator($curve, $settings));
    }

    private function data(string $vat = 'excluded', string $type = 'energy_general', bool $reset = false, bool $discount = false)
    {
        $components = [[
            'component_type' => $type, 'unit' => 'cents_per_kwh', 'price_role' => 'current',
            'amount' => $type === 'spot_margin' ? 1 : 8, 'normal_amount' => $discount ? 10 : null, 'vat_status' => $vat,
        ]];
        if ($discount) {
            $components[] = ['component_type' => 'monthly_fee', 'unit' => 'eur_per_month', 'price_role' => 'normal', 'amount' => 1.255, 'normal_amount' => 2.51, 'vat_status' => 'included'];
            $components[] = ['component_type' => 'flat_fee', 'unit' => 'eur_flat', 'price_role' => 'normal', 'amount' => 1, 'normal_amount' => 2, 'vat_status' => 'unknown'];
        }

        // The VAT comparison uses a complete first-year offer with disclosed normal amounts.
        $ends = $discount ? ['kind' => 'after_months', 'value' => '12'] : ['kind' => 'none'];

        return (new CanonicalPricingParser)->parse(['phases' => [[
            'label' => 'Current', 'phase_kind' => 'current_structured', 'starts' => ['kind' => 'contract_start'], 'ends' => $ends, 'components' => $components,
        ]], 'recurring_schedule' => ['present' => $reset, 'cadence' => $reset ? 'monthly' : 'none', 'future_price_known' => false]], ['status' => 'exact'], ['structured_pricing_status' => 'complete']);
    }

    private function context(?string $target, string $model = 'FixedPrice', string $term = 'FixedTerm'): ContractContext
    {
        return new ContractContext($model, $term, 'General', 'Fixed12', $target);
    }

    public function test_all_audiences_and_explicit_or_unknown_components_use_one_basis_for_actual_and_normal(): void
    {
        $calculator = $this->calculator();
        foreach (['Household', 'Both', null, 'Company'] as $target) {
            foreach (['included', 'excluded', 'unknown'] as $vat) {
                $context = $this->context($target);
                $factor = $vat === 'unknown' ? 1 : ($context->includesVat() ? ($vat === 'excluded' ? 1.255 : 1) : ($vat === 'included' ? 1 / 1.255 : 1));
                $fee = $context->includesVat() ? 1.255 : 1;
                $out = $calculator->calculate($this->data($vat, discount: true), $context, new EnergyUsage(total: 5000, basicLiving: 5000), new SpotAssumptions(6.275, 6.275), CarbonImmutable::parse('2026-07-01'));
                $this->assertEqualsWithDelta(400 * $factor + 12 * $fee + 1, $out->totalCost, 1e-8);
                $this->assertEqualsWithDelta(500 * $factor + 24 * $fee + 2, $out->baseTotalCost, 1e-8);
                $this->assertEqualsWithDelta(100 * $factor + 12 * $fee + 1, $out->discountSavingsTotal(), 1e-8);
                $this->assertSame($context->includesVat() ? 'included' : 'excluded', $out->toCalculatedCostArray()['vat_basis']);
                $this->assertEqualsWithDelta(8 * $factor, $calculator->directGeneralRate($this->data($vat), $context, '2026-07-01'), 1e-8);
            }
        }
    }

    public function test_reset_and_supplier_curves_use_the_bill_basis(): void
    {
        foreach ([true, false] as $reset) {
            foreach (['Company', 'Household'] as $target) {
                $context = $this->context($target, term: 'OpenEnded');
                $data = $this->data(reset: $reset);
                $out = $this->calculator()->calculate($data, $context, new EnergyUsage(total: 5000, basicLiving: 5000), new SpotAssumptions(6.275, 6.275), CarbonImmutable::parse('2026-07-01'), new PriceEpisodeAnchor(CarbonImmutable::parse('2026-06-01'), PriceEpisodeEvidenceBasis::CurrentSourceObservation));
                $factor = $target === 'Company' ? 1 : 1.255;
                $this->assertEqualsWithDelta((400 + 5000 * 11 / 12 * 4 / 100) * $factor, $out->totalCost, 1e-8);
                $evidence = $reset ? $out->resetEstimate : $out->supplierAdjustedEstimate;
                $this->assertEqualsWithDelta(5 * $factor, $evidence['reference_price'], 1e-8);
            }
        }
    }

    public function test_spot_copies_preserve_shared_evidence_and_company_forward_is_enabled(): void
    {
        $spot = new SpotAssumptions(6.275, 6.275, 6.275, actualHours: 8760, expectedHours: 8760);
        $estimate = new SpotEstimate(SpotEstimateBasis::ForwardCurve, 6.275, 6.275, 6.275, 0, 0, '2025-07-01', '2026-06-30', '2026-06-30', '2026-06-30', [], 6.275, 6.275, 6.275, 'higher', flags: ['zero_intraday_shape_fallback'], shapeCoverage: $spot->coverage());
        $copy = $estimate->withVatBasis(false, 1.255);
        $this->assertSame($copy, $copy->withVatBasis(false, 1.255));
        $this->assertSame($estimate->shapeCoverage, $copy->shapeCoverage);
        $this->assertSame(6.275, $estimate->annualEquivalentDayCentsPerKwh);
        foreach (['Company', 'Household'] as $target) {
            $out = $this->calculator()->calculate($this->data(type: 'spot_margin'), $this->context($target, 'Spot'), new EnergyUsage(total: 5000, basicLiving: 5000), $spot, CarbonImmutable::parse('2026-07-01'), spotEstimate: $estimate);
            $this->assertEqualsWithDelta(300 * ($target === 'Company' ? 1 : 1.255), $out->totalCost, 1e-8);
            $this->assertNotNull($out->spotEstimate);
            $this->assertContains('spot_forward_curve_flat_baseload_shape', $out->assumptions);
            $this->assertNotContains('spot_forward_curve_with_rolling_365_intraday_shape', $out->assumptions);
        }
        $this->assertSame(6.275, $spot->dayAvgWithTax);
        $this->assertSame($spot->coverage(), $spot->withVatBasis(false, 1.255)->coverage());
    }

    public function test_exact_period_uses_hourly_evidence_and_does_not_guess_old_vat(): void
    {
        foreach (['2026-07-01', '2023-07-01'] as $date) {
            foreach ([null, 5.0] as $exclusive) {
                $start = CarbonImmutable::parse($date, 'Europe/Helsinki');
                $history = [new HistoricalSpotPrice($start->utc(), 6.275, $exclusive)];
                $request = new CanonicalPeriodPricingRequest($start, $start, 24, 5000, $history);
                $calculator = $this->calculator();
                $data = $this->data(type: 'spot_margin');
                $context = $this->context('Company', 'Spot');
                $spot = new SpotAssumptions(6.275, 6.275);
                $annual = $calculator->calculate($data, $context, new EnergyUsage(total: 5000, basicLiving: 5000), $spot, $start);
                $period = $calculator->calculatePeriod($data, $context, $request, $spot, $annual);
                if ($date === '2023-07-01' && $exclusive === null) {
                    $this->assertFalse($period->isAvailable());
                } else {
                    $this->assertEqualsWithDelta(1.44, $period->periodTotal, 1e-8);
                }
            }
        }
    }
}
