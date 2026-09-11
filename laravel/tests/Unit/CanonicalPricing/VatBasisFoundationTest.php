<?php

namespace Tests\Unit\CanonicalPricing;

use App\Services\CanonicalPricing\CanonicalPricingParser;
use App\Services\CanonicalPricing\MarketReset\DTO\ResetEstimateRequest;
use App\Services\CanonicalPricing\MarketReset\DTO\ResetEstimatorSettings;
use App\Services\CanonicalPricing\MarketReset\MarketReferenceCurveProvider;
use App\Services\CanonicalPricing\MarketReset\MarketResetPriceEstimator;
use App\Services\CanonicalPricing\SupplierAdjusted\DTO\PriceEpisodeAnchor;
use App\Services\CanonicalPricing\SupplierAdjusted\DTO\SupplierAdjustedEstimateRequest;
use App\Services\CanonicalPricing\SupplierAdjusted\Enums\PriceEpisodeEvidenceBasis;
use App\Services\CanonicalPricing\SupplierAdjusted\SupplierAdjustedPriceEstimator;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\TestCase;

class VatBasisFoundationTest extends TestCase
{
    public function test_mixed_components_normalize_without_changing_source_or_phase_metadata(): void
    {
        $components = [];
        foreach (['included', 'excluded', 'unknown'] as $status) {
            $components[] = [
                'component_type' => 'energy_general', 'amount' => 10, 'normal_amount' => 20,
                'unit' => 'cents_per_kwh', 'price_role' => 'normal', 'vat_status' => $status,
            ];
        }
        $components[] = [
            'component_type' => 'consumption_effect', 'amount' => -1, 'normal_amount' => null,
            'unit' => 'cents_per_kwh', 'price_role' => 'normal', 'vat_status' => 'excluded',
        ];
        $raw = ['phases' => [[
            'label' => 'Current', 'phase_kind' => 'normal',
            'starts' => ['kind' => 'contract_start'], 'ends' => ['kind' => 'none'],
            'components' => $components,
        ], [
            'label' => 'Package', 'phase_kind' => 'continuation',
            'starts' => ['kind' => 'after_months', 'value' => '6'], 'ends' => ['kind' => 'none'],
            'components' => [], 'package' => [
                'monthly_fee_eur' => 30, 'included_kwh' => 200,
                'allowance_cadence' => 'monthly', 'excess_rate_cents_per_kwh' => 8,
            ],
        ]]];
        $source = (new CanonicalPricingParser)->parse($raw, ['status' => 'exact'], []);
        foreach ([true, false] as $includeVat) {
            $data = $source->withVatBasis($includeVat, 1.255);
            $this->assertEquals($data, $data->withVatBasis($includeVat, 1.255));
            $this->assertSame($source->phases[0]->starts, $data->phases[0]->starts);
            $this->assertSame($source->phases[0]->ends, $data->phases[0]->ends);
            $this->assertSame('Current', $data->phases[0]->label);
            $this->assertSame($source->phases[1]->package, $data->phases[1]->package);
            $this->assertSame($source->consumptionEffect, $data->consumptionEffect);
            foreach ($data->phases[0]->components as $i => $component) {
                $this->assertSame($includeVat ? 'included' : 'excluded', $component->vatStatus);
                if ($i < 3) {
                    $factor = $i === 2 ? 1 : ($includeVat ? ($i === 1 ? 1.255 : 1) : ($i === 0 ? 1 / 1.255 : 1));
                    $this->assertEqualsWithDelta(10 * $factor, $component->amount, 1e-10);
                    $this->assertEqualsWithDelta(20 * $factor, $component->normalAmount, 1e-10);
                } else {
                    $this->assertFalse($component->isBilled());
                    $this->assertNull($component->normalAmount);
                }
            }
        }
        $this->assertSame(10.0, $source->phases[0]->components[1]->amount);
        $this->assertSame('excluded', $source->phases[0]->components[1]->vatStatus);
        $this->assertSame($components, $raw['phases'][0]['components']);
    }

