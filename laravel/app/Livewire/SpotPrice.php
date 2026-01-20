<?php

namespace App\Livewire;

use App\Models\SpotPriceHour;
use Carbon\Carbon;
use Livewire\Component;

class SpotPrice extends Component
{
    public array $hourlyPrices = [];
    public bool $loading = true;
    public ?string $error = null;
    public bool $historicalDataLoaded = false;

    // Historical data (lazy loaded)
    public array $weeklyDailyAverages = [];
    public array $weeklyChartData = [];
    public array $monthlyComparison = [];
    public array $yearOverYearComparison = [];

    private const REGION = 'FI';
    private const TIMEZONE = 'Europe/Helsinki';

    private const FINNISH_MONTHS = [
        1 => 'Tammikuu',
        2 => 'Helmikuu',
        3 => 'Maaliskuu',
        4 => 'Huhtikuu',
        5 => 'Toukokuu',
        6 => 'Kesäkuu',
        7 => 'Heinäkuu',
        8 => 'Elokuu',
        9 => 'Syyskuu',
        10 => 'Lokakuu',
        11 => 'Marraskuu',
        12 => 'Joulukuu',
    ];

    public function mount(): void
    {
        $this->fetchPrices();
    }

    public function fetchPrices(): void
    {
        $this->loading = true;
        $this->error = null;

        try {
            $this->hourlyPrices = $this->loadPricesFromDatabase();
        } catch (\Exception $e) {
            $this->error = 'Hintatietojen lataaminen epäonnistui. Yritä myöhemmin uudelleen.';
        }

        $this->loading = false;
    }

    /**
     * Load prices for today and tomorrow from the database.
     */
    private function loadPricesFromDatabase(): array
    {
        $helsinkiNow = Carbon::now(self::TIMEZONE);
        $todayStart = $helsinkiNow->copy()->startOfDay()->setTimezone('UTC');
        $tomorrowEnd = $helsinkiNow->copy()->addDay()->endOfDay()->setTimezone('UTC');

        $prices = SpotPriceHour::forRegion(self::REGION)
            ->whereBetween('utc_datetime', [$todayStart, $tomorrowEnd])
            ->orderBy('utc_datetime')
            ->get();

        return $prices->map(function (SpotPriceHour $price) {
            // Parse as UTC explicitly, then convert to Helsinki
            $utcTime = Carbon::parse($price->utc_datetime)->shiftTimezone('UTC');
            $helsinkiTime = $utcTime->copy()->setTimezone(self::TIMEZONE);

            return [
                'region' => $price->region,
                'timestamp' => $price->timestamp,
                'utc_datetime' => $utcTime->toIso8601String(),
                'price_without_tax' => $price->price_without_tax,
                'vat_rate' => $price->vat_rate,
                'price_with_tax' => round($price->price_with_tax, 2),
                'helsinki_hour' => (int) $helsinkiTime->format('H'),
                'helsinki_date' => $helsinkiTime->format('Y-m-d'),
            ];
        })->toArray();
    }

    /**
     * Get today's prices only (for statistics).
     */
    private function getTodayPrices(): array
    {
        $todayHelsinkiDate = Carbon::now(self::TIMEZONE)->format('Y-m-d');

        return array_filter(
            $this->hourlyPrices,
            fn($price) => $price['helsinki_date'] === $todayHelsinkiDate
        );
    }

    /**
     * Get current hour's spot price.
     */
    public function getCurrentPrice(): ?array
    {
        if (empty($this->hourlyPrices)) {
            return null;
        }

        $currentHour = (int) Carbon::now(self::TIMEZONE)->format('H');
        $todayDate = Carbon::now(self::TIMEZONE)->format('Y-m-d');

        foreach ($this->hourlyPrices as $price) {
            if ($price['helsinki_hour'] === $currentHour && $price['helsinki_date'] === $todayDate) {
                return $price;
            }
        }

        return null;
    }

