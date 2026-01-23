<?php

namespace App\Services;

use App\Models\SpotPriceHour;
use App\Models\SpotPriceQuarter;
use App\Models\SpotPriceAverage;
use Carbon\Carbon;

/**
 * Service for generating video-ready spot price data.
 *
 * Provides structured data for Remotion video generation including
 * daily prices, appliance recommendations, and statistics.
 */
class SpotPriceVideoService
{
    private const REGION = 'FI';
    private const TIMEZONE = 'Europe/Helsinki';
    private const VAT_RATE = 0.255; // 25.5% VAT

    private const FINNISH_WEEKDAYS = [
        0 => 'Sunnuntai',
        1 => 'Maanantai',
        2 => 'Tiistai',
        3 => 'Keskiviikko',
        4 => 'Torstai',
        5 => 'Perjantai',
        6 => 'Lauantai',
    ];

    private const FINNISH_MONTHS = [
        1 => 'tammikuuta',
        2 => 'helmikuuta',
        3 => 'maaliskuuta',
        4 => 'huhtikuuta',
        5 => 'toukokuuta',
        6 => 'kesäkuuta',
        7 => 'heinäkuuta',
        8 => 'elokuuta',
        9 => 'syyskuuta',
        10 => 'lokakuuta',
        11 => 'marraskuuta',
        12 => 'joulukuuta',
    ];

    /**
     * Get all data needed for a daily spot price video.
     */
    public function getDailyVideoData(?Carbon $date = null): array
    {
        $helsinkiNow = $date ?? Carbon::now(self::TIMEZONE);
        $todayStart = $helsinkiNow->copy()->startOfDay()->setTimezone('UTC');
        $tomorrowEnd = $helsinkiNow->copy()->addDay()->endOfDay()->setTimezone('UTC');

        // Load prices
        $hourlyPrices = $this->loadHourlyPrices($todayStart, $tomorrowEnd);
        $todayPrices = $this->filterTodayPrices($hourlyPrices, $helsinkiNow);

        // Current price (15-minute or hourly)
        $currentPrice = $this->getCurrentPrice($helsinkiNow);

        // Statistics
        $statistics = $this->calculateStatistics($todayPrices);

        // Appliance recommendations
        $appliances = $this->calculateApplianceRecommendations($hourlyPrices, $helsinkiNow);

        // EV charging recommendation (3 hours at 11 kW for typical home charger)
        $evCharging = $this->calculateEvChargingRecommendation($hourlyPrices, $helsinkiNow);

        // Chart data with colors
        $chartData = $this->generateChartData($todayPrices);

        // Historical comparison
        $comparison = $this->getHistoricalComparison($helsinkiNow, $statistics['average']);

        return [
            'generated_at' => Carbon::now()->toIso8601String(),
            'date' => [
                'iso' => $helsinkiNow->format('Y-m-d'),
                'weekday' => self::FINNISH_WEEKDAYS[$helsinkiNow->dayOfWeek],
                'day' => $helsinkiNow->day,
                'month' => self::FINNISH_MONTHS[$helsinkiNow->month],
                'formatted' => sprintf(
                    '%s %d. %s',
                    self::FINNISH_WEEKDAYS[$helsinkiNow->dayOfWeek],
                    $helsinkiNow->day,
                    self::FINNISH_MONTHS[$helsinkiNow->month]
                ),
            ],
            'current_price' => $currentPrice,
            'statistics' => $statistics,
            'chart' => $chartData,
            'appliances' => $appliances,
            'ev_charging' => $evCharging,
            'comparison' => $comparison,
            'prices' => [
                'today' => $this->formatPricesForVideo($todayPrices),
                'tomorrow' => $this->formatPricesForVideo(
                    $this->filterTomorrowPrices($hourlyPrices, $helsinkiNow)
                ),
            ],
        ];
    }

