<?php

namespace App\Livewire;

use App\Models\Company;
use App\Models\ContractPriceDailyStatistic;
use App\Models\ElectricityContract;
use App\Models\SpotPriceHour;
use App\Models\SpotPriceQuarter;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Livewire\Component;

class HomePage extends Component
{
    private const REGION = 'FI';
    private const TIMEZONE = 'Europe/Helsinki';

    public function render()
    {
        return view('livewire.home-page', [
            'contractCount' => ElectricityContract::active()->count(),
            'companyCount' => Company::count(),
            'currentSpotPrice' => $this->getCurrentSpotPrice(),
            'todaysSpotPrices' => $this->getTodaysSpotPrices(),
            'contractPriceTrend' => $this->getContractPriceTrend(),
        ])->layout('layouts.app', [
            'title' => 'Voltikka – Suomen kattavin energiapalvelu',
            'metaDescription' => 'Vertaile sähkösopimuksia, seuraa pörssihintoja, laske aurinkopaneelien tuotto ja löydä paras lämpöpumppu. Kaikki energiapäätökset yhdessä paikassa.',
        ]);
    }

    /**
     * Get today's hourly spot prices (Helsinki time) with tier classification
     * and a flag for the current hour, for inline sparkline rendering.
     *
     * @return array<int, array{hour:int, price:float, tier:string, is_current:bool}>
     */
    private function getTodaysSpotPrices(): array
    {
        $helsinkiNow = Carbon::now(self::TIMEZONE);
        $todayStart = $helsinkiNow->copy()->startOfDay()->setTimezone('UTC');
        $todayEnd = $helsinkiNow->copy()->endOfDay()->setTimezone('UTC');
        $currentHour = (int) $helsinkiNow->format('H');

        return SpotPriceHour::forRegion(self::REGION)
            ->whereBetween('utc_datetime', [$todayStart, $todayEnd])
            ->orderBy('utc_datetime')
            ->get()
            ->map(function (SpotPriceHour $price) use ($currentHour) {
                $hour = (int) Carbon::parse($price->utc_datetime)
                    ->shiftTimezone('UTC')
                    ->setTimezone(self::TIMEZONE)
                    ->format('H');
                $rounded = round($price->price_with_tax, 2);
                $tier = $rounded < 10 ? 'low' : ($rounded < 20 ? 'medium' : 'high');
                return [
                    'hour' => $hour,
                    'price' => $rounded,
                    'tier' => $tier,
                    'is_current' => $hour === $currentHour,
                ];
            })
            ->values()
            ->toArray();
    }

    /**
     * Get the current spot price (15-minute or hourly fallback).
     */
    private function getCurrentSpotPrice(): ?array
    {
        $helsinkiNow = Carbon::now(self::TIMEZONE);

        // Try to get 15-minute price first
        $quarterPrice = $this->getCurrentQuarterPrice($helsinkiNow);
        if ($quarterPrice) {
            return $quarterPrice;
        }

        // Fall back to hourly price
        return $this->getCurrentHourlyPrice($helsinkiNow);
    }

    /**
     * Get the current 15-minute interval price.
     */
    private function getCurrentQuarterPrice(Carbon $helsinkiNow): ?array
    {
        $minute = (int) $helsinkiNow->format('i');
        $quarterMinute = (int) floor($minute / 15) * 15;
        $quarterStart = $helsinkiNow->copy()->minute($quarterMinute)->second(0)->setTimezone('UTC');
        $quarterEnd = $quarterStart->copy()->addMinutes(15);

        $price = SpotPriceQuarter::forRegion(self::REGION)
            ->where('utc_datetime', '>=', $quarterStart)
            ->where('utc_datetime', '<', $quarterEnd)
            ->first();

        if (!$price) {
            return null;
        }

        return [
            'price_with_tax' => round($price->price_with_tax, 2),
            'price_without_tax' => round($price->price_without_tax, 2),
        ];
    }

    /**
     * Get the current hourly price (fallback).
     */
    private function getCurrentHourlyPrice(Carbon $helsinkiNow): ?array
    {
        $currentHour = (int) $helsinkiNow->format('H');
        $todayStart = $helsinkiNow->copy()->startOfDay()->setTimezone('UTC');
        $todayEnd = $helsinkiNow->copy()->endOfDay()->setTimezone('UTC');

        $price = SpotPriceHour::forRegion(self::REGION)
            ->whereBetween('utc_datetime', [$todayStart, $todayEnd])
            ->get()
            ->first(function (SpotPriceHour $price) use ($currentHour) {
                $helsinkiTime = Carbon::parse($price->utc_datetime)
                    ->shiftTimezone('UTC')
                    ->setTimezone(self::TIMEZONE);
                return (int) $helsinkiTime->format('H') === $currentHour;
            });

        if (!$price) {
            return null;
        }

        return [
            'price_with_tax' => round($price->price_with_tax, 2),
            'price_without_tax' => round($price->price_without_tax, 2),
        ];
    }

