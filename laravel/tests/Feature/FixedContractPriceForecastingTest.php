<?php

namespace Tests\Feature;

use App\Models\ContractPriceDailyStatistic;
use App\Models\ElectricityFuturesEodPrice;
use App\Models\FixedContractPriceForecast;
use App\Services\PriceForecasting\FixedTermHedgeCostService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
        $this->assertEqualsWithDelta(9.60, $forecast->current_price_cents_per_kwh, 0.0001);
    }

    public function test_evaluate_command_updates_matured_forecasts_with_actual_prices(): void
    {
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
    }

    private function retailStat(string $date, int $durationMonths, ?float $p20 = null, ?float $median = null, ?float $p80 = null): void
    {
        ContractPriceDailyStatistic::create([
            'stat_date' => $date,
            'segment_key' => "fixed_term_{$durationMonths}",
            'metric_key' => 'energy_price',
            'consumption_kwh' => null,
            'p20_value' => $p20,
            'median_value' => $median,
            'p80_value' => $p80,
            'contract_count' => 50,
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
