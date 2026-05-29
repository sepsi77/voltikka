<?php

namespace App\Livewire;

use App\Models\SpotPriceAverage;
use App\Models\SpotPriceForecast;
use App\Models\SpotPriceHour;
use App\Models\SpotPriceQuarter;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Livewire\Component;

class SpotPrice extends Component
{
    public array $hourlyPrices = [];
    public array $quarterPricesByHour = [];  // Pre-loaded quarter prices keyed by hour timestamp
    public array $forecastPrices = [];
    public array $forecastSource = [];
    public bool $loading = true;
    public ?string $error = null;
    public bool $historicalDataLoaded = false;
    public ?float $rolling30DayAvgWithVat = null;

    // Historical data (lazy loaded)
    public array $weeklyDailyAverages = [];
    public array $weeklyChartData = [];
    public array $monthlyComparison = [];
    public array $yearOverYearComparison = [];
    public array $multiYearMonthly = [];

    private const REGION = 'FI';
    private const TIMEZONE = 'Europe/Helsinki';
    private const FORECAST_DISPLAY_HOURS = 72;

    /**
     * Last manual review date of the methodology/FAQ copy on this page. Shown as an
     * editorial "Päivitetty" byline; bump this when the price/forecast explanations change.
     */
    private const METHODOLOGY_REVIEWED_AT = '2026-05-29';
    private const FORECAST_CACHE_KEY = 'spot_price:forecast:nordpool_predict_fi:v1';

