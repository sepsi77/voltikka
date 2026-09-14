<?php

namespace Tests\Feature;

use App\Models\ContractPriceDailyStatistic;
use App\Models\FixedContractPriceForecast;
use App\Services\PriceForecasting\FixedTermForecastEvaluationService;
use App\Services\PriceForecasting\FixedTermForecastReport;
use App\Services\PriceForecasting\FixedTermPriceForecastService;
use App\Services\PriceForecasting\ForecastOutlook;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class FixedContractForecastReportTest extends TestCase
{
    use RefreshDatabase;

    public function test_direction_boundaries_and_four_exhaustive_outcomes(): void
    {
        $service = app(FixedTermPriceForecastService::class);
        foreach ([['rising', 0.15], ['falling', -0.15], ['slightly_rising', 0.1499], ['slightly_falling', -0.1499], ['flat', 0.0]] as [$label, $change]) {
            $this->assertSame($label, $service->directionLabel($change, 0.15));
        }
        foreach (['rising', 'falling', 'flat'] as $forecast) {
            foreach (['rising', 'falling', 'flat'] as $actual) {
                $expected = $forecast === $actual ? 'correct' : ($forecast === 'flat' ? 'missed_move' : ($actual === 'flat' ? 'false_move' : 'wrong_way'));
                $this->assertSame($expected, ForecastOutlook::outcome($forecast, $actual));
            }
        }
        $this->assertNull($service->directionCategory(null));
        $this->assertNull($service->directionCategory('unknown'));
        $this->assertNull(ForecastOutlook::outcome(null, 'flat'));
    }

    public function test_new_evaluations_record_outcomes_and_dry_run_preserves_all_rows(): void
    {
        foreach ([['rising', 1.15, 'correct'], ['falling', 1.15, 'wrong_way'], ['slightly_rising', 0.85, 'missed_move'], ['rising', 1.1499, 'false_move']] as $i => [$direction, $price, $outcome]) {
            $row = $this->row(['forecast_date' => '2026-01-0'.($i + 1), 'direction' => $direction], completed: false);
            ContractPriceDailyStatistic::create([
                'stat_date' => $row->target_date, 'segment_key' => 'fixed_term_12', 'metric_key' => 'energy_price',
                'pricing_basis' => 'observed_seller_data', 'method_version' => 'unit_statistics_v1',
                'consumption_kwh' => null, 'median_value' => $price, 'contract_count' => 20,
            ]);
        }
        $old = $this->row(['forecast_date' => '2025-12-01', 'source_metadata' => []]);
        $oldBytes = $old->fresh()->getRawOriginal();
        $before = $this->bytes();
        config()->set('canonical_pricing.enabled', true);
        config()->set('price_forecasting.fixed_term.direction_threshold_cents_per_kwh', 10);
        $result = app(FixedTermForecastEvaluationService::class)->evaluateMatured(CarbonImmutable::parse('2026-03-01'), dryRun: true);
        $this->assertSame(4, $result['evaluated']);
        $this->assertSame(['correct', 'wrong_way', 'missed_move', 'false_move'], $result['forecasts']->map(fn ($row) => $row->source_metadata['direction_outcome'])->all());
        $this->assertSame(['rising', 'rising', 'falling', 'flat'], $result['forecasts']->map(fn ($row) => $row->source_metadata['actual_direction_category'])->all());
        $this->assertSame($before, $this->bytes());
        $this->assertSame(0, Artisan::call('forecasting:evaluate-fixed-contracts', ['--as-of' => '2026-03-01', '--dry-run' => true]));
        $previewOutput = Artisan::output();
        $this->assertStringContainsString('n=4, issue_days=4, correct=1, wrong_way=1, missed_move=1, false_move=1', $previewOutput);
        $this->assertSame($before, $this->bytes());
        Artisan::call('forecasting:evaluate-fixed-contracts', ['--as-of' => '2026-03-01']);
        $this->assertStringContainsString('n=4, issue_days=4, correct=1, wrong_way=1, missed_move=1, false_move=1', Artisan::output());
        $this->assertSame($oldBytes, $old->fresh()->getRawOriginal());
        $after = $this->bytes();
        app(FixedTermForecastEvaluationService::class)->evaluateMatured(CarbonImmutable::parse('2026-03-01'));
        $this->assertSame($after, $this->bytes());
    }

    public function test_report_groups_saved_compatibility_and_uses_median_only_without_writes(): void
    {
        $this->row();
        $this->row(['target_quantile' => 'p20']);
        $this->row(['target_quantile' => 'p80']);
        $this->row(['duration_months' => 6]);
        $this->row(['model_version' => 'other']);
        $this->row(['forecast_date' => '2026-01-02'], basis: 'canonical_calculation');
        $this->row(['forecast_date' => '2026-01-03'], threshold: 0.5);
        $this->row(['forecast_date' => '2026-01-04', 'horizon_days' => 45]);
        $this->row(['forecast_date' => '2026-01-05', 'source_metadata' => []]);
        $before = $this->bytes();
        $writes = [];
        DB::listen(function ($query) use (&$writes) {
            if (preg_match('/^\s*(insert|update|delete|replace|create|drop|alter)/i', $query->sql)) {
                $writes[] = $query->sql;
            }
        });
        config()->set('canonical_pricing.enabled', true);
        config()->set('price_forecasting.fixed_term.direction_threshold_cents_per_kwh', 99);
        $this->assertSame(0, Artisan::call('forecasting:report-fixed-contracts'));
        $output = Artisan::output();
        $this->assertSame(4, substr_count($output, 'n=1, issue_days=1'));
        $this->assertStringContainsString('Skipped unsupported_provenance: 1', $output);
        $this->assertStringNotContainsString('other |', $output);
        $this->assertStringContainsString('canonical_calculation', $output);
        $this->assertStringContainsString('threshold=0.5', $output);
        $this->assertStringContainsString('correct_rate=100.0%', $output);
        $this->assertSame(0, Artisan::call('forecasting:report-fixed-contracts', ['--model-version' => 'all']));
        $this->assertStringContainsString('other |', Artisan::output());
        $this->assertSame(0, Artisan::call('forecasting:report-fixed-contracts', ['--horizon' => '45']));
        $this->assertStringContainsString('45d | 12m', Artisan::output());
        $this->assertSame(0, Artisan::call('forecasting:report-fixed-contracts', ['--from' => '2026-01-03', '--to' => '2026-01-03']));
        $this->assertSame(1, substr_count(Artisan::output(), 'n=1, issue_days=1'));
        $this->assertSame([], $writes);
        $this->assertSame($before, $this->bytes());
    }

    public function test_report_named_skips_and_empty_rates(): void
    {
        $this->row(['direction' => 'unknown']);
        $this->row(['forecast_date' => '2026-01-02', 'source_metadata' => ['evaluation_method_version' => 'other']]);
        $this->row(['forecast_date' => '2026-01-03', 'target_date' => '2026-03-01']);
        $this->row(['forecast_date' => '2026-01-04', 'evaluated_at' => null]);
        Artisan::call('forecasting:report-fixed-contracts');
        $output = Artisan::output();
        foreach (['unsupported_direction', 'unsupported_provenance', 'malformed_values', 'incomplete'] as $reason) {
            $this->assertStringContainsString("Skipped {$reason}: 1", $output);
        }
        $this->assertStringContainsString('Rates are unavailable', $output);
        $this->assertStringNotContainsString('%', $output);
        foreach ([['--from' => 'not-a-date'], ['--from' => '2026-02-30'], ['--horizon' => '0'], ['--from' => '2026-02-01', '--to' => '2026-01-01']] as $options) {
            $this->assertSame(1, Artisan::call('forecasting:report-fixed-contracts', $options));
        }
    }

    public function test_report_rejects_incompatible_saved_actual_provenance_and_malformed_values(): void
    {
        $valid = $this->row();
        $metadata = $valid->source_metadata;
        foreach ([
            ['actual_retail_method_version' => 'annual_cost_as_of_v2'],
            ['evaluation_method_version' => 'other'],
            ['actual_retail_pricing_basis' => 'canonical_calculation'],
            ['evaluation_direction_threshold_cents_per_kwh' => 0.5],
            ['actual_retail_source_date' => '2025-12-31'],
            ['actual_retail_segment' => 'fixed_term_24'],
            ['actual_retail_metric' => 'annual_cost'],
            ['actual_retail_contract_count' => 0],
            ['direction_threshold_cents_per_kwh' => 'invalid'],
        ] as $i => $change) {
            $row = $this->row(['forecast_date' => CarbonImmutable::parse('2026-01-02')->addDays($i)->toDateString()]);
            $row->update(['source_metadata' => array_replace($row->source_metadata, $change)]);
        }
        $malformed = $this->row(['forecast_date' => '2026-01-20']);
        DB::table('fixed_contract_price_forecasts')->where('id', $malformed->id)->update(['current_price_cents_per_kwh' => 'not-a-price']);
        $badDate = $this->row(['forecast_date' => '2026-01-21']);
        DB::table('fixed_contract_price_forecasts')->where('id', $badDate->id)->update(['target_date' => 'not-a-date']);
        $before = $this->bytes();
        Artisan::call('forecasting:report-fixed-contracts');
        $output = Artisan::output();
        $this->assertStringContainsString('Skipped unsupported_provenance: 9', $output);
        $this->assertStringContainsString('Skipped malformed_values: 2', $output);
        $this->assertStringContainsString('n=1, issue_days=1', $output);
        $this->assertStringContainsString('correct_rate=100.0%', $output);
        $this->assertSame($before, $this->bytes());
        $this->assertSame($metadata, $valid->fresh()->source_metadata);
    }

    public function test_unknown_forecast_direction_is_not_evaluated_as_flat(): void
    {
        $this->row(['direction' => 'unknown'], completed: false);
        $before = $this->bytes();
        $result = app(FixedTermForecastEvaluationService::class)->evaluateMatured(CarbonImmutable::parse('2026-03-01'));
        $this->assertSame(0, $result['evaluated']);
        $this->assertSame(1, $result['unsupported_provenance']);
        $this->assertSame($before, $this->bytes());
    }

    public function test_report_multiple_pages_keep_exact_samples_and_unique_issue_days(): void
    {
        // Reverse insertion order proves that issue-day counting does not depend on IDs.
        for ($i = 104; $i >= 0; $i--) {
            $date = CarbonImmutable::parse('2025-01-01')->addDays($i)->toDateString();
            foreach ([6, 12, 24] as $duration) {
                $this->row(['forecast_date' => $date, 'duration_months' => $duration, 'direction' => 'flat', 'actual_direction' => 'slightly_falling']);
            }
        }
        $before = $this->bytes();
        Artisan::call('forecasting:report-fixed-contracts');
        $first = Artisan::output();
        $this->assertSame(3, substr_count($first, 'n=105, issue_days=105, correct=105'));
        $this->assertSame(3, substr_count($first, 'unchanged_correct=105'));
        Artisan::call('forecasting:report-fixed-contracts');
        $this->assertSame($first, Artisan::output());
        $this->assertSame($before, $this->bytes());
        $summary = app(FixedTermForecastReport::class)->summarize(FixedContractPriceForecast::orderBy('forecast_date')->orderBy('id')->lazy(100));
        $this->assertSame(315, array_sum(array_column($summary['groups'], 'n')));
    }

    private function row(array $overrides = [], string $basis = 'observed_seller_data', float $threshold = 0.15, bool $completed = true): FixedContractPriceForecast
    {
        $date = $overrides['forecast_date'] ?? '2026-01-01';
        $horizon = $overrides['horizon_days'] ?? 30;
        $target = $overrides['target_date'] ?? CarbonImmutable::parse($date)->addDays($horizon)->toDateString();
        $duration = $overrides['duration_months'] ?? 12;
        $metadata = [
            'current_retail_pricing_basis' => $basis, 'current_retail_method_version' => 'unit_statistics_v1',
            'direction_threshold_cents_per_kwh' => $threshold,
        ];
        if ($completed) {
            $metadata += ['evaluation_method_version' => 'same_basis_v1', 'actual_retail_pricing_basis' => $basis,
                'actual_retail_method_version' => 'unit_statistics_v1', 'evaluation_direction_threshold_cents_per_kwh' => $threshold,
                'actual_retail_source_date' => $target, 'actual_retail_segment' => "fixed_term_{$duration}",
                'actual_retail_metric' => 'energy_price', 'actual_retail_contract_count' => 20];
        }

        return FixedContractPriceForecast::create(array_replace([
            'forecast_date' => $date, 'target_date' => $target, 'horizon_days' => $horizon, 'duration_months' => $duration,
            'futures_trade_date' => CarbonImmutable::parse($date)->subDay()->toDateString(),
            'target_quantile' => 'median', 'current_price_cents_per_kwh' => 1, 'forecast_price_cents_per_kwh' => 1.2,
            'expected_change_cents_per_kwh' => 0.2, 'hedge_cost_cents_per_kwh' => 0.5, 'retail_premium_cents_per_kwh' => 0.5,
            'normal_retail_premium_cents_per_kwh' => 0.7, 'fair_price_cents_per_kwh' => 1.2, 'gap_cents_per_kwh' => 0.2,
            'direction' => 'rising', 'consumer_signal' => 'wait_if_flexible', 'confidence' => 'low', 'coverage_quality' => 'all_monthly',
            'contract_count' => 20, 'model_version' => 'fixed_term_futures_adjusted_v1', 'source_metadata' => $metadata,
            'actual_price_cents_per_kwh' => $completed ? 1.3 : null, 'actual_direction' => $completed ? 'rising' : null,
            'evaluated_at' => $completed ? '2026-03-01 00:00:00' : null,
        ], $overrides));
    }

    private function bytes(): string
    {
        return json_encode(DB::table('fixed_contract_price_forecasts')->orderBy('id')->get());
    }
}
