<?php

namespace Tests\Feature;

use App\Models\ContractPriceDailyStatistic;
use App\Models\ElectricityFuturesEodPrice;
use App\Models\FixedContractPriceForecast;
use App\Services\ContractMarketInsights\ContractMarketInsightService;
use App\Services\PriceForecasting\FixedTermHedgeCostService;
use App\Services\PriceForecasting\FixedTermPriceForecastService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class FixedContractPriceForecastingTest extends TestCase
{
    use RefreshDatabase;

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

    public function test_run_command_persists_fixed_contract_forecast(): void
    {
        config()->set('price_forecasting.fixed_term.minimum_history_observations', 2);
        config()->set('price_forecasting.fixed_term.gap_closure_lambda', 0.30);
        config()->set('price_forecasting.fixed_term.ewma_alpha', 0.25);
        config()->set('price_forecasting.fixed_term.model_version', 'test_model');

        $this->retailStat('2026-05-21', 12, median: 9.40);
        $this->retailStat('2026-05-22', 12, median: 9.50);
        $this->retailStat('2026-05-23', 12, median: 9.60);
        $this->monthlyCurve('2026-05-20', 60.0);
        $this->monthlyCurve('2026-05-21', 60.0);
        $this->monthlyCurve('2026-05-22', 60.0);

        $this->artisan('forecasting:run-fixed-contracts --as-of=2026-05-23 --horizon=30 --duration=12 --quantile=median')
            ->assertExitCode(0);

        $forecast = FixedContractPriceForecast::first();
        $this->assertNotNull($forecast);
        $this->assertSame('2026-05-23', $forecast->forecast_date->toDateString());
        $this->assertSame('2026-06-22', $forecast->target_date->toDateString());
        $this->assertSame(12, $forecast->duration_months);
        $this->assertSame('median', $forecast->target_quantile);
        $this->assertSame('test_model', $forecast->model_version);
        $this->assertSame('low', $forecast->confidence);
        $this->assertNotNull($forecast->source_metadata['history_observations'] ?? null);
        $this->assertSame('observed_seller_data', $forecast->source_metadata['current_retail_pricing_basis']);
        $this->assertSame('2026-05-23', $forecast->source_metadata['current_retail_source_date']);
        $this->assertSame('fixed_term_12', $forecast->source_metadata['current_retail_segment']);
        $this->assertSame('energy_price', $forecast->source_metadata['current_retail_metric']);
        $this->assertEqualsWithDelta(9.60, $forecast->current_price_cents_per_kwh, 0.0001);
    }

    public function test_canonical_mode_requires_canonical_current_input_and_records_observed_history_provenance(): void
    {
        config()->set('canonical_pricing.enabled', true);
        config()->set('price_forecasting.fixed_term.minimum_history_observations', 2);
        config()->set('price_forecasting.fixed_term.model_version', 'canonical_model');

        $this->retailStat('2026-05-21', 12, median: 9.40);
        $this->retailStat('2026-05-21', 12, median: 9.45);
        $this->retailStat('2026-05-22', 12, median: 9.50);
        $this->retailStat('2026-05-23', 12, median: 19.60);
        $this->retailStat('2026-05-23', 12, median: 9.60, pricingBasis: 'canonical_calculation');
        $this->monthlyCurve('2026-05-20', 60.0);
        $this->monthlyCurve('2026-05-21', 60.0);
        $this->monthlyCurve('2026-05-22', 60.0);

        $forecast = app(FixedTermPriceForecastService::class)
            ->buildForecasts(CarbonImmutable::parse('2026-05-23'), 30, [12], ['median'])
            ->first();

        $this->assertNotNull($forecast);
        $this->assertEqualsWithDelta(9.60, $forecast['current_price_cents_per_kwh'], 0.0001);
        $this->assertSame('canonical_calculation', $forecast['source_metadata']['current_retail_pricing_basis']);
        $this->assertSame('2026-05-23', $forecast['source_metadata']['current_retail_source_date']);
        $this->assertSame('fixed_term_12', $forecast['source_metadata']['current_retail_segment']);
        $this->assertSame('energy_price', $forecast['source_metadata']['current_retail_metric']);
        $this->assertSame(2, $forecast['source_metadata']['historical_retail_observations']);
        $this->assertSame(
            ['observed_seller_data' => 2],
            $forecast['source_metadata']['historical_retail_pricing_basis_counts'],
        );
        $this->assertSame('2026-05-21', $forecast['source_metadata']['historical_retail_source_start_date']);
        $this->assertSame('2026-05-22', $forecast['source_metadata']['historical_retail_source_end_date']);
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

    public function test_new_model_version_preserves_prior_forecast_rows(): void
    {
        config()->set('canonical_pricing.enabled', false);
        config()->set('price_forecasting.fixed_term.minimum_history_observations', 1);
        config()->set('price_forecasting.fixed_term.model_version', 'fixed_term_ewma_gap_v2');

        $this->forecastRow('2026-05-23', 'fixed_term_ewma_gap_v1', 'observed_seller_data');
        $this->retailStat('2026-05-22', 12, median: 9.50);
        $this->retailStat('2026-05-23', 12, median: 9.60);
        $this->monthlyCurve('2026-05-21', 60.0);
        $this->monthlyCurve('2026-05-22', 60.0);

        $this->artisan('forecasting:run-fixed-contracts --as-of=2026-05-23 --horizon=30 --duration=12 --quantile=median')
            ->assertExitCode(0);

        $this->assertSame(
            ['fixed_term_ewma_gap_v1', 'fixed_term_ewma_gap_v2'],
            FixedContractPriceForecast::query()->orderBy('model_version')->pluck('model_version')->all(),
        );
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
            'source_metadata' => [],
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
        config()->set('price_forecasting.fixed_term.model_version', 'current_model');
        Cache::flush();

        $this->forecastRow('2026-05-22', 'current_model', 'observed_seller_data', 9.99);
        $this->forecastRow('2026-05-23', 'current_model', 'canonical_calculation', 8.88);

        $this->get('/sahkosopimus/sahkon-hintaennuste')
            ->assertOk()
            ->assertDontSee('Ennusteita ei ole vielä saatavilla')
            ->assertSee('8,88')
            ->assertDontSee('9,99')
            ->assertSee('Voltikan kanonisesta hintalaskennasta');

        $insight = app(ContractMarketInsightService::class)->insight(null, 5000, true);
        $this->assertSame('2026-05-23', $insight['forecast']['forecast_date']);
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
            ->assertSee('kyseisen päivän myyjiltä havaitusta hintatilastosta');
    }

    private function retailStat(
        string $date,
        int $durationMonths,
        ?float $p20 = null,
        ?float $median = null,
        ?float $p80 = null,
        string $pricingBasis = 'observed_seller_data',
    ): void {
        ContractPriceDailyStatistic::create([
            'stat_date' => $date,
            'segment_key' => "fixed_term_{$durationMonths}",
            'metric_key' => 'energy_price',
            'pricing_basis' => $pricingBasis,
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