    public function test_fees_margins_and_one_time_charges_use_the_same_conversion(): void
    {
        foreach ([['monthly_fee', 'eur_per_month'], ['flat_fee', 'eur_flat'], ['spot_margin', 'cents_per_kwh']] as [$type, $unit]) {
            $data = (new CanonicalPricingParser)->parse(['phases' => [[
                'label' => 'Current', 'phase_kind' => 'normal', 'components' => [[
                    'component_type' => $type, 'unit' => $unit, 'price_role' => 'normal',
                    'amount' => 12.55, 'normal_amount' => 25.1, 'vat_status' => 'included',
                ]],
            ]]], ['status' => 'exact'], []);
            $component = $data->withVatBasis(false, 1.255)->phases[0]->components[0];
            $this->assertEqualsWithDelta(10, $component->amount, 1e-10);
            $this->assertEqualsWithDelta(20, $component->normalAmount, 1e-10);
            $this->assertTrue($component->isBilled());
        }
    }

    public function test_seasonal_ratios_do_not_receive_a_vat_multiplier(): void
    {
        $date = CarbonImmutable::parse('2026-07-01', 'Europe/Helsinki');
        $curve = $this->createMock(MarketReferenceCurveProvider::class);
        $curve->method('tradeDate')->willReturn(null);
        $curve->method('spotSeasonalIndex')->willReturn([7 => 1.0, 8 => 1.5]);
        $settings = new ResetEstimatorSettings(enabled: true, beta: 0.5);
        $reset = (new MarketResetPriceEstimator($curve, $settings))->estimate(new ResetEstimateRequest(
            'monthly', $date, $date, $date, ['2026-08'], 8, ['2026-08' => 1], 1 / 1.255,
        ));
        $supplier = (new SupplierAdjustedPriceEstimator($curve, $settings))->estimate(new SupplierAdjustedEstimateRequest(
            $date, new PriceEpisodeAnchor($date, PriceEpisodeEvidenceBasis::Missing), ['2026-08'], 8, 4,
            ['2026-08' => 1], 1 / 1.255,
        ));
        foreach ([$reset, $supplier] as $estimate) {
            $this->assertSame(2.0, $estimate->offsetsByMonthKey['2026-08']);
            $this->assertNull($estimate->referencePriceCentsPerKwh);
        }
    }

    public function test_both_estimators_convert_market_prices_once_and_keep_vintages(): void
    {
        $asOf = CarbonImmutable::parse('2026-07-15', 'Europe/Helsinki');
        $anchor = $asOf->startOfMonth();
        $curve = $this->createMock(MarketReferenceCurveProvider::class);
        $curve->method('tradeDate')->willReturn($asOf->subDay());
        $curve->method('referencePrice')->willReturnCallback(function ($date) use ($anchor) {
            $this->assertEquals($anchor, $date);
            return ['kind' => 'month', 'price_cents_per_kwh' => 5 * 1.255, 'trade_date' => '2026-06-30'];
        });
        $curve->method('forwardPriceForMonth')->willReturnCallback(function ($date) use ($asOf) {
            $this->assertEquals($asOf, $date);
            return ['kind' => 'month', 'price_cents_per_kwh' => 9 * 1.255];
        });
        $settings = new ResetEstimatorSettings(enabled: true);
        foreach ([1.0, 1 / 1.255] as $multiplier) {
            $reset = (new MarketResetPriceEstimator($curve, $settings))->estimate(new ResetEstimateRequest(
                'monthly', $asOf, $anchor, $anchor, ['2026-08'], 8, ['2026-07' => 1, '2026-08' => 1], $multiplier,
            ));
            $supplier = (new SupplierAdjustedPriceEstimator($curve, $settings))->estimate(new SupplierAdjustedEstimateRequest(
                $asOf, new PriceEpisodeAnchor($anchor, PriceEpisodeEvidenceBasis::Missing), ['2026-08'], 8, 4,
                ['2026-07' => 1, '2026-08' => 1], $multiplier,
            ));
            foreach ([$reset, $supplier] as $estimate) {
                $this->assertEqualsWithDelta(5.02 * $multiplier, $estimate->offsetsByMonthKey['2026-08'], 1e-10);
                $this->assertEqualsWithDelta(6.275 * $multiplier, $estimate->referencePriceCentsPerKwh, 1e-10);
                $this->assertSame('2026-06-30', $estimate->referenceTradeDate);
                $this->assertSame('2026-07-14', $estimate->curveTradeDate);
            }
        }
        $this->assertSame(1.0, (new ResetEstimateRequest('monthly', $asOf, $anchor, $anchor, [], 8, []))->marketPriceMultiplier);
        $this->assertSame(1.0, (new SupplierAdjustedEstimateRequest($asOf, PriceEpisodeAnchor::missing(), [], 8, 4, []))->marketPriceMultiplier);
    }
}