    /**
     * Weekly energy-price (c/kWh) trend for the four primary segments shown on
     * the /sahkosopimus/tilastot lead chart: pörssi (lead), määräaikainen 12 kk,
     * toistaiseksi voimassa oleva, joustosähkö. Spot uses `spot_total_energy_price`
     * (stored daily spot avg + typical supplier margin), other segments use
     * `energy_price`. Daily medians are averaged per ISO week so the line stays
     * market-day weighted.
     *
     * Shaped for the shared uPlot mount in resources/js/contract-price-statistics.js;
     * `data-line-chart="spot"` on the container makes the lead series render in coral.
     *
     * Cached until tomorrow because the underlying statistics table refreshes
     * once per day after `contracts:calculate-price-statistics`.
     *
     * @return array{x: array<int,int>, series: array<int, array{label:string, values:array<int,?float>}>, caption: ?string}
     */
    private function getContractPriceTrend(): array
    {
        return Cache::remember('home-page:contract-price-trend:v4', Carbon::tomorrow(), function () {
            $segments = [
                'spot' => 'Pörssisähkö',
                'fixed_term_12' => 'Määräaikainen 12 kk',
                'open_ended' => 'Toistaiseksi voimassa oleva',
                'hybrid' => 'Joustosähkö',
            ];
            $start = Carbon::now()->subDays(180)->toDateString();

            $rows = ContractPriceDailyStatistic::query()
                ->whereIn('segment_key', array_keys($segments))
                ->whereIn('metric_key', ['energy_price', 'spot_total_energy_price'])
                ->whereNull('consumption_kwh')
                ->where('stat_date', '>=', $start)
                ->orderBy('stat_date')
                ->get(['stat_date', 'segment_key', 'metric_key', 'median_value']);

            $weeklyBySegment = [];
            foreach ($segments as $segmentKey => $label) {
                $metric = $segmentKey === 'spot' ? 'spot_total_energy_price' : 'energy_price';
                $byWeek = [];
                foreach ($rows as $row) {
                    if ($row->segment_key !== $segmentKey || $row->metric_key !== $metric || $row->median_value === null) {
                        continue;
                    }
                    $weekStart = Carbon::parse($row->stat_date)->startOfWeek(Carbon::MONDAY)->toDateString();
                    $byWeek[$weekStart][] = (float) $row->median_value;
                }
                ksort($byWeek);

                $weeklyBySegment[$segmentKey] = array_map(
                    static fn (array $vals) => round(array_sum($vals) / count($vals), 2),
                    $byWeek,
                );
            }

            $allWeeks = [];
            foreach ($weeklyBySegment as $weekly) {
                foreach (array_keys($weekly) as $weekStart) {
                    $allWeeks[$weekStart] = true;
                }
            }
            $allWeeks = array_keys($allWeeks);
            sort($allWeeks);

            if (count($allWeeks) < 2) {
                return ['x' => [], 'series' => []];
            }

            $series = [];
            foreach ($segments as $segmentKey => $label) {
                $values = [];
                foreach ($allWeeks as $weekStart) {
                    $values[] = $weeklyBySegment[$segmentKey][$weekStart] ?? null;
                }
                $series[] = ['label' => $label, 'values' => $values];
            }

            return [
                'x' => array_map(static fn ($w) => Carbon::parse($w)->getTimestamp(), $allWeeks),
                'series' => $series,
                'caption' => $this->buildTrendCaption($series),
            ];
        });
    }

    /**
     * Build a one-paragraph plain-Finnish caption describing the chart's
     * current state from the actual series values: spot's weekly range,
     * fixed-term 12 kk range, and which segment is currently most/least
     * expensive. Returns null if data is too sparse to characterize.
     *
     * @param array<int, array{label:string, values:array<int,?float>}> $series
     */
    private function buildTrendCaption(array $series): ?string
    {
        if (count($series) < 4) {
            return null;
        }

        $spotValues = array_values(array_filter($series[0]['values'], static fn ($v) => $v !== null));
        $fixedValues = array_values(array_filter($series[1]['values'], static fn ($v) => $v !== null));

        if (count($spotValues) < 2 || count($fixedValues) < 2) {
            return null;
        }

        $latestBySegment = [];
        foreach ($series as $segment) {
            for ($i = count($segment['values']) - 1; $i >= 0; $i--) {
                if ($segment['values'][$i] !== null) {
                    $latestBySegment[$segment['label']] = $segment['values'][$i];
                    break;
                }
            }
        }

        if (count($latestBySegment) < 2) {
            return null;
        }

        arsort($latestBySegment);
        $highestLabel = array_key_first($latestBySegment);
        $lowestLabel = array_key_last($latestBySegment);

        $fmt = static fn (float $v): string => number_format($v, 1, ',', ' ');
        $lcfirst = static fn (string $s): string => mb_strtolower(mb_substr($s, 0, 1)) . mb_substr($s, 1);

        return sprintf(
            'Pörssin viikkohinta on vaihdellut %s–%s c/kWh, kun määräaikaisten 12 kk hinta on pysynyt %s–%s c/kWh välillä. Kallein vaihtoehto tällä hetkellä on %s, edullisin %s. Tarkemmat luvut, vuosikustannukset ja kulutustasot löytyvät tilastosivulta.',
            $fmt(min($spotValues)),
            $fmt(max($spotValues)),
            $fmt(min($fixedValues)),
            $fmt(max($fixedValues)),
            $lcfirst($highestLabel),
            $lcfirst($lowestLabel),
        );
    }
}
