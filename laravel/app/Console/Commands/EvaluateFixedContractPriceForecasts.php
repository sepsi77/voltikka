<?php

namespace App\Console\Commands;

use App\Services\PriceForecasting\FixedTermForecastEvaluationService;
use App\Services\PriceForecasting\FixedTermForecastReport;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

class EvaluateFixedContractPriceForecasts extends Command
{
    protected $signature = 'forecasting:evaluate-fixed-contracts
        {--as-of= : Evaluate forecasts with target dates up to this date. Defaults to today in Europe/Helsinki.}
        {--horizon= : Limit to one forecast horizon in days.}
        {--model-version= : Limit to one model version.}
        {--dry-run : Calculate evaluation results without writing to the database.}';

    protected $description = 'Evaluate matured fixed-term contract price forecasts against realized retail price statistics';

    public function handle(FixedTermForecastEvaluationService $evaluationService, FixedTermForecastReport $report): int
    {
        $asOf = $this->option('as-of')
            ? CarbonImmutable::parse($this->option('as-of'))->startOfDay()
            : CarbonImmutable::now('Europe/Helsinki')->startOfDay();

        $horizon = $this->option('horizon') !== null ? (int) $this->option('horizon') : null;
        $modelVersion = $this->option('model-version') ?: null;

        $dryRun = (bool) $this->option('dry-run');
        if ($dryRun) {
            $this->info('Dry run: no database changes.');
        }

        $result = $evaluationService->evaluateMatured($asOf, $horizon, $modelVersion, $dryRun);

        foreach ($result['forecasts'] as $forecast) {
            $this->line(sprintf(
                '%s -> %s %dm %s: forecast %.4f, actual %.4f, error %+.4f c/kWh, direction %s/%s',
                $forecast->forecast_date->toDateString(),
                $forecast->target_date->toDateString(),
                $forecast->duration_months,
                $forecast->target_quantile,
                $forecast->forecast_price_cents_per_kwh,
                $forecast->actual_price_cents_per_kwh,
                $forecast->forecast_error_cents_per_kwh,
                $forecast->direction,
                $forecast->actual_direction,
            ));
        }

        $this->info(sprintf(
            'Done. Evaluated %d forecasts; %d matured forecasts still lack target-date retail statistics; %d have unsupported provenance.',
            $result['evaluated'],
            $result['missing_actual'],
            $result['unsupported_provenance'],
        ));

        $this->info('Direction outcomes for newly evaluated median forecasts:');
        foreach ($report->lines($report->summarize($result['forecasts']->sortBy([
            ['forecast_date', 'asc'], ['id', 'asc'],
        ]))) as $line) {
            $this->line($line);
        }

        return self::SUCCESS;
    }
}