    /**
     * Get weekly summary data for video generation.
     */
    public function getWeeklySummaryData(): array
    {
        $helsinkiNow = Carbon::now(self::TIMEZONE);
        $weekStart = $helsinkiNow->copy()->subDays(7)->startOfDay()->setTimezone('UTC');
        $yesterdayEnd = $helsinkiNow->copy()->subDay()->endOfDay()->setTimezone('UTC');

        $prices = SpotPriceHour::forRegion(self::REGION)
            ->whereBetween('utc_datetime', [$weekStart, $yesterdayEnd])
            ->orderBy('utc_datetime')
            ->get();

        // Group by day
        $dailyData = [];
        foreach ($prices as $price) {
            $helsinkiTime = Carbon::parse($price->utc_datetime)->shiftTimezone('UTC')->setTimezone(self::TIMEZONE);
            $date = $helsinkiTime->format('Y-m-d');

            if (!isset($dailyData[$date])) {
                $dailyData[$date] = [
                    'prices' => [],
                    'min' => PHP_FLOAT_MAX,
                    'max' => PHP_FLOAT_MIN,
                ];
            }
            $dailyData[$date]['prices'][] = $price->price_without_tax;
            $dailyData[$date]['min'] = min($dailyData[$date]['min'], $price->price_without_tax);
            $dailyData[$date]['max'] = max($dailyData[$date]['max'], $price->price_without_tax);
        }

        // Calculate daily summaries
        $days = [];
        ksort($dailyData);
        foreach ($dailyData as $date => $data) {
            $dateCarbon = Carbon::parse($date);
            $average = array_sum($data['prices']) / count($data['prices']);
            $days[] = [
                'date' => $date,
                'weekday' => self::FINNISH_WEEKDAYS[$dateCarbon->dayOfWeek],
                'weekday_short' => mb_substr(self::FINNISH_WEEKDAYS[$dateCarbon->dayOfWeek], 0, 2),
                'average' => round($average, 2),
                'min' => round($data['min'], 2),
                'max' => round($data['max'], 2),
            ];
        }

        // Find best and worst days
        $sortedByAvg = $days;
        usort($sortedByAvg, fn($a, $b) => $a['average'] <=> $b['average']);
        $bestDay = $sortedByAvg[0] ?? null;
        $worstDay = $sortedByAvg[count($sortedByAvg) - 1] ?? null;

        // Overall statistics
        $allPrices = array_merge(...array_column($dailyData, 'prices'));
        $weeklyAverage = !empty($allPrices) ? array_sum($allPrices) / count($allPrices) : null;

        // Rolling averages for context
        $rolling30 = SpotPriceAverage::latestRolling30Days(self::REGION);
        $rolling365 = SpotPriceAverage::latestRolling365Days(self::REGION);

        return [
            'generated_at' => Carbon::now()->toIso8601String(),
            'period' => [
                'start' => $helsinkiNow->copy()->subDays(7)->format('Y-m-d'),
                'end' => $helsinkiNow->copy()->subDay()->format('Y-m-d'),
            ],
            'days' => $days,
            'summary' => [
                'average' => $weeklyAverage !== null ? round($weeklyAverage, 2) : null,
                'best_day' => $bestDay,
                'worst_day' => $worstDay,
            ],
            'context' => [
                'rolling_30d_average' => $rolling30?->avg_price_without_tax ? round($rolling30->avg_price_without_tax, 2) : null,
                'rolling_365d_average' => $rolling365?->avg_price_without_tax ? round($rolling365->avg_price_without_tax, 2) : null,
            ],
        ];
    }

    private function loadHourlyPrices(Carbon $start, Carbon $end): array
    {
        return SpotPriceHour::forRegion(self::REGION)
            ->whereBetween('utc_datetime', [$start, $end])
            ->orderBy('utc_datetime')
            ->get()
            ->map(function (SpotPriceHour $price) {
                $utcTime = Carbon::parse($price->utc_datetime)->shiftTimezone('UTC');
                $helsinkiTime = $utcTime->copy()->setTimezone(self::TIMEZONE);

                return [
                    'timestamp' => $price->timestamp,
                    'utc_datetime' => $utcTime->toIso8601String(),
                    'price_without_tax' => $price->price_without_tax,
                    'price_with_tax' => round($price->price_with_tax, 2),
                    'vat_rate' => $price->vat_rate,
                    'helsinki_hour' => (int) $helsinkiTime->format('H'),
                    'helsinki_date' => $helsinkiTime->format('Y-m-d'),
                ];
            })
            ->toArray();
    }

    private function filterTodayPrices(array $prices, Carbon $helsinkiNow): array
    {
        $todayDate = $helsinkiNow->format('Y-m-d');
        return array_filter($prices, fn($p) => $p['helsinki_date'] === $todayDate);
    }

    private function filterTomorrowPrices(array $prices, Carbon $helsinkiNow): array
    {
        $tomorrowDate = $helsinkiNow->copy()->addDay()->format('Y-m-d');
        return array_filter($prices, fn($p) => $p['helsinki_date'] === $tomorrowDate);
    }

