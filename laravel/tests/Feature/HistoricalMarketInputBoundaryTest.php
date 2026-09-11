<?php

namespace Tests\Feature;

use App\Models\ElectricityFuturesEodPrice;
use App\Models\SpotPriceAverage;
use App\Services\CanonicalPricing\MarketReset\DTO\ResetEstimateRequest;
use App\Services\CanonicalPricing\MarketReset\DTO\ResetEstimatorSettings;
use App\Services\CanonicalPricing\MarketReset\EexMarketReferenceCurveProvider;
use App\Services\CanonicalPricing\MarketReset\MarketResetPriceEstimator;
use App\Services\CanonicalPricing\SupplierAdjusted\DTO\PriceEpisodeAnchor;
use App\Services\CanonicalPricing\SupplierAdjusted\DTO\SupplierAdjustedEstimateRequest;
use App\Services\CanonicalPricing\SupplierAdjusted\Enums\PriceEpisodeEvidenceBasis;
use App\Services\CanonicalPricing\SupplierAdjusted\SupplierAdjustedPriceEstimator;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class HistoricalMarketInputBoundaryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('canonical_pricing.reset_forward_shift.seasonal_index', [
            'lookback_years' => 4, 'min_years_per_month' => 2,
        ]);
        config()->set('price_forecasting.fixed_term.vat_multiplier', 1.255);
    }

    public function test_future_extremes_and_in_progress_months_cannot_change_an_older_index(): void
    {
        $this->history();
        $target = $this->date('2026-07-15');
        $expected = $this->provider()->spotSeasonalIndex($target);
        $this->assertNotNull($expected);

        // Even an early declared end cannot make the current calendar month eligible.
        $this->month('2026-07-01', 999999, '2026-07-10');
        $this->month('2026-08-01', 999999);
        $this->month('2040-01-01', 999999);
        // A prior start is insufficient if its declared end is not yet in the past.
        $this->month('2026-06-01', 999999, '2026-07-15');

        $this->assertSame($expected, $this->provider()->spotSeasonalIndex($target));
        $this->assertSame($expected, $this->provider()->spotSeasonalIndex($this->date('2026-07-01')));
    }

    public function test_stored_whole_current_month_is_excluded_until_the_next_helsinki_month(): void
    {
        $this->history();
        for ($month = 1; $month <= 6; $month++) {
            $this->month(sprintf('2026-%02d-01', $month), 2);
        }
        $target = $this->date('2026-07-15');
        $expected = $this->provider()->spotSeasonalIndex($target);
        $this->month('2026-07-01', 999999);
        $provider = $this->provider();
        $this->assertSame($expected, $provider->spotSeasonalIndex($target));
        $this->assertSame($expected, $provider->spotSeasonalIndex($this->date('2026-07-31')));
        $this->assertNotSame($expected, $provider->spotSeasonalIndex(
            CarbonImmutable::parse('2026-07-31 21:00:00', 'UTC'),
        ));
    }

    public function test_date_cache_is_independent_in_both_lookup_orders(): void
    {
        $this->history();
        for ($month = 1; $month <= 12; $month++) {
            $this->month(sprintf('2026-%02d-01', $month), $month === 1 ? 100 : 1);
        }
        $older = $this->date('2026-01-01');
        $newer = $this->date('2027-01-01');
        $oldIndex = $this->provider()->spotSeasonalIndex($older);
        $newIndex = $this->provider()->spotSeasonalIndex($newer);
        $this->assertNotSame($oldIndex, $newIndex);

        foreach ([[$older, $newer], [$newer, $older]] as $order) {
            $provider = $this->provider();
            foreach (array_merge($order, $order) as $target) {
                $this->assertSame($target === $older ? $oldIndex : $newIndex, $provider->spotSeasonalIndex($target));
            }
        }
    }

    public function test_no_completed_history_returns_null_and_both_estimators_hold(): void
    {
        $this->history();
        $target = $this->date('2024-01-15');
        $provider = $this->provider();
        $this->assertNull($provider->spotSeasonalIndex($target));
        $this->assertNotNull($provider->spotSeasonalIndex($this->date('2026-01-01')));
        $this->assertNull($provider->spotSeasonalIndex($target));
        [$reset, $supplier] = $this->estimates($target, $target);
        $this->assertSame('hold_flat', $reset->basis->value);
        $this->assertSame('hold_flat', $supplier->basis->value);
    }

    public function test_future_announced_period_keeps_delivery_anchor_but_never_uses_later_vintages(): void
    {
        foreach (['2026-07-14' => 40, '2026-07-15' => 400, '2026-07-31' => 900] as $trade => $price) {
            $this->future('202608', $trade, $price);
            $this->future('202609', $trade, $price + 10);
        }
        [$reset, $supplier] = $this->estimates($this->date('2026-07-15'), $this->date('2026-08-01'));
        foreach ([$reset, $supplier] as $estimate) {
            $this->assertSame('forward_curve_shift', $estimate->basis->value);
            $this->assertEqualsWithDelta(5.02, $estimate->referencePriceCentsPerKwh, 0.00001);
            $this->assertEqualsWithDelta(1.255, $estimate->offsetsByMonthKey['2026-09'], 0.00001);
            $this->assertSame('2026-07-14', $estimate->curveTradeDate);
            $this->assertContains('reference_vintage_bounded_by_as_of', $estimate->flags);
        }
    }

    public function test_same_target_has_same_seasonal_inputs_under_current_and_later_clocks(): void
    {
        $this->history();
        $this->month('2030-01-01', 999999);
        $target = $this->date('2026-07-15');
        try {
            CarbonImmutable::setTestNow($target);
            $current = $this->estimates($target, $target);
            CarbonImmutable::setTestNow($this->date('2031-01-01'));
            $historical = $this->estimates($target, $target);
            foreach ([0, 1] as $index) {
                $this->assertSame('spot_seasonal_index', $current[$index]->basis->value);
                $this->assertEquals($current[$index], $historical[$index]);
            }
        } finally {
            CarbonImmutable::setTestNow();
        }
    }

    public function test_missing_old_reference_uses_target_vintage_not_process_clock(): void
    {
        $this->future('202604', '2026-07-14', 40);
        $this->future('202609', '2026-07-14', 50);
        $this->future('202604', '2026-08-01', 900);
        $this->future('202609', '2026-08-01', 950);
        [$reset] = $this->estimates($this->date('2026-07-15'), $this->date('2026-04-01'));
        $this->assertContains('reference_vintage_fallback_today', $reset->flags);
        $this->assertEqualsWithDelta(5.02, $reset->referencePriceCentsPerKwh, 0.00001);
    }

    private function estimates(CarbonImmutable $target, CarbonImmutable $anchor): array
    {
        $provider = $this->provider();
        $settings = new ResetEstimatorSettings(enabled: true);

        return [
            (new MarketResetPriceEstimator($provider, $settings))->estimate(new ResetEstimateRequest(
                cadence: 'monthly', asOfDate: $target, anchorPeriodMonth: $anchor->startOfMonth(),
                currentPeriodStart: $anchor, tailMonthKeys: ['2026-09'],
                anchorEnergyPriceCentsPerKwh: 7, monthWeights: ['2026-07' => 1, '2026-09' => 1],
            )),
            (new SupplierAdjustedPriceEstimator($provider, $settings))->estimate(new SupplierAdjustedEstimateRequest(
                asOfDate: $target,
                priceEpisodeAnchor: new PriceEpisodeAnchor($anchor, PriceEpisodeEvidenceBasis::ObservedSellerSnapshotRun),
                tailMonthKeys: ['2026-09'], currentEnergyPriceCentsPerKwh: 7, monthlyFeeEur: 4,
                monthWeights: ['2026-07' => 1, '2026-09' => 1],
            )),
        ];
    }

    private function history(): void
    {
        foreach ([2024, 2025] as $year) {
            for ($month = 1; $month <= 12; $month++) {
                $this->month(sprintf('%d-%02d-01', $year, $month), $month <= 6 ? 2 : 1);
            }
        }
    }

    private function month(string $start, float $price, ?string $end = null): void
    {
        // Cover both raw SQL DATE and datetime forms without Eloquent date casts.
        DB::table('spot_price_averages')->insert([
            'region' => 'FI', 'period_type' => SpotPriceAverage::PERIOD_MONTHLY,
            'period_start' => $start.($this->date($start)->month % 2 === 0 ? ' 00:00:00' : ''),
            'period_end' => $end ?? $this->date($start)->endOfMonth()->toDateString(),
            'avg_price_with_tax' => $price, 'avg_price_without_tax' => $price / 1.255,
            'hours_count' => 720,
        ]);
    }

    private function future(string $maturity, string $trade, float $price): void
    {
        ElectricityFuturesEodPrice::create([
            'exchange' => 'EEX', 'commodity' => 'POWER', 'pricing' => 'F', 'product' => 'Base',
            'area' => 'FI', 'short_code' => 'FNBM', 'maturity_type' => 'month',
            'maturity' => $maturity, 'trade_date' => $trade, 'settlement_price' => $price,
        ]);
    }

    private function provider(): EexMarketReferenceCurveProvider
    {
        return app(EexMarketReferenceCurveProvider::class);
    }

    private function date(string $date): CarbonImmutable
    {
        return CarbonImmutable::parse($date, 'Europe/Helsinki');
    }
}