    /**
     * Get min and max prices for today.
     */
    public function getTodayMinMax(): array
    {
        $todayPrices = $this->getTodayPrices();

        if (empty($todayPrices)) {
            return ['min' => null, 'max' => null];
        }

        $prices = array_column($todayPrices, 'price_without_tax');
        return [
            'min' => min($prices),
            'max' => max($prices),
        ];
    }

    /**
     * Get cheapest hour for today.
     */
    public function getCheapestHour(): ?array
    {
        $todayPrices = $this->getTodayPrices();

        if (empty($todayPrices)) {
            return null;
        }

        $sorted = $todayPrices;
        usort($sorted, fn($a, $b) => $a['price_without_tax'] <=> $b['price_without_tax']);

        return $sorted[0] ?? null;
    }

    /**
     * Get most expensive hour for today.
     */
    public function getMostExpensiveHour(): ?array
    {
        $todayPrices = $this->getTodayPrices();

        if (empty($todayPrices)) {
            return null;
        }

        $sorted = $todayPrices;
        usort($sorted, fn($a, $b) => $b['price_without_tax'] <=> $a['price_without_tax']);

        return $sorted[0] ?? null;
    }

    /**
     * Calculate daily statistics (min, max, average, median).
     */
    public function getTodayStatistics(): array
    {
        $todayPrices = $this->getTodayPrices();

        if (empty($todayPrices)) {
            return [
                'min' => null,
                'max' => null,
                'average' => null,
                'median' => null,
            ];
        }

        $prices = array_column($todayPrices, 'price_without_tax');
        sort($prices);

        $count = count($prices);
        $middle = (int) floor($count / 2);

        // Calculate median
        if ($count % 2 === 0) {
            $median = ($prices[$middle - 1] + $prices[$middle]) / 2;
        } else {
            $median = $prices[$middle];
        }

        return [
            'min' => min($prices),
            'max' => max($prices),
            'average' => array_sum($prices) / $count,
            'median' => $median,
        ];
    }

    // ==========================================
    // Analytics Methods
    // ==========================================

    /**
     * Find the best consecutive hours for high-consumption tasks.
     *
     * @param int $hoursNeeded Number of consecutive hours needed
     * @return array|null Returns null if not enough data
     */
    public function getBestConsecutiveHours(int $hoursNeeded): ?array
    {
        $todayPrices = $this->getTodayPrices();

        if (count($todayPrices) < $hoursNeeded) {
            return null;
        }

        // Sort by helsinki_hour to ensure consecutive
        $sortedPrices = array_values($todayPrices);
        usort($sortedPrices, fn($a, $b) => $a['helsinki_hour'] <=> $b['helsinki_hour']);

        $bestWindow = null;
        $bestAverage = PHP_FLOAT_MAX;

        for ($i = 0; $i <= count($sortedPrices) - $hoursNeeded; $i++) {
            // Check if hours are actually consecutive
            $window = array_slice($sortedPrices, $i, $hoursNeeded);
            $isConsecutive = true;

            for ($j = 1; $j < count($window); $j++) {
                if ($window[$j]['helsinki_hour'] !== $window[$j - 1]['helsinki_hour'] + 1) {
                    $isConsecutive = false;
                    break;
                }
            }

            if (!$isConsecutive) {
                continue;
            }

            $sum = array_sum(array_column($window, 'price_without_tax'));
            $average = $sum / $hoursNeeded;

            if ($average < $bestAverage) {
                $bestAverage = $average;
                $bestWindow = $window;
            }
        }

        if ($bestWindow === null) {
            return null;
        }

        return [
            'start_hour' => $bestWindow[0]['helsinki_hour'],
            'end_hour' => $bestWindow[count($bestWindow) - 1]['helsinki_hour'],
            'average_price' => $bestAverage,
            'prices' => $bestWindow,
        ];
    }