    private function getCurrentPrice(Carbon $helsinkiNow): ?array
    {
        // Try 15-minute price first
        $minute = (int) $helsinkiNow->format('i');
        $quarterMinute = (int) floor($minute / 15) * 15;
        $quarterStart = $helsinkiNow->copy()->minute($quarterMinute)->second(0)->setTimezone('UTC');
        $quarterEnd = $quarterStart->copy()->addMinutes(15);

        $quarterPrice = SpotPriceQuarter::forRegion(self::REGION)
            ->where('utc_datetime', '>=', $quarterStart)
            ->where('utc_datetime', '<', $quarterEnd)
            ->first();

        if ($quarterPrice) {
            $quarterEndMinute = $quarterMinute + 15;
            return [
                'price_with_tax' => round($quarterPrice->price_with_tax, 2),
                'price_without_tax' => round($quarterPrice->price_without_tax, 2),
                'is_quarter' => true,
                'time_label' => sprintf(
                    '%s:%02d-%s:%02d',
                    $helsinkiNow->format('H'),
                    $quarterMinute,
                    $quarterEndMinute === 60 ? $helsinkiNow->copy()->addHour()->format('H') : $helsinkiNow->format('H'),
                    $quarterEndMinute === 60 ? 0 : $quarterEndMinute
                ),
            ];
        }

        // Fall back to hourly
        $hourlyPrice = SpotPriceHour::forRegion(self::REGION)
            ->forDate($helsinkiNow->copy()->setTimezone('UTC'))
            ->first();

        if ($hourlyPrice) {
            return [
                'price_with_tax' => round($hourlyPrice->price_with_tax, 2),
                'price_without_tax' => round($hourlyPrice->price_without_tax, 2),
                'is_quarter' => false,
                'time_label' => sprintf('%s:00-%s:00', $helsinkiNow->format('H'), $helsinkiNow->copy()->addHour()->format('H')),
            ];
        }

        return null;
    }

    private function calculateStatistics(array $todayPrices): array
    {
        if (empty($todayPrices)) {
            return [
                'min' => null,
                'max' => null,
                'average' => null,
                'median' => null,
                'cheapest_hour' => null,
                'expensive_hour' => null,
            ];
        }

        // Use price_with_tax for all consumer-facing statistics
        $prices = array_column($todayPrices, 'price_with_tax');
        sort($prices);

        $count = count($prices);
        $middle = (int) floor($count / 2);
        $median = $count % 2 === 0
            ? ($prices[$middle - 1] + $prices[$middle]) / 2
            : $prices[$middle];

        // Find cheapest and most expensive hours
        $sorted = $todayPrices;
        usort($sorted, fn($a, $b) => $a['price_with_tax'] <=> $b['price_with_tax']);
        $cheapest = reset($sorted);
        $expensive = end($sorted);

        return [
            'min' => round(min($prices), 2),
            'max' => round(max($prices), 2),
            'average' => round(array_sum($prices) / $count, 2),
            'median' => round($median, 2),
            'cheapest_hour' => $cheapest ? [
                'hour' => $cheapest['helsinki_hour'],
                'price' => round($cheapest['price_with_tax'], 2),
                'label' => sprintf('%02d:00', $cheapest['helsinki_hour']),
            ] : null,
            'expensive_hour' => $expensive ? [
                'hour' => $expensive['helsinki_hour'],
                'price' => round($expensive['price_with_tax'], 2),
                'label' => sprintf('%02d:00', $expensive['helsinki_hour']),
            ] : null,
            'cheapest_period' => $this->findCheapestPeriod($todayPrices),
            'expensive_period' => $this->findExpensivePeriod($todayPrices),
        ];
    }

    private function calculateApplianceRecommendations(array $allPrices, Carbon $helsinkiNow): array
    {
        $currentHour = (int) $helsinkiNow->format('H');
        $todayDate = $helsinkiNow->format('Y-m-d');

        // Filter to future hours only
        $futurePrices = array_filter($allPrices, function ($p) use ($currentHour, $todayDate) {
            if ($p['helsinki_date'] === $todayDate) {
                return $p['helsinki_hour'] > $currentHour;
            }
            return true;
        });

        return [
            'sauna' => $this->calculateSaunaRecommendation($futurePrices, $currentHour, $todayDate),
            'laundry' => $this->calculateLaundryRecommendation($futurePrices, $currentHour, $todayDate),
            'dishwasher' => $this->calculateDishwasherRecommendation($futurePrices, $currentHour, $todayDate),
            'water_heater' => $this->calculateWaterHeaterRecommendation($futurePrices),
        ];
    }

