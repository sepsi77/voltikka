<?php

namespace Tests\Feature;

use App\Models\ContractPriceDailyStatistic;
use App\Models\ElectricityFuturesEodPrice;
use App\Models\FixedContractPriceForecast;
use App\Services\ContractMarketInsights\ContractMarketInsightService;
use App\Services\PriceForecasting\FixedTermForecastEvaluationService;
use App\Services\PriceForecasting\FixedTermHedgeCostService;
use App\Services\PriceForecasting\FixedTermPriceForecastService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\Concerns\SeedsFlatForecastFutures;
use Tests\TestCase;

class FixedContractPriceForecastingTest extends TestCase
{
    use RefreshDatabase;
    use SeedsFlatForecastFutures;

    public function test_hedge_cost_uses_prior_trade_date_and_fallback_order(): void
    {
        config()->set('price_forecasting.fixed_term.area', 'FI');
        config()->set('price_forecasting.fixed_term.vat_multiplier', 1.255);

        $this->future('month', '202606', '2026-05-22', 40.0);
        $this->future('quarter', '202607', '2026-05-22', 60.0);
        $this->future('quarter', '202610', '2026-05-22', 70.0);
        $this->future('month', '202606', '2026-05-23', 100.0); // same-day settlement must not leak in

        $hedge = app(FixedTermHedgeCostService::class)->calculate(CarbonImmutable::parse('2026-05-23'), 6);

        $this->assertNotNull($hedge);
        $this->assertSame('2026-05-22', $hedge['trade_date']);
        $this->assertSame('mixed_with_quarter_fallback', $hedge['coverage_quality']);
        $this->assertSame(1, $hedge['monthly_futures_months']);
        $this->assertSame(5, $hedge['quarter_futures_months']);
        $this->assertSame([], $hedge['missing_delivery_months']);

        $weightedEurPerMwh = (
            30 * 40.0 + // Jun
            31 * 60.0 + // Jul
            31 * 60.0 + // Aug
            30 * 60.0 + // Sep
            31 * 70.0 + // Oct
            30 * 70.0   // Nov
        ) / (30 + 31 + 31 + 30 + 31 + 30);
        $expectedCentsPerKwh = $weightedEurPerMwh / 10 * 1.255;

        $this->assertEqualsWithDelta($expectedCentsPerKwh, $hedge['price_cents_per_kwh'], 0.0001);
    }

    public function test_canonical_mode_does_not_fall_back_to_observed_current_input(): void
    {
        config()->set('canonical_pricing.enabled', true);
        config()->set('price_forecasting.fixed_term.minimum_history_observations', 1);

        $this->retailStat('2026-05-22', 12, median: 9.50);
        $this->retailStat('2026-05-23', 12, median: 9.60);
        $this->monthlyCurve('2026-05-21', 60.0);
        $this->monthlyCurve('2026-05-22', 60.0);

        $forecasts = app(FixedTermPriceForecastService::class)
            ->buildForecasts(CarbonImmutable::parse('2026-05-23'), 30, [12], ['median']);

        $this->assertTrue($forecasts->isEmpty());
    }

