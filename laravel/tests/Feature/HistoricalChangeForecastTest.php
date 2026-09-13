<?php

namespace Tests\Feature;

use App\Models\ContractPriceDailyStatistic;
use App\Models\FixedContractPriceForecast;
use App\Services\PriceForecasting\FixedTermHedgeCostService;
use App\Services\PriceForecasting\FixedTermPriceForecastService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class HistoricalChangeForecastTest extends TestCase
{
    use RefreshDatabase;

    private const MODEL = 'fixed_term_historical_change_v1';

    protected function setUp(): void
    {
        parent::setUp();
        config(['price_forecasting.fixed_term.model_version' => self::MODEL]);
    }

    private function stat(int $day, int $term = 12, ?float $price = 0, string $basis = 'observed_seller_data', ?array $values = null): void
    {
        ContractPriceDailyStatistic::create([
            'stat_date' => $this->date($day), 'segment_key' => 'fixed_term_'.$term,
            'metric_key' => 'energy_price', 'consumption_kwh' => null,
            'method_version' => 'unit_statistics_v1', 'pricing_basis' => $basis,
            'contract_count' => 30, 'p20_value' => $values[0] ?? $price,
            'median_value' => $values[1] ?? $price, 'p80_value' => $values[2] ?? $price,
        ]);
    }

    private function date(int $day): string
    {
        return CarbonImmutable::parse('2026-01-01')->addDays($day)->toDateString();
    }

    private function build(int $day, int $horizon = 30, array $terms = [12], array $quantiles = ['median'])
    {
        return app(FixedTermPriceForecastService::class)->buildForecasts(CarbonImmutable::parse($this->date($day)), $horizon, $terms, $quantiles);
    }

    public function test_hard_floor_and_exact_independent_means_use_all_history_without_futures(): void
    {
        config(['price_forecasting.fixed_term.minimum_history_observations' => 10]);
        $this->mock(FixedTermHedgeCostService::class)->shouldNotReceive('calculate');
        foreach ([6, 12, 24] as $term) {
            for ($day = 0; $day <= 51; $day++) {
                $this->stat($day, $term, values: [-10 + $day * $term / 600, $day * $term / 300, 10 - $day * $term / 600]);
            }
        }
        $this->assertCount(0, $this->build(49, terms: [6, 12, 24], quantiles: ['p20', 'median', 'p80']));
        DB::enableQueryLog();
        $rows = $this->build(50, terms: [6, 12, 24], quantiles: ['p20', 'median', 'p80']);
        $this->assertCount(9, $rows);
        foreach ($rows as $row) {
            $expected = $row['duration_months'] / ($row['target_quantile'] === 'median' ? 10 : 20);
            $expected *= $row['target_quantile'] === 'p80' ? -1 : 1;
            $this->assertEqualsWithDelta($expected, $row['expected_change_cents_per_kwh'], 0.00001);
            $this->assertEqualsWithDelta($row['current_price_cents_per_kwh'] + $expected, $row['forecast_price_cents_per_kwh'], 0.00001);
            $m = $row['source_metadata'];
            $this->assertSame(20, $m['pair_count']);
            $this->assertSame(20, $m['unique_issue_days']);
            $this->assertSame(20, $m['minimum_history_observations']);
            $this->assertSame($this->date(0), $m['pair_start_min']);
            $this->assertSame($this->date(19), $m['pair_start_max']);
            $this->assertSame($this->date(30), $m['pair_target_min']);
            $this->assertSame($this->date(49), $m['pair_target_max']);
            $this->assertSame(20, $m['confidence_history_observations']);
            $this->assertSame(['observed_seller_data' => 20], $m['historical_retail_pricing_basis_counts']);
            foreach (['hedge_cost_cents_per_kwh', 'retail_premium_cents_per_kwh', 'normal_retail_premium_cents_per_kwh', 'fair_price_cents_per_kwh', 'gap_cents_per_kwh', 'futures_trade_date', 'coverage_quality', 'interval_low_cents_per_kwh', 'interval_high_cents_per_kwh'] as $field) {
                $this->assertNull($row[$field]);
            }
            $this->assertArrayNotHasKey('monthly_futures_months', $m);
        }
        foreach (DB::getQueryLog() as $query) {
            $this->assertStringNotContainsString('futures', $query['query']);
            $this->assertStringNotContainsString('annual_cost', $query['query']);
        }
        DB::disableQueryLog();
        $this->assertSame(21, $this->build(51)->first()['source_metadata']['pair_count']);
        config(['price_forecasting.fixed_term.minimum_history_observations' => 21]);
        $this->assertCount(0, $this->build(50));
    }

    public function test_confidence_uses_completed_current_basis_pair_thresholds(): void
    {
        for ($day = 0; $day <= 395; $day++) {
            $this->stat($day, price: 0);
        }
        foreach ([149 => 'low', 150 => 'medium', 394 => 'medium', 395 => 'high'] as $day => $confidence) {
            $row = $this->build($day)->first();
            $this->assertSame($day - 30, $row['source_metadata']['confidence_history_observations']);
            $this->assertSame($confidence, $row['confidence']);
        }
    }

    public function test_presence_boundary_latest_invalid_ownership_and_no_seam_or_missing_date_fill(): void
    {
        config(['canonical_pricing.enabled' => true]);
        for ($day = 0; $day <= 100; $day++) {
            $this->stat($day, price: $day);
            if ($day >= 51) {
                $this->stat($day, price: 100 + 2 * $day, basis: 'canonical_calculation');
            }
        }
        // Invalid presence starts the boundary. Observed day 50 cannot revive.
        $this->stat(50, price: null, basis: 'canonical_calculation');
        // Latest invalid target removes one canonical pair; missing target removes another.
        $this->stat(82, price: null, basis: 'canonical_calculation');
        DB::table('contract_price_daily_statistics')->whereDate('stat_date', $this->date(83))->where('pricing_basis', 'canonical_calculation')->delete();
        $this->stat(84, price: 123, basis: 'canonical_calculation');
        DB::table('contract_price_daily_statistics')->where('id', ContractPriceDailyStatistic::max('id'))->update(['median_value' => DB::raw('1e999')]);
        $row = $this->build(100)->first();
        $m = $row['source_metadata'];
        $this->assertSame($this->date(50), $m['historical_retail_transition_date']);
        // Observed starts 0..19, canonical starts 51..69 except 52,53,54.
        $this->assertSame(['canonical_calculation' => 16, 'observed_seller_data' => 20], $m['historical_retail_pricing_basis_counts']);
        $this->assertSame(36, $m['pair_count']);
        $this->assertSame(16, $m['confidence_history_observations']);
        $this->assertSame('low', $row['confidence']);
        $this->assertEqualsWithDelta((20 * 30 + 16 * 60) / 36, $m['mean_change_cents_per_kwh'], 0.00001);
        $this->assertSame($this->date(99), $m['pair_target_max']);
        // An invalid latest current row cannot fall back to observed or an older duplicate.
        $this->stat(100, price: null, basis: 'canonical_calculation');
        $this->assertCount(0, $this->build(100));
    }

    public function test_future_presence_cannot_set_boundary_and_current_target_never_enters_fit(): void
    {
        for ($day = 0; $day <= 51; $day++) {
            $this->stat($day, price: 0);
        }
        $this->stat(52, price: 999, basis: 'canonical_calculation');
        $before = $this->build(50)->first();
        $this->assertNull($before['source_metadata']['historical_retail_transition_date']);
        $this->stat(50, price: 100);
        $after = $this->build(50)->first();
        $this->assertSame(0.0, $after['expected_change_cents_per_kwh']);
        $this->assertSame('flat', $after['direction']);
        $this->assertSame($before['source_metadata']['pair_count'], $after['source_metadata']['pair_count']);
        $tomorrow = $this->build(51)->first();
        $this->assertEqualsWithDelta(100 / 21, $tomorrow['source_metadata']['mean_change_cents_per_kwh'], 0.00001);
        $this->assertSame(21, $tomorrow['source_metadata']['pair_count']);
        config(['canonical_pricing.enabled' => true]);
        app()->forgetScopedInstances();
        $this->stat(51, price: 5, basis: 'canonical_calculation');
        $canonical = $this->build(51)->first();
        $this->assertSame($this->date(51), $canonical['source_metadata']['historical_retail_transition_date']);
        $this->assertSame(21, $canonical['source_metadata']['pair_count']);
        $this->assertSame(0, $canonical['source_metadata']['confidence_history_observations']);
    }

    public function test_equal_weight_cancellation_and_stored_precision_direction(): void
    {
        for ($day = 0; $day <= 21; $day++) {
            $this->stat($day, price: $day % 2 === 0 ? -1 : 1);
        }
        $row = $this->build(21, 1)->first();
        $this->assertSame(0.0, $row['expected_change_cents_per_kwh']);
        $this->assertSame('flat', $row['direction']);
        $this->stat(20, price: 2);
        $row = $this->build(21, 1)->first();
        $this->assertSame(0.15, $row['expected_change_cents_per_kwh']);
        $this->assertSame('rising', $row['direction']);
    }

    public function test_persistence_dry_run_rerun_and_nullable_migration_preserve_old_rows(): void
    {
        for ($day = 0; $day <= 50; $day++) {
            $this->stat($day, price: 5 + $day / 100);
        }
        $row = $this->build(50)->first();
        $old = FixedContractPriceForecast::create(array_replace($row, [
            'model_version' => 'fixed_term_ewma_gap_v2', 'actual_price_cents_per_kwh' => 6,
            'hedge_cost_cents_per_kwh' => 1, 'retail_premium_cents_per_kwh' => 2,
            'normal_retail_premium_cents_per_kwh' => 3, 'fair_price_cents_per_kwh' => 4,
            'gap_cents_per_kwh' => 5, 'futures_trade_date' => $this->date(49), 'coverage_quality' => 'all_monthly',
        ]));
        $before = $old->fresh()->getRawOriginal();
        $migration = require database_path('migrations/2026_09_14_000001_make_forecast_gap_diagnostics_nullable.php');
        $migration->up();
        $migration->down();
        $this->assertSame($before, $old->fresh()->getRawOriginal());
        $args = ['--as-of' => $this->date(50), '--duration' => [12], '--quantile' => ['median']];
        $this->assertSame(0, Artisan::call('forecasting:run-fixed-contracts', $args + ['--dry-run' => true]));
        $this->assertStringContainsString('Pairs 20 (minimum 20)', Artisan::output());
        $this->assertSame(1, FixedContractPriceForecast::count());
        $this->assertSame(0, Artisan::call('forecasting:run-fixed-contracts', $args));
        $new = FixedContractPriceForecast::where('model_version', self::MODEL)->firstOrFail();
        $this->assertNull($new->hedge_cost_cents_per_kwh);
        $saved = $new->fresh()->getRawOriginal();
        Artisan::call('forecasting:run-fixed-contracts', $args);
        $this->assertSame($saved, $new->fresh()->getRawOriginal());
        $new->update(['actual_price_cents_per_kwh' => 7, 'evaluated_at' => now()]);
        $completed = $new->fresh()->getRawOriginal();
        $this->stat(50, price: 99);
        Artisan::call('forecasting:run-fixed-contracts', $args + ['--overwrite' => true]);
        $this->assertSame($completed, $new->fresh()->getRawOriginal());
        $this->assertSame($before, $old->fresh()->getRawOriginal());
    }

    public function test_page_discloses_crossed_quantiles_without_reordering_or_null_diagnostic_casts(): void
    {
        for ($day = 0; $day <= 50; $day++) {
            $this->stat($day, values: [$day < 30 ? 0 : 9, 10, 11]);
        }
        foreach ($this->build(50, quantiles: ['p20', 'median', 'p80']) as $row) {
            FixedContractPriceForecast::create($row);
        }
        $this->get('/sahkosopimus/sahkon-hintaennuste')->assertOk()
            ->assertSeeText('Hintatasojen ennusteet menevät ristiin.')
            ->assertSeeText('Jokainen hyväksytty muutos saa saman painon.')
            ->assertSeeText('Päällekkäiset muutosjaksot eivät ole toisistaan riippumattomia havaintoja.')
            ->assertDontSeeText('Markkinatason hinta')->assertDontSeeText('Futuuripäivä');
        $this->assertEquals(18, FixedContractPriceForecast::where('target_quantile', 'p20')->value('forecast_price_cents_per_kwh'));
    }
}