    private function calculateSaunaRecommendation(array $futurePrices, int $currentHour, string $todayDate): ?array
    {
        // Evening hours 17:00-22:00, 8 kW for 1 hour
        $eveningPrices = array_filter($futurePrices, function ($p) use ($currentHour, $todayDate) {
            $hour = $p['helsinki_hour'];
            $inWindow = $hour >= 17 && $hour < 22;
            // Only future hours in window
            if ($p['helsinki_date'] === $todayDate) {
                return $inWindow && $hour > $currentHour;
            }
            return $inWindow;
        });

        if (empty($eveningPrices)) {
            return null;
        }

        // Use price_with_tax for consumer-facing recommendations
        usort($eveningPrices, fn($a, $b) => $a['price_with_tax'] <=> $b['price_with_tax']);
        $cheapest = reset($eveningPrices);
        $expensive = end($eveningPrices);

        $kw = 8.0;
        $cheapestCost = $cheapest['price_with_tax'] * $kw;
        $expensiveCost = $expensive['price_with_tax'] * $kw;

        return [
            'best_hour' => $cheapest['helsinki_hour'],
            'best_hour_label' => sprintf('%02d:00', $cheapest['helsinki_hour']),
            'best_price' => round($cheapest['price_with_tax'], 2),
            'worst_hour' => $expensive['helsinki_hour'],
            'worst_price' => round($expensive['price_with_tax'], 2),
            'cost_cents' => round($cheapestCost, 1),
            'cost_euros' => round($cheapestCost / 100, 2),
            'savings_cents' => round($expensiveCost - $cheapestCost, 1),
            'savings_euros' => round(($expensiveCost - $cheapestCost) / 100, 2),
            'power_kw' => $kw,
            'duration_hours' => 1,
            'time_window' => '17:00-22:00',
        ];
    }

    private function calculateLaundryRecommendation(array $futurePrices, int $currentHour, string $todayDate): ?array
    {
        // Daytime 07:00-22:00, 2 kW for 2 hours
        return $this->findBestConsecutiveHours($futurePrices, 7, 22, 2, 2.0, $currentHour, $todayDate);
    }

    private function calculateDishwasherRecommendation(array $futurePrices, int $currentHour, string $todayDate): ?array
    {
        // Evening/night 18:00-08:00 (crosses midnight), 1.5 kW for 2 hours
        return $this->findBestConsecutiveHours($futurePrices, 18, 8, 2, 1.5, $currentHour, $todayDate);
    }

    private function calculateWaterHeaterRecommendation(array $futurePrices): ?array
    {
        // Any time, 2.5 kW for 1 hour
        if (empty($futurePrices)) {
            return null;
        }

        // Use price_with_tax for consumer-facing recommendations
        usort($futurePrices, fn($a, $b) => $a['price_with_tax'] <=> $b['price_with_tax']);
        $cheapest = reset($futurePrices);
        $expensive = end($futurePrices);

        $kw = 2.5;
        $cheapestCost = $cheapest['price_with_tax'] * $kw;
        $expensiveCost = $expensive['price_with_tax'] * $kw;

        return [
            'best_hour' => $cheapest['helsinki_hour'],
            'best_hour_label' => sprintf('%02d:00', $cheapest['helsinki_hour']),
            'best_price' => round($cheapest['price_with_tax'], 2),
            'cost_cents' => round($cheapestCost, 1),
            'cost_euros' => round($cheapestCost / 100, 2),
            'savings_cents' => round($expensiveCost - $cheapestCost, 1),
            'savings_euros' => round(($expensiveCost - $cheapestCost) / 100, 2),
            'power_kw' => $kw,
            'duration_hours' => 1,
            'time_window' => 'Milloin vain',
        ];
    }