    public function test_evaluate_command_updates_matured_forecasts_with_observed_actual_provenance(): void
    {
        config()->set('canonical_pricing.enabled', true);

        $forecast = FixedContractPriceForecast::create([
            'forecast_date' => '2026-05-01',
            'target_date' => '2026-05-31',
            'horizon_days' => 30,
            'duration_months' => 12,
            'target_quantile' => 'median',
            'current_price_cents_per_kwh' => 9.00,
            'forecast_price_cents_per_kwh' => 9.30,
            'expected_change_cents_per_kwh' => 0.30,
            'hedge_cost_cents_per_kwh' => 7.00,
            'retail_premium_cents_per_kwh' => 2.00,
            'normal_retail_premium_cents_per_kwh' => 2.10,
            'fair_price_cents_per_kwh' => 9.10,
            'gap_cents_per_kwh' => 0.10,
            'futures_trade_date' => '2026-04-30',
            'coverage_quality' => 'all_monthly',
            'confidence' => 'low',
            'direction' => 'rising',
            'consumer_signal' => 'lock_sooner',
            'contract_count' => 50,
            'model_version' => 'test_model',
            'source_metadata' => [
                'current_retail_pricing_basis' => 'observed_seller_data',
                'current_retail_method_version' => 'unit_statistics_v1',
                'direction_threshold_cents_per_kwh' => 0.15,
            ],
        ]);
        $this->retailStat('2026-05-31', 12, median: 9.20);
        $this->retailStat('2026-05-31', 12, median: 19.20, pricingBasis: 'canonical_calculation');

        $this->artisan('forecasting:evaluate-fixed-contracts --as-of=2026-06-01')
            ->assertExitCode(0);

        $forecast->refresh();
        $this->assertEqualsWithDelta(9.20, $forecast->actual_price_cents_per_kwh, 0.0001);
        $this->assertEqualsWithDelta(0.20, $forecast->actual_change_cents_per_kwh, 0.0001);
        $this->assertEqualsWithDelta(0.10, $forecast->forecast_error_cents_per_kwh, 0.0001);
        $this->assertEqualsWithDelta(0.10, $forecast->absolute_error_cents_per_kwh, 0.0001);
        $this->assertSame('rising', $forecast->actual_direction);
        $this->assertTrue($forecast->direction_correct);
        $this->assertNotNull($forecast->evaluated_at);
        $this->assertSame('observed_seller_data', $forecast->source_metadata['actual_retail_pricing_basis']);
        $this->assertSame('2026-05-31', $forecast->source_metadata['actual_retail_source_date']);
        $this->assertSame('fixed_term_12', $forecast->source_metadata['actual_retail_segment']);
        $this->assertSame('energy_price', $forecast->source_metadata['actual_retail_metric']);
    }

    public function test_public_page_hides_old_missing_and_observed_provenance_in_canonical_mode(): void
    {
        config()->set('canonical_pricing.enabled', true);
        config()->set('price_forecasting.fixed_term.model_version', 'current_model');

        $this->forecastRow('2026-05-20', 'old_model', 'canonical_calculation', 7.77);
        $this->forecastRow('2026-05-21', 'current_model', null, 8.88);
        $this->forecastRow('2026-05-22', 'current_model', 'observed_seller_data', 9.99);

        $this->get('/sahkosopimus/sahkon-hintaennuste')
            ->assertOk()
            ->assertSee('Ennusteita ei ole vielä saatavilla')
            ->assertDontSee('9,99');
    }

    public function test_public_page_and_market_insight_show_current_canonical_forecast(): void
    {
        config()->set('canonical_pricing.enabled', true);
        config()->set('price_forecasting.fixed_term.model_version', 'fixed_term_futures_adjusted_v1');
        Cache::flush();

        $this->forecastRow('2026-05-24', 'fixed_term_ewma_gap_v2', 'canonical_calculation', 9.99);
        $this->forecastRow('2026-05-23', 'fixed_term_futures_adjusted_v1', 'canonical_calculation', 8.88);
        $this->forecastRow('2026-05-24', 'fixed_term_historical_change_v1', 'canonical_calculation', 9.99);

        $this->get('/sahkosopimus/sahkon-hintaennuste')
            ->assertOk()
            ->assertDontSee('Ennusteita ei ole vielä saatavilla')
            ->assertSee('8,88')
            ->assertDontSee('9,99')
            ->assertSeeText('Ennustejakso')
            ->assertSeeText('Ennuste perustuu sähkösopimusten hintakehitykseen ja sähkön tukkumarkkinoiden hintoihin.')
            ->assertSeeText('Sähköfutuurien hinnat kuvaavat tulevien kuukausien sähkön tukkuhintaa.')
            ->assertDontSeeText('Jokainen hyväksytty muutos saa saman painon.')
            ->assertDontSeeText('kanonis')
            ->assertDontSeeText('Kolmas syöte')
            ->assertDontSeeText('settlement-hinta');

        $insight = app(ContractMarketInsightService::class)->insight(null, 5000, true);
        $this->assertSame('2026-05-23', $insight['forecast']['forecast_date']);
        $this->assertSame('Hintatason odotetaan pysyvän suunnilleen ennallaan', $insight['forecast']['headline']);
        $this->assertSame('Suunnilleen ennallaan', $insight['forecast']['direction_label']);
        $this->assertNotSame('Vakaata', $insight['forecast']['direction_label']);
    }

