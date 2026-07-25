<?php

namespace Tests\Feature;

use App\Models\ElectricityFuturesEodPrice;
use App\Services\RetailPremium\VintageAwareReferencePriceService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class VintageAwareReferencePriceServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_single_delivery_lookup_returns_every_available_candidate_at_prior_vintage(): void
    {
        config()->set('price_forecasting.fixed_term.vat_multiplier', 1.255);
        $this->future('month', '202607', '2026-06-30', 40.0);
        $this->future('quarter', '202607', '2026-06-30', 50.0);
        $this->future('year', '202601', '2026-06-30', 60.0);
        $this->future('month', '202607', '2026-07-01', 99.0);

        $references = app(VintageAwareReferencePriceService::class)->forDeliveryMonth(
            CarbonImmutable::parse('2026-07-01'),
            CarbonImmutable::parse('2026-07-15'),
        );

        $this->assertSame(['month', 'quarter', 'year'], $references->keys()->all());
        $this->assertSame('2026-06-30', $references['month']['trade_date']);
        $this->assertEqualsWithDelta(4.0, $references['month']['price_cents_per_kwh_excluding_vat'], 0.0001);
        $this->assertEqualsWithDelta(5.02, $references['month']['price_cents_per_kwh_including_vat'], 0.0001);
        $this->assertEqualsWithDelta(5.0, $references['quarter']['price_cents_per_kwh_excluding_vat'], 0.0001);
        $this->assertEqualsWithDelta(6.0, $references['year']['price_cents_per_kwh_excluding_vat'], 0.0001);
    }

    public function test_term_lookup_keeps_month_quarter_year_and_existing_mixed_strip_candidates(): void
    {
        config()->set('price_forecasting.fixed_term.vat_multiplier', 1.255);
        $this->future('month', '202607', '2026-05-31', 40.0);
        $this->future('month', '202608', '2026-05-31', 50.0);
        $this->future('quarter', '202607', '2026-05-31', 60.0);
        $this->future('year', '202601', '2026-05-31', 70.0);

        $references = app(VintageAwareReferencePriceService::class)->forDeliveryTerm(
            CarbonImmutable::parse('2026-06-01'),
            2,
        );

        $this->assertSame(['month', 'quarter', 'year', 'term_strip'], $references->keys()->all());
        $weightedMonth = (31 * 40.0 + 31 * 50.0) / 62 / 10.0 * 1.255;
        $this->assertEqualsWithDelta($weightedMonth, $references['month']['price_cents_per_kwh_including_vat'], 0.0001);
        $this->assertEqualsWithDelta(7.53, $references['quarter']['price_cents_per_kwh_including_vat'], 0.0001);
        $this->assertEqualsWithDelta(8.785, $references['year']['price_cents_per_kwh_including_vat'], 0.0001);
        $this->assertEqualsWithDelta($weightedMonth, $references['term_strip']['price_cents_per_kwh_including_vat'], 0.0001);
        $this->assertEqualsWithDelta(7.0, app(VintageAwareReferencePriceService::class)->priceForVatBasis($references['year'], 'excluded'), 0.0001);
        $this->assertNull(app(VintageAwareReferencePriceService::class)->priceForVatBasis($references['year'], 'unknown'));
        $this->assertNull(app(VintageAwareReferencePriceService::class)->priceForVatBasis([
            'price_cents_per_kwh_including_vat' => null,
            'price_cents_per_kwh_excluding_vat' => null,
        ], 'included'));
    }

    private function future(string $maturityType, string $maturity, string $tradeDate, float $settlementPrice): void
    {
        ElectricityFuturesEodPrice::create([
            'exchange' => 'EEX',
            'commodity' => 'POWER',
            'pricing' => 'F',
            'product' => 'Base',
            'area' => 'FI',
            'short_code' => match ($maturityType) {
                'month' => 'FNBM',
                'quarter' => 'FNBQ',
                'year' => 'FNBY',
            },
            'maturity' => $maturity,
            'maturity_type' => $maturityType,
            'trade_date' => $tradeDate,
            'settlement_price' => $settlementPrice,
        ]);
    }
}