    /**
     * Calculate price variance and volatility for today.
     *
     * @return array Includes variance, standard deviation, average, and range
     */
    public function getPriceVolatility(): array
    {
        $todayPrices = $this->getTodayPrices();

        if (empty($todayPrices)) {
            return [
                'variance' => null,
                'std_deviation' => null,
                'average' => null,
                'range' => null,
            ];
        }

        $prices = array_column($todayPrices, 'price_without_tax');
        $count = count($prices);
        $average = array_sum($prices) / $count;

        // Calculate variance
        $sumSquaredDiff = 0;
        foreach ($prices as $price) {
            $sumSquaredDiff += pow($price - $average, 2);
        }
        $variance = $sumSquaredDiff / $count;

        return [
            'variance' => $variance,
            'std_deviation' => sqrt($variance),
            'average' => $average,
            'range' => max($prices) - min($prices),
        ];
    }

    /**
     * Compare today's average with yesterday and weekly average.
     *
     * @return array Historical comparison data
     */
    public function getHistoricalComparison(): array
    {
        $todayAverage = $this->getTodayStatistics()['average'];

        // Get yesterday's prices from database
        $helsinkiNow = Carbon::now(self::TIMEZONE);
        $yesterdayStart = $helsinkiNow->copy()->subDay()->startOfDay()->setTimezone('UTC');
        $yesterdayEnd = $helsinkiNow->copy()->subDay()->endOfDay()->setTimezone('UTC');

        $yesterdayPrices = SpotPriceHour::forRegion(self::REGION)
            ->whereBetween('utc_datetime', [$yesterdayStart, $yesterdayEnd])
            ->pluck('price_without_tax')
            ->toArray();

        $yesterdayAverage = !empty($yesterdayPrices)
            ? array_sum($yesterdayPrices) / count($yesterdayPrices)
            : null;

        // Get weekly prices (7 days before today, not including today)
        $weekStart = $helsinkiNow->copy()->subDays(7)->startOfDay()->setTimezone('UTC');
        $weekEnd = $helsinkiNow->copy()->subDay()->endOfDay()->setTimezone('UTC');

        $weeklyPrices = SpotPriceHour::forRegion(self::REGION)
            ->whereBetween('utc_datetime', [$weekStart, $weekEnd])
            ->get();

        $weeklyAverage = null;
        $weeklyDaysAvailable = 0;

        if ($weeklyPrices->isNotEmpty()) {
            // Group by day and calculate daily averages
            $dailyAverages = [];
            foreach ($weeklyPrices as $price) {
                $helsinkiTime = Carbon::parse($price->utc_datetime)->shiftTimezone('UTC')->setTimezone(self::TIMEZONE);
                $date = $helsinkiTime->format('Y-m-d');

                if (!isset($dailyAverages[$date])) {
                    $dailyAverages[$date] = [];
                }
                $dailyAverages[$date][] = $price->price_without_tax;
            }

            // Calculate average of daily averages
            $dailyAvgValues = array_map(fn($prices) => array_sum($prices) / count($prices), $dailyAverages);
            $weeklyAverage = array_sum($dailyAvgValues) / count($dailyAvgValues);
            $weeklyDaysAvailable = count($dailyAverages);
        }

        // Calculate percentage changes
        $changeFromYesterday = ($yesterdayAverage !== null && $yesterdayAverage > 0)
            ? (($todayAverage - $yesterdayAverage) / $yesterdayAverage) * 100
            : null;

        $changeFromWeekly = ($weeklyAverage !== null && $weeklyAverage > 0)
            ? (($todayAverage - $weeklyAverage) / $weeklyAverage) * 100
            : null;

        return [
            'today_average' => $todayAverage,
            'yesterday_average' => $yesterdayAverage,
            'weekly_average' => $weeklyAverage,
            'weekly_days_available' => $weeklyDaysAvailable,
            'change_from_yesterday_percent' => $changeFromYesterday,
            'change_from_weekly_percent' => $changeFromWeekly,
        ];
    }