    public function test_public_history_uses_complete_daily_statistics_timeline_in_canonical_mode(): void
    {
        config()->set('canonical_pricing.enabled', true);
        config()->set('price_forecasting.fixed_term.model_version', 'current_model');

        $this->forecastRow('2026-04-01', 'old_model', 'observed_seller_data', 6.66);
        $this->forecastRow('2026-07-29', 'current_model', 'canonical_calculation', 18.83);

        $this->retailStat('2026-05-01', 12, median: 7.10);
        $this->retailStat('2026-06-15', 12, median: 7.20);
        $this->retailStat('2026-07-26', 12, median: 7.30);
        $this->retailStat('2026-07-27', 12, median: 7.40, pricingBasis: 'canonical_calculation');
        $this->retailStat('2026-07-29', 12, median: 99.99);
        $this->retailStat('2026-07-29', 12, median: 7.50, pricingBasis: 'canonical_calculation');

        $this->get('/sahkosopimus/sahkon-hintaennuste')
            ->assertOk()
            ->assertSeeText('Aineisto on kerätty 1.5.2026–29.7.2026.')
            ->assertSee('5 mittausta')
            ->assertSee('7,10')
            ->assertSee('7,50')
            ->assertSeeText('Vanhemmat pisteet perustuvat kyseisinä päivinä myyjiltä havaittuihin hintoihin.')
            ->assertSeeText('Uudemmat mediaanihinnat Voltikka laskee myyjiltä kerätyistä sopimus- ja hintatiedoista.')
            ->assertDontSee('6,66')
            ->assertDontSee('99,99');
    }

    public function test_public_page_keeps_feature_off_observed_forecasts_functional(): void
    {
        config()->set('canonical_pricing.enabled', false);
        config()->set('price_forecasting.fixed_term.model_version', 'current_model');

        $this->forecastRow('2026-05-23', 'current_model', 'observed_seller_data', 7.66);

        $this->get('/sahkosopimus/sahkon-hintaennuste')
            ->assertOk()
            ->assertDontSee('Ennusteita ei ole vielä saatavilla')
            ->assertSee('7,66')
            ->assertSeeText('Laskemme ennusteen erikseen 6, 12 ja 24 kuukauden sopimuksille.');
    }

    public function test_old_basis_history_cannot_upgrade_confidence_and_feature_off_ignores_canonical(): void
    {
        $this->seedFlatForecastFutures();
        $hedge = ['price_cents_per_kwh' => 7.53, 'trade_date' => '2026-05-01', 'coverage_quality' => 'all_monthly', 'monthly_futures_months' => 12, 'quarter_futures_months' => 0, 'year_futures_months' => 0, 'missing_delivery_months' => [], 'delivery_start_month' => '202606', 'delivery_end_month' => '202705'];
        $this->mock(FixedTermHedgeCostService::class)->shouldReceive('calculate')->andReturn($hedge);
        $asOf = CarbonImmutable::parse('2026-05-23');
        for ($day = 365; $day >= 0; $day--) {
            $this->retailStat($asOf->subDays($day)->toDateString(), 12, median: 9);
        }
        $this->retailStat('2026-05-23', 12, median: 11, pricingBasis: 'canonical_calculation');
        $this->retailStat('2026-05-24', 12, median: 99, pricingBasis: 'canonical_calculation');
        config()->set('canonical_pricing.enabled', true);
        $forecast = app(FixedTermPriceForecastService::class)->buildForecasts($asOf, 30, [12], ['median'])->first();
        $this->assertSame(335, $forecast['source_metadata']['history_observations']);
        $this->assertSame(0, $forecast['source_metadata']['confidence_history_observations']);
        $this->assertSame('low', $forecast['confidence']);
        $this->assertSame('2026-05-23', $forecast['source_metadata']['historical_retail_transition_date']);
        $this->retailStat('2026-05-01', 12, median: 99, pricingBasis: 'canonical_calculation');
        config()->set('canonical_pricing.enabled', false);
        app()->forgetScopedInstances();
        $forecast = app(FixedTermPriceForecastService::class)->buildForecasts($asOf, 30, [12], ['median'])->first();
        $this->assertSame(335, $forecast['source_metadata']['confidence_history_observations']);
        $this->assertSame('medium', $forecast['confidence']);
        $this->assertNull($forecast['source_metadata']['historical_retail_transition_date']);
        $this->assertSame(['observed_seller_data' => 335], $forecast['source_metadata']['historical_retail_pricing_basis_counts']);
        $this->assertEquals(9, $forecast['current_price_cents_per_kwh']);
    }

