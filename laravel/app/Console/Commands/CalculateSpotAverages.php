<?php

namespace App\Console\Commands;

use App\Services\SpotPriceAverageService;
use Illuminate\Console\Command;

class CalculateSpotAverages extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'spot:averages
                            {--type= : Calculate specific type only (daily, monthly, yearly, rolling)}
                            {--region=FI : Region to calculate averages for}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Calculate and store spot price averages (daily, monthly, yearly, rolling)';

    public function __construct(private readonly SpotPriceAverageService $averageService)
    {
        parent::__construct();
    }

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $region = $this->option('region');
        $type = $this->option('type');

        $this->info("Calculating spot price averages for region: {$region}");

        if ($type === null || $type === 'all') {
            $this->averageService->calculateAllAverages($region);
            $this->info('All averages calculated successfully.');
        } else {
            match ($type) {
                'daily' => $this->calculateDaily($region),
                'monthly' => $this->calculateMonthly($region),
                'yearly' => $this->calculateYearly($region),
                'rolling' => $this->calculateRolling($region),
                default => $this->error("Unknown type: {$type}. Use: daily, monthly, yearly, rolling"),
            };
        }

        // Display current averages
        $this->displayCurrentAverages($region);

        return Command::SUCCESS;
    }

    private function calculateDaily(string $region): void
    {
        $count = $this->averageService->calculateDailyAverages($region);
        $this->info("Calculated {$count} daily averages.");
    }

    private function calculateMonthly(string $region): void
    {
        $count = $this->averageService->calculateMonthlyAverages($region);
        $this->info("Calculated {$count} monthly averages.");
    }

    private function calculateYearly(string $region): void
    {
        $count = $this->averageService->calculateYearlyAverages($region);
        $this->info("Calculated {$count} yearly averages.");
    }

    private function calculateRolling(string $region): void
    {
        $this->averageService->calculateRollingAverages($region);
        $this->info('Calculated rolling averages (30d and 365d).');
    }

    private function displayCurrentAverages(string $region): void
    {
        $averages = $this->averageService->getLatestAverages($region);

        $this->newLine();
        $this->info('Current Spot Price Averages:');
        $this->table(
            ['Period', 'Avg (excl. VAT)', 'Avg (incl. VAT)', 'Day Avg', 'Night Avg', 'Hours'],
            [
                [
                    'Today',
                    $this->formatPrice($averages['daily']['avg_price_without_tax'] ?? null),
                    $this->formatPrice($averages['daily']['avg_price_with_tax'] ?? null),
                    $this->formatPrice($averages['daily']['day_avg_with_tax'] ?? null),
                    $this->formatPrice($averages['daily']['night_avg_with_tax'] ?? null),
                    $averages['daily']['hours_count'] ?? '-',
                ],
                [
                    'This Month',
                    $this->formatPrice($averages['monthly']['avg_price_without_tax'] ?? null),
                    $this->formatPrice($averages['monthly']['avg_price_with_tax'] ?? null),
                    $this->formatPrice($averages['monthly']['day_avg_with_tax'] ?? null),
                    $this->formatPrice($averages['monthly']['night_avg_with_tax'] ?? null),
                    $averages['monthly']['hours_count'] ?? '-',
                ],
                [
                    'Rolling 30d',
                    $this->formatPrice($averages['rolling_30d']['avg_price_without_tax'] ?? null),
                    $this->formatPrice($averages['rolling_30d']['avg_price_with_tax'] ?? null),
                    $this->formatPrice($averages['rolling_30d']['day_avg_with_tax'] ?? null),
                    $this->formatPrice($averages['rolling_30d']['night_avg_with_tax'] ?? null),
                    $averages['rolling_30d']['hours_count'] ?? '-',
                ],
                [
                    'Rolling 365d',
                    $this->formatPrice($averages['rolling_365d']['avg_price_without_tax'] ?? null),
                    $this->formatPrice($averages['rolling_365d']['avg_price_with_tax'] ?? null),
                    $this->formatPrice($averages['rolling_365d']['day_avg_with_tax'] ?? null),
                    $this->formatPrice($averages['rolling_365d']['night_avg_with_tax'] ?? null),
                    $averages['rolling_365d']['hours_count'] ?? '-',
                ],
            ]
        );
    }

    private function formatPrice(?float $price): string
    {
        if ($price === null) {
            return '-';
        }
        return number_format($price, 2) . ' c/kWh';
    }
}