    private function findBestConsecutiveHours(
        array $futurePrices,
        int $startHour,
        int $endHour,
        int $duration,
        float $kw,
        int $currentHour,
        string $todayDate
    ): ?array {
        $crossesMidnight = $startHour > $endHour;

        // Filter to time window
        $windowPrices = array_filter($futurePrices, function ($p) use ($startHour, $endHour, $crossesMidnight, $currentHour, $todayDate) {
            $hour = $p['helsinki_hour'];

            // Skip past hours
            if ($p['helsinki_date'] === $todayDate && $hour <= $currentHour) {
                return false;
            }

            if ($crossesMidnight) {
                return $hour >= $startHour || $hour < $endHour;
            }
            return $hour >= $startHour && $hour < $endHour;
        });

        if (count($windowPrices) < $duration) {
            return null;
        }

        // Sort by date and hour
        $windowPrices = array_values($windowPrices);
        usort($windowPrices, function ($a, $b) {
            $dateCompare = strcmp($a['helsinki_date'], $b['helsinki_date']);
            return $dateCompare !== 0 ? $dateCompare : $a['helsinki_hour'] <=> $b['helsinki_hour'];
        });

        $bestWindow = null;
        $bestAverage = PHP_FLOAT_MAX;
        $worstAverage = PHP_FLOAT_MIN;

        for ($i = 0; $i <= count($windowPrices) - $duration; $i++) {
            $window = array_slice($windowPrices, $i, $duration);

            // Check consecutive
            $isConsecutive = true;
            for ($j = 1; $j < count($window); $j++) {
                $prevHour = $window[$j - 1]['helsinki_hour'];
                $currHour = $window[$j]['helsinki_hour'];
                $prevDate = $window[$j - 1]['helsinki_date'];
                $currDate = $window[$j]['helsinki_date'];

                $sameDayConsec = ($prevDate === $currDate && $currHour === $prevHour + 1);
                $midnightCross = ($prevDate !== $currDate && $prevHour === 23 && $currHour === 0);

                if (!$sameDayConsec && !$midnightCross) {
                    $isConsecutive = false;
                    break;
                }
            }

            if (!$isConsecutive) {
                continue;
            }

            // Use price_with_tax for consumer-facing recommendations
            $avg = array_sum(array_column($window, 'price_with_tax')) / $duration;
            if ($avg < $bestAverage) {
                $bestAverage = $avg;
                $bestWindow = $window;
            }
            if ($avg > $worstAverage) {
                $worstAverage = $avg;
            }
        }

        if ($bestWindow === null) {
            return null;
        }

        $totalKwh = $kw * $duration;
        $cheapestCost = $bestAverage * $totalKwh;
        $expensiveCost = $worstAverage * $totalKwh;

        $startH = $bestWindow[0]['helsinki_hour'];
        $endH = ($bestWindow[$duration - 1]['helsinki_hour'] + 1) % 24;

        return [
            'start_hour' => $startH,
            'end_hour' => $endH,
            'time_label' => sprintf('%02d:00-%02d:00', $startH, $endH),
            'average_price' => round($bestAverage, 2),
            'cost_cents' => round($cheapestCost, 1),
            'cost_euros' => round($cheapestCost / 100, 2),
            'savings_cents' => round($expensiveCost - $cheapestCost, 1),
            'savings_euros' => round(($expensiveCost - $cheapestCost) / 100, 2),
            'power_kw' => $kw,
            'duration_hours' => $duration,
            'time_window' => $crossesMidnight
                ? sprintf('%02d:00-%02d:00', $startHour, $endHour)
                : sprintf('%02d:00-%02d:00', $startHour, $endHour),
        ];
    }

    private function calculateEvChargingRecommendation(array $allPrices, Carbon $helsinkiNow): ?array
    {
        $currentHour = (int) $helsinkiNow->format('H');
        $todayDate = $helsinkiNow->format('Y-m-d');

        // Filter to future hours
        $futurePrices = array_filter($allPrices, function ($p) use ($currentHour, $todayDate) {
            if ($p['helsinki_date'] === $todayDate) {
                return $p['helsinki_hour'] > $currentHour;
            }
            return true;
        });

        // EV charging: 3 hours at 11 kW (typical home charger)
        $duration = 3;
        $kw = 11.0;

        if (count($futurePrices) < $duration) {
            return null;
        }

        // Sort by date and hour
        $futurePrices = array_values($futurePrices);
        usort($futurePrices, function ($a, $b) {
            $dateCompare = strcmp($a['helsinki_date'], $b['helsinki_date']);
            return $dateCompare !== 0 ? $dateCompare : $a['helsinki_hour'] <=> $b['helsinki_hour'];
        });

        $bestWindow = null;
        $bestAverage = PHP_FLOAT_MAX;
        $worstAverage = PHP_FLOAT_MIN;

        for ($i = 0; $i <= count($futurePrices) - $duration; $i++) {
            $window = array_slice($futurePrices, $i, $duration);

            // Check consecutive
            $isConsecutive = true;
            for ($j = 1; $j < count($window); $j++) {
                $prevHour = $window[$j - 1]['helsinki_hour'];
                $currHour = $window[$j]['helsinki_hour'];
                $prevDate = $window[$j - 1]['helsinki_date'];
                $currDate = $window[$j]['helsinki_date'];

                $sameDayConsec = ($prevDate === $currDate && $currHour === $prevHour + 1);
                $midnightCross = ($prevDate !== $currDate && $prevHour === 23 && $currHour === 0);

                if (!$sameDayConsec && !$midnightCross) {
                    $isConsecutive = false;
                    break;
                }
            }

            if (!$isConsecutive) {
                continue;
            }

            // Use price_with_tax for consumer-facing recommendations
            $avg = array_sum(array_column($window, 'price_with_tax')) / $duration;
            if ($avg < $bestAverage) {
                $bestAverage = $avg;
                $bestWindow = $window;
            }
            if ($avg > $worstAverage) {
                $worstAverage = $avg;
            }
        }

        if ($bestWindow === null) {
            return null;
        }

        $totalKwh = $kw * $duration;
        $cheapestCost = $bestAverage * $totalKwh;
        $expensiveCost = $worstAverage * $totalKwh;

        $startH = $bestWindow[0]['helsinki_hour'];
        $endH = ($bestWindow[$duration - 1]['helsinki_hour'] + 1) % 24;

        return [
            'start_hour' => $startH,
            'end_hour' => $endH,
            'time_label' => sprintf('%02d:00-%02d:00', $startH, $endH),
            'average_price' => round($bestAverage, 2),
            'cost_cents' => round($cheapestCost, 1),
            'cost_euros' => round($cheapestCost / 100, 2),
            'savings_cents' => round($expensiveCost - $cheapestCost, 1),
            'savings_euros' => round(($expensiveCost - $cheapestCost) / 100, 2),
            'power_kw' => $kw,
            'duration_hours' => $duration,
            'kwh_added' => $totalKwh,
            'range_km_estimate' => round($totalKwh * 6, 0), // ~6 km per kWh average
        ];
    }