    public function test_reserved_model_versions_cannot_generate_or_overwrite(): void
    {
        foreach (['fixed_term_ewma_gap_v1', 'fixed_term_ewma_gap_v2', 'fixed_term_ewma_gap_v3', 'fixed_term_historical_change_v1', 'unknown'] as $version) {
            config()->set('price_forecasting.fixed_term.model_version', $version);
            $row = $this->forecastRow('2026-05-01', $version, null);
            $before = $row->fresh()->getRawOriginal();
            try {
                app(FixedTermPriceForecastService::class)->buildForecasts(CarbonImmutable::parse('2026-05-01'));
                $this->fail('Reserved model must be rejected.');
            } catch (\InvalidArgumentException $exception) {
                $this->assertStringContainsString('reserved', $exception->getMessage());
            }
            $this->artisan('forecasting:run-fixed-contracts --as-of=2026-05-01 --overwrite --require-freshness')
                ->expectsOutput('Generation requires fixed_term_futures_adjusted_v1. Other model names are reserved for stored forecasts.')
                ->assertExitCode(1);
            $this->assertSame($before, $row->fresh()->getRawOriginal());
        }
        $this->assertSame(5, FixedContractPriceForecast::count());
    }

    public function test_evaluation_uses_saved_canonical_basis_threshold_and_baseline_without_runtime_leakage(): void
    {
        config()->set('canonical_pricing.enabled', false);
        config()->set('price_forecasting.fixed_term.direction_threshold_cents_per_kwh', 0.01);
        $row = $this->forecastRow('2026-05-01', 'fixed_term_ewma_gap_v2', 'canonical_calculation');
        $row->update(['source_metadata' => ['current_retail_pricing_basis' => 'canonical_calculation', 'direction_threshold_cents_per_kwh' => 0.5]]);
        $before = $row->fresh()->getRawOriginal();
        $this->retailStat('2026-05-31', 12, median: 9.2, pricingBasis: 'canonical_calculation');
        $this->retailStat('2026-05-31', 12, median: 99);
        $service = app(FixedTermForecastEvaluationService::class);
        $result = $service->evaluateMatured(CarbonImmutable::parse('2026-06-01'), dryRun: true);
        $this->assertSame(1, $result['evaluated']);
        $preview = $result['forecasts']->first();
        $this->assertSame('slightly_rising', $preview->actual_direction);
        $this->assertEquals(0.2, $preview->source_metadata['unchanged_price_absolute_error_cents_per_kwh']);
        $this->assertSame('same_basis_v1', $preview->source_metadata['evaluation_method_version']);
        $this->assertSame('unit_statistics_v1', $preview->source_metadata['actual_retail_method_version']);
        $this->assertSame(0.5, $preview->source_metadata['evaluation_direction_threshold_cents_per_kwh']);
        $this->assertSame($before, $row->fresh()->getRawOriginal());
        $service->evaluateMatured(CarbonImmutable::parse('2026-06-01'));
        $saved = $row->fresh()->getRawOriginal();
        foreach ($before as $key => $value) {
            if (! in_array($key, ['actual_price_cents_per_kwh', 'actual_change_cents_per_kwh', 'forecast_error_cents_per_kwh', 'absolute_error_cents_per_kwh', 'actual_direction', 'direction_correct', 'source_metadata', 'evaluated_at', 'updated_at'], true)) {
                $this->assertSame($value, $saved[$key]);
            }
        }
        $this->assertSame(0, $service->evaluateMatured(CarbonImmutable::parse('2026-06-01'))['evaluated']);
        $this->assertSame($saved, $row->fresh()->getRawOriginal());
    }