    /**
     * Get the cheapest hours remaining today (future hours only).
     *
     * @param int $count Number of hours to return
     * @return array Array of future hours sorted by price
     */
    public function getCheapestRemainingHours(int $count): array
    {
        $helsinkiNow = Carbon::now(self::TIMEZONE);
        $currentHour = (int) $helsinkiNow->format('H');
        $todayDate = $helsinkiNow->format('Y-m-d');

        // Filter to only future hours
        $futureHours = array_filter(
            $this->hourlyPrices,
            function ($price) use ($currentHour, $todayDate) {
                // Exclude today's current and past hours
                if ($price['helsinki_date'] === $todayDate) {
                    return $price['helsinki_hour'] > $currentHour;
                }
                // Include all of tomorrow
                return true;
            }
        );

        // Sort by price ascending
        usort($futureHours, fn($a, $b) => $a['price_without_tax'] <=> $b['price_without_tax']);

        return array_slice($futureHours, 0, $count);
    }

    /**
     * Calculate potential savings by using cheapest hours.
     *
     * @param int $hoursNeeded Number of hours needed for the task
     * @param float $kwhPerHour Consumption in kWh per hour
     * @return array|null Savings data or null if not enough data
     */
    public function calculatePotentialSavings(int $hoursNeeded, float $kwhPerHour): ?array
    {
        $todayPrices = $this->getTodayPrices();

        if (count($todayPrices) < $hoursNeeded) {
            return null;
        }

        $prices = array_column($todayPrices, 'price_without_tax');
        sort($prices);

        // Get cheapest hours
        $cheapestPrices = array_slice($prices, 0, $hoursNeeded);
        $cheapestAverage = array_sum($cheapestPrices) / $hoursNeeded;

        // Overall average
        $overallAverage = array_sum($prices) / count($prices);

        // Calculate savings
        $totalKwh = $hoursNeeded * $kwhPerHour;
        $savingsPerKwh = $overallAverage - $cheapestAverage;
        $savingsCents = $savingsPerKwh * $totalKwh;
        $savingsPercent = ($overallAverage > 0)
            ? ($savingsPerKwh / $overallAverage) * 100
            : 0;

        return [
            'cheapest_average' => $cheapestAverage,
            'overall_average' => $overallAverage,
            'savings_per_kwh' => $savingsPerKwh,
            'savings_cents' => $savingsCents,
            'savings_euros' => $savingsCents / 100,
            'savings_percent' => $savingsPercent,
            'total_kwh' => $totalKwh,
        ];
    }

    /**
     * Calculate sauna heating cost comparison between cheapest and most expensive hours.
     *
     * Uses 8 kW as default sauna heater power for 1 hour of heating.
     *
     * @param float $kw Sauna heater power in kW (default 8 kW)
     * @return array|null Sauna cost data or null if not enough data
     */
    public function calculateSaunaCost(float $kw = 8.0): ?array
    {
        $cheapestHour = $this->getCheapestHour();
        $mostExpensiveHour = $this->getMostExpensiveHour();

        if ($cheapestHour === null || $mostExpensiveHour === null) {
            return null;
        }

        // Calculate cost for 1 hour of heating at given kW
        // Price is in c/kWh, so cost = price * kWh
        $cheapestCost = $cheapestHour['price_without_tax'] * $kw;
        $expensiveCost = $mostExpensiveHour['price_without_tax'] * $kw;
        $costDifference = $expensiveCost - $cheapestCost;

        return [
            'cheapest_hour' => $cheapestHour['helsinki_hour'],
            'cheapest_price' => $cheapestHour['price_without_tax'],
            'expensive_hour' => $mostExpensiveHour['helsinki_hour'],
            'expensive_price' => $mostExpensiveHour['price_without_tax'],
            'cheapest_cost' => $cheapestCost,
            'expensive_cost' => $expensiveCost,
            'cost_difference' => $costDifference,
            'cost_difference_euros' => $costDifference / 100,
            'kw' => $kw,
        ];
    }

