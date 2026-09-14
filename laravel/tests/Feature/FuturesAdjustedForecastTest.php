<?php

namespace Tests\Feature;

use App\Models\ContractPriceDailyStatistic;
use App\Models\ElectricityFuturesEodPrice;
use App\Models\FixedContractPriceForecast;
use App\Services\PriceForecasting\FixedTermForecastEvaluationService;
use App\Services\PriceForecasting\FixedTermHedgeCostService;
use App\Services\PriceForecasting\FixedTermPriceForecastService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\SeedsFlatForecastFutures;
use Tests\TestCase;

class FuturesAdjustedForecastTest extends TestCase
{
    use RefreshDatabase;
    use SeedsFlatForecastFutures;

    private function date(int $day): CarbonImmutable
    {
        return CarbonImmutable::parse('2026-01-01')->addDays($day);
    }

    private function statistics(): void
    {
        foreach ([6, 12, 24] as $term) {
            for ($d = 0; $d <= 61; $d++) {
                ContractPriceDailyStatistic::create([
                    'stat_date' => $this->date($d), 'segment_key' => 'fixed_term_'.$term,
                    'metric_key' => 'energy_price', 'consumption_kwh' => null,
                    'method_version' => 'unit_statistics_v1', 'pricing_basis' => 'observed_seller_data',
                    'contract_count' => 30, 'p20_value' => round(2 + $d ** 2 * $term / 100000, 4),
                    'median_value' => round(4 + $d ** 2 * $term / 50000, 4),
                    'p80_value' => round(8 - $d ** 2 * $term / 100000, 4),
                ]);
            }
        }
    }

    private function curve(int $day, float $price): void
    {
        foreach ([2026, 2027, 2028, 2029] as $year) {
            ElectricityFuturesEodPrice::create([
                'exchange' => 'EEX', 'area' => 'FI', 'product' => 'Base', 'short_code' => 'FYBY',
                'maturity_type' => 'year', 'maturity' => $year.'01',
                'trade_date' => $this->date($day), 'settlement_price' => $price,
            ]);
        }
    }

    public function test_independent_full_mean_feature_cohort_ridge_and_four_decimal_prediction_for_all_lanes(): void
    {
        $this->statistics();
        for ($d = 10; $d <= 61; $d++) {
            $this->curve($d, 40 + $d ** 2 / 1000);
        }
        DB::enableQueryLog();
        $rows = app(FixedTermPriceForecastService::class)->buildForecasts($this->date(61), 10);
        $this->assertCount(9, $rows);
        $this->assertCount(1, array_filter(DB::getQueryLog(), fn ($q) => str_contains($q['query'], 'electricity_futures')));
        DB::disableQueryLog();
        foreach ($rows as $row) {
            $term = $row['duration_months'];
            $quantile = $row['target_quantile'];
            $price = fn ($d) => round(match ($quantile) {
                'p20' => 2 + $d ** 2 * $term / 100000,
                'median' => 4 + $d ** 2 * $term / 50000,
                'p80' => 8 - $d ** 2 * $term / 100000,
            }, 4);
            $x = fn ($d) => (($d - 1) ** 2 - ($d - 8) ** 2) / 1000 / 10 * 1.255;
            $full = $ys = $xs = [];
            for ($d = 0; $d < 51; $d++) {
                $delta = $price($d + 10) - $price($d);
                $full[] = $delta;
                if ($d >= 18) {
                    $ys[] = $delta;
                    $xs[] = $x($d);
                }
            }
            $mean = array_sum($full) / count($full);
            $xm = array_sum($xs) / count($xs);
            $ym = array_sum($ys) / count($ys);
            $std = sqrt(array_sum(array_map(fn ($v) => ($v - $xm) ** 2, $xs)) / count($xs));
            $slope = 0;
            foreach ($xs as $i => $value) {
                $slope += (($value - $xm) / $std) * ($ys[$i] - $ym) / count($xs) / 2;
            }
            $contribution = $slope * (($x(61) - $xm) / $std);
            $m = $row['source_metadata'];
            $this->assertSame(51, $m['pair_count']);
            $this->assertSame(33, $m['feature_pair_count']);
            foreach (['mean_change_cents_per_kwh' => $mean, 'feature_mean' => $xm,
                'feature_population_std' => $std, 'feature_delta_mean' => $ym,
                'feature_standardized_slope' => $slope, 'futures_contribution_cents_per_kwh' => $contribution] as $key => $expected) {
                $this->assertEqualsWithDelta($expected, $m[$key], 1e-10, $key);
            }
            $this->assertSame(round($mean + $contribution, 4), $row['expected_change_cents_per_kwh']);
            $this->assertSame(round($price(61) + round($mean + $contribution, 4), 4), $row['forecast_price_cents_per_kwh']);
            $feature = $m['current_futures_feature'];
            $this->assertSame('2026-04-01', $feature['current_basket']['delivery_start_month']);
            $this->assertSame('2026-04-01', $feature['lag_basket']['delivery_start_month']);
            $this->assertSame($this->date(60)->toDateString(), $feature['current_basket']['trade_date']);
            $this->assertSame($this->date(53)->toDateString(), $feature['lag_basket']['trade_date']);
        }
    }

