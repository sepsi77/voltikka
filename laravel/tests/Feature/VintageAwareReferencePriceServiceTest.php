<?php

namespace Tests\Feature;

use App\Models\ElectricityFuturesEodPrice;
use App\Services\RetailPremium\VintageAwareReferencePriceService;
use Carbon\CarbonImmutable;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
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

    public function test_exact_vintage_lookup_uses_the_given_trade_date_without_resolving_again(): void
    {
        $this->future('month', '202607', '2026-06-30', 40.0);
        $this->future('month', '202607', '2026-07-24', 99.0);

        $queries = [];
        DB::listen(function (QueryExecuted $query) use (&$queries): void {
            $queries[] = $query;
        });

        $references = app(VintageAwareReferencePriceService::class)->forResetPeriodAtTradeDate(
            CarbonImmutable::parse('2026-06-30'),
            CarbonImmutable::parse('2026-07-01'),
            ['month'],
        );

        $this->assertSame('2026-06-30', $references['month']['trade_date']);
        $this->assertEqualsWithDelta(4.0, $references['month']['price_cents_per_kwh_excluding_vat'], 0.0001);
        $this->assertCount(0, $this->latestTradeDateQueries($queries));
    }

    public function test_reset_period_lookup_resolves_the_vintage_once(): void
    {
        $this->future('month', '202607', '2026-06-30', 40.0);
        $this->future('month', '202608', '2026-06-30', 50.0);
        $this->future('month', '202609', '2026-06-30', 60.0);
        $this->future('quarter', '202607', '2026-06-30', 50.0);

        $queries = [];
        DB::listen(function (QueryExecuted $query) use (&$queries): void {
            $queries[] = $query;
        });

        $references = app(VintageAwareReferencePriceService::class)->forResetPeriod(
            CarbonImmutable::parse('2026-07-01'),
            CarbonImmutable::parse('2026-07-01'),
        );

        $this->assertSame(['month', 'quarter', 'quarter_month_average'], $references->keys()->all());
        $this->assertCount(1, $this->latestTradeDateQueries($queries));
    }

    public function test_reset_period_lookup_returns_the_published_quarter_before_delivery_starts(): void
    {
        config()->set('price_forecasting.fixed_term.vat_multiplier', 1.255);
        $this->future('month', '202607', '2026-06-30', 40.0);
        $this->future('quarter', '202607', '2026-06-30', 50.0);

        $references = app(VintageAwareReferencePriceService::class)->forResetPeriod(
            CarbonImmutable::parse('2026-07-01'),
            CarbonImmutable::parse('2026-07-01'),
        );

        $this->assertSame(['month', 'quarter'], $references->keys()->all());
        $this->assertEqualsWithDelta(5.0, $references['quarter']['price_cents_per_kwh_excluding_vat'], 0.0001);
        $this->assertSame('202607', $references['quarter']['metadata']['maturity']);
        $this->assertSame('2026-07-01', $references['quarter']['metadata']['delivery_start_month']);
        $this->assertSame('2026-09-01', $references['quarter']['metadata']['delivery_end_month']);
        $this->assertFalse($references['quarter']['metadata']['vintage_inside_delivery_period']);
    }

    public function test_reset_period_lookup_derives_a_quarter_from_month_futures_inside_delivery(): void
    {
        config()->set('price_forecasting.fixed_term.vat_multiplier', 1.255);
        $this->future('month', '202604', '2026-05-12', 30.0);
        $this->future('month', '202605', '2026-05-12', 40.0);
        $this->future('month', '202606', '2026-05-12', 50.0);

        $references = app(VintageAwareReferencePriceService::class)->forResetPeriod(
            CarbonImmutable::parse('2026-05-13'),
            CarbonImmutable::parse('2026-05-13'),
        );

        $this->assertSame(['month', 'quarter_month_average'], $references->keys()->all());
        $derived = $references['quarter_month_average'];
        $expected = (30 * 30.0 + 31 * 40.0 + 30 * 50.0) / 91 / 10.0;
        $this->assertEqualsWithDelta($expected, $derived['price_cents_per_kwh_excluding_vat'], 0.0001);
        $this->assertSame('day_weighted_month_futures_average', $derived['metadata']['derivation']);
        $this->assertSame(['202604', '202605', '202606'], $derived['metadata']['month_maturities']);
        $this->assertTrue($derived['metadata']['vintage_inside_delivery_period']);
    }

    public function test_reset_period_lookup_omits_a_derived_quarter_when_a_month_is_missing(): void
    {
        $this->future('month', '202605', '2026-05-12', 40.0);
        $this->future('month', '202606', '2026-05-12', 50.0);

        $references = app(VintageAwareReferencePriceService::class)->forResetPeriod(
            CarbonImmutable::parse('2026-05-13'),
            CarbonImmutable::parse('2026-05-13'),
        );

        $this->assertSame(['month'], $references->keys()->all());
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

    /**
     * @param  list<QueryExecuted>  $queries
     * @return list<QueryExecuted>
     */
    private function latestTradeDateQueries(array $queries): array
    {
        return array_values(array_filter($queries, function (QueryExecuted $query): bool {
            $sql = strtolower($query->sql);

            return str_contains($sql, 'electricity_futures_eod_prices')
                && str_contains($sql, 'max(')
                && str_contains($sql, 'trade_date');
        }));
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