    public function test_evaluation_fails_closed_for_incomplete_or_invalid_provenance(): void
    {
        $valid = ['current_retail_pricing_basis' => 'canonical_calculation', 'current_retail_method_version' => 'unit_statistics_v1', 'direction_threshold_cents_per_kwh' => 0.15];
        $cases = [
            ['fixed_term_ewma_gap_v2', []],
            ['fixed_term_ewma_gap_v2', ['current_retail_pricing_basis' => 'canonical_calculation']],
            ['custom', []],
            ['custom', array_diff_key($valid, ['current_retail_method_version' => true])],
            ['custom', array_replace($valid, ['current_retail_pricing_basis' => 'unknown'])],
            ['custom', array_replace($valid, ['current_retail_pricing_basis' => []])],
            ['custom', array_replace($valid, ['current_retail_method_version' => 'wrong'])],
            ['custom', array_replace($valid, ['direction_threshold_cents_per_kwh' => -1])],
            ['custom', array_replace($valid, ['direction_threshold_cents_per_kwh' => 'INF'])],
            ['custom', array_replace($valid, ['direction_threshold_cents_per_kwh' => null])],
            ['fixed_term_ewma_gap_v1', ['direction_threshold_cents_per_kwh' => null]],
            ['fixed_term_ewma_gap_v1', ['current_retail_pricing_basis' => null]],
        ];
        foreach ($cases as $index => [$version, $metadata]) {
            $row = $this->forecastRow(CarbonImmutable::parse('2026-05-01')->addDays($index)->toDateString(), $version, null);
            $row->update(['source_metadata' => $metadata]);
            $this->retailStat($row->target_date->toDateString(), 12, median: 10, pricingBasis: 'canonical_calculation');
        }
        $result = app(FixedTermForecastEvaluationService::class)->evaluateMatured(CarbonImmutable::parse('2026-07-01'));
        $this->assertSame(count($cases), $result['unsupported_provenance']);
        $this->assertSame(0, $result['evaluated']);
        $this->assertSame(0, $result['missing_actual']);
    }

    public function test_evaluation_requires_exact_basis_date_and_method_and_accepts_legacy_zero(): void
    {
        $legacy = $this->forecastRow('2026-05-01', 'fixed_term_ewma_gap_v1', null);
        $this->retailStat('2026-05-31', 12, median: 0);
        $row = $this->forecastRow('2026-05-02', 'fixed_term_ewma_gap_v2', 'canonical_calculation');
        $row->update(['source_metadata' => ['current_retail_pricing_basis' => 'canonical_calculation', 'direction_threshold_cents_per_kwh' => 0.15]]);
        $this->retailStat('2026-06-01', 12, median: 10);
        $this->retailStat('2026-05-31', 12, median: 10, pricingBasis: 'canonical_calculation');
        $this->retailStat('2026-06-01', 12, median: 10, pricingBasis: 'canonical_calculation', methodVersion: 'wrong');
        $result = app(FixedTermForecastEvaluationService::class)->evaluateMatured(CarbonImmutable::parse('2026-06-02'));
        $this->assertSame(1, $result['evaluated']);
        $this->assertSame(1, $result['missing_actual']);
        $this->assertEquals(0, $legacy->fresh()->actual_price_cents_per_kwh);
        $this->assertSame(0.15, $legacy->fresh()->source_metadata['evaluation_direction_threshold_cents_per_kwh']);
        $this->assertNull($row->fresh()->actual_price_cents_per_kwh);
    }

    public function test_evaluation_rejects_non_finite_actual_without_older_row_fallback(): void
    {
        $row = $this->forecastRow('2026-05-01', 'fixed_term_ewma_gap_v1', null);
        $this->retailStat('2026-05-31', 12, median: 9.2);
        $this->retailStat('2026-05-31', 12, median: 10);
        // SQLite needs a numeric expression; PDO binds PHP INF as text.
        DB::table('contract_price_daily_statistics')->where('id', ContractPriceDailyStatistic::max('id'))
            ->update(['median_value' => DB::raw('1e999')]);
        $result = app(FixedTermForecastEvaluationService::class)->evaluateMatured(CarbonImmutable::parse('2026-06-01'));
        $this->assertSame(0, $result['evaluated']);
        $this->assertSame(1, $result['missing_actual']);
        $this->assertNull($row->fresh()->actual_price_cents_per_kwh);
    }

