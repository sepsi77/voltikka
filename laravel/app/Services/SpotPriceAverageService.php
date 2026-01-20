<?php

namespace App\Services;

use App\Models\SpotPriceAverage;
use App\Models\SpotPriceHour;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class SpotPriceAverageService
{
    /**
     * Day hours in Finnish time: 07:00-22:00 (UTC: depends on DST).
     * Night hours: 22:00-07:00.
     */
    private const DAY_START_HOUR = 7;
    private const DAY_END_HOUR = 22;

    /**
     * Calculate and store all averages.
     *
     * @param string $region Region code (default: FI)
     */
    public function calculateAllAverages(string $region = 'FI'): void
    {
        Log::info('Calculating all spot price averages', ['region' => $region]);

        $this->calculateDailyAverages($region);
        $this->calculateMonthlyAverages($region);
        $this->calculateYearlyAverages($region);
        $this->calculateRollingAverages($region);

        Log::info('Spot price averages calculation completed');
    }

    /**
     * Calculate daily averages for all dates with data.
     */
    public function calculateDailyAverages(string $region = 'FI'): int
    {
        $dates = SpotPriceHour::forRegion($region)
            ->selectRaw('DATE(utc_datetime) as date')
            ->groupByRaw('DATE(utc_datetime)')
            ->pluck('date');

        $count = 0;
        foreach ($dates as $date) {
            $this->calculateDailyAverage($date, $region);
            $count++;
        }

        Log::info("Calculated {$count} daily averages");
        return $count;
    }

    /**
     * Calculate daily average for a specific date.
     */
    public function calculateDailyAverage(string $date, string $region = 'FI'): ?SpotPriceAverage
    {
        $startOfDay = Carbon::parse($date, 'UTC')->startOfDay();
        $endOfDay = Carbon::parse($date, 'UTC')->endOfDay();

        $hours = SpotPriceHour::forRegion($region)
            ->whereBetween('utc_datetime', [$startOfDay, $endOfDay])
            ->get();

        if ($hours->isEmpty()) {
            return null;
        }

        $stats = $this->calculateStats($hours);

        return SpotPriceAverage::updateOrCreate(
            [
                'region' => $region,
                'period_type' => SpotPriceAverage::PERIOD_DAILY,
                'period_start' => $date,
            ],
            [
                'period_end' => $date,
                'avg_price_without_tax' => $stats['avg_without_tax'],
                'avg_price_with_tax' => $stats['avg_with_tax'],
                'day_avg_without_tax' => $stats['day_avg_without_tax'],
                'day_avg_with_tax' => $stats['day_avg_with_tax'],
                'night_avg_without_tax' => $stats['night_avg_without_tax'],
                'night_avg_with_tax' => $stats['night_avg_with_tax'],
                'min_price_without_tax' => $stats['min_without_tax'],
                'max_price_without_tax' => $stats['max_without_tax'],
                'hours_count' => $hours->count(),
            ]
        );
    }

    /**
     * Calculate monthly averages for all months with data.
     */
    public function calculateMonthlyAverages(string $region = 'FI'): int
    {
        // Get distinct months using SQLite-compatible syntax
        $months = SpotPriceHour::forRegion($region)
            ->selectRaw("strftime('%Y-%m', utc_datetime) as month")
            ->groupByRaw("strftime('%Y-%m', utc_datetime)")
            ->pluck('month');

        $count = 0;
        foreach ($months as $month) {
            $this->calculateMonthlyAverage($month, $region);
            $count++;
        }

        Log::info("Calculated {$count} monthly averages");
        return $count;
    }

    /**
     * Calculate monthly average for a specific month.
     */
    public function calculateMonthlyAverage(string $month, string $region = 'FI'): ?SpotPriceAverage
    {
        $startOfMonth = Carbon::parse($month . '-01', 'UTC')->startOfMonth();
        $endOfMonth = $startOfMonth->copy()->endOfMonth();

        $hours = SpotPriceHour::forRegion($region)
            ->whereBetween('utc_datetime', [$startOfMonth, $endOfMonth])
            ->get();

        if ($hours->isEmpty()) {
            return null;
        }

        $stats = $this->calculateStats($hours);

        return SpotPriceAverage::updateOrCreate(
            [
                'region' => $region,
                'period_type' => SpotPriceAverage::PERIOD_MONTHLY,
                'period_start' => $startOfMonth->toDateString(),
            ],
            [
                'period_end' => $endOfMonth->toDateString(),
                'avg_price_without_tax' => $stats['avg_without_tax'],
                'avg_price_with_tax' => $stats['avg_with_tax'],
                'day_avg_without_tax' => $stats['day_avg_without_tax'],
                'day_avg_with_tax' => $stats['day_avg_with_tax'],
                'night_avg_without_tax' => $stats['night_avg_without_tax'],
                'night_avg_with_tax' => $stats['night_avg_with_tax'],
                'min_price_without_tax' => $stats['min_without_tax'],
                'max_price_without_tax' => $stats['max_without_tax'],
                'hours_count' => $hours->count(),
            ]
        );
    }

    /**
     * Calculate yearly averages for all years with data.
     */
    public function calculateYearlyAverages(string $region = 'FI'): int
    {
        $years = SpotPriceHour::forRegion($region)
            ->selectRaw("strftime('%Y', utc_datetime) as year")
            ->groupByRaw("strftime('%Y', utc_datetime)")
            ->pluck('year');

        $count = 0;
        foreach ($years as $year) {
            $this->calculateYearlyAverage((int)$year, $region);
            $count++;
        }

        Log::info("Calculated {$count} yearly averages");
        return $count;
    }

    /**
     * Calculate yearly average for a specific year.
     */
    public function calculateYearlyAverage(int $year, string $region = 'FI'): ?SpotPriceAverage
    {
        $startOfYear = Carbon::create($year, 1, 1, 0, 0, 0, 'UTC');
        $endOfYear = $startOfYear->copy()->endOfYear();

        $hours = SpotPriceHour::forRegion($region)
            ->whereBetween('utc_datetime', [$startOfYear, $endOfYear])
            ->get();

        if ($hours->isEmpty()) {
            return null;
        }

        $stats = $this->calculateStats($hours);

        return SpotPriceAverage::updateOrCreate(
            [
                'region' => $region,
                'period_type' => SpotPriceAverage::PERIOD_YEARLY,
                'period_start' => $startOfYear->toDateString(),
            ],
            [
                'period_end' => $endOfYear->toDateString(),
                'avg_price_without_tax' => $stats['avg_without_tax'],
                'avg_price_with_tax' => $stats['avg_with_tax'],
                'day_avg_without_tax' => $stats['day_avg_without_tax'],
                'day_avg_with_tax' => $stats['day_avg_with_tax'],
                'night_avg_without_tax' => $stats['night_avg_without_tax'],
                'night_avg_with_tax' => $stats['night_avg_with_tax'],
                'min_price_without_tax' => $stats['min_without_tax'],
                'max_price_without_tax' => $stats['max_without_tax'],
                'hours_count' => $hours->count(),
            ]
        );
    }

    /**
     * Calculate rolling averages (30 days and 365 days).
     */
    public function calculateRollingAverages(string $region = 'FI'): void
    {
        $today = Carbon::today('UTC');

        // Rolling 30 days
        $this->calculateRolling30DayAverage($today, $region);

        // Rolling 365 days
        $this->calculateRolling365DayAverage($today, $region);
    }

    /**
     * Calculate rolling 30-day average ending on a specific date.
     */
    public function calculateRolling30DayAverage(Carbon $endDate, string $region = 'FI'): ?SpotPriceAverage
    {
        $startDate = $endDate->copy()->subDays(29)->startOfDay();
        $endDateTime = $endDate->copy()->endOfDay();

        $hours = SpotPriceHour::forRegion($region)
            ->whereBetween('utc_datetime', [$startDate, $endDateTime])
            ->get();

        if ($hours->isEmpty()) {
            return null;
        }

        $stats = $this->calculateStats($hours);

        return SpotPriceAverage::updateOrCreate(
            [
                'region' => $region,
                'period_type' => SpotPriceAverage::PERIOD_ROLLING_30D,
                'period_start' => $endDate->toDateString(),
            ],
            [
                'period_end' => $endDate->toDateString(),
                'avg_price_without_tax' => $stats['avg_without_tax'],
                'avg_price_with_tax' => $stats['avg_with_tax'],
                'day_avg_without_tax' => $stats['day_avg_without_tax'],
                'day_avg_with_tax' => $stats['day_avg_with_tax'],
                'night_avg_without_tax' => $stats['night_avg_without_tax'],
                'night_avg_with_tax' => $stats['night_avg_with_tax'],
                'min_price_without_tax' => $stats['min_without_tax'],
                'max_price_without_tax' => $stats['max_without_tax'],
                'hours_count' => $hours->count(),
            ]
        );
    }

    /**
     * Calculate rolling 365-day average ending on a specific date.
     */
    public function calculateRolling365DayAverage(Carbon $endDate, string $region = 'FI'): ?SpotPriceAverage
    {
        $startDate = $endDate->copy()->subDays(364)->startOfDay();
        $endDateTime = $endDate->copy()->endOfDay();

        $hours = SpotPriceHour::forRegion($region)
            ->whereBetween('utc_datetime', [$startDate, $endDateTime])
            ->get();

        if ($hours->isEmpty()) {
            return null;
        }

        $stats = $this->calculateStats($hours);

        return SpotPriceAverage::updateOrCreate(
            [
                'region' => $region,
                'period_type' => SpotPriceAverage::PERIOD_ROLLING_365D,
                'period_start' => $endDate->toDateString(),
            ],
            [
                'period_end' => $endDate->toDateString(),
                'avg_price_without_tax' => $stats['avg_without_tax'],
                'avg_price_with_tax' => $stats['avg_with_tax'],
                'day_avg_without_tax' => $stats['day_avg_without_tax'],
                'day_avg_with_tax' => $stats['day_avg_with_tax'],
                'night_avg_without_tax' => $stats['night_avg_without_tax'],
                'night_avg_with_tax' => $stats['night_avg_with_tax'],
                'min_price_without_tax' => $stats['min_without_tax'],
                'max_price_without_tax' => $stats['max_without_tax'],
                'hours_count' => $hours->count(),
            ]
        );
    }

    /**
     * Calculate statistics from a collection of hourly prices.
     *
     * @param \Illuminate\Support\Collection $hours Collection of SpotPriceHour models
     * @return array Statistics array
     */
    private function calculateStats($hours): array
    {
        $dayHours = $hours->filter(fn($h) => $this->isDayHour($h->utc_datetime));
        $nightHours = $hours->filter(fn($h) => !$this->isDayHour($h->utc_datetime));

        // Calculate averages
        $avgWithoutTax = $hours->avg('price_without_tax');
        $avgWithTax = $hours->avg(fn($h) => $h->price_with_tax);

        // Day/night averages
        $dayAvgWithoutTax = $dayHours->isNotEmpty() ? $dayHours->avg('price_without_tax') : null;
        $dayAvgWithTax = $dayHours->isNotEmpty() ? $dayHours->avg(fn($h) => $h->price_with_tax) : null;
        $nightAvgWithoutTax = $nightHours->isNotEmpty() ? $nightHours->avg('price_without_tax') : null;
        $nightAvgWithTax = $nightHours->isNotEmpty() ? $nightHours->avg(fn($h) => $h->price_with_tax) : null;

        return [
            'avg_without_tax' => $avgWithoutTax,
            'avg_with_tax' => $avgWithTax,
            'day_avg_without_tax' => $dayAvgWithoutTax,
            'day_avg_with_tax' => $dayAvgWithTax,
            'night_avg_without_tax' => $nightAvgWithoutTax,
            'night_avg_with_tax' => $nightAvgWithTax,
            'min_without_tax' => $hours->min('price_without_tax'),
            'max_without_tax' => $hours->max('price_without_tax'),
        ];
    }

    /**
     * Check if a UTC datetime falls within Finnish day hours (07:00-22:00 Finnish time).
     */
    private function isDayHour(Carbon $utcDatetime): bool
    {
        $finnishHour = $utcDatetime->copy()->setTimezone('Europe/Helsinki')->hour;
        return $finnishHour >= self::DAY_START_HOUR && $finnishHour < self::DAY_END_HOUR;
    }

    /**
     * Get the latest averages for display.
     *
     * @param string $region
     * @return array
     */
    public function getLatestAverages(string $region = 'FI'): array
    {
        $rolling30d = SpotPriceAverage::latestRolling30Days($region);
        $rolling365d = SpotPriceAverage::latestRolling365Days($region);

        $today = Carbon::today('UTC')->toDateString();
        $daily = SpotPriceAverage::forDate($today, $region);

        $currentMonth = Carbon::today('UTC')->format('Y-m');
        $monthly = SpotPriceAverage::forMonth($currentMonth, $region);

        return [
            'daily' => $daily?->toArray(),
            'monthly' => $monthly?->toArray(),
            'rolling_30d' => $rolling30d?->toArray(),
            'rolling_365d' => $rolling365d?->toArray(),
        ];
    }
}