    /**
     * Generate chart data for today's hourly prices.
     *
     * @return array Chart.js compatible data structure with color coding
     */
    public function getChartData(): array
    {
        $todayPrices = $this->getTodayPrices();

        if (empty($todayPrices)) {
            return [
                'labels' => [],
                'datasets' => [
                    [
                        'label' => 'Hinta (c/kWh)',
                        'data' => [],
                        'backgroundColor' => [],
                        'borderColor' => [],
                        'borderWidth' => 1,
                    ],
                ],
            ];
        }

        // Sort by hour
        usort($todayPrices, fn($a, $b) => $a['helsinki_hour'] <=> $b['helsinki_hour']);

        $labels = [];
        $data = [];
        $backgroundColors = [];
        $borderColors = [];

        $prices = array_column($todayPrices, 'price_without_tax');
        $minPrice = min($prices);
        $maxPrice = max($prices);
        $priceRange = $maxPrice - $minPrice;

        // Define color thresholds based on price range
        // Green for cheap, yellow for moderate, red for expensive
        foreach ($todayPrices as $price) {
            $hour = $price['helsinki_hour'];
            $labels[] = sprintf('%02d:00', $hour);
            $data[] = round($price['price_without_tax'], 2);

            // Calculate color based on price position in range
            $priceValue = $price['price_without_tax'];
            if ($priceRange > 0) {
                $normalizedPrice = ($priceValue - $minPrice) / $priceRange;
            } else {
                $normalizedPrice = 0.5;
            }

            // Color gradient: green (cheap) -> yellow (moderate) -> red (expensive)
            if ($normalizedPrice < 0.33) {
                // Green - cheap
                $backgroundColors[] = 'rgba(34, 197, 94, 0.7)';
                $borderColors[] = 'rgb(34, 197, 94)';
            } elseif ($normalizedPrice < 0.66) {
                // Yellow - moderate
                $backgroundColors[] = 'rgba(234, 179, 8, 0.7)';
                $borderColors[] = 'rgb(234, 179, 8)';
            } else {
                // Red - expensive
                $backgroundColors[] = 'rgba(239, 68, 68, 0.7)';
                $borderColors[] = 'rgb(239, 68, 68)';
            }
        }

        return [
            'labels' => $labels,
            'datasets' => [
                [
                    'label' => 'Hinta (c/kWh)',
                    'data' => $data,
                    'backgroundColor' => $backgroundColors,
                    'borderColor' => $borderColors,
                    'borderWidth' => 1,
                ],
            ],
        ];
    }

    // ==========================================
    // Historical Data Methods (Lazy Loaded)
    // ==========================================

    /**
     * Load historical data on demand (lazy loading).
     */
    public function loadHistoricalData(): void
    {
        $this->weeklyDailyAverages = $this->getWeeklyDailyAverages();
        $this->weeklyChartData = $this->getWeeklyChartData();
        $this->monthlyComparison = $this->getMonthlyComparison();
        $this->yearOverYearComparison = $this->getYearOverYearComparison();
        $this->historicalDataLoaded = true;
    }

    /**
     * Get daily averages for the past 7 days (excluding today).
     *
     * @return array Array of daily averages with date and average price
     */
    public function getWeeklyDailyAverages(): array
    {
        $helsinkiNow = Carbon::now(self::TIMEZONE);
        $weekStart = $helsinkiNow->copy()->subDays(7)->startOfDay()->setTimezone('UTC');
        $yesterdayEnd = $helsinkiNow->copy()->subDay()->endOfDay()->setTimezone('UTC');

        $prices = SpotPriceHour::forRegion(self::REGION)
            ->whereBetween('utc_datetime', [$weekStart, $yesterdayEnd])
            ->orderBy('utc_datetime')
            ->get();

        if ($prices->isEmpty()) {
            return [];
        }

        // Group by Helsinki date
        $dailyPrices = [];
        foreach ($prices as $price) {
            $helsinkiTime = Carbon::parse($price->utc_datetime)->shiftTimezone('UTC')->setTimezone(self::TIMEZONE);
            $date = $helsinkiTime->format('Y-m-d');

            if (!isset($dailyPrices[$date])) {
                $dailyPrices[$date] = [
                    'prices' => [],
                    'min' => PHP_FLOAT_MAX,
                    'max' => PHP_FLOAT_MIN,
                ];
            }
            $dailyPrices[$date]['prices'][] = $price->price_without_tax;
            $dailyPrices[$date]['min'] = min($dailyPrices[$date]['min'], $price->price_without_tax);
            $dailyPrices[$date]['max'] = max($dailyPrices[$date]['max'], $price->price_without_tax);
        }

        // Calculate daily averages and sort by date
        $result = [];
        ksort($dailyPrices);

        foreach ($dailyPrices as $date => $data) {
            $result[] = [
                'date' => $date,
                'average' => array_sum($data['prices']) / count($data['prices']),
                'min' => $data['min'],
                'max' => $data['max'],
            ];
        }

        return $result;
    }

