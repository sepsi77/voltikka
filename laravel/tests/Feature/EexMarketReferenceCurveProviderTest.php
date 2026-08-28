<?php

namespace Tests\Feature;

use App\Models\ContractPriceDailyStatistic;
use App\Models\ElectricityFuturesEodPrice;
use App\Models\SpotPriceAverage;
use App\Services\CanonicalPricing\MarketReset\MarketReferenceCurveProvider;
use Carbon\CarbonImmutable;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Pins the market-data reads behind the reset estimator, above all the two-vintage rule: every
 * lookup resolves the latest `trade_date` strictly before its own `$asOfDate`, and the estimator
 * passes today for `F_m` but the current period's start for `F_reference`.
 */
class EexMarketReferenceCurveProviderTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('price_forecasting.fixed_term.vat_multiplier', 1.255);
    }

    public function test_many_as_of_dates_use_one_available_trade_date_query_and_keep_strict_vintages(): void
    {
        $this->future('month', '202607', '2026-06-29', 31.0);
        $this->future('month', '202607', '2026-06-30', 32.0);
        $this->future('month', '202607', '2026-07-24', 24.0);

        $queries = [];
        DB::listen(function (QueryExecuted $query) use (&$queries): void {
            $queries[] = $query;
        });

        $provider = app(MarketReferenceCurveProvider::class);
        $anchor = CarbonImmutable::parse('2026-07-01');
        $references = [];

        for ($offset = 0; $offset < 40; $offset++) {
            $asOf = CarbonImmutable::parse('2026-06-25')->addDays($offset);
            $references[$asOf->toDateString()] = $provider->referencePrice($asOf, $anchor, ['month']);
        }

        $this->assertSame('2026-06-29', $references['2026-06-30']['trade_date']);
        $this->assertSame('2026-06-30', $references['2026-07-01']['trade_date']);
        $this->assertCount(1, $this->availableTradeDateQueries($queries));
        $this->assertCount(0, $this->latestTradeDateQueries($queries));
    }

    public function test_repeated_no_curve_lookups_do_not_requery_available_trade_dates(): void
    {
        $queries = [];
        DB::listen(function (QueryExecuted $query) use (&$queries): void {
            $queries[] = $query;
        });

        $provider = app(MarketReferenceCurveProvider::class);
        $asOf = CarbonImmutable::parse('2026-07-25');
        $anchor = CarbonImmutable::parse('2026-07-01');

        for ($attempt = 0; $attempt < 5; $attempt++) {
            $this->assertNull($provider->tradeDate($asOf));
            $this->assertNull($provider->referencePrice($asOf, $anchor, ['month']));
            $this->assertNull($provider->forwardPriceForMonth($asOf, $anchor));
        }

        $this->assertCount(1, $this->availableTradeDateQueries($queries));
        $this->assertCount(1, $this->futuresSelectQueries($queries));
    }

    public function test_plain_sqlite_trade_date_is_available_to_reference_and_curve_lookups(): void
    {
        $this->future('month', '202607', '2026-06-30', 40.0);

        DB::table('electricity_futures_eod_prices')
            ->where('maturity_type', 'month')
            ->where('maturity', '202607')
            ->update(['trade_date' => '2026-06-30']);

        $this->assertSame(
            '2026-06-30',
            DB::table('electricity_futures_eod_prices')->value('trade_date'),
        );

        $provider = app(MarketReferenceCurveProvider::class);
        $asOf = CarbonImmutable::parse('2026-07-01');
        $delivery = CarbonImmutable::parse('2026-07-01');

        $reference = $provider->referencePrice($asOf, $delivery, ['month']);
        $forward = $provider->forwardPriceForMonth($asOf, $delivery);

        $this->assertSame('2026-06-30', $reference['trade_date']);
        $this->assertEqualsWithDelta(5.02, $reference['price_cents_per_kwh'], 0.0001);
        $this->assertEqualsWithDelta(5.02, $forward['price_cents_per_kwh'], 0.0001);
    }

    public function test_month_only_reference_does_not_query_the_derived_quarter_months(): void
    {
        $this->future('month', '202607', '2026-06-30', 40.0);
        $this->future('month', '202608', '2026-06-30', 50.0);
        $this->future('month', '202609', '2026-06-30', 60.0);

        $queries = [];
        DB::listen(function (QueryExecuted $query) use (&$queries): void {
            $queries[] = $query;
        });

        $reference = app(MarketReferenceCurveProvider::class)->referencePrice(
            CarbonImmutable::parse('2026-07-01'),
            CarbonImmutable::parse('2026-07-01'),
            ['month'],
        );

        $this->assertSame('month', $reference['kind']);
        $this->assertCount(2, $this->futuresSelectQueries($queries));
        $this->assertFalse(collect($queries)->contains(function (QueryExecuted $query): bool {
            $bindings = array_map('strval', $query->bindings);

            return str_contains(strtolower($query->sql), 'electricity_futures_eod_prices')
                && count(array_intersect(['202607', '202608', '202609'], $bindings)) === 3;
        }));
    }

    public function test_each_lookup_resolves_its_own_vintage_and_never_leaks_the_same_day(): void
    {
        $provider = app(MarketReferenceCurveProvider::class);
        $today = CarbonImmutable::parse('2026-07-25');
        $periodStart = CarbonImmutable::parse('2026-07-01');

        // The July contract before its delivery month started, and after it had largely converged.
        $this->future('month', '202607', '2026-06-30', 32.13);
        $this->future('month', '202607', '2026-07-24', 19.53);
        $this->future('month', '202609', '2026-07-24', 87.05);
        // Same-day settlements must never leak into a lookup anchored on that same day.
        $this->future('month', '202607', '2026-07-25', 1.0);

        $this->assertSame('2026-07-24', $provider->tradeDate($today)?->toDateString());
        $this->assertSame('2026-06-30', $provider->tradeDate($periodStart)?->toDateString());

        // F_reference reads the pricing vintage: 32,13 EUR/MWh, not the converged 19,53.
        $reference = $provider->referencePrice($periodStart, $periodStart, ['month']);
        // F_m reads today's vintage.
        $forward = $provider->forwardPriceForMonth($today, CarbonImmutable::parse('2026-09-01'));

        $this->assertSame('month', $reference['kind']);
        $this->assertSame('2026-06-30', $reference['trade_date']);
        $this->assertEqualsWithDelta(32.13 / 10 * 1.255, $reference['price_cents_per_kwh'], 0.0001);
        $this->assertEqualsWithDelta(87.05 / 10 * 1.255, $forward['price_cents_per_kwh'], 0.0001);
    }

    public function test_a_quarter_not_yet_in_delivery_resolves_to_the_direct_quarter_contract(): void
    {
        // At the pricing vintage of a Q3 period (30 June, before Q3 starts) EEX still publishes the
        // Q3 contract, so the primary `quarter` candidate resolves. This is the case the derived
        // month-average exists to cover only once the quarter has entered delivery.
        $provider = app(MarketReferenceCurveProvider::class);

        $this->future('quarter', '202607', '2026-06-30', 47.20);
        $this->future('month', '202607', '2026-06-30', 32.13);
        $this->future('month', '202608', '2026-06-30', 43.51);
        $this->future('month', '202609', '2026-06-30', 66.57);

        $reference = $provider->referencePrice(
            CarbonImmutable::parse('2026-07-01'),
            CarbonImmutable::parse('2026-07-01'),
            ['quarter', 'quarter_month_average'],
        );

        $this->assertSame('quarter', $reference['kind']);
        $this->assertEqualsWithDelta(47.20 / 10 * 1.255, $reference['price_cents_per_kwh'], 0.0001);
    }

    public function test_no_curve_before_the_period_start_reports_no_pricing_vintage(): void
    {
        // The estimator uses this to decide whether to fall back to today's vintage and flag it.
        $provider = app(MarketReferenceCurveProvider::class);

        $this->future('month', '202607', '2026-07-24', 19.53);

        $this->assertNull($provider->tradeDate(CarbonImmutable::parse('2026-04-01')));
        $this->assertSame('2026-07-24', $provider->tradeDate(CarbonImmutable::parse('2026-07-25'))?->toDateString());
    }

    public function test_forward_price_falls_back_month_then_quarter_then_year(): void
    {
        $provider = app(MarketReferenceCurveProvider::class);
        $asOf = CarbonImmutable::parse('2026-07-25');

        $this->future('month', '202701', '2026-07-24', 100.43);
        $this->future('quarter', '202704', '2026-07-24', 40.42);
        $this->future('year', '202801', '2026-07-24', 48.23);

        $this->assertSame('month', $provider->forwardPriceForMonth($asOf, CarbonImmutable::parse('2027-01-01'))['kind']);
        $this->assertSame('quarter', $provider->forwardPriceForMonth($asOf, CarbonImmutable::parse('2027-05-01'))['kind']);
        $this->assertSame('year', $provider->forwardPriceForMonth($asOf, CarbonImmutable::parse('2028-06-01'))['kind']);
        $this->assertNull($provider->forwardPriceForMonth($asOf, CarbonImmutable::parse('2029-06-01')));
    }

    public function test_a_quarter_in_delivery_resolves_to_the_month_average_candidate(): void
    {
        // EEX stops publishing a quarter contract once that quarter enters delivery, so a vintage
        // inside the quarter has no direct `quarter` row. With the pricing-vintage rule this only
        // happens when the period's own start has no curve and the lookup falls back to today.
        $provider = app(MarketReferenceCurveProvider::class);
        $asOf = CarbonImmutable::parse('2026-07-25');

        $this->future('month', '202607', '2026-07-24', 19.53);
        $this->future('month', '202608', '2026-07-24', 41.64);
        $this->future('month', '202609', '2026-07-24', 87.05);

        $reference = $provider->referencePrice($asOf, CarbonImmutable::parse('2026-08-01'), ['quarter', 'quarter_month_average']);

        $this->assertSame('quarter_month_average', $reference['kind']);
        $expected = (19.53 * 31 + 41.64 * 31 + 87.05 * 30) / 92 / 10 * 1.255;
        $this->assertEqualsWithDelta($expected, $reference['price_cents_per_kwh'], 0.0001);
    }

    public function test_no_curve_at_all_returns_null_everywhere(): void
    {
        $provider = app(MarketReferenceCurveProvider::class);
        $asOf = CarbonImmutable::parse('2026-07-25');

        $this->assertNull($provider->tradeDate($asOf));
        $this->assertNull($provider->referencePrice($asOf, CarbonImmutable::parse('2026-07-01'), ['month']));
        $this->assertNull($provider->forwardPriceForMonth($asOf, CarbonImmutable::parse('2026-09-01')));
    }

    public function test_spot_seasonal_index_normalises_each_year_by_its_own_mean(): void
    {
        // Two years of a simple shape: winter twice the summer price. Normalising per year gives
        // an index of about 1,333 in winter and 0,667 in summer, whatever the price level is.
        foreach ([2024 => 1.0, 2025 => 10.0] as $year => $scale) {
            for ($month = 1; $month <= 12; $month++) {
                $winter = in_array($month, [1, 2, 3, 10, 11, 12], true);
                $this->monthlySpotAverage($year, $month, $scale * ($winter ? 2.0 : 1.0));
            }
        }

        $index = app(MarketReferenceCurveProvider::class)->spotSeasonalIndex();

        $this->assertNotNull($index);
        $this->assertCount(12, $index);
        $this->assertEqualsWithDelta(4 / 3, $index[1], 0.001);
        $this->assertEqualsWithDelta(2 / 3, $index[7], 0.001);
    }

    public function test_spot_seasonal_index_needs_enough_years_per_month(): void
    {
        for ($month = 1; $month <= 12; $month++) {
            $this->monthlySpotAverage(2025, $month, 5.0);
        }

        $this->assertNull(app(MarketReferenceCurveProvider::class)->spotSeasonalIndex());
    }

    public function test_fixed_term_median_reads_the_latest_statistic_row(): void
    {
        ContractPriceDailyStatistic::create([
            'stat_date' => '2026-07-23', 'segment_key' => 'fixed_term_12', 'metric_key' => 'energy_price',
            'consumption_kwh' => null, 'median_value' => 9.0, 'avg_value' => 9.1, 'contract_count' => 40,
        ]);
        ContractPriceDailyStatistic::create([
            'stat_date' => '2026-07-24', 'segment_key' => 'fixed_term_12', 'metric_key' => 'energy_price',
            'consumption_kwh' => null, 'median_value' => 10.4667, 'avg_value' => 10.4724, 'contract_count' => 49,
        ]);

        $this->assertEqualsWithDelta(10.4667, app(MarketReferenceCurveProvider::class)->fixedTermMedianEnergyPrice(), 0.0001);
    }

    /**
     * @param  list<QueryExecuted>  $queries
     * @return list<QueryExecuted>
     */
    private function futuresSelectQueries(array $queries): array
    {
        return array_values(array_filter($queries, fn (QueryExecuted $query): bool => str_starts_with(strtolower(ltrim($query->sql)), 'select')
            && str_contains(strtolower($query->sql), 'electricity_futures_eod_prices')
        ));
    }

    /**
     * @param  list<QueryExecuted>  $queries
     * @return list<QueryExecuted>
     */
    private function availableTradeDateQueries(array $queries): array
    {
        return array_values(array_filter($this->futuresSelectQueries($queries), function (QueryExecuted $query): bool {
            $sql = strtolower($query->sql);
            $bindings = array_map('strval', $query->bindings);

            return str_contains($sql, 'select distinct')
                && str_contains($sql, 'trade_date')
                && str_contains($sql, 'order by')
                && in_array('FI', $bindings, true)
                && in_array('Base', $bindings, true);
        }));
    }

    /**
     * @param  list<QueryExecuted>  $queries
     * @return list<QueryExecuted>
     */
    private function latestTradeDateQueries(array $queries): array
    {
        return array_values(array_filter($this->futuresSelectQueries($queries), fn (QueryExecuted $query): bool => str_contains(strtolower($query->sql), 'max(')
            && str_contains(strtolower($query->sql), 'trade_date')
        ));
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

    private function monthlySpotAverage(int $year, int $month, float $price): void
    {
        $start = CarbonImmutable::create($year, $month, 1);

        SpotPriceAverage::create([
            'region' => 'FI',
            'period_type' => SpotPriceAverage::PERIOD_MONTHLY,
            'period_start' => $start->toDateString(),
            'period_end' => $start->endOfMonth()->toDateString(),
            'avg_price_with_tax' => $price,
            'avg_price_without_tax' => $price / 1.255,
            'hours_count' => 24 * $start->daysInMonth,
        ]);
    }
}