    /**
     * Find the cheapest consecutive period of hours.
     *
     * Uses a dynamic approach: finds the longest stretch of hours
     * where all prices are below the day's median (cheap zone).
     * All prices are with VAT included.
     */
    private function findCheapestPeriod(array $todayPrices): ?array
    {
        if (count($todayPrices) < 2) {
            return null;
        }

        // Sort by hour
        usort($todayPrices, fn($a, $b) => $a['helsinki_hour'] <=> $b['helsinki_hour']);

        // Calculate median to define "cheap" (using price with VAT)
        $prices = array_column($todayPrices, 'price_with_tax');
        sort($prices);
        $count = count($prices);
        $median = $count % 2 === 0
            ? ($prices[(int) ($count / 2) - 1] + $prices[(int) ($count / 2)]) / 2
            : $prices[(int) floor($count / 2)];

        // Find consecutive hours below median
        $bestPeriod = null;
        $currentPeriod = [];

        foreach ($todayPrices as $price) {
            if ($price['price_with_tax'] <= $median) {
                $currentPeriod[] = $price;
            } else {
                if (count($currentPeriod) >= 2 && ($bestPeriod === null || count($currentPeriod) > count($bestPeriod))) {
                    $bestPeriod = $currentPeriod;
                }
                $currentPeriod = [];
            }
        }
        // Check final period
        if (count($currentPeriod) >= 2 && ($bestPeriod === null || count($currentPeriod) > count($bestPeriod))) {
            $bestPeriod = $currentPeriod;
        }

        if ($bestPeriod === null) {
            // Fallback: find best 4 consecutive hours
            return $this->findBestConsecutiveWindow($todayPrices, 4);
        }

        $startHour = $bestPeriod[0]['helsinki_hour'];
        $endHour = (end($bestPeriod)['helsinki_hour'] + 1) % 24;
        $avgPrice = array_sum(array_column($bestPeriod, 'price_with_tax')) / count($bestPeriod);

        return [
            'start_hour' => $startHour,
            'end_hour' => $endHour,
            'duration_hours' => count($bestPeriod),
            'label' => sprintf('%02d:00-%02d:00', $startHour, $endHour),
            'average_price' => round($avgPrice, 2),
            'hours' => array_map(fn($p) => $p['helsinki_hour'], $bestPeriod),
        ];
    }

    /**
     * Find the most expensive consecutive period of hours.
     * All prices are with VAT included.
     */
    private function findExpensivePeriod(array $todayPrices): ?array
    {
        if (count($todayPrices) < 2) {
            return null;
        }

        // Sort by hour
        usort($todayPrices, fn($a, $b) => $a['helsinki_hour'] <=> $b['helsinki_hour']);

        // Calculate median to define "expensive" (using price with VAT)
        $prices = array_column($todayPrices, 'price_with_tax');
        sort($prices);
        $count = count($prices);
        $median = $count % 2 === 0
            ? ($prices[(int) ($count / 2) - 1] + $prices[(int) ($count / 2)]) / 2
            : $prices[(int) floor($count / 2)];

        // Find consecutive hours above median
        $worstPeriod = null;
        $currentPeriod = [];

        foreach ($todayPrices as $price) {
            if ($price['price_with_tax'] > $median) {
                $currentPeriod[] = $price;
            } else {
                if (count($currentPeriod) >= 2 && ($worstPeriod === null || count($currentPeriod) > count($worstPeriod))) {
                    $worstPeriod = $currentPeriod;
                }
                $currentPeriod = [];
            }
        }
        // Check final period
        if (count($currentPeriod) >= 2 && ($worstPeriod === null || count($currentPeriod) > count($worstPeriod))) {
            $worstPeriod = $currentPeriod;
        }

        if ($worstPeriod === null) {
            return null;
        }

        $startHour = $worstPeriod[0]['helsinki_hour'];
        $endHour = (end($worstPeriod)['helsinki_hour'] + 1) % 24;
        $avgPrice = array_sum(array_column($worstPeriod, 'price_with_tax')) / count($worstPeriod);

        return [
            'start_hour' => $startHour,
            'end_hour' => $endHour,
            'duration_hours' => count($worstPeriod),
            'label' => sprintf('%02d:00-%02d:00', $startHour, $endHour),
            'average_price' => round($avgPrice, 2),
            'hours' => array_map(fn($p) => $p['helsinki_hour'], $worstPeriod),
        ];
    }