    /**
     * Get monthly comparison data (this month vs last month).
     *
     * @return array Monthly comparison with averages and percentage change
     */
    public function getMonthlyComparison(): array
    {
        $helsinkiNow = Carbon::now(self::TIMEZONE);
        $currentMonth = $helsinkiNow->month;
        $currentYear = $helsinkiNow->year;

        // Calculate last month
        $lastMonthDate = $helsinkiNow->copy()->subMonth();
        $lastMonth = $lastMonthDate->month;
        $lastMonthYear = $lastMonthDate->year;

        // Get current month data (from start of month to now)
        $currentMonthStart = $helsinkiNow->copy()->startOfMonth()->startOfDay()->setTimezone('UTC');
        $currentMonthEnd = $helsinkiNow->copy()->endOfDay()->setTimezone('UTC');

        $currentMonthPrices = SpotPriceHour::forRegion(self::REGION)
            ->whereBetween('utc_datetime', [$currentMonthStart, $currentMonthEnd])
            ->get();

        // Get last month data (full month)
        $lastMonthStart = $lastMonthDate->copy()->startOfMonth()->startOfDay()->setTimezone('UTC');
        $lastMonthEnd = $lastMonthDate->copy()->endOfMonth()->endOfDay()->setTimezone('UTC');

        $lastMonthPrices = SpotPriceHour::forRegion(self::REGION)
            ->whereBetween('utc_datetime', [$lastMonthStart, $lastMonthEnd])
            ->get();

        // Calculate averages
        $currentMonthAverage = null;
        $currentMonthDays = 0;
        if ($currentMonthPrices->isNotEmpty()) {
            $currentMonthAverage = $currentMonthPrices->avg('price_without_tax');
            $currentMonthDays = $this->countUniqueDays($currentMonthPrices);
        }

        $lastMonthAverage = null;
        $lastMonthDays = 0;
        if ($lastMonthPrices->isNotEmpty()) {
            $lastMonthAverage = $lastMonthPrices->avg('price_without_tax');
            $lastMonthDays = $this->countUniqueDays($lastMonthPrices);
        }

        // Calculate change percentage
        $changePercent = null;
        if ($lastMonthAverage !== null && $lastMonthAverage > 0 && $currentMonthAverage !== null) {
            $changePercent = (($currentMonthAverage - $lastMonthAverage) / $lastMonthAverage) * 100;
        }

        return [
            'current_month_name' => self::FINNISH_MONTHS[$currentMonth],
            'last_month_name' => self::FINNISH_MONTHS[$lastMonth],
            'current_month_average' => $currentMonthAverage,
            'last_month_average' => $lastMonthAverage,
            'current_month_days' => $currentMonthDays,
            'last_month_days' => $lastMonthDays,
            'change_percent' => $changePercent,
        ];
    }