    private const FINNISH_WEEKDAYS_SHORT = [
        1 => 'ma',
        2 => 'ti',
        3 => 'ke',
        4 => 'to',
        5 => 'pe',
        6 => 'la',
        7 => 'su',
    ];

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
        $this->loadForecastPrices();
        $this->loadRolling30DayAverage();
        $this->loadHistoricalData();
    }

    /**
     * Load the 30-day rolling average with VAT for the clock chart.
     */
    private function loadRolling30DayAverage(): void
    {
        $this->rolling30DayAvgWithVat = Cache::remember('spot_price:rolling30:v1', 300, function () {
            $rolling30 = SpotPriceAverage::latestRolling30Days(self::REGION);

            return $rolling30?->avg_price_without_tax
                ? round($rolling30->avg_price_without_tax * 1.255, 2)
                : null;
        });
    }

    /**
     * Get today's 24 hourly prices (with VAT) for the clock chart.
     *
     * Returns an array of 24 prices indexed by hour (0-23), each with:
     * - hour: 0-23
     * - price_with_vat: price in c/kWh with VAT
     *
     * @return array
     */
    public function getTodayPricesForClock(): array
    {
        $todayPrices = $this->getTodayPrices();

        if (empty($todayPrices)) {
            return [];
        }

        // Sort by hour and extract just what the clock needs
        usort($todayPrices, fn($a, $b) => $a['helsinki_hour'] <=> $b['helsinki_hour']);

        return array_map(fn($price) => [
            'hour' => $price['helsinki_hour'],
            'price_with_vat' => $price['price_with_tax'],
        ], $todayPrices);
    }

    /**
     * Get color class based on percentage difference from 30d average.
     * Uses the same 7-tier scale as the clock chart.
     */
    private function getColorClassFromAvgDiff(float $price, ?float $avg30d): string
    {
        if ($avg30d === null || $avg30d <= 0) {
            return 'bg-yellow-400'; // Default to yellow if no average
        }

        $percentDiff = (($price - $avg30d) / $avg30d) * 100;

        if ($percentDiff <= -30) return 'bg-green-700';
        if ($percentDiff <= -15) return 'bg-green-500';
        if ($percentDiff <= -5) return 'bg-green-300';
        if ($percentDiff <= 5) return 'bg-yellow-400';
        if ($percentDiff <= 15) return 'bg-orange-400';
        if ($percentDiff <= 30) return 'bg-red-500';
        return 'bg-red-700';
    }

    /**
     * Get price badge (Edullinen/Normaali/Kallis) based on 5% threshold from 30d average.
     *
     * @param float $price The price to evaluate
     * @param float|null $avg30d The 30-day average to compare against
     * @return array{label: string, type: string} Badge label and type (cheap/normal/expensive)
     */
    private function getPriceBadge(float $price, ?float $avg30d): array
    {
        if ($avg30d === null || $avg30d <= 0) {
            return ['label' => 'Normaali', 'type' => 'normal'];
        }

        $percentDiff = (($price - $avg30d) / $avg30d) * 100;

        if ($percentDiff <= -5) {
            return ['label' => 'Edullinen', 'type' => 'cheap'];
        } elseif ($percentDiff >= 5) {
            return ['label' => 'Kallis', 'type' => 'expensive'];
        } else {
            return ['label' => 'Normaali', 'type' => 'normal'];
        }
    }

    /**
     * Get today's prices with metadata for the horizontal bar chart.
     *
     * Returns an array of prices for today with:
     * - timestamp: Unix timestamp for the hour start
     * - hour: 0-23
     * - price_with_vat: Price in c/kWh with VAT
     * - price_without_vat: Price in c/kWh without VAT
     * - colorClass: Tailwind color class based on % diff from 30d average
     * - widthPercent: Bar width percentage (0-100) based on absolute price
     * - isCurrentHour: Whether this is the current hour
     *
     * @return array
     */
    public function getTodayPricesWithMeta(): array
    {
        $todayPrices = $this->getTodayPrices();

        if (empty($todayPrices)) {
            return [];
        }

        // Sort by hour
        usort($todayPrices, fn($a, $b) => $a['helsinki_hour'] <=> $b['helsinki_hour']);

        // Calculate rank within today (1 = cheapest, 24 = most expensive)
        $pricesForRanking = $todayPrices;
        usort($pricesForRanking, fn($a, $b) => $a['price_with_tax'] <=> $b['price_with_tax']);
        $todayRanks = [];
        foreach ($pricesForRanking as $rank => $price) {
            $todayRanks[$price['helsinki_hour']] = $rank + 1; // 1-indexed
        }

        // Find max price across today+tomorrow for consistent absolute scale
        $allPrices = array_column($this->hourlyPrices, 'price_with_tax');
        $maxPrice = !empty($allPrices) ? max($allPrices) : 20;
        // Ensure minimum scale of 20 c/kWh so bars are visible
        $scaleMax = max($maxPrice, 20);

        // Get 30d average (with VAT) for color calculation
        $avg30dWithVat = $this->rolling30DayAvgWithVat;

        // Get current hour for highlighting
        $helsinkiNow = Carbon::now(self::TIMEZONE);
        $currentHour = (int) $helsinkiNow->format('H');
        $todayDate = $helsinkiNow->format('Y-m-d');

        return array_map(function ($price) use ($scaleMax, $avg30dWithVat, $currentHour, $todayDate, $todayRanks) {
            $priceWithVat = $price['price_with_tax'];
            $hour = $price['helsinki_hour'];

            // Check if this is the current hour
            $isCurrentHour = $price['helsinki_date'] === $todayDate && $hour === $currentHour;

            // Get color based on % difference from 30d average (same for all hours, including current)
            $colorClass = $this->getColorClassFromAvgDiff($priceWithVat, $avg30dWithVat);

            // Calculate width as absolute percentage (price / scaleMax * 100)
            $widthPercent = min(100, max(3, round(($priceWithVat / $scaleMax) * 100)));

            // Get badge (Edullinen/Normaali/Kallis) - relative to 30d avg
            $badge = $this->getPriceBadge($priceWithVat, $avg30dWithVat);

            // Get today's rank (1 = cheapest today)
            $todayRank = $todayRanks[$hour] ?? null;

            return [
                'timestamp' => $price['timestamp'],
                'hour' => $hour,
                'price_with_vat' => $priceWithVat,
                'price_without_vat' => $price['price_without_tax'],
                'colorClass' => $colorClass,
                'widthPercent' => $widthPercent,
                'isCurrentHour' => $isCurrentHour,
                'badge' => $badge,
                'todayRank' => $todayRank,
            ];
        }, $todayPrices);
    }

    /**
     * Get tomorrow's prices with metadata for the horizontal bar chart.
     *
     * Tomorrow's prices are typically available after ~14:00 Finnish time from ENTSO-E.
     *
     * @return array Empty array if tomorrow's prices are not yet available
     */
    public function getTomorrowPricesWithMeta(): array
    {
        $helsinkiNow = Carbon::now(self::TIMEZONE);
        $tomorrowDate = $helsinkiNow->copy()->addDay()->format('Y-m-d');

        // Filter to only tomorrow's prices
        $tomorrowPrices = array_filter(
            $this->hourlyPrices,
            fn($price) => $price['helsinki_date'] === $tomorrowDate
        );

        if (empty($tomorrowPrices)) {
            return [];
        }

        // Sort by hour
        usort($tomorrowPrices, fn($a, $b) => $a['helsinki_hour'] <=> $b['helsinki_hour']);

        // Find max price across today+tomorrow for consistent absolute scale
        $allPrices = array_column($this->hourlyPrices, 'price_with_tax');
        $maxPrice = !empty($allPrices) ? max($allPrices) : 20;
        $scaleMax = max($maxPrice, 20);

        // Get 30d average (with VAT) for color calculation
        $avg30dWithVat = $this->rolling30DayAvgWithVat;

        return array_map(function ($price) use ($scaleMax, $avg30dWithVat) {
            $priceWithVat = $price['price_with_tax'];

            // Get color based on % difference from 30d average
            $colorClass = $this->getColorClassFromAvgDiff($priceWithVat, $avg30dWithVat);

            // Calculate width as absolute percentage (price / scaleMax * 100)
            $widthPercent = min(100, max(3, round(($priceWithVat / $scaleMax) * 100)));

            // Get badge (Edullinen/Normaali/Kallis)
            $badge = $this->getPriceBadge($priceWithVat, $avg30dWithVat);

            return [
                'timestamp' => $price['timestamp'],
                'hour' => $price['helsinki_hour'],
                'price_with_vat' => $priceWithVat,
                'price_without_vat' => $price['price_without_tax'],
                'colorClass' => $colorClass,
                'widthPercent' => $widthPercent,
                'isCurrentHour' => false, // Tomorrow's hours are never "current"
                'badge' => $badge,
            ];
        }, array_values($tomorrowPrices));
    }

    /**
     * Check if tomorrow's prices are available.
     *
     * @return bool
     */
    public function hasTomorrowPrices(): bool
    {
        return !empty($this->getTomorrowPricesWithMeta());
    }

    /**
     * Get third-party forecast prices with metadata for the forecast bar chart.
     *
     * @return array<int, array<string, mixed>>
     */
    public function getForecastPricesWithMeta(): array
    {
        if (empty($this->forecastPrices)) {
            return [];
        }

        $allPrices = array_merge(
            array_column($this->hourlyPrices, 'price_with_tax'),
            array_column($this->forecastPrices, 'price_with_tax')
        );
        $maxPrice = !empty($allPrices) ? max($allPrices) : 20;
        $scaleMax = max($maxPrice, 20);
        $avg30dWithVat = $this->rolling30DayAvgWithVat;

        return array_map(function ($price) use ($scaleMax, $avg30dWithVat) {
            $priceWithVat = $price['price_with_tax'];
            $colorClass = $this->getColorClassFromAvgDiff($priceWithVat, $avg30dWithVat);
            $widthPercent = min(100, max(3, round(($priceWithVat / $scaleMax) * 100)));
            $badge = $this->getPriceBadge($priceWithVat, $avg30dWithVat);

            return [
                'timestamp' => $price['timestamp'],
                'hour' => $price['helsinki_hour'],
                'helsinki_date' => $price['helsinki_date'],
                'price_with_vat' => $priceWithVat,
                'price_without_vat' => $price['price_without_tax'],
                'colorClass' => $colorClass,
                'widthPercent' => $widthPercent,
                'badge' => $badge,
                'source' => $price['source'],
                'isForecast' => true,
            ];
        }, $this->forecastPrices);
    }

    /**
     * Build a unified list of day-strips for the column-bar timeline chart.
     *
     * Tomorrow's actual hours and tomorrow's third-party forecast hours are merged
     * into a single strip so the user doesn't see two charts for the same calendar
     * day. Later forecast-only days are kept only when they have at least 6 hours
     * of data so the page doesn't show sparse "looks broken" strips.
     *
     * Each strip is self-scaled — its bar heights are computed against its own max
     * price, not a globally unified max, so a 7-day-out forecast spike can't squash
     * today's bars. The 30-day average is exposed per strip as a percent height so
     * the view can draw a labeled baseline.
     *
     * @return array<int, array<string, mixed>>
     */
    public function getDayStripsForChart(): array
    {
        $helsinkiNow = Carbon::now(self::TIMEZONE);
        $todayDate = $helsinkiNow->format('Y-m-d');
        $tomorrowDate = $helsinkiNow->copy()->addDay()->format('Y-m-d');

        $forecastByDate = [];
        foreach ($this->getForecastPricesGroupedByDate() as $group) {
            $forecastByDate[$group['date']] = $group['prices'];
        }

        $strips = [];

        $today = $this->getTodayPricesWithMeta();
        if (!empty($today)) {
            $strips[] = [
                'key' => 'today',
                'label' => 'Tänään',
                'date' => $todayDate,
                'provenance' => null,
                'isForecast' => false,
                'forecastStartHour' => null,
                'prices' => array_map(fn(array $p) => $p + ['isForecast' => false], $today),
            ];
        }

        $tomorrowActual = $this->getTomorrowPricesWithMeta();
        $tomorrowForecast = $forecastByDate[$tomorrowDate] ?? [];
        unset($forecastByDate[$tomorrowDate]);

        if (!empty($tomorrowActual) || !empty($tomorrowForecast)) {
            $merged = array_map(fn(array $p) => $p + ['isForecast' => false], $tomorrowActual);
            foreach ($tomorrowForecast as $p) {
                $merged[] = $p + ['isForecast' => true];
            }

            $hasActual = !empty($tomorrowActual);
            $hasForecast = !empty($tomorrowForecast);
            $forecastStartHour = $hasForecast
                ? min(array_map(fn($p) => (int) ($p['helsinki_hour'] ?? $p['hour'] ?? 0), $tomorrowForecast))
                : null;

            $provenance = match (true) {
                $hasActual && $hasForecast => 'hybrid',
                $hasActual && !$hasForecast => 'uudet',
                default => 'ennuste',
            };

            $strips[] = [
                'key' => 'tomorrow',
                'label' => 'Huomenna',
                'date' => $tomorrowDate,
                'provenance' => $provenance,
                'isForecast' => !$hasActual,
                'forecastStartHour' => $forecastStartHour,
                'prices' => $merged,
            ];
        }

        foreach ($forecastByDate as $date => $prices) {
            if (count($prices) < 6) {
                continue;
            }

            $strips[] = [
                'key' => 'forecast_' . $date,
                'label' => $this->formatForecastDateLabel($date),
                'date' => $date,
                'provenance' => 'ennuste',
                'isForecast' => true,
                'forecastStartHour' => null,
                'prices' => array_map(fn(array $p) => $p + ['isForecast' => true], $prices),
            ];
        }

        $avg30d = $this->rolling30DayAvgWithVat;

        // Shared scale across every strip so days are visually comparable. The scale
        // also reserves enough headroom for the 30-day average baseline so it sits
        // inside the chart area on every strip, including days whose own max is
        // below the average.
        $allValues = [];
        foreach ($strips as $strip) {
            foreach ($strip['prices'] as $price) {
                $value = $price['price_with_vat'] ?? null;
                if ($value !== null) {
                    $allValues[] = (float) $value;
                }
            }
        }
        $globalMax = !empty($allValues) ? max($allValues) : 0;
        $avgFloor = ($avg30d !== null && $avg30d > 0) ? $avg30d * 1.15 : 0;
        $scaleMax = max($globalMax * 1.1, $avgFloor, 3.0);

        return array_map(function (array $strip) use ($avg30d, $scaleMax) {
            $strip['stats'] = $this->calculateStripStats($strip['prices']);
            $strip['scaleMax'] = $scaleMax;
            $strip['avgBaselinePercent'] = ($avg30d !== null && $avg30d > 0 && $avg30d <= $scaleMax)
                ? round(($avg30d / $scaleMax) * 100, 1)
                : null;
            $strip['avgBaselineValue'] = $avg30d;

            $strip['prices'] = array_map(function (array $price) use ($scaleMax) {
                $value = $price['price_with_vat'] ?? null;
                $isForecast = !empty($price['isForecast']);
                $isCurrent = !empty($price['isCurrentHour']);

                $price['widthPercent'] = $value !== null
                    ? (int) max(4, min(100, round(((float) $value / $scaleMax) * 100)))
                    : 0;

                if ($isCurrent) {
                    $price['colorClass'] = 'bg-coral-500';
                } elseif ($isForecast) {
                    $price['colorClass'] = 'bg-slate-300';
                } else {
                    $price['colorClass'] = 'bg-slate-500';
                }

                return $price;
            }, $strip['prices']);

            $strip['prices'] = $this->padPricesTo24Slots($strip['prices']);

            return $strip;
        }, $strips);
    }

    /**
     * Pad a price array to 24 hour slots so visual axes line up across strips.
     * Missing hours are filled with placeholder rows that render as empty columns.
     *
     * @param array<int, array<string, mixed>> $prices
     * @return array<int, array<string, mixed>>
     */
    private function padPricesTo24Slots(array $prices): array
    {
        $byHour = [];
        foreach ($prices as $price) {
            $hour = $price['hour'] ?? $price['helsinki_hour'] ?? null;
            if ($hour !== null) {
                $byHour[(int) $hour] = $price;
            }
        }

        $padded = [];
        for ($hour = 0; $hour < 24; $hour++) {
            $padded[] = $byHour[$hour] ?? [
                'hour' => $hour,
                'price_with_vat' => null,
                'colorClass' => 'bg-transparent',
                'widthPercent' => 0,
                'isCurrentHour' => false,
                'badge' => ['label' => '', 'type' => 'normal'],
                'timestamp' => 0,
                'isPlaceholder' => true,
            ];
        }

        return $padded;
    }

    /**
     * @param array<int, array<string, mixed>> $prices
     * @return array{min: float|null, avg: float|null, max: float|null}
     */
    private function calculateStripStats(array $prices): array
    {
        $values = array_column($prices, 'price_with_vat');

        if (empty($values)) {
            return ['min' => null, 'avg' => null, 'max' => null];
        }

        return [
            'min' => min($values),
            'avg' => array_sum($values) / count($values),
            'max' => max($values),
        ];
    }

    /**
     * Group forecast prices by Helsinki date for compact display.
     *
     * @return array<int, array{date: string, label: string, prices: array<int, array<string, mixed>>}>
     */
    public function getForecastPricesGroupedByDate(): array
    {
        $prices = $this->getForecastPricesWithMeta();

        if (empty($prices)) {
            return [];
        }

        $groups = [];
        foreach ($prices as $price) {
            $date = $price['helsinki_date'];

            if (!isset($groups[$date])) {
                $groups[$date] = [
                    'date' => $date,
                    'label' => $this->formatForecastDateLabel($date),
                    'prices' => [],
                ];
            }

            $groups[$date]['prices'][] = $price;
        }

        return array_values($groups);
    }

    public function fetchPrices(): void
    {
        $this->loading = true;
        $this->error = null;

        try {
            $priceData = Cache::remember('spot_price:current:v1', 60, fn () => $this->buildCurrentPriceData());
            $this->hourlyPrices = $priceData['hourlyPrices'];
            $this->quarterPricesByHour = $priceData['quarterPricesByHour'];
        } catch (\Exception $e) {
            $this->error = 'Hintatietojen lataaminen epäonnistui. Yritä myöhemmin uudelleen.';
        }

        $this->loading = false;
    }

    /**
     * Load prices for today and tomorrow from the database.
     *
     * @return array{hourlyPrices: array<int, array<string, mixed>>, quarterPricesByHour: array<int, array<int, array<string, mixed>>>}
     */
    private function buildCurrentPriceData(): array
    {
        $helsinkiNow = Carbon::now(self::TIMEZONE);
        $todayStart = $helsinkiNow->copy()->startOfDay()->setTimezone('UTC');
        $tomorrowEnd = $helsinkiNow->copy()->addDay()->endOfDay()->setTimezone('UTC');

        $prices = SpotPriceHour::forRegion(self::REGION)
            ->whereBetween('utc_datetime', [$todayStart, $tomorrowEnd])
            ->orderBy('utc_datetime')
            ->get();

        return [
            'hourlyPrices' => $prices->map(function (SpotPriceHour $price) {
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
            })->toArray(),
            'quarterPricesByHour' => $this->loadQuarterPricesForRange($todayStart, $tomorrowEnd),
        ];
    }

    /**
     * Load third-party forecast prices from the database.
     */
    private function loadForecastPrices(): void
    {
        try {
            $forecastData = app()->runningUnitTests()
                ? $this->buildForecastPriceData()
                : Cache::remember(self::FORECAST_CACHE_KEY, 300, fn () => $this->buildForecastPriceData());

            $this->forecastPrices = $forecastData['forecastPrices'];
            $this->forecastSource = $forecastData['source'];
        } catch (\Exception) {
            $this->forecastPrices = [];
            $this->forecastSource = [];
        }
    }

    /**
     * Build display-ready third-party forecast data after the latest official actual price.
     *
     * @return array{forecastPrices: array<int, array<string, mixed>>, source: array<string, mixed>}
     */
    private function buildForecastPriceData(): array
    {
        $helsinkiNow = Carbon::now(self::TIMEZONE);
        $nowUtc = $helsinkiNow->copy()->startOfHour()->setTimezone('UTC');
        $latestActualUtc = SpotPriceHour::forRegion(self::REGION)
            ->where('utc_datetime', '>=', $nowUtc)
            ->where('utc_datetime', '<=', $helsinkiNow->copy()->addDays(2)->endOfDay()->setTimezone('UTC'))
            ->orderByDesc('utc_datetime')
            ->value('utc_datetime');

        $startUtc = $latestActualUtc
            ? Carbon::parse($latestActualUtc, 'UTC')->addHour()
            : $nowUtc->copy();

        if ($startUtc->lt($nowUtc)) {
            $startUtc = $nowUtc->copy();
        }

        $endUtc = $helsinkiNow->copy()->addDays(7)->endOfDay()->setTimezone('UTC');

        $forecasts = SpotPriceForecast::forRegion(self::REGION)
            ->forSource(SpotPriceForecast::SOURCE_NORDPOOL_PREDICT_FI)
            ->where('utc_datetime', '>=', $startUtc)
            ->where('utc_datetime', '<=', $endUtc)
            ->orderBy('utc_datetime')
            ->limit(self::FORECAST_DISPLAY_HOURS)
            ->get();

        $latestSourceRecord = SpotPriceForecast::forRegion(self::REGION)
            ->forSource(SpotPriceForecast::SOURCE_NORDPOOL_PREDICT_FI)
            ->orderByDesc('fetched_at')
            ->first();

        return [
            'forecastPrices' => $forecasts->map(function (SpotPriceForecast $forecast) {
                $utcTime = Carbon::parse($forecast->utc_datetime)->shiftTimezone('UTC');
                $helsinkiTime = $utcTime->copy()->setTimezone(self::TIMEZONE);

                return [
                    'region' => $forecast->region,
                    'source' => $forecast->source,
                    'timestamp' => $forecast->timestamp,
                    'utc_datetime' => $utcTime->toIso8601String(),
                    'price_without_tax' => round($forecast->price_without_tax, 2),
                    'vat_rate' => $forecast->vat_rate,
                    'price_with_tax' => round($forecast->price_with_tax, 2),
                    'helsinki_hour' => (int) $helsinkiTime->format('H'),
                    'helsinki_date' => $helsinkiTime->format('Y-m-d'),
                ];
            })->toArray(),
            'source' => [
                'name' => 'nordpool-predict-fi',
                'author' => 'vividfog',
                'url' => (string) config('services.spot_forecasts.nordpool_predict_fi.source_url', 'https://github.com/vividfog/nordpool-predict-fi'),
                'license' => 'MIT',
                'display_hours' => self::FORECAST_DISPLAY_HOURS,
                'fetched_at' => $latestSourceRecord?->fetched_at
                    ? $latestSourceRecord->fetched_at->copy()->setTimezone(self::TIMEZONE)->format('j.n.Y H:i')
                    : null,
            ],
        ];
    }

    /**
     * Format a Helsinki date for forecast day headings.
     */
    private function formatForecastDateLabel(string $date): string
    {
        $forecastDate = Carbon::parse($date, self::TIMEZONE)->startOfDay();
        $today = Carbon::now(self::TIMEZONE)->startOfDay();

        if ($forecastDate->equalTo($today)) {
            return 'Tänään';
        }

        if ($forecastDate->equalTo($today->copy()->addDay())) {
            return 'Huomenna';
        }

        $weekday = self::FINNISH_WEEKDAYS_SHORT[$forecastDate->isoWeekday()] ?? '';

        return trim($weekday . ' ' . $forecastDate->format('j.n.'));
    }

    /**
     * Pre-load all quarter prices for a time range and organize by hour timestamp.
     *
     * @return array<int, array<int, array<string, mixed>>>
     */
    private function loadQuarterPricesForRange(Carbon $start, Carbon $end): array
    {
        $quarterPrices = SpotPriceQuarter::forRegion(self::REGION)
            ->whereBetween('utc_datetime', [$start, $end])
            ->orderBy('utc_datetime')
            ->get();

        $quarterPricesByHour = [];

        // Get current Helsinki time for determining the active slot
        $helsinkiNow = Carbon::now(self::TIMEZONE);
        $currentDate = $helsinkiNow->format('Y-m-d');
        $currentHour = (int) $helsinkiNow->format('H');
        $currentMinute = (int) $helsinkiNow->format('i');
        // Calculate current quarter (0, 15, 30, 45)
        $currentQuarterMinute = (int) floor($currentMinute / 15) * 15;

        foreach ($quarterPrices as $price) {
            $utcTime = Carbon::parse($price->utc_datetime)->shiftTimezone('UTC');
            $helsinkiTime = $utcTime->copy()->setTimezone(self::TIMEZONE);

            // Calculate the hour's start timestamp for grouping
            $hourStart = $utcTime->copy()->minute(0)->second(0);
            $hourTimestamp = $hourStart->timestamp;

            if (!isset($quarterPricesByHour[$hourTimestamp])) {
                $quarterPricesByHour[$hourTimestamp] = [];
            }

            $minute = (int) $helsinkiTime->format('i');
            $nextMinute = $minute + 15;
            $priceDate = $helsinkiTime->format('Y-m-d');
            $priceHour = (int) $helsinkiTime->format('H');

            // Check if this is the current 15-minute slot
            $isCurrentSlot = (
                $priceDate === $currentDate &&
                $priceHour === $currentHour &&
                $minute === $currentQuarterMinute
            );

            $quarterPricesByHour[$hourTimestamp][] = [
                'timestamp' => $price->timestamp,
                'price_without_tax' => $price->price_without_tax,
                'price_with_tax' => round($price->price_with_tax, 2),
                'vat_rate' => $price->vat_rate,
                'helsinki_minute' => $minute,
                'is_current_slot' => $isCurrentSlot,
                'time_label' => sprintf(
                    '%s:%02d-%s:%02d',
                    $helsinkiTime->format('H'),
                    $minute,
                    $nextMinute === 60 ? $helsinkiTime->copy()->addHour()->format('H') : $helsinkiTime->format('H'),
                    $nextMinute === 60 ? 0 : $nextMinute
                ),
            ];
        }

        return $quarterPricesByHour;
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
        $helsinkiNow = Carbon::now(self::TIMEZONE);

        // Try to get 15-minute price first
        $quarterPrice = $this->getCurrentQuarterPrice($helsinkiNow);
        if ($quarterPrice) {
            return $quarterPrice;
        }

        // Fall back to hourly price
        if (empty($this->hourlyPrices)) {
            return null;
        }

        $currentHour = (int) $helsinkiNow->format('H');
        $todayDate = $helsinkiNow->format('Y-m-d');

        foreach ($this->hourlyPrices as $price) {
            if ($price['helsinki_hour'] === $currentHour && $price['helsinki_date'] === $todayDate) {
                $price['is_quarter'] = false;
                $price['time_label'] = sprintf('%s:00 - %s:00', $helsinkiNow->format('H'), $helsinkiNow->copy()->addHour()->format('H'));
                return $price;
            }
        }

        return null;
    }

    /**
     * Get the current 15-minute interval price.
     */
    private function getCurrentQuarterPrice(Carbon $helsinkiNow): ?array
    {
        $minute = (int) $helsinkiNow->format('i');
        $quarterMinute = (int) floor($minute / 15) * 15;
        $hourStartTimestamp = $helsinkiNow->copy()->minute(0)->second(0)->setTimezone('UTC')->timestamp;
        $quarters = $this->quarterPricesByHour[$hourStartTimestamp] ?? [];

        if (empty($quarters)) {
            return null;
        }

        $currentQuarter = collect($quarters)->first(fn (array $quarter) => ($quarter['helsinki_minute'] ?? null) === $quarterMinute);

        if (! $currentQuarter) {
            return null;
        }

        $quarterIndex = (int) floor($minute / 15);
        $quarterStartMinute = $quarterIndex * 15;
        $quarterEndMinute = $quarterStartMinute + 15;

        return [
            'price_with_tax' => $currentQuarter['price_with_tax'],
            'price_without_tax' => $currentQuarter['price_without_tax'],
            'is_quarter' => true,
            'time_label' => sprintf(
                '%s:%02d - %s:%02d',
                $helsinkiNow->format('H'),
                $quarterStartMinute,
                $quarterEndMinute === 60 ? $helsinkiNow->copy()->addHour()->format('H') : $helsinkiNow->format('H'),
                $quarterEndMinute === 60 ? 0 : $quarterEndMinute
            ),
        ];
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
     * Get today's verdict comparing day average vs 30-day average.
     *
     * Returns:
     * - verdict: 'cheap' (≤-10%), 'normal' (-10% to +10%), 'expensive' (≥+10%)
     * - verdict_label: Finnish label (Edullinen/Normaali/Kallis päivä)
     * - today_avg_with_vat: Today's average price with VAT
     * - avg_30d_with_vat: 30-day average with VAT
     * - percent_diff: Percentage difference from 30d average
     * - hours_above_avg: Number of hours above 30d average
     * - hours_below_avg: Number of hours below 30d average
     * - total_hours: Total hours with prices today
     */
    public function getTodayVerdict(): array
    {
        $todayPrices = $this->getTodayPrices();
        $avg30dWithVat = $this->rolling30DayAvgWithVat;

        if (empty($todayPrices) || $avg30dWithVat === null || $avg30dWithVat <= 0) {
            return [
                'verdict' => null,
                'verdict_label' => null,
                'today_avg_with_vat' => null,
                'avg_30d_with_vat' => $avg30dWithVat,
                'percent_diff' => null,
                'hours_above_avg' => 0,
                'hours_below_avg' => 0,
                'total_hours' => 0,
            ];
        }

        // Calculate today's average with VAT
        $pricesWithVat = array_map(fn($p) => $p['price_with_tax'], $todayPrices);
        $todayAvgWithVat = array_sum($pricesWithVat) / count($pricesWithVat);

        // Calculate percentage difference
        $percentDiff = (($todayAvgWithVat - $avg30dWithVat) / $avg30dWithVat) * 100;

        // Determine verdict based on thresholds
        if ($percentDiff <= -10) {
            $verdict = 'cheap';
            $verdictLabel = 'Edullinen päivä';
        } elseif ($percentDiff >= 10) {
            $verdict = 'expensive';
            $verdictLabel = 'Kallis päivä';
        } else {
            $verdict = 'normal';
            $verdictLabel = 'Normaali päivä';
        }

        // Count hours above and below 30d average
        $hoursAbove = 0;
        $hoursBelow = 0;
        foreach ($pricesWithVat as $price) {
            if ($price > $avg30dWithVat) {
                $hoursAbove++;
            } else {
                $hoursBelow++;
            }
        }

        return [
            'verdict' => $verdict,
            'verdict_label' => $verdictLabel,
            'today_avg_with_vat' => round($todayAvgWithVat, 2),
            'avg_30d_with_vat' => round($avg30dWithVat, 2),
            'percent_diff' => round($percentDiff, 1),
            'hours_above_avg' => $hoursAbove,
            'hours_below_avg' => $hoursBelow,
            'total_hours' => count($todayPrices),
        ];
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
     * Only considers future hours (includes tomorrow if available).
     *
     * @param int $hoursNeeded Number of consecutive hours needed
     * @return array|null Returns null if not enough data
     */
    public function getBestConsecutiveHours(int $hoursNeeded): ?array
    {
        // Get future prices only (full day range, but filtered to future)
        $futurePrices = $this->getPricesInTimeRange(0, 24);

        if (count($futurePrices) < $hoursNeeded) {
            return null;
        }

        // Sort by date and hour to ensure consecutive check works across midnight
        $sortedPrices = array_values($futurePrices);
        usort($sortedPrices, function($a, $b) {
            $dateCompare = strcmp($a['helsinki_date'], $b['helsinki_date']);
            if ($dateCompare !== 0) return $dateCompare;
            return $a['helsinki_hour'] <=> $b['helsinki_hour'];
        });

        $bestWindow = null;
        $bestAverage = PHP_FLOAT_MAX;

        for ($i = 0; $i <= count($sortedPrices) - $hoursNeeded; $i++) {
            // Check if hours are actually consecutive (handles midnight crossing)
            $window = array_slice($sortedPrices, $i, $hoursNeeded);
            $isConsecutive = true;

            for ($j = 1; $j < count($window); $j++) {
                $prevHour = $window[$j - 1]['helsinki_hour'];
                $currHour = $window[$j]['helsinki_hour'];
                $prevDate = $window[$j - 1]['helsinki_date'];
                $currDate = $window[$j]['helsinki_date'];

                // Check if consecutive: either same day +1 hour, or midnight crossing (23->0 next day)
                $sameDayConsecutive = ($prevDate === $currDate && $currHour === $prevHour + 1);
                $midnightCrossing = ($prevDate !== $currDate && $prevHour === 23 && $currHour === 0);

                if (!$sameDayConsecutive && !$midnightCrossing) {
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

        // Calculate diff from 30-day average
        $priceWithVat = $bestAverage * 1.255;
        $diffFrom30dPercent = null;
        if ($this->rolling30DayAvgWithVat !== null && $this->rolling30DayAvgWithVat > 0) {
            $diffFrom30dPercent = round((($priceWithVat - $this->rolling30DayAvgWithVat) / $this->rolling30DayAvgWithVat) * 100, 1);
        }

        return [
            'start_hour' => $bestWindow[0]['helsinki_hour'],
            'end_hour' => $bestWindow[count($bestWindow) - 1]['helsinki_hour'],
            'start_date' => $bestWindow[0]['helsinki_date'],
            'end_date' => $bestWindow[count($bestWindow) - 1]['helsinki_date'],
            'average_price' => $bestAverage,
            'prices' => $bestWindow,
            'diff_from_30d_percent' => $diffFrom30dPercent,
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
     * Find the *next* (chronologically earliest) cheap hour from now on.
     *
     * "Cheap" = price with VAT at least 5 % below the rolling 30-day average,
     * matching the same threshold getPriceBadge() uses to label an hour
     * "Edullinen". Users acting on a "Seuraava halpa tunti" callout expect the
     * *soonest* good window, not the absolute cheapest hour over the next 24+ h
     * (which would often pick a slot in the middle of tomorrow night).
     *
     * Falls back to the chronologically earliest hour that is at least cheaper
     * than *now* if no hour clears the -5 % threshold. Returns null only if
     * there are no future hours at all.
     *
     * @return array|null Single hour record, or null if nothing useful exists
     */
    public function getNextCheapHour(): ?array
    {
        $helsinkiNow = Carbon::now(self::TIMEZONE);
        $currentHour = (int) $helsinkiNow->format('H');
        $todayDate = $helsinkiNow->format('Y-m-d');

        // Sort all hours chronologically and keep only future ones
        $futureHours = array_filter(
            $this->hourlyPrices,
            function ($price) use ($currentHour, $todayDate) {
                if ($price['helsinki_date'] === $todayDate) {
                    return $price['helsinki_hour'] > $currentHour;
                }
                return true;
            }
        );
        usort($futureHours, function ($a, $b) {
            $aKey = $a['helsinki_date'] . sprintf('%02d', $a['helsinki_hour']);
            $bKey = $b['helsinki_date'] . sprintf('%02d', $b['helsinki_hour']);
            return $aKey <=> $bKey;
        });

        if (empty($futureHours)) {
            return null;
        }

        // Primary: first hour at least 5 % below the 30-day average
        if ($this->rolling30DayAvgWithVat !== null && $this->rolling30DayAvgWithVat > 0) {
            $threshold = $this->rolling30DayAvgWithVat * 0.95;
            foreach ($futureHours as $hour) {
                if (($hour['price_with_tax'] ?? PHP_FLOAT_MAX) <= $threshold) {
                    return $hour;
                }
            }
        }

        // Fallback: first hour that's at least cheaper than the current price
        $currentPrice = $this->getCurrentPrice();
        if ($currentPrice !== null && isset($currentPrice['price_with_tax'])) {
            foreach ($futureHours as $hour) {
                if (($hour['price_with_tax'] ?? PHP_FLOAT_MAX) < $currentPrice['price_with_tax']) {
                    return $hour;
                }
            }
        }

        // Last resort: the very next hour, even if not cheap
        return $futureHours[0] ?? null;
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
        // Use future prices only (full day range, but filtered to future)
        $futurePrices = $this->getPricesInTimeRange(0, 24);

        if (count($futurePrices) < $hoursNeeded) {
            return null;
        }

        $prices = array_column($futurePrices, 'price_without_tax');
        sort($prices);

        // Get cheapest hours
        $cheapestPrices = array_slice($prices, 0, $hoursNeeded);
        $cheapestAverage = array_sum($cheapestPrices) / $hoursNeeded;

        // Get most expensive hours (for comparison)
        $expensivePrices = array_slice($prices, -$hoursNeeded);
        $expensiveAverage = array_sum($expensivePrices) / $hoursNeeded;

        // Calculate savings vs most expensive
        $totalKwh = $hoursNeeded * $kwhPerHour;
        $savingsPerKwh = $expensiveAverage - $cheapestAverage;
        $savingsCents = $savingsPerKwh * $totalKwh;
        $savingsPercent = ($expensiveAverage > 0)
            ? ($savingsPerKwh / $expensiveAverage) * 100
            : 0;

        return [
            'cheapest_average' => $cheapestAverage,
            'expensive_average' => $expensiveAverage,
            'savings_per_kwh' => $savingsPerKwh,
            'savings_cents' => $savingsCents,
            'savings_euros' => $savingsCents / 100,
            'savings_percent' => $savingsPercent,
            'total_kwh' => $totalKwh,
        ];
    }

    /**
     * Calculate sauna heating cost comparison for evening hours (17:00-22:00).
     *
     * Uses 8 kW as default sauna heater power for 1 hour of heating.
     * Sauna is typically heated in the evening, so we constrain to 17:00-22:00.
     *
     * @param float $kw Sauna heater power in kW (default 8 kW)
     * @return array|null Sauna cost data or null if not enough data
     */
    public function calculateSaunaCost(float $kw = 8.0): ?array
    {
        // Evening sauna hours: 17:00-22:00 (hours 17, 18, 19, 20, 21)
        $cheapestResult = $this->findCheapestHoursInRange(17, 22, 1);
        $mostExpensiveHour = $this->findMostExpensiveHourInRange(17, 22);

        if ($cheapestResult === null || $mostExpensiveHour === null) {
            return null;
        }

        $cheapestHour = $cheapestResult['prices'][0];

        // Calculate cost for 1 hour of heating at given kW
        // Price is in c/kWh, so cost = price * kWh
        $cheapestCost = $cheapestHour['price_without_tax'] * $kw;
        $expensiveCost = $mostExpensiveHour['price_without_tax'] * $kw;
        $costDifference = $expensiveCost - $cheapestCost;

        // Calculate diff from 30-day average
        $priceWithVat = $cheapestHour['price_without_tax'] * 1.255;
        $diffFrom30dPercent = null;
        if ($this->rolling30DayAvgWithVat !== null && $this->rolling30DayAvgWithVat > 0) {
            $diffFrom30dPercent = round((($priceWithVat - $this->rolling30DayAvgWithVat) / $this->rolling30DayAvgWithVat) * 100, 1);
        }

        return [
            'cheapest_hour' => $cheapestHour['helsinki_hour'],
            'start_date' => $cheapestHour['helsinki_date'],
            'end_date' => $cheapestHour['helsinki_date'],
            'cheapest_price' => $cheapestHour['price_without_tax'],
            'expensive_hour' => $mostExpensiveHour['helsinki_hour'],
            'expensive_price' => $mostExpensiveHour['price_without_tax'],
            'cheapest_cost' => $cheapestCost,
            'expensive_cost' => $expensiveCost,
            'cost_difference' => $costDifference,
            'cost_difference_euros' => $costDifference / 100,
            'kw' => $kw,
            'time_window' => '17:00-22:00',
            'diff_from_30d_percent' => $diffFrom30dPercent,
        ];
    }

    /**
     * Calculate washing machine/laundry cost comparison.
     *
     * Constraints: reasonable hours 07:00-22:00 (due to noise), typical cycle 2 hours, power ~2 kW average.
     *
     * @param float $kw Average power consumption in kW (default 2 kW)
     * @param int $duration Cycle duration in hours (default 2)
     * @return array|null Laundry cost data or null if not enough data
     */
    public function calculateLaundryCost(float $kw = 2.0, int $duration = 2): ?array
    {
        // Daytime hours for noise consideration: 07:00-22:00 (hours 7-21)
        $cheapestResult = $this->findCheapestHoursInRange(7, 22, $duration);

        if ($cheapestResult === null) {
            return null;
        }

        // Find most expensive consecutive hours in the same range for comparison
        $mostExpensiveResult = $this->findMostExpensiveConsecutiveHoursInRange(7, 22, $duration);

        // Calculate costs
        $totalKwh = $kw * $duration;
        $cheapestCost = $cheapestResult['average_price'] * $totalKwh;
        $expensiveCost = $mostExpensiveResult ? $mostExpensiveResult['average_price'] * $totalKwh : null;
        $costDifference = $expensiveCost !== null ? $expensiveCost - $cheapestCost : null;

        // Calculate diff from 30-day average
        $priceWithVat = $cheapestResult['average_price'] * 1.255;
        $diffFrom30dPercent = null;
        if ($this->rolling30DayAvgWithVat !== null && $this->rolling30DayAvgWithVat > 0) {
            $diffFrom30dPercent = round((($priceWithVat - $this->rolling30DayAvgWithVat) / $this->rolling30DayAvgWithVat) * 100, 1);
        }

        return [
            'start_hour' => $cheapestResult['start_hour'],
            'end_hour' => ($cheapestResult['end_hour'] + 1) % 24,
            'start_date' => $cheapestResult['start_date'] ?? null,
            'end_date' => $cheapestResult['end_date'] ?? null,
            'average_price' => $cheapestResult['average_price'],
            'cheapest_cost' => $cheapestCost,
            'expensive_cost' => $expensiveCost,
            'cost_difference' => $costDifference,
            'cost_difference_euros' => $costDifference !== null ? $costDifference / 100 : null,
            'kw' => $kw,
            'duration' => $duration,
            'total_kwh' => $totalKwh,
            'time_window' => '07:00-22:00',
            'diff_from_30d_percent' => $diffFrom30dPercent,
        ];
    }

    /**
     * Calculate dishwasher cost comparison.
     *
     * Constraints: can delay start until next morning (18:00-08:00), typical cycle 2 hours, power ~1.5 kW average.
     * Many dishwashers have a delay start feature, making overnight operation practical.
     *
     * @param float $kw Average power consumption in kW (default 1.5 kW)
     * @param int $duration Cycle duration in hours (default 2)
     * @return array|null Dishwasher cost data or null if not enough data
     */
    public function calculateDishwasherCost(float $kw = 1.5, int $duration = 2): ?array
    {
        // Evening to morning hours for delayed start: 18:00-08:00 (crosses midnight)
        $cheapestResult = $this->findCheapestHoursInRange(18, 8, $duration);

        if ($cheapestResult === null) {
            return null;
        }

        // Find most expensive consecutive hours in the same range for comparison
        $mostExpensiveResult = $this->findMostExpensiveConsecutiveHoursInRange(18, 8, $duration);

        // Calculate costs
        $totalKwh = $kw * $duration;
        $cheapestCost = $cheapestResult['average_price'] * $totalKwh;
        $expensiveCost = $mostExpensiveResult ? $mostExpensiveResult['average_price'] * $totalKwh : null;
        $costDifference = $expensiveCost !== null ? $expensiveCost - $cheapestCost : null;

        // Calculate diff from 30-day average
        $priceWithVat = $cheapestResult['average_price'] * 1.255;
        $diffFrom30dPercent = null;
        if ($this->rolling30DayAvgWithVat !== null && $this->rolling30DayAvgWithVat > 0) {
            $diffFrom30dPercent = round((($priceWithVat - $this->rolling30DayAvgWithVat) / $this->rolling30DayAvgWithVat) * 100, 1);
        }

        return [
            'start_hour' => $cheapestResult['start_hour'],
            'end_hour' => ($cheapestResult['end_hour'] + 1) % 24,
            'start_date' => $cheapestResult['start_date'] ?? null,
            'end_date' => $cheapestResult['end_date'] ?? null,
            'average_price' => $cheapestResult['average_price'],
            'cheapest_cost' => $cheapestCost,
            'expensive_cost' => $expensiveCost,
            'cost_difference' => $costDifference,
            'cost_difference_euros' => $costDifference !== null ? $costDifference / 100 : null,
            'kw' => $kw,
            'duration' => $duration,
            'total_kwh' => $totalKwh,
            'time_window' => '18:00-08:00',
            'diff_from_30d_percent' => $diffFrom30dPercent,
        ];
    }

    /**
     * Calculate water heater boost cost comparison.
     *
     * Constraints: can run anytime (no noise concerns), typical boost 1-2 hours, power ~2.5 kW.
     * Good for homes with timer-controlled water heaters that can schedule heating.
     *
     * @param float $kw Average power consumption in kW (default 2.5 kW)
     * @param int $duration Boost duration in hours (default 1)
     * @return array|null Water heater cost data or null if not enough data
     */
    public function calculateWaterHeaterCost(float $kw = 2.5, int $duration = 1): ?array
    {
        // Water heater can run anytime (0-24), find absolute cheapest hour(s)
        $cheapestResult = $this->findCheapestHoursInRange(0, 24, $duration);

        if ($cheapestResult === null) {
            return null;
        }

        // Find most expensive hours for comparison (full day)
        $mostExpensiveResult = $this->findMostExpensiveConsecutiveHoursInRange(0, 24, $duration);

        // Calculate costs
        $totalKwh = $kw * $duration;
        $cheapestCost = $cheapestResult['average_price'] * $totalKwh;
        $expensiveCost = $mostExpensiveResult ? $mostExpensiveResult['average_price'] * $totalKwh : null;
        $costDifference = $expensiveCost !== null ? $expensiveCost - $cheapestCost : null;

        // Calculate diff from 30-day average
        $priceWithVat = $cheapestResult['average_price'] * 1.255;
        $diffFrom30dPercent = null;
        if ($this->rolling30DayAvgWithVat !== null && $this->rolling30DayAvgWithVat > 0) {
            $diffFrom30dPercent = round((($priceWithVat - $this->rolling30DayAvgWithVat) / $this->rolling30DayAvgWithVat) * 100, 1);
        }

        return [
            'start_hour' => $cheapestResult['start_hour'],
            'end_hour' => ($cheapestResult['end_hour'] + 1) % 24,
            'start_date' => $cheapestResult['start_date'] ?? null,
            'end_date' => $cheapestResult['end_date'] ?? null,
            'average_price' => $cheapestResult['average_price'],
            'cheapest_cost' => $cheapestCost,
            'expensive_cost' => $expensiveCost,
            'cost_difference' => $costDifference,
            'cost_difference_euros' => $costDifference !== null ? $costDifference / 100 : null,
            'kw' => $kw,
            'duration' => $duration,
            'total_kwh' => $totalKwh,
            'time_window' => 'Koko päivä',
            'diff_from_30d_percent' => $diffFrom30dPercent,
        ];
    }

    /**
     * Find the most expensive consecutive hours within a specified time range.
     *
     * @param int $startHour Start of the time window (0-23)
     * @param int $endHour End of the time window (0-23)
     * @param int $duration Number of consecutive hours needed
     * @return array|null Returns array with start_hour, average_price, or null if not enough data
     */
    private function findMostExpensiveConsecutiveHoursInRange(int $startHour, int $endHour, int $duration): ?array
    {
        $pricesInRange = $this->getPricesInTimeRange($startHour, $endHour);

        if (count($pricesInRange) < $duration) {
            return null;
        }

        // Sort by date and hour to ensure consecutive check works across midnight
        $pricesInRange = array_values($pricesInRange);
        usort($pricesInRange, function($a, $b) {
            $dateCompare = strcmp($a['helsinki_date'], $b['helsinki_date']);
            if ($dateCompare !== 0) return $dateCompare;
            return $a['helsinki_hour'] <=> $b['helsinki_hour'];
        });

        $worstWindow = null;
        $worstAverage = PHP_FLOAT_MIN;

        for ($i = 0; $i <= count($pricesInRange) - $duration; $i++) {
            $window = array_slice($pricesInRange, $i, $duration);

            // Check if hours are consecutive (handles midnight crossing with dates)
            $isConsecutive = true;
            for ($j = 1; $j < count($window); $j++) {
                $prevHour = $window[$j - 1]['helsinki_hour'];
                $currHour = $window[$j]['helsinki_hour'];
                $prevDate = $window[$j - 1]['helsinki_date'];
                $currDate = $window[$j]['helsinki_date'];

                $sameDayConsecutive = ($prevDate === $currDate && $currHour === $prevHour + 1);
                $midnightCrossing = ($prevDate !== $currDate && $prevHour === 23 && $currHour === 0);

                if (!$sameDayConsecutive && !$midnightCrossing) {
                    $isConsecutive = false;
                    break;
                }
            }

            if (!$isConsecutive) {
                continue;
            }

            $sum = array_sum(array_column($window, 'price_without_tax'));
            $average = $sum / $duration;

            if ($average > $worstAverage) {
                $worstAverage = $average;
                $worstWindow = $window;
            }
        }

        if ($worstWindow === null) {
            return null;
        }

        return [
            'start_hour' => $worstWindow[0]['helsinki_hour'],
            'end_hour' => $worstWindow[count($worstWindow) - 1]['helsinki_hour'],
            'average_price' => $worstAverage,
            'prices' => $worstWindow,
        ];
    }

    /**
     * Find the cheapest consecutive hours within a specified time range.
     *
     * This method handles time ranges that cross midnight (e.g., 22:00-06:00).
     *
     * @param int $startHour Start of the time window (0-23)
     * @param int $endHour End of the time window (0-23)
     * @param int $duration Number of consecutive hours needed (default 1)
     * @return array|null Returns array with start_hour, average_price, prices, or null if not enough data
     */
    public function findCheapestHoursInRange(int $startHour, int $endHour, int $duration = 1): ?array
    {
        $pricesInRange = $this->getPricesInTimeRange($startHour, $endHour);

        if (count($pricesInRange) < $duration) {
            return null;
        }

        // Sort by date and hour to ensure consecutive check works across midnight
        $pricesInRange = array_values($pricesInRange);
        usort($pricesInRange, function($a, $b) {
            $dateCompare = strcmp($a['helsinki_date'], $b['helsinki_date']);
            if ($dateCompare !== 0) return $dateCompare;
            return $a['helsinki_hour'] <=> $b['helsinki_hour'];
        });

        if ($duration === 1) {
            // Simple case: find single cheapest hour
            $cheapest = null;
            foreach ($pricesInRange as $price) {
                if ($cheapest === null || $price['price_without_tax'] < $cheapest['price_without_tax']) {
                    $cheapest = $price;
                }
            }
            return $cheapest ? [
                'start_hour' => $cheapest['helsinki_hour'],
                'end_hour' => $cheapest['helsinki_hour'],
                'start_date' => $cheapest['helsinki_date'],
                'end_date' => $cheapest['helsinki_date'],
                'average_price' => $cheapest['price_without_tax'],
                'prices' => [$cheapest],
            ] : null;
        }

        // Find best consecutive hours within the range
        $bestWindow = null;
        $bestAverage = PHP_FLOAT_MAX;

        for ($i = 0; $i <= count($pricesInRange) - $duration; $i++) {
            $window = array_slice($pricesInRange, $i, $duration);

            // Check if hours are consecutive (handles midnight crossing with dates)
            $isConsecutive = true;
            for ($j = 1; $j < count($window); $j++) {
                $prevHour = $window[$j - 1]['helsinki_hour'];
                $currHour = $window[$j]['helsinki_hour'];
                $prevDate = $window[$j - 1]['helsinki_date'];
                $currDate = $window[$j]['helsinki_date'];

                $sameDayConsecutive = ($prevDate === $currDate && $currHour === $prevHour + 1);
                $midnightCrossing = ($prevDate !== $currDate && $prevHour === 23 && $currHour === 0);

                if (!$sameDayConsecutive && !$midnightCrossing) {
                    $isConsecutive = false;
                    break;
                }
            }

            if (!$isConsecutive) {
                continue;
            }

            $sum = array_sum(array_column($window, 'price_without_tax'));
            $average = $sum / $duration;

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
            'start_date' => $bestWindow[0]['helsinki_date'],
            'end_date' => $bestWindow[count($bestWindow) - 1]['helsinki_date'],
            'average_price' => $bestAverage,
            'prices' => $bestWindow,
        ];
    }

    /**
     * Find the most expensive hour within a specified time range.
     *
     * @param int $startHour Start of the time window (0-23)
     * @param int $endHour End of the time window (0-23)
     * @return array|null Returns the price array for the most expensive hour, or null if no data
     */
    public function findMostExpensiveHourInRange(int $startHour, int $endHour): ?array
    {
        $pricesInRange = $this->getPricesInTimeRange($startHour, $endHour);

        if (empty($pricesInRange)) {
            return null;
        }

        $mostExpensive = null;
        foreach ($pricesInRange as $price) {
            if ($mostExpensive === null || $price['price_without_tax'] > $mostExpensive['price_without_tax']) {
                $mostExpensive = $price;
            }
        }

        return $mostExpensive;
    }

    /**
     * Get future prices filtered to a specific time range.
     *
     * Handles time ranges that cross midnight (e.g., 22:00-06:00 includes hours 22, 23, 0, 1, 2, 3, 4, 5).
     * Only includes fully future hours (excludes the current and past hours from today).
     * Includes tomorrow's prices if available.
     *
     * @param int $startHour Start of the time window (0-23)
     * @param int $endHour End of the time window (0-23)
     * @return array Array of price data within the specified time range
     */
    private function getPricesInTimeRange(int $startHour, int $endHour): array
    {
        // Use all hourly prices (today + tomorrow) not just today
        if (empty($this->hourlyPrices)) {
            return [];
        }

        $helsinkiNow = Carbon::now(self::TIMEZONE);
        $currentHour = (int) $helsinkiNow->format('H');
        $todayDate = $helsinkiNow->format('Y-m-d');

        $crossesMidnight = $startHour > $endHour;

        return array_filter($this->hourlyPrices, function ($price) use ($startHour, $endHour, $crossesMidnight, $currentHour, $todayDate) {
            $hour = $price['helsinki_hour'];
            $priceDate = $price['helsinki_date'];

            // Skip the current and past hours from today. Appliance cards should only
            // recommend fully upcoming slots; at 17:45, 17:00 is no longer actionable.
            if ($priceDate === $todayDate && $hour <= $currentHour) {
                return false;
            }

            // Check if hour is within the time range
            if ($crossesMidnight) {
                // Range like 22:00-06:00 means hours 22, 23, 0, 1, 2, 3, 4, 5
                return $hour >= $startHour || $hour <= $endHour;
            } else {
                // Range like 07:00-22:00 means hours 7, 8, ..., 21
                return $hour >= $startHour && $hour < $endHour;
            }
        });
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
        $historicalData = Cache::remember('spot_price:historical:v2', 3600, function () {
            $monthlyDailyAverages = $this->getMonthlyDailyAverages(30);

            return [
                'weeklyDailyAverages' => $monthlyDailyAverages,
                'weeklyChartData' => $this->buildTrendChartData($monthlyDailyAverages),
                'monthlyComparison' => $this->getMonthlyComparison(),
                'yearOverYearComparison' => $this->getYearOverYearComparison(),
                'multiYearMonthly' => $this->getMultiYearMonthlyComparison(5),
            ];
        });

        $this->weeklyDailyAverages = $historicalData['weeklyDailyAverages'];
        $this->weeklyChartData = $historicalData['weeklyChartData'];
        $this->monthlyComparison = $historicalData['monthlyComparison'];
        $this->yearOverYearComparison = $historicalData['yearOverYearComparison'];
        $this->multiYearMonthly = $historicalData['multiYearMonthly'];
        $this->historicalDataLoaded = true;
    }

    /**
     * Daily averages (min/avg/max) for the past N days, excluding today.
     *
     * @return array<int, array{date: string, average: float, min: float, max: float}>
     */
    public function getMonthlyDailyAverages(int $days = 30): array
    {
        $helsinkiNow = Carbon::now(self::TIMEZONE);
        $start = $helsinkiNow->copy()->subDays($days)->startOfDay()->setTimezone('UTC');
        $end = $helsinkiNow->copy()->subDay()->endOfDay()->setTimezone('UTC');

        $prices = SpotPriceHour::forRegion(self::REGION)
            ->whereBetween('utc_datetime', [$start, $end])
            ->orderBy('utc_datetime')
            ->get();

        if ($prices->isEmpty()) {
            return [];
        }

        $byDate = [];
        foreach ($prices as $price) {
            $date = Carbon::parse($price->utc_datetime)
                ->shiftTimezone('UTC')
                ->setTimezone(self::TIMEZONE)
                ->format('Y-m-d');

            $byDate[$date] ??= ['prices' => [], 'min' => PHP_FLOAT_MAX, 'max' => -PHP_FLOAT_MAX];
            $byDate[$date]['prices'][] = $price->price_without_tax;
            $byDate[$date]['min'] = min($byDate[$date]['min'], $price->price_without_tax);
            $byDate[$date]['max'] = max($byDate[$date]['max'], $price->price_without_tax);
        }

        ksort($byDate);

        $result = [];
        foreach ($byDate as $date => $data) {
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
     * Average price for the current calendar month across the last N years.
     *
     * Reads pre-computed monthly aggregates from `spot_price_averages` when
     * present; falls back silently when older years aren't backfilled so the
     * chart degrades gracefully to whatever years we actually have data for.
     *
     * Prices are returned with the current 25.5 % VAT applied so they're
     * directly comparable to the c/kWh values rendered elsewhere on the page.
     *
     * @return array<int, array{year: int, label: string, average_with_vat: ?float, has_data: bool, is_current: bool}>
     */
    public function getMultiYearMonthlyComparison(int $years = 5): array
    {
        $helsinkiNow = Carbon::now(self::TIMEZONE);
        $currentMonth = $helsinkiNow->month;
        $currentYear = $helsinkiNow->year;

        $result = [];
        for ($i = $years - 1; $i >= 0; $i--) {
            $year = $currentYear - $i;

            $row = SpotPriceAverage::forRegion(self::REGION)
                ->where('period_type', 'monthly')
                ->whereYear('period_end', $year)
                ->whereMonth('period_end', $currentMonth)
                ->first();

            $avgWithVat = $row?->avg_price_with_tax !== null ? round($row->avg_price_with_tax, 2) : null;

            $result[] = [
                'year' => $year,
                'label' => (string) $year,
                'average_with_vat' => $avgWithVat,
                'has_data' => $avgWithVat !== null,
                'is_current' => $year === $currentYear,
            ];
        }

        return $result;
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
        return $this->buildTrendChartData($this->weeklyDailyAverages ?: $this->getMonthlyDailyAverages(30));
    }

    /**
     * Build line-chart data for the recent daily-average trend.
     *
     * The dataset is in c/kWh ex VAT. A 30-day rolling average baseline (with VAT
     * stripped to stay comparable) is included so the line chart visually answers
     * "where does today sit vs. the recent norm" without a second axis.
     */
    private function buildTrendChartData(array $trendData): array
    {
        if (empty($trendData)) {
            return [
                'labels' => [],
                'datasets' => [
                    [
                        'label' => 'Keskihinta (c/kWh)',
                        'data' => [],
                        'borderColor' => 'rgb(15, 23, 42)',
                        'backgroundColor' => 'rgba(15, 23, 42, 0.05)',
                        'tension' => 0.3,
                    ],
                ],
            ];
        }

        $labels = [];
        $averages = [];
        $mins = [];
        $maxs = [];

        foreach ($trendData as $day) {
            $date = Carbon::parse($day['date']);
            $labels[] = $date->format('d.m.');
            $averages[] = round($day['average'], 2);
            $mins[] = round($day['min'], 2);
            $maxs[] = round($day['max'], 2);
        }

        $datasets = [
            [
                'label' => 'Päivän alin (c/kWh)',
                'data' => $mins,
                'borderColor' => 'rgba(148, 163, 184, 0.0)',
                'backgroundColor' => 'rgba(249, 115, 22, 0.08)',
                'tension' => 0.3,
                'pointRadius' => 0,
                'fill' => '+1',
            ],
            [
                'label' => 'Päivän ylin (c/kWh)',
                'data' => $maxs,
                'borderColor' => 'rgba(148, 163, 184, 0.0)',
                'backgroundColor' => 'rgba(249, 115, 22, 0.08)',
                'tension' => 0.3,
                'pointRadius' => 0,
                'fill' => false,
            ],
            [
                'label' => 'Päivän keskihinta (c/kWh)',
                'data' => $averages,
                'borderColor' => 'rgb(15, 23, 42)',
                'backgroundColor' => 'rgb(15, 23, 42)',
                'borderWidth' => 2,
                'tension' => 0.3,
                'fill' => false,
            ],
        ];

        if ($this->rolling30DayAvgWithVat !== null) {
            $avgExVat = round($this->rolling30DayAvgWithVat / 1.255, 2);
            $datasets[] = [
                'label' => '30 pv keskiarvo (c/kWh)',
                'data' => array_fill(0, count($labels), $avgExVat),
                'borderColor' => 'rgba(100, 116, 139, 0.7)',
                'backgroundColor' => 'rgba(100, 116, 139, 0.0)',
                'borderDash' => [4, 4],
                'borderWidth' => 1.5,
                'pointRadius' => 0,
                'tension' => 0,
                'fill' => false,
            ];
        }

        return [
            'labels' => $labels,
            'datasets' => $datasets,
        ];
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

    /**
     * Get 15-minute prices for a specific hour.
     *
     * @param int $timestamp Unix timestamp for the hour
     * @return array Array of 15-minute price data for the hour
     */
    public function getQuarterPricesForHour(int $timestamp): array
    {
        $hourStart = Carbon::createFromTimestamp($timestamp, 'UTC');
        $hourEnd = $hourStart->copy()->addHour();

        $prices = SpotPriceQuarter::forRegion(self::REGION)
            ->where('utc_datetime', '>=', $hourStart)
            ->where('utc_datetime', '<', $hourEnd)
            ->orderBy('utc_datetime')
            ->get();

        return $prices->map(function (SpotPriceQuarter $price) {
            $utcTime = Carbon::parse($price->utc_datetime)->shiftTimezone('UTC');
            $helsinkiTime = $utcTime->copy()->setTimezone(self::TIMEZONE);

            $minute = (int) $helsinkiTime->format('i');
            $nextMinute = $minute + 15;

            return [
                'timestamp' => $price->timestamp,
                'price_without_tax' => $price->price_without_tax,
                'price_with_tax' => round($price->price_with_tax, 2),
                'vat_rate' => $price->vat_rate,
                'helsinki_minute' => $minute,
                'time_label' => sprintf(
                    '%s:%02d-%s:%02d',
                    $helsinkiTime->format('H'),
                    $minute,
                    $nextMinute === 60 ? $helsinkiTime->copy()->addHour()->format('H') : $helsinkiTime->format('H'),
                    $nextMinute === 60 ? 0 : $nextMinute
                ),
            ];
        })->toArray();
    }

    /**
     * Dataset JSON-LD schema. Mirrors the pattern used on the contract
     * price statistics and fixed-term forecast pages so search engines see
     * /spot-price as a labelled, machine-readable price + forecast dataset.
     *
     * @return array<string, mixed>
     */
    private function buildJsonLd(): array
    {
        $today = Carbon::today(self::TIMEZONE);

        $earliestStored = Cache::remember(
            'spot_price:earliest_hour:v1',
            86400,
            fn () => SpotPriceHour::forRegion(self::REGION)->min('utc_datetime'),
        );
        $earliestDate = $earliestStored
            ? Carbon::parse($earliestStored)->setTimezone(self::TIMEZONE)->toDateString()
            : null;

        $latestForecastTimestamp = collect($this->forecastPrices)->pluck('utc_datetime')->max();
        $latestDate = $latestForecastTimestamp
            ? Carbon::parse($latestForecastTimestamp)->setTimezone(self::TIMEZONE)->toDateString()
            : $today->copy()->addDay()->toDateString();

        return [
            '@context' => 'https://schema.org',
            '@type' => 'Dataset',
            'name' => 'Voltikka — Pörssisähkön hinta ja hintaennuste',
            'description' => 'Pörssisähkön tuntihinnat tänään ja huomenna sekä mallipohjainen hintaennuste seuraaville päiville Suomen hinta-alueella. Päivittyy useita kertoja päivässä.',
            'url' => config('app.url') . '/spot-price',
            'license' => 'https://creativecommons.org/licenses/by/4.0/',
            'isAccessibleForFree' => true,
            'keywords' => [
                'pörssisähkön hinta',
                'pörssisähkön hinta tänään',
                'pörssisähkön hinta huomenna',
                'pörssisähkön hintaennuste',
                'pörssisähkö ennuste',
                'spot-hinta',
                'Nord Pool',
                'Suomi',
            ],
            'inLanguage' => 'fi',
            'creator' => [
                '@type' => 'Organization',
                'name' => 'Voltikka',
                'url' => config('app.url'),
            ],
            'temporalCoverage' => $earliestDate ? "{$earliestDate}/{$latestDate}" : null,
            'dateModified' => $today->toDateString(),
            'variableMeasured' => [
                'Pörssisähkön tuntihinta c/kWh (ALV 0 %)',
                'Pörssisähkön tuntihinta c/kWh (sis. ALV 25,5 %)',
                'Pörssisähkön ennustettu tuntihinta c/kWh',
            ],
            'distribution' => [
                [
                    '@type' => 'DataDownload',
                    'encodingFormat' => 'text/csv',
                    'contentUrl' => config('app.url') . '/spot-price.csv',
                    'name' => 'Pörssisähkön tuntihinnat (CSV)',
                ],
            ],
            'isBasedOn' => [
                [
                    '@type' => 'CreativeWork',
                    'name' => 'Nord Pool day-ahead prices (via ENTSO-E)',
                    'url' => 'https://transparency.entsoe.eu/',
                ],
                [
                    '@type' => 'CreativeWork',
                    'name' => 'nordpool-predict-fi',
                    'url' => 'https://github.com/vividfog/nordpool-predict-fi',
                ],
            ],
        ];
    }

    /**
     * FAQPage JSON-LD built from the visible FAQ block so the structured data
     * mirrors what users see.
     *
     * @return array<string, mixed>
     */
    private function buildFaqJsonLd(): array
    {
        return [
            '@context' => 'https://schema.org',
            '@type' => 'FAQPage',
            'mainEntity' => array_map(fn (array $faq): array => [
                '@type' => 'Question',
                'name' => $faq['question'],
                'acceptedAnswer' => [
                    '@type' => 'Answer',
                    'text' => $faq['answer'],
                ],
            ], $this->faqItems),
        ];
    }

    /**
     * FAQ items shown at the bottom of the page and emitted as FAQPage JSON-LD.
     * Each answer is sourced from data that is also visible on the page (today's
     * stats, tomorrow's availability, ENTSO-E publishing schedule, the third-party
     * forecast methodology) so the structured data stays in sync with the UI.
     *
     * @return array<int, array{question: string, answer: string}>
     */
    public function getFaqItemsProperty(): array
    {
        return [
            [
                'question' => 'Mikä on pörssisähkö ja miten hinta muodostuu?',
                'answer' => "Tällä sivulla esitetyt hintatiedot ovat Pohjoismaiden ja Baltian maiden sähköpörssi Nord Poolin määrittämiä sähkön spot-hintoja. Kaupankäynnissä jokaisella päivän tunnilla on aina oma hintansa.\n\nHinnan määräytyminen Pohjoismaissa perustuu energialähteiden (vesivoima, tuulivoima, ydinvoima ja voimapolttoaineet: hiili, öljy, maakaasu) tuotantoon neljällä markkina-alueella (Suomi, Norja, Ruotsi, Tanska) sekä niihin liittyvien päästöoikeuksien (päästökauppa) sääntelyyn, sähkönkulutukseen ja markkinapsykologiaan.",
            ],
            [
                'question' => 'Mistä pörssisähkön hinta koostuu sähkölaskullasi?',
                'answer' => 'Tällä sivulla näkyvä hinta on Nord Poolin Suomen aluehinta (spot-hinta) lisättynä arvonlisäverolla (25,5 %). Sähkölaskuusi tulee tämän lisäksi sähkönsiirto (~3–5 c/kWh), sähkövero ja sähkösopimuksesi marginaali (~0,3–0,5 c/kWh pörssisähkösopimuksissa). Voltikan sopimusvertailu näyttää eri sopimusten kokonaiskustannuksen sinun kulutuksellesi laskettuna.',
            ],
            [
                'question' => 'Milloin huomisen pörssisähkön hinta julkaistaan?',
                'answer' => 'Huomisen viralliset päivähinnat julkaistaan Nord Poolissa päivittäin noin klo 14:15 Suomen aikaa. Ennen julkaisua tällä sivulla näkyvä huomisen tuntihinta on mallipohjainen ennuste, joka korvautuu toteutuneella spot-hinnalla heti kun viralliset hinnat ovat saatavilla.',
            ],
            [
                'question' => 'Miten pörssisähkön hintaennuste lasketaan?',
                'answer' => "Pörssisähkön hintaennuste tuotetaan koneoppimismallilla (XGBoost), joka on opetettu Nord Poolin Suomen aluehinnan historialla. Malli arvioi jokaisen tunnin hinnan erikseen useiden samanaikaisten muuttujien perusteella sen sijaan, että se ennustaisi pelkästään aiempien hintojen jatkumona.\n\nTärkeimmät syötteet: tuuli- ja sääennusteet (Ilmatieteen laitos), ydinvoiman käytettävyys ja huoltoseisokit, siirtoyhteyksien kapasiteetti Ruotsiin ja Viroon, aurinkosäteily, hydrologia (sade ja lumen vesiarvo) sekä kalenterimuuttujat (viikonpäivä ja tunti).\n\nEnnuste ulottuu noin 3–7 vuorokautta eteenpäin ja päivittyy useita kertoja päivässä. Lähde: nordpool-predict-fi (MIT-lisenssi).",
            ],
            [
                'question' => 'Onko ennuste sama kuin virallinen pörssisähkön hinta?',
                'answer' => 'Ei. Ennuste on suuntaa-antava mallin tuottama arvio, joka voi poiketa toteutuneesta spot-hinnasta. Käytä ennustetta apuna sähkönkäytön ajoituksessa (esim. saunan tai auton lataus), mutta laskutus perustuu aina Nord Poolin viralliseen aluehintaan.',
            ],
            [
                'question' => 'Mitä ALV-muutokset tarkoittavat hintahistoriassa?',
                'answer' => '1.9.2024 alkaen sähkön arvonlisävero on 25,5 %. Hinnat ajalta 1.12.2022–30.4.2023 sisältävät ALV:n 10 % (väliaikainen alennus). Hinnat ajalta 1.5.2023–31.8.2024 sisältävät ALV:n 24 %.',
            ],
        ];
    }

    public function render()
    {
        $this->enableBackButtonCache();

        $viewData = [
            'currentPrice' => $this->getCurrentPrice(),
            'todayMinMax' => $this->getTodayMinMax(),
            'cheapestHour' => $this->getCheapestHour(),
            'mostExpensiveHour' => $this->getMostExpensiveHour(),
            'todayStatistics' => $this->getTodayStatistics(),
            'todayVerdict' => $this->getTodayVerdict(),
            // Analytics data
            'priceVolatility' => $this->getPriceVolatility(),
            'historicalComparison' => $this->getHistoricalComparison(),
            'cheapestRemainingHours' => $this->getCheapestRemainingHours(5),
            'nextCheapHour' => $this->getNextCheapHour(),
            'bestConsecutiveHours' => $this->getBestConsecutiveHours(3),
            'potentialSavings' => $this->calculatePotentialSavings(3, 3.7), // 3 hours at 3.7 kW (typical EV charging)
            // Household energy tips
            'saunaCost' => $this->calculateSaunaCost(),           // 1 hour at 8 kW (evening 17-22)
            'laundryCost' => $this->calculateLaundryCost(),       // 2 hours at 2 kW (daytime 07-22)
            'dishwasherCost' => $this->calculateDishwasherCost(), // 2 hours at 1.5 kW (evening/night 18-08)
            'waterHeaterCost' => $this->calculateWaterHeaterCost(), // 1 hour at 2.5 kW (anytime)
            // Chart data
            'chartData' => $this->getChartData(),
            // Bar chart data
            'todayPricesWithMeta' => $this->getTodayPricesWithMeta(),
            'tomorrowPricesWithMeta' => $this->getTomorrowPricesWithMeta(),
            'hasTomorrowPrices' => $this->hasTomorrowPrices(),
            'forecastPricesGroupedByDate' => $this->getForecastPricesGroupedByDate(),
            'forecastSource' => $this->forecastSource,
            // Column-bar timeline (today + tomorrow + forecast days, unified)
            'dayStrips' => $this->getDayStripsForChart(),
        ];

        // Add historical data if loaded
        if ($this->historicalDataLoaded) {
            $viewData['weeklyDailyAverages'] = $this->weeklyDailyAverages;
            $viewData['weeklyChartData'] = $this->weeklyChartData;
            $viewData['monthlyComparison'] = $this->monthlyComparison;
            $viewData['yearOverYearComparison'] = $this->yearOverYearComparison;
            $viewData['multiYearMonthly'] = $this->multiYearMonthly;
        }

        $viewData['jsonLd'] = $this->buildJsonLd();
        $viewData['faqJsonLd'] = $this->buildFaqJsonLd();
        $viewData['methodologyReviewedAt'] = \Carbon\Carbon::parse(self::METHODOLOGY_REVIEWED_AT)->format('j.n.Y');

        return view('livewire.spot-price', $viewData)
            ->layout('layouts.app', [
                'title' => 'Pörssisähkön hinta tänään ja huomenna – hintaennuste | Voltikka',
                'metaDescription' => 'Pörssisähkön hinta tänään ja huomenna sekä hintaennuste seuraaville päiville. Näe tuntikohtaiset hinnat ja milloin sähkö on halvinta.',
            ])->response(function ($response) {
                $response->setPublic();
                $response->setMaxAge(60);
                $response->setSharedMaxAge(300);
                $response->setStaleWhileRevalidate(900);
                $response->headers->remove('Pragma');
                $response->headers->remove('Expires');
                $response->headers->set('Vary', 'Accept-Encoding', false);
            });
    }
}