    /**
     * Find best N consecutive hours (fallback for cheapest period).
     * All prices are with VAT included.
     */
    private function findBestConsecutiveWindow(array $todayPrices, int $windowSize): ?array
    {
        if (count($todayPrices) < $windowSize) {
            return null;
        }

        usort($todayPrices, fn($a, $b) => $a['helsinki_hour'] <=> $b['helsinki_hour']);

        $bestWindow = null;
        $bestAverage = PHP_FLOAT_MAX;

        for ($i = 0; $i <= count($todayPrices) - $windowSize; $i++) {
            $window = array_slice($todayPrices, $i, $windowSize);

            // Check consecutive hours
            $isConsecutive = true;
            for ($j = 1; $j < $windowSize; $j++) {
                if ($window[$j]['helsinki_hour'] !== $window[$j - 1]['helsinki_hour'] + 1) {
                    $isConsecutive = false;
                    break;
                }
            }

            if (!$isConsecutive) {
                continue;
            }

            $avg = array_sum(array_column($window, 'price_with_tax')) / $windowSize;
            if ($avg < $bestAverage) {
                $bestAverage = $avg;
                $bestWindow = $window;
            }
        }

        if ($bestWindow === null) {
            return null;
        }

        $startHour = $bestWindow[0]['helsinki_hour'];
        $endHour = (end($bestWindow)['helsinki_hour'] + 1) % 24;

        return [
            'start_hour' => $startHour,
            'end_hour' => $endHour,
            'duration_hours' => $windowSize,
            'label' => sprintf('%02d:00-%02d:00', $startHour, $endHour),
            'average_price' => round($bestAverage, 2),
            'hours' => array_map(fn($p) => $p['helsinki_hour'], $bestWindow),
        ];
    }

    private function generateChartData(array $todayPrices): array
    {
        if (empty($todayPrices)) {
            return ['hours' => [], 'prices' => [], 'colors' => []];
        }

        usort($todayPrices, fn($a, $b) => $a['helsinki_hour'] <=> $b['helsinki_hour']);

        // Use price_with_tax for consumer-facing chart
        $prices = array_column($todayPrices, 'price_with_tax');
        $minPrice = min($prices);
        $maxPrice = max($prices);
        $range = $maxPrice - $minPrice;

        $hours = [];
        $priceValues = [];
        $colors = [];

        foreach ($todayPrices as $price) {
            $hours[] = sprintf('%02d', $price['helsinki_hour']);
            $priceValues[] = round($price['price_with_tax'], 2);

            // Color based on price position
            $normalized = $range > 0 ? ($price['price_with_tax'] - $minPrice) / $range : 0.5;
            if ($normalized < 0.33) {
                $colors[] = '#22c55e'; // green
            } elseif ($normalized < 0.66) {
                $colors[] = '#eab308'; // yellow
            } else {
                $colors[] = '#ef4444'; // red
            }
        }

        return [
            'hours' => $hours,
            'prices' => $priceValues,
            'colors' => $colors,
            'min' => round($minPrice, 2),
            'max' => round($maxPrice, 2),
        ];
    }