    public function test_no_futures_or_only_current_vintages_never_fall_back_to_mean(): void
    {
        $this->statistics();
        $service = app(FixedTermPriceForecastService::class);
        $this->assertCount(0, $service->buildForecasts($this->date(61), 10));
        $this->curve(60, 50);
        $this->assertCount(0, $service->buildForecasts($this->date(61), 10));
        $this->curve(53, 49);
        $this->assertCount(0, $service->buildForecasts($this->date(61), 10));
    }

    public function test_feature_history_has_its_own_hard_floor(): void
    {
        $this->statistics();
        config(['price_forecasting.fixed_term.minimum_history_observations' => 1]);
        $this->curve(24, 50); // First feature start 32: only 19 completed feature starts.
        $this->assertCount(0, app(FixedTermPriceForecastService::class)->buildForecasts($this->date(61), 10));
        $this->curve(23, 50);
        $rows = app(FixedTermPriceForecastService::class)->buildForecasts($this->date(61), 10);
        $this->assertCount(9, $rows);
        $this->assertSame(20, $rows->first()['source_metadata']['feature_pair_count']);
        $this->assertSame(0.0, $rows->first()['source_metadata']['futures_contribution_cents_per_kwh']);
    }

    public function test_incomplete_latest_curve_is_not_replaced_by_old_complete_curve_or_cached_between_builds(): void
    {
        $this->statistics();
        $this->seedFlatForecastFutures();
        $service = app(FixedTermPriceForecastService::class);
        $this->assertCount(9, $service->buildForecasts($this->date(61), 10));
        ElectricityFuturesEodPrice::create([
            'exchange' => 'EEX', 'area' => 'FI', 'product' => 'Base', 'short_code' => 'FYBM',
            'maturity_type' => 'month', 'maturity' => '202604', 'trade_date' => $this->date(60), 'settlement_price' => 50,
        ]);
        $this->assertCount(0, $service->buildForecasts($this->date(61), 10));
    }

    public function test_saved_provenance_keeps_historical_and_new_models_evaluable_without_rewriting_completed_rows(): void
    {
        $this->statistics();
        $this->seedFlatForecastFutures();
        $row = app(FixedTermPriceForecastService::class)->buildForecasts($this->date(51), 10, [12], ['median'])->first();
        foreach (['fixed_term_futures_adjusted_v1', 'fixed_term_historical_change_v1', 'fixed_term_ewma_gap_v3'] as $model) {
            FixedContractPriceForecast::create(array_replace($row, ['model_version' => $model]));
        }
        $completed = FixedContractPriceForecast::create(array_replace($row, [
            'model_version' => 'fixed_term_ewma_gap_v1', 'actual_price_cents_per_kwh' => 3,
            'direction_correct' => false, 'evaluated_at' => now(),
        ]));
        $before = $completed->fresh()->getRawOriginal();
        $result = app(FixedTermForecastEvaluationService::class)->evaluateMatured($this->date(61));
        $this->assertSame(3, $result['evaluated']);
        $this->assertSame(0, $result['unsupported_provenance']);
        $this->assertSame($before, $completed->fresh()->getRawOriginal());
        $this->assertSame(0, app(FixedTermForecastEvaluationService::class)->evaluateMatured($this->date(61))['evaluated']);
    }

    public function test_optional_fixed_basket_preserves_default_rolling_month_semantics(): void
    {
        $this->seedFlatForecastFutures();
        $this->curve(53, 50);
        ElectricityFuturesEodPrice::create([
            'exchange' => 'EEX', 'area' => 'FI', 'product' => 'Base', 'short_code' => 'FYBM',
            'maturity_type' => 'month', 'maturity' => '202603', 'trade_date' => $this->date(53), 'settlement_price' => 100,
        ]);
        $hedge = new FixedTermHedgeCostService;
        $lag = $this->date(54);
        $rolling = $hedge->calculate($lag, 6);
        $fixed = $hedge->calculate($lag, 6, CarbonImmutable::parse('2026-04-01'));
        $this->assertSame('2026-03-01', $rolling['delivery_start_month']);
        $this->assertSame('2026-04-01', $fixed['delivery_start_month']);
        $this->assertGreaterThan($fixed['price_cents_per_kwh'], $rolling['price_cents_per_kwh']);
        $this->assertEqualsWithDelta(6.275, $fixed['price_cents_per_kwh'], 1e-10);
    }
}