    /**
     * Get year-over-year comparison (this month vs same month last year).
     *
     * @return array Year-over-year comparison data
     */
    public function getYearOverYearComparison(): array
    {
        $helsinkiNow = Carbon::now(self::TIMEZONE);
        $currentYear = $helsinkiNow->year;
        $currentMonth = $helsinkiNow->month;
        $lastYear = $currentYear - 1;

        // Get current month data
        $currentMonthStart = $helsinkiNow->copy()->startOfMonth()->startOfDay()->setTimezone('UTC');
        $currentMonthEnd = $helsinkiNow->copy()->endOfDay()->setTimezone('UTC');

        $currentYearPrices = SpotPriceHour::forRegion(self::REGION)
            ->whereBetween('utc_datetime', [$currentMonthStart, $currentMonthEnd])
            ->get();

        // Get same month last year
        $lastYearMonthStart = Carbon::create($lastYear, $currentMonth, 1, 0, 0, 0, self::TIMEZONE)
            ->startOfDay()->setTimezone('UTC');
        $lastYearMonthEnd = Carbon::create($lastYear, $currentMonth, 1, 0, 0, 0, self::TIMEZONE)
            ->endOfMonth()->endOfDay()->setTimezone('UTC');

        $lastYearPrices = SpotPriceHour::forRegion(self::REGION)
            ->whereBetween('utc_datetime', [$lastYearMonthStart, $lastYearMonthEnd])
            ->get();

        // Calculate averages
        $currentYearAverage = $currentYearPrices->isNotEmpty() ? $currentYearPrices->avg('price_without_tax') : null;
        $lastYearAverage = $lastYearPrices->isNotEmpty() ? $lastYearPrices->avg('price_without_tax') : null;

        // Calculate change percentage
        $changePercent = null;
        if ($lastYearAverage !== null && $lastYearAverage > 0 && $currentYearAverage !== null) {
            $changePercent = (($currentYearAverage - $lastYearAverage) / $lastYearAverage) * 100;
        }

        return [
            'current_year' => $currentYear,
            'last_year' => $lastYear,
            'current_year_average' => $currentYearAverage,
            'last_year_average' => $lastYearAverage,
            'change_percent' => $changePercent,
            'has_last_year_data' => $lastYearPrices->isNotEmpty(),
            'month_name' => self::FINNISH_MONTHS[$currentMonth],
        ];
    }

    /**
     * Generate Chart.js compatible data for weekly price trends.
     *
     * @return array Chart.js data structure with daily averages and min/max range
     */
    public function getWeeklyChartData(): array
    {
        $weeklyData = $this->getWeeklyDailyAverages();

        if (empty($weeklyData)) {
            return [
                'labels' => [],
                'datasets' => [
                    [
                        'label' => 'Keskihinta (c/kWh)',
                        'data' => [],
                        'borderColor' => 'rgb(59, 130, 246)',
                        'backgroundColor' => 'rgba(59, 130, 246, 0.5)',
                        'tension' => 0.3,
                    ],
                ],
            ];
        }

        $labels = [];
        $averages = [];
        $mins = [];
        $maxs = [];

        foreach ($weeklyData as $day) {
            $date = Carbon::parse($day['date']);
            // Finnish format: d.m. (e.g., "13.1.")
            $labels[] = $date->format('d.m.');
            $averages[] = round($day['average'], 2);
            $mins[] = round($day['min'], 2);
            $maxs[] = round($day['max'], 2);
        }

        return [
            'labels' => $labels,
            'datasets' => [
                [
                    'label' => 'Keskihinta (c/kWh)',
                    'data' => $averages,
                    'borderColor' => 'rgb(59, 130, 246)',
                    'backgroundColor' => 'rgba(59, 130, 246, 0.5)',
                    'tension' => 0.3,
                    'fill' => false,
                ],
                [
                    'label' => 'Vaihteluväli (min-max)',
                    'data' => $maxs,
                    'borderColor' => 'rgba(239, 68, 68, 0.5)',
                    'backgroundColor' => 'rgba(239, 68, 68, 0.1)',
                    'fill' => '+1',
                    'tension' => 0.3,
                    'pointRadius' => 0,
                ],
            ],
        ];
    }