    public function test_evaluation_id_chunks_dry_run_and_filters(): void
    {
        for ($index = 105; $index >= 0; $index--) {
            $date = CarbonImmutable::parse('2026-01-01')->addDays($index);
            $row = $this->forecastRow($date->toDateString(), 'fixed_term_ewma_gap_v1', null);
            $this->retailStat($row->target_date->toDateString(), 12, median: 9.2);
        }
        $excluded = $this->forecastRow('2026-01-01', 'custom', null);
        $otherHorizon = $this->forecastRow('2025-12-31', 'fixed_term_ewma_gap_v1', null);
        $otherHorizon->update(['horizon_days' => 60]);
        $before = DB::table('fixed_contract_price_forecasts')->orderBy('id')->get()->toJson();
        $this->artisan('forecasting:evaluate-fixed-contracts --as-of=2026-07-01 --horizon=30 --model-version=fixed_term_ewma_gap_v1 --dry-run')
            ->expectsOutput('Dry run: no database changes.')
            ->expectsOutput('Done. Evaluated 106 forecasts; 0 matured forecasts still lack target-date retail statistics; 0 have unsupported provenance.')
            ->assertExitCode(0);
        $this->assertSame($before, DB::table('fixed_contract_price_forecasts')->orderBy('id')->get()->toJson());
        $result = app(FixedTermForecastEvaluationService::class)->evaluateMatured(CarbonImmutable::parse('2026-07-01'), 30, 'fixed_term_ewma_gap_v1');
        $this->assertSame(106, $result['evaluated']);
        $this->assertSame(106, $result['forecasts']->pluck('id')->unique()->count());
        $this->assertNull($excluded->fresh()->actual_price_cents_per_kwh);
        $this->assertNull($otherHorizon->fresh()->actual_price_cents_per_kwh);
    }

    public function test_public_outlook_uses_all_saved_directions_and_ignores_legacy_advice(): void
    {
        config()->set('price_forecasting.fixed_term.model_version', 'qualified_test');
        $row = $this->forecastRow('2026-05-23', 'qualified_test', 'observed_seller_data');
        foreach ([
            'rising' => 'Nousua odotettavissa',
            'falling' => 'Laskua odotettavissa',
            'flat' => 'Suunnilleen ennallaan',
            'slightly_rising' => 'Suunnilleen ennallaan',
            'slightly_falling' => 'Suunnilleen ennallaan',
            'unrecognized' => 'Ennuste ei saatavilla',
        ] as $direction => $label) {
            $row->update(['direction' => $direction, 'consumer_signal' => 'wait_if_flexible', 'confidence' => 'low']);
            Cache::flush();
            $page = Livewire::test(\App\Livewire\FixedContractPriceForecast::class);
            $page->assertSeeText($label)->assertSeeText('Ennuste voi muuttua markkinatilanteen mukana.')
                ->assertDontSeeText('Lukitse pian')->assertDontSeeText('Kannattaa odottaa');
            $this->assertSame($direction === 'unrecognized' ? 'unknown' : app(FixedTermPriceForecastService::class)->directionCategory($direction), $page->viewData('rowsByDuration')[12]['signal']['key']);
            $insight = app(ContractMarketInsightService::class)->insight(null, 5000, true);
            if ($direction === 'unrecognized') {
                $this->assertNull($insight['forecast']);
            } else {
                $this->assertSame($label, $insight['forecast']['direction_label']);
            }
        }
    }

