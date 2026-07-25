<?php

namespace Tests\Feature;

use App\Models\ContractPriceDailyStatistic;
use App\Models\ElectricityFuturesEodPrice;
use App\Models\SpotPriceAverage;
use App\Services\CanonicalPricing\MarketReset\MarketReferenceCurveProvider;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Pins the market-data reads behind the reset estimator, above all the one-vintage rule:
 * `F_m` and `F_reference` must come from the same settlement day, otherwise the shape-only
 * shift stops cancelling the curve level and reintroduces the drift it exists to remove.
 */
class EexMarketReferenceCurveProviderTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('price_forecasting.fixed_term.vat_multiplier', 1.255);
    }

    public function test_reference_and_forward_months_share_one_vintage_and_ignore_same_day_rows(): void
    {
        $provider = app(MarketReferenceCurveProvider::class);
        $asOf = CarbonImmutable::parse('2026-07-25');

        $this->future('month', '202607', '2026-07-24', 19.53);
        $this->future('month', '202609', '2026-07-24', 87.05);
        // Same-day settlements must never leak in, and an older vintage must never be mixed in.
        $this->future('month', '202607', '2026-07-25', 1.0);
        $this->future('month', '202609', '2026-07-23', 999.0);

        $this->assertSame('2026-07-24', $provider->tradeDate($asOf)?->toDateString());

        $reference = $provider->referencePrice($asOf, CarbonImmutable::parse('2026-07-01'), ['month']);
        $forward = $provider->forwardPriceForMonth($asOf, CarbonImmutable::parse('2026-09-01'));

        $this->assertSame('month', $reference['kind']);
        $this->assertEqualsWithDelta(19.53 / 10 * 1.255, $reference['price_cents_per_kwh'], 0.0001);
        $this->assertEqualsWithDelta(87.05 / 10 * 1.255, $forward['price_cents_per_kwh'], 0.0001);
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
        // This is the live mid-quarter case: EEX stops publishing a quarter contract once that
        // quarter enters delivery, so at today's vintage the direct `quarter` lookup is empty.
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
