<?php

namespace App\Livewire;

use App\Models\Company;
use App\Models\ContractPriceDailyStatistic;
use App\Models\ElectricityContract;
use App\Models\SpotPriceHour;
use App\Models\SpotPriceQuarter;
use App\Services\CanonicalPricing\PricingMode;
use App\Services\ContractStatistics\AnnualSeriesCompatibility;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Livewire\Component;

class HomePage extends Component
{
    private const REGION = 'FI';

    private const TIMEZONE = 'Europe/Helsinki';

    public function render()
    {
        $contractCount = ElectricityContract::active()->count();
        $companyCount = Company::count();

        return view('livewire.home-page', [
            'contractCount' => $contractCount,
            'companyCount' => $companyCount,
            'currentSpotPrice' => $this->getCurrentSpotPrice(),
            'todaysSpotPrices' => $this->getTodaysSpotPrices(),
            'contractPriceTrend' => $this->getContractPriceTrend(),
        ])->layout('layouts.app', [
            'title' => 'Voltikka – yksi Suomen kattavimmista energiavertailuista',
            'metaDescription' => "Vertaile {$contractCount} sähkösopimusta {$companyCount} yhtiöltä puolueettomasti, seuraa pörssihintoja ja laske aurinkopaneelien tuotto ja lämpöpumpun säästöt. Riippumaton palvelu ilman mainosrahaa.",
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

        if (! $price) {
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

        if (! $price) {
            return null;
        }

        return [
            'price_with_tax' => round($price->price_with_tax, 2),
            'price_without_tax' => round($price->price_without_tax, 2),
        ];
    }

    /**
     * Weekly 5,000 kWh annual-cost trend for the four primary segments shown on
     * `/sahkosopimus/tilastot`. This metric is canonical-backed on forward rows
     * and remains observed seller evidence on historical rows. Using it avoids
     * claiming that a relational unit rate is today's market price.
     * Daily medians are averaged per ISO week so the line stays market-day weighted.
     *
     * Shaped for the shared uPlot mount in resources/js/contract-price-statistics.js;
     * `data-line-chart="spot"` on the container makes the lead series render in coral.
     *
     * Cached until tomorrow because the underlying statistics table refreshes
     * once per day after `contracts:calculate-price-statistics`. The key carries
     * the expected current basis and a source fingerprint so a feature-mode flip
     * or same-day rewrite cannot serve a stale current point.
     *
     * @return array{x: array<int,int>, series: array<int, array{label:string, values:array<int,?float>}>, pricing_basis?: string, caption?: ?string}
     */
    private function getContractPriceTrend(): array
    {
        $segments = [
            'spot' => 'Pörssisähkö',
            'fixed_term_12' => 'Määräaikainen 12 kk',
            'open_ended' => 'Toistaiseksi voimassa oleva',
            'hybrid' => 'Joustosähkö',
        ];
        $start = Carbon::now()->subDays(180)->toDateString();
        $expectedBasis = app(PricingMode::class)->expectedContractPriceBasis()->value;
        $sourceQuery = ContractPriceDailyStatistic::query()
            ->activeAnnualMethod()
            ->whereIn('segment_key', array_keys($segments))
            ->where('consumption_kwh', 5000)
            ->where('stat_date', '>=', $start);
        $latestExpectedDate = (clone $sourceQuery)
            ->where('pricing_basis', $expectedBasis)
            ->max('stat_date');

        if ($latestExpectedDate === null) {
            return ['x' => [], 'series' => []];
        }
        $latestExpectedDate = Carbon::parse($latestExpectedDate)->toDateString();

        $sourceVersion = (clone $sourceQuery)
            ->where('stat_date', '<=', $latestExpectedDate)
            ->selectRaw('COUNT(*) as row_count, MAX(updated_at) as latest_update')
            ->first();
        $cacheKey = 'home-page:contract-price-trend:v7:'.md5(json_encode([
            'annual_method' => ContractPriceDailyStatistic::activeAnnualMethodVersion()->value,
            'pricing_basis' => $expectedBasis,
            'latest_expected_date' => (string) $latestExpectedDate,
            'row_count' => (int) ($sourceVersion?->row_count ?? 0),
            'latest_update' => $sourceVersion?->latest_update,
        ]));

        return Cache::remember($cacheKey, Carbon::tomorrow(), function () use ($segments, $start, $expectedBasis, $latestExpectedDate) {
            $rows = ContractPriceDailyStatistic::query()
                ->activeAnnualMethod()
                ->whereIn('segment_key', array_keys($segments))
                ->where('consumption_kwh', 5000)
                ->where('stat_date', '>=', $start)
                ->where(function ($query) use ($expectedBasis, $latestExpectedDate) {
                    $query->where('stat_date', '<', $latestExpectedDate)
                        ->orWhere(function ($current) use ($expectedBasis, $latestExpectedDate) {
                            $current->whereDate('stat_date', $latestExpectedDate)
                                ->where('pricing_basis', $expectedBasis);
                        });
                })
                ->orderBy('stat_date')
                ->get(['stat_date', 'segment_key', 'median_value', 'pricing_basis', 'compatibility_key']);

            $latestPricingBasis = $expectedBasis;
            $weeklyBySegment = [];
            foreach ($segments as $segmentKey => $label) {
                $byWeek = $rows
                    ->where('segment_key', $segmentKey)
                    ->groupBy(fn ($row) => Carbon::parse($row->stat_date)->startOfWeek(Carbon::MONDAY)->toDateString())
                    ->sortKeys();
                $compatibility = new AnnualSeriesCompatibility;
                $weeklyBySegment[$segmentKey] = [];

                foreach ($byWeek as $weekStart => $weekRows) {
                    $weekRows = $weekRows->sortBy('stat_date')->values();
                    $period = $compatibility->evaluatePeriod($weekRows->pluck('compatibility_key')->all());
                    $values = $weekRows->pluck('median_value')->filter(fn ($value) => $value !== null);
                    $weeklyBySegment[$segmentKey][$weekStart] = [
                        'value' => $period['comparable'] && $values->isNotEmpty()
                            ? round((float) $values->avg(), 2)
                            : null,
                        'compatibility_key' => $period['compatibility_key'],
                    ];
                }
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
                $compatibilityKeys = [];
                foreach ($allWeeks as $weekStart) {
                    $values[] = $weeklyBySegment[$segmentKey][$weekStart]['value'] ?? null;
                    $compatibilityKeys[] = $weeklyBySegment[$segmentKey][$weekStart]['compatibility_key'] ?? null;
                }
                $series[] = [
                    'label' => $label,
                    'values' => $values,
                    'compatibility_keys' => $compatibilityKeys,
                ];
            }

            return [
                'x' => array_map(static fn ($w) => Carbon::parse($w)->getTimestamp(), $allWeeks),
                'series' => $series,
                'pricing_basis' => $latestPricingBasis,
                'caption' => $this->buildTrendCaption($series, $latestPricingBasis),
            ];
        });
    }

    /**
     * Build a one-paragraph plain-Finnish caption describing the chart's
     * current state from the actual annual-cost series values: spot's weekly
     * range, fixed-term 12-month range, and which segment is currently most or
     * least expensive. Returns null if data is too sparse to characterize.
     *
     * @param  array<int, array{label:string, values:array<int,?float>, compatibility_keys:array<int,?string>}>  $series
     */
    private function buildTrendCaption(array $series, string $latestPricingBasis): ?string
    {
        if (count($series) < 4) {
            return null;
        }

        $spotValues = $this->valuesInLatestAnnualRegime($series[0]);
        $fixedValues = $this->valuesInLatestAnnualRegime($series[1]);

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

        $fmt = static fn (float $v): string => number_format($v, 0, ',', ' ');
        $lcfirst = static fn (string $s): string => mb_strtolower(mb_substr($s, 0, 1)).mb_substr($s, 1);

        $provenance = $latestPricingBasis === 'canonical_calculation'
            ? 'Aikasarjan vanhat pisteet säilyttävät oman keräyspäivänsä perusteen; uusin laskelma käyttää kanonista nykyhintaa.'
            : 'Pisteet perustuvat kyseisinä päivinä havaittuihin myyjähintoihin.';

        return sprintf(
            'Pörssisähkön vuosikustannus on vaihdellut %s–%s €/v, kun määräaikaisten 12 kk sopimusten vuosikustannus on ollut %s–%s €/v. Kallein vaihtoehto uusimmassa laskelmassa on %s, edullisin %s. %s',
            $fmt(min($spotValues)),
            $fmt(max($spotValues)),
            $fmt(min($fixedValues)),
            $fmt(max($fixedValues)),
            $lcfirst($highestLabel),
            $lcfirst($lowestLabel),
            $provenance,
        );
    }

    /**
     * @param  array{values:array<int,?float>,compatibility_keys:array<int,?string>}  $series
     * @return array<int,float>
     */
    private function valuesInLatestAnnualRegime(array $series): array
    {
        if ($series['compatibility_keys'] === []) {
            return [];
        }

        $latestKey = $series['compatibility_keys'][array_key_last($series['compatibility_keys'])] ?? null;

        return collect($series['values'])
            ->filter(function ($value, int $index) use ($latestKey, $series): bool {
                $key = $series['compatibility_keys'][$index] ?? null;

                return $value !== null && AnnualSeriesCompatibility::sameKey($key, $latestKey);
            })
            ->map(fn ($value) => (float) $value)
            ->values()
            ->all();
    }
}