    /**
     * Generate CSV data for price export.
     *
     * @param int $days Number of days to include (default 1 = today only)
     * @return string CSV formatted string
     */
    public function generateCsvData(int $days = 1): string
    {
        $helsinkiNow = Carbon::now(self::TIMEZONE);
        $startDate = $helsinkiNow->copy()->subDays($days - 1)->startOfDay()->setTimezone('UTC');
        $endDate = $helsinkiNow->copy()->endOfDay()->setTimezone('UTC');

        $prices = SpotPriceHour::forRegion(self::REGION)
            ->whereBetween('utc_datetime', [$startDate, $endDate])
            ->orderBy('utc_datetime')
            ->get();

        // CSV header
        $csv = "Päivämäärä;Tunti;Hinta (c/kWh) ALV 0%;Hinta (c/kWh) sis. ALV;ALV %\n";

        foreach ($prices as $price) {
            $helsinkiTime = Carbon::parse($price->utc_datetime)->shiftTimezone('UTC')->setTimezone(self::TIMEZONE);
            $date = $helsinkiTime->format('d.m.Y');
            $hour = $helsinkiTime->format('H') . ':00-' . $helsinkiTime->copy()->addHour()->format('H') . ':00';
            $priceWithoutTax = number_format($price->price_without_tax, 2, ',', '');
            $priceWithTax = number_format($price->price_with_tax, 2, ',', '');
            $vatPercent = number_format($price->vat_rate * 100, 1, ',', '');

            $csv .= "{$date};{$hour};{$priceWithoutTax};{$priceWithTax};{$vatPercent}\n";
        }

        return $csv;
    }

    /**
     * Download CSV file with spot prices.
     *
     * @return \Symfony\Component\HttpFoundation\StreamedResponse
     */
    public function downloadCsv()
    {
        $csvData = $this->generateCsvData(days: 7); // Last 7 days

        return response()->streamDownload(function () use ($csvData) {
            echo "\xEF\xBB\xBF"; // UTF-8 BOM for Excel compatibility
            echo $csvData;
        }, 'spot-hinnat.csv', [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }

    /**
     * Count unique days in a collection of prices.
     */
    private function countUniqueDays($prices): int
    {
        $dates = [];
        foreach ($prices as $price) {
            $helsinkiTime = Carbon::parse($price->utc_datetime)->shiftTimezone('UTC')->setTimezone(self::TIMEZONE);
            $dates[$helsinkiTime->format('Y-m-d')] = true;
        }
        return count($dates);
    }

    public function render()
    {
        $viewData = [
            'currentPrice' => $this->getCurrentPrice(),
            'todayMinMax' => $this->getTodayMinMax(),
            'cheapestHour' => $this->getCheapestHour(),
            'mostExpensiveHour' => $this->getMostExpensiveHour(),
            'todayStatistics' => $this->getTodayStatistics(),
            // Analytics data
            'priceVolatility' => $this->getPriceVolatility(),
            'historicalComparison' => $this->getHistoricalComparison(),
            'cheapestRemainingHours' => $this->getCheapestRemainingHours(5),
            'bestConsecutiveHours' => $this->getBestConsecutiveHours(3),
            'potentialSavings' => $this->calculatePotentialSavings(3, 3.7), // 3 hours at 3.7 kW (typical EV charging)
            'saunaCost' => $this->calculateSaunaCost(), // 1 hour at 8 kW (typical sauna heater)
            // Chart data
            'chartData' => $this->getChartData(),
        ];

        // Add historical data if loaded
        if ($this->historicalDataLoaded) {
            $viewData['weeklyDailyAverages'] = $this->weeklyDailyAverages;
            $viewData['weeklyChartData'] = $this->weeklyChartData;
            $viewData['monthlyComparison'] = $this->monthlyComparison;
            $viewData['yearOverYearComparison'] = $this->yearOverYearComparison;
        }

        return view('livewire.spot-price', $viewData)
            ->layout('layouts.app', ['title' => 'Pörssisähkön hinta - Voltikka']);
    }
}