    private function getHistoricalComparison(Carbon $helsinkiNow, ?float $todayAverage): array
    {
        // Yesterday's average (with VAT)
        $yesterdayStart = $helsinkiNow->copy()->subDay()->startOfDay()->setTimezone('UTC');
        $yesterdayEnd = $helsinkiNow->copy()->subDay()->endOfDay()->setTimezone('UTC');

        $yesterdayPrices = SpotPriceHour::forRegion(self::REGION)
            ->whereBetween('utc_datetime', [$yesterdayStart, $yesterdayEnd])
            ->get()
            ->pluck('price_with_tax')
            ->toArray();

        $yesterdayAverage = !empty($yesterdayPrices)
            ? array_sum($yesterdayPrices) / count($yesterdayPrices)
            : null;

        // Rolling averages (stored without tax, need to apply VAT)
        $rolling30 = SpotPriceAverage::latestRolling30Days(self::REGION);
        $rolling365 = SpotPriceAverage::latestRolling365Days(self::REGION);

        // Apply VAT to rolling averages
        $rolling30AvgWithVat = $rolling30?->avg_price_without_tax
            ? $rolling30->avg_price_without_tax * (1 + self::VAT_RATE)
            : null;
        $rolling365AvgWithVat = $rolling365?->avg_price_without_tax
            ? $rolling365->avg_price_without_tax * (1 + self::VAT_RATE)
            : null;

        // Calculate changes (now comparing VAT-inclusive prices)
        $changeFromYesterday = ($yesterdayAverage && $yesterdayAverage > 0 && $todayAverage)
            ? round((($todayAverage - $yesterdayAverage) / $yesterdayAverage) * 100, 1)
            : null;

        $changeFrom30d = ($rolling30AvgWithVat && $rolling30AvgWithVat > 0 && $todayAverage)
            ? round((($todayAverage - $rolling30AvgWithVat) / $rolling30AvgWithVat) * 100, 1)
            : null;

        // Day rating based on comparison to 30-day average (all with VAT)
        $dayRating = $this->calculateDayRating($todayAverage, $rolling30AvgWithVat);

        return [
            'yesterday_average' => $yesterdayAverage ? round($yesterdayAverage, 2) : null,
            'change_from_yesterday_percent' => $changeFromYesterday,
            'rolling_30d_average' => $rolling30AvgWithVat ? round($rolling30AvgWithVat, 2) : null,
            'change_from_30d_percent' => $changeFrom30d,
            'rolling_365d_average' => $rolling365AvgWithVat ? round($rolling365AvgWithVat, 2) : null,
            'day_rating' => $dayRating,
        ];
    }

    /**
     * Calculate day rating based on today's average vs 30-day rolling average.
     *
     * Rating thresholds:
     * - HALPA: 15%+ cheaper than average
     * - EDULLINEN: 5-15% cheaper
     * - NORMAALI: within 5% of average
     * - KALLIS: 5-15% more expensive
     * - ERITTÄIN KALLIS: 15%+ more expensive
     */
    private function calculateDayRating(?float $todayAverage, ?float $rolling30Avg): array
    {
        if ($todayAverage === null || $rolling30Avg === null || $rolling30Avg <= 0) {
            return [
                'code' => 'unknown',
                'label' => 'Ei tietoa',
                'label_short' => '—',
                'color' => '#6b7280', // gray
                'description' => null,
            ];
        }

        $percentDiff = (($todayAverage - $rolling30Avg) / $rolling30Avg) * 100;

        if ($percentDiff <= -15) {
            return [
                'code' => 'very_cheap',
                'label' => 'Halpa päivä',
                'label_short' => 'HALPA',
                'color' => '#22c55e', // green
                'percent_diff' => round($percentDiff, 1),
                'description' => sprintf('%d%% halvempi kuin normaalisti', abs(round($percentDiff))),
            ];
        } elseif ($percentDiff <= -5) {
            return [
                'code' => 'cheap',
                'label' => 'Edullinen päivä',
                'label_short' => 'EDULLINEN',
                'color' => '#84cc16', // lime
                'percent_diff' => round($percentDiff, 1),
                'description' => sprintf('%d%% halvempi kuin normaalisti', abs(round($percentDiff))),
            ];
        } elseif ($percentDiff <= 5) {
            return [
                'code' => 'normal',
                'label' => 'Normaali päivä',
                'label_short' => 'NORMAALI',
                'color' => '#eab308', // yellow
                'percent_diff' => round($percentDiff, 1),
                'description' => 'Lähellä 30 päivän keskiarvoa',
            ];
        } elseif ($percentDiff <= 15) {
            return [
                'code' => 'expensive',
                'label' => 'Kallis päivä',
                'label_short' => 'KALLIS',
                'color' => '#f97316', // orange
                'percent_diff' => round($percentDiff, 1),
                'description' => sprintf('%d%% kalliimpi kuin normaalisti', round($percentDiff)),
            ];
        } else {
            return [
                'code' => 'very_expensive',
                'label' => 'Erittäin kallis päivä',
                'label_short' => 'KALLIS',
                'color' => '#ef4444', // red
                'percent_diff' => round($percentDiff, 1),
                'description' => sprintf('%d%% kalliimpi kuin normaalisti', round($percentDiff)),
            ];
        }
    }

    private function formatPricesForVideo(array $prices): array
    {
        usort($prices, fn($a, $b) => $a['helsinki_hour'] <=> $b['helsinki_hour']);

        // Primary price is with VAT for consumer-facing display
        return array_map(fn($p) => [
            'hour' => $p['helsinki_hour'],
            'label' => sprintf('%02d:00', $p['helsinki_hour']),
            'price' => round($p['price_with_tax'], 2),
        ], array_values($prices));
    }
}
