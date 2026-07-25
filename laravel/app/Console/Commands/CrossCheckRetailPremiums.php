<?php

namespace App\Console\Commands;

use App\Services\RetailPremium\RetailPremiumCrossCheckService;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

class CrossCheckRetailPremiums extends Command
{
    protected $signature = 'retail-premiums:cross-check
        {--as-of= : Forecast date to compare. Defaults to today in Europe/Helsinki.}';

    protected $description = 'Compare per-lineage fixed-term premiums with market-level EWMA forecasts';

    public function handle(RetailPremiumCrossCheckService $service): int
    {
        $asOf = $this->option('as-of')
            ? CarbonImmutable::parse($this->option('as-of'), 'Europe/Helsinki')->startOfDay()
            : CarbonImmutable::now('Europe/Helsinki')->startOfDay();
        $results = $service->compare($asOf);

        if ($results->isEmpty()) {
            $this->warn('No comparable fixed-term retail premium observations were found.');

            return self::SUCCESS;
        }

        $this->table(
            ['Duration', 'Rows', 'Dataset median', 'Market current', 'Market EWMA', 'Diff vs EWMA'],
            $results->map(fn (array $result) => [
                $result['duration_months'].' months',
                $result['observation_count'],
                $this->price($result['dataset_median_retail_premium_cents_per_kwh']),
                $this->price($result['forecast_current_retail_premium_cents_per_kwh']),
                $this->price($result['forecast_normal_ewma_retail_premium_cents_per_kwh']),
                $this->price($result['difference_from_normal_ewma_cents_per_kwh']),
            ])->all(),
        );

        foreach ($results as $result) {
            $this->line($result['duration_months'].' months by company:');

            foreach ($result['company_medians_cents_per_kwh'] as $company => $premium) {
                $this->line(sprintf('  %s: %s', $company, $this->price($premium)));
            }
        }

        return self::SUCCESS;
    }

    private function price(?float $value): string
    {
        return $value === null ? 'n/a' : sprintf('%+.4f c/kWh', $value);
    }
}
