<?php

namespace App\Console\Commands;

use App\Models\FixedContractPriceForecast;
use App\Services\PriceForecasting\FixedTermForecastReport;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

class ReportFixedContractPriceForecasts extends Command
{
    protected $signature = 'forecasting:report-fixed-contracts
        {--model-version= : Stored model version, or all; defaults to the configured model}
        {--horizon= : Horizon in days; defaults to the configured horizon}
        {--from= : First forecast issue date (YYYY-MM-DD)}
        {--to= : Last forecast issue date (YYYY-MM-DD)}';

    protected $description = 'Read stored completed median forecasts in separate compatible evaluation groups';

    public function handle(FixedTermForecastReport $report): int
    {
        $horizon = $this->option('horizon') ?? config('price_forecasting.fixed_term.default_horizon_days', 30);
        if (filter_var($horizon, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) === false) {
            $this->error('Horizon must be a positive integer.');

            return self::FAILURE;
        }
        $from = $this->option('from');
        $to = $this->option('to');
        foreach ([$from, $to] as $date) {
            if ($date !== null && (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || ! CarbonImmutable::hasFormat($date, 'Y-m-d')
                || CarbonImmutable::parse($date)->toDateString() !== $date)) {
                $this->error('Dates must be valid YYYY-MM-DD dates.');

                return self::FAILURE;
            }
        }
        if ($from !== null && $to !== null && $from > $to) {
            $this->error('The first date must not follow the last date.');

            return self::FAILURE;
        }
        $version = $this->option('model-version') ?? config('price_forecasting.fixed_term.model_version', 'fixed_term_futures_adjusted_v1');
        $query = FixedContractPriceForecast::query()
            ->where('target_quantile', 'median')
            ->whereNotNull('actual_price_cents_per_kwh')
            ->where('horizon_days', (int) $horizon)
            ->when($version !== 'all', fn ($query) => $query->where('model_version', $version))
            ->when($from !== null, fn ($query) => $query->whereDate('forecast_date', '>=', $from))
            ->when($to !== null, fn ($query) => $query->whereDate('forecast_date', '<=', $to))
            ->orderBy('forecast_date')->orderBy('id');

        $this->info('Read-only report. Stored median forecasts only. Groups do not share a denominator.');
        foreach ($report->lines($report->summarize($query->lazy(100))) as $line) {
            $this->line($line);
        }

        return self::SUCCESS;
    }
}