    public function test_overall_outlook_distinguishes_complete_mixed_and_partial_data_and_stored_horizon(): void
    {
        config()->set('price_forecasting.fixed_term.model_version', 'qualified_test');
        config()->set('price_forecasting.fixed_term.default_horizon_days', 45);
        $rows = collect();
        foreach ([6, 12, 24] as $duration) {
            $row = $this->forecastRow('2026-05-01', 'qualified_test', 'observed_seller_data');
            $row->update(['forecast_date' => '2026-05-23', 'duration_months' => $duration, 'horizon_days' => 45, 'target_date' => '2026-07-07', 'direction' => 'rising']);
            $rows->push($row);
        }
        $page = Livewire::test(\App\Livewire\FixedContractPriceForecast::class);
        $this->assertSame('Hintojen odotetaan nousevan', $page->viewData('overall')['headline']);
        $page->assertSeeText('45 päivän hintanäkymä')->assertSeeText('23.5.2026–7.7.2026')->assertDontSeeText('30 päivän');
        $response = $this->get('/sahkosopimus/sahkon-hintaennuste')->assertOk()
            ->assertSee('Sähkön hintaennuste: mihin määräaikaisten hinnat ovat menossa?')
            ->assertDontSee('kannattaako lukita')->assertDontSee('Suositus (')
            ->assertDontSeeText('Suuntaa antava arvio, ei varma hintakehitys.')
            ->assertDontSeeText('ridge')->assertDontSeeText('kohortti');
        $this->assertSame(1, substr_count(strip_tags($response->getContent()), 'Ennuste voi muuttua markkinatilanteen mukana.'));
        $rows[2]->update(['direction' => 'falling']);
        Livewire::test(\App\Livewire\FixedContractPriceForecast::class)->assertSeeText('Hintojen suunnat eroavat sopimuspituuksittain');
        $rows[2]->update(['direction' => 'unknown']);
        Livewire::test(\App\Livewire\FixedContractPriceForecast::class)->assertSeeText('Hintanäkymä on saatavilla vain osalle sopimuspituuksista');
        $rows[1]->delete();
        $rows[2]->delete();
        Livewire::test(\App\Livewire\FixedContractPriceForecast::class)->assertSeeText('Hintanäkymä on saatavilla vain osalle sopimuspituuksista');
    }

    private function retailStat(
        string $date,
        int $durationMonths,
        ?float $p20 = null,
        ?float $median = null,
        ?float $p80 = null,
        string $pricingBasis = 'observed_seller_data',
        string $methodVersion = 'unit_statistics_v1',
    ): void {
        ContractPriceDailyStatistic::create([
            'stat_date' => $date,
            'segment_key' => "fixed_term_{$durationMonths}",
            'metric_key' => 'energy_price',
            'pricing_basis' => $pricingBasis,
            'method_version' => $methodVersion,
            'consumption_kwh' => null,
            'p20_value' => $p20,
            'median_value' => $median,
            'p80_value' => $p80,
            'contract_count' => 50,
        ]);
    }

    private function forecastRow(
        string $forecastDate,
        string $modelVersion,
        ?string $currentRetailPricingBasis,
        float $currentPrice = 9.00,
    ): FixedContractPriceForecast {
        return FixedContractPriceForecast::create([
            'forecast_date' => $forecastDate,
            'target_date' => CarbonImmutable::parse($forecastDate)->addDays(30)->toDateString(),
            'horizon_days' => 30,
            'duration_months' => 12,
            'target_quantile' => 'median',
            'current_price_cents_per_kwh' => $currentPrice,
            'forecast_price_cents_per_kwh' => $currentPrice + 0.10,
            'expected_change_cents_per_kwh' => 0.10,
            'hedge_cost_cents_per_kwh' => 7.00,
            'retail_premium_cents_per_kwh' => 2.00,
            'normal_retail_premium_cents_per_kwh' => 2.10,
            'fair_price_cents_per_kwh' => $currentPrice + 0.20,
            'gap_cents_per_kwh' => 0.20,
            'futures_trade_date' => CarbonImmutable::parse($forecastDate)->subDay()->toDateString(),
            'coverage_quality' => 'all_monthly',
            'confidence' => 'low',
            'direction' => 'slightly_rising',
            'consumer_signal' => 'neutral',
            'contract_count' => 50,
            'model_version' => $modelVersion,
            'source_metadata' => $currentRetailPricingBasis === null
                ? []
                : ['current_retail_pricing_basis' => $currentRetailPricingBasis],
        ]);
    }

    private function monthlyCurve(string $tradeDate, float $settlementPrice): void
    {
        foreach ($this->deliveryMonthsFor2026MayAsOf12Months() as $maturity) {
            $this->future('month', $maturity, $tradeDate, $settlementPrice);
        }
    }

    private function deliveryMonthsFor2026MayAsOf12Months(): array
    {
        return [
            '202606', '202607', '202608', '202609', '202610', '202611',
            '202612', '202701', '202702', '202703', '202704', '202705',
        ];
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
                default => 'FNBX',
            },
            'maturity' => $maturity,
            'maturity_type' => $maturityType,
            'trade_date' => $tradeDate,
            'settlement_price' => $settlementPrice,
        ]);
    }
}
