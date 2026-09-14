<?php

namespace App\Livewire;

use App\Models\ContractPriceDailyStatistic;
use App\Models\FixedContractPriceForecast as ForecastModel;
use App\Services\CanonicalPricing\PricingMode;
use App\Services\PriceForecasting\FixedTermPriceForecastService;
use App\Services\PriceForecasting\ForecastOutlook;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Livewire\Component;

class FixedContractPriceForecast extends Component
{
    /** Durations shown on the page, in display order. */
    public array $durations = [6, 12, 24];

    /** Finnish labels for each duration. */
    public array $durationLabels = [
        6 => '6 kuukauden määräaikainen',
        12 => '12 kuukauden määräaikainen',
        24 => '24 kuukauden määräaikainen',
    ];

    /** Short labels for chips and table headers. */
    public array $durationShortLabels = [
        6 => '6 kk',
        12 => '12 kk',
        24 => '24 kk',
    ];

    /** URL anchor slugs for each duration. */
    public array $durationAnchors = [
        6 => 'maaraaikainen-6-kk',
        12 => 'maaraaikainen-12-kk',
        24 => 'maaraaikainen-24-kk',
    ];

    /** Plain-Finnish description per duration, shown under each section heading. */
    public array $durationDescriptions = [
        6 => 'Kuuden kuukauden sopimuksessa energiahinta pysyy samana puoli vuotta.',
        12 => 'Vuoden sopimuksessa energiahinta pysyy samana koko sopimuskauden.',
        24 => 'Kahden vuoden sopimuksessa energiahinta pysyy samana kaksi vuotta.',
    ];

    public function render()
    {
        return view('livewire.fixed-contract-price-forecast', $this->buildViewData())
            ->layout('layouts.app', [
                'title' => 'Sähkön hintaennuste: mihin määräaikaisten hinnat ovat menossa? | Voltikka',
                'metaDescription' => 'Katso 6, 12 ja 24 kuukauden sähkösopimusten hintaennuste ja vertaa sitä nykyhintoihin. Ennuste yhdistää sopimushintojen kehityksen ja sähkön tukkuhinnat.',
                'canonical' => config('app.url').'/sahkosopimus/sahkon-hintaennuste',
            ]);
    }

    /**
     * @return array<string,mixed>
     */
    private function buildViewData(): array
    {
        $expectedBasis = app(PricingMode::class)->expectedContractPriceBasis();
        $latestForecastDate = ForecastModel::query()
            ->eligibleForPublicDisplay($expectedBasis)
            ->where('horizon_days', config('price_forecasting.fixed_term.default_horizon_days', 30))
            ->whereIn('duration_months', $this->durations)
            ->max('forecast_date');
        $latestForecastDate = $latestForecastDate ? Carbon::parse($latestForecastDate)->toDateString() : null;

        if ($latestForecastDate === null) {
            return [
                'hasData' => false,
                'forecastDate' => null,
                'targetDate' => null,
                'horizonDays' => (int) config('price_forecasting.fixed_term.default_horizon_days', 30),
                'durations' => $this->durations,
                'rowsByDuration' => collect(),
                'history' => collect(),
                'overall' => null,
                'citations' => $this->citations(null),
                'jsonLd' => $this->jsonLd(null, null),
            ];
        }

        $latestRows = ForecastModel::query()
            ->eligibleForPublicDisplay($expectedBasis)
            ->where('horizon_days', config('price_forecasting.fixed_term.default_horizon_days', 30))
            ->whereIn('duration_months', $this->durations)
            ->whereDate('forecast_date', $latestForecastDate)
            ->orderBy('duration_months')
            ->get();

        $rowsByDuration = $latestRows
            ->groupBy('duration_months')
            ->map(fn (Collection $rows) => $this->durationPayload($rows));

        $history = $this->historySeries();

        $forecastDate = Carbon::parse($latestForecastDate);
        $sampleRow = $latestRows->first();
        $horizonDays = (int) ($sampleRow->horizon_days ?? 30);
        $targetDate = $sampleRow ? Carbon::parse($sampleRow->target_date) : $forecastDate->copy()->addDays($horizonDays);

        return [
            'hasData' => $rowsByDuration->isNotEmpty(),
            'forecastDate' => $forecastDate,
            'targetDate' => $targetDate,
            'horizonDays' => $horizonDays,
            'durations' => $this->durations,
            'durationLabels' => $this->durationLabels,
            'durationShortLabels' => $this->durationShortLabels,
            'durationAnchors' => $this->durationAnchors,
            'durationDescriptions' => $this->durationDescriptions,
            'rowsByDuration' => $rowsByDuration,
            'history' => $history,
            'overall' => $this->buildOverallSummary($rowsByDuration),
            'citations' => $this->citations($forecastDate),
            'jsonLd' => $this->jsonLd($forecastDate, $history),
        ];
    }

    /**
     * @param  Collection<int, ForecastModel>  $rows
     * @return array<string,mixed>
     */
    private function durationPayload(Collection $rows): array
    {
        $byQuantile = $rows->keyBy('target_quantile');
        $median = $byQuantile->get('median');
        $p20 = $byQuantile->get('p20');
        $p80 = $byQuantile->get('p80');

        $lanes = [
            'p20' => $this->laneFromRow($p20, 'Edulliset sopimukset', 'Edullisempi hintataso (p20).'),
            'median' => $this->laneFromRow($median, 'Tyypilliset sopimukset', 'Keskimmäinen hintataso (mediaani).'),
            'p80' => $this->laneFromRow($p80, 'Kalliimmat sopimukset', 'Kalliimpi hintataso (p80).'),
        ];

        $duration = (int) ($median?->duration_months ?? $rows->first()->duration_months);

        return [
            'duration_months' => $duration,
            'median_row' => $median,
            'lanes' => array_values(array_filter($lanes)),
            'signal' => $this->signalFromRow($median, $duration),
            'quantiles_crossed' => $p20 !== null && $median !== null && $p80 !== null
                && ((float) $p20->forecast_price_cents_per_kwh > (float) $median->forecast_price_cents_per_kwh
                    || (float) $median->forecast_price_cents_per_kwh > (float) $p80->forecast_price_cents_per_kwh),
            'contract_count' => $median?->contract_count,
            'confidence' => $median?->confidence,
        ];
    }

    /**
     * @return array<string,mixed>|null
     */
    private function laneFromRow(?ForecastModel $row, string $label, string $sublabel): ?array
    {
        if ($row === null) {
            return null;
        }

        return [
            'quantile' => $row->target_quantile,
            'label' => $label,
            'sublabel' => $sublabel,
            'current_price' => (float) $row->current_price_cents_per_kwh,
            'forecast_price' => (float) $row->forecast_price_cents_per_kwh,
            'expected_change' => (float) $row->expected_change_cents_per_kwh,
            'expected_change_pct' => $this->pctChange($row->current_price_cents_per_kwh, $row->expected_change_cents_per_kwh),
            'direction' => $row->direction,
        ];
    }

    /**
     * Qualified outlook from the saved median direction, not legacy timing advice.
     *
     * @return array<string,string>
     */
    private function signalFromRow(?ForecastModel $row, ?int $durationMonths = null): array
    {
        if ($row === null) {
            return [
                'key' => 'unknown',
                'label' => 'Ei tarpeeksi tietoa',
                'headline' => '–',
                'tone' => 'neutral',
                'body' => 'Tähän sopimuspituuteen ei juuri nyt ole tarpeeksi aineistoa ennusteen tekemistä varten.',
            ];
        }

        $signal = ForecastOutlook::presentation($row->direction);
        $signal['body'] = $signal['key'] === 'unknown'
            ? 'Tallennettu suuntatieto puuttuu tai sitä ei voida tulkita.'
            : sprintf('%d kk sopimusten hintanäkymä ajalle %s–%s (%d päivää).',
                $durationMonths ?? $row->duration_months,
                $row->forecast_date->format('j.n.Y'),
                $row->target_date->format('j.n.Y'),
                $row->horizon_days,
            );

        return $signal;
    }

    /**
     * Aggregate signal headline for the page lead.
     *
     * @param  Collection<int, array<string,mixed>>  $rowsByDuration
     * @return array<string,mixed>|null
     */
    private function buildOverallSummary(Collection $rowsByDuration): ?array
    {
        if ($rowsByDuration->isEmpty()) {
            return null;
        }

        $summary = ForecastOutlook::summary($rowsByDuration->pluck('signal')->pluck('key')->all());
        $signal = ForecastOutlook::presentation(match ($summary) {
            'up' => 'rising', 'down' => 'falling', 'stable' => 'flat', default => null,
        });
        $signal['headline'] = match ($summary) {
            'mixed' => 'Hintojen suunnat eroavat sopimuspituuksittain',
            'incomplete' => 'Hintanäkymä on saatavilla vain osalle sopimuspituuksista',
            default => $signal['headline'],
        };
        $signal['body'] = 'Katso kunkin sopimuspituuden hinnat ja ennuste alta.';

        return $signal;
    }

    /**
     * Historical offered-price medians per duration, used for the trend chart.
     *
     * @return Collection<int, array<string,mixed>>
     */
    private function historySeries(): Collection
    {
        $durationBySegment = array_flip(FixedTermPriceForecastService::SEGMENTS);
        $rows = ContractPriceDailyStatistic::query()
            ->whereIn('segment_key', array_keys($durationBySegment))
            ->where('metric_key', 'energy_price')
            ->whereIn('pricing_basis', [
                FixedTermPriceForecastService::OBSERVED_PRICING_BASIS,
                FixedTermPriceForecastService::CANONICAL_PRICING_BASIS,
            ])
            ->whereNull('consumption_kwh')
            ->whereNotNull('median_value')
            ->orderBy('stat_date')
            ->get(['stat_date', 'segment_key', 'pricing_basis', 'median_value']);

        return $rows->groupBy('segment_key')->map(function (Collection $segmentRows, string $segment) use ($durationBySegment) {
            $duration = (int) $durationBySegment[$segment];
            $points = $segmentRows
                ->groupBy(fn (ContractPriceDailyStatistic $stat) => $stat->stat_date->toDateString())
                ->map(fn (Collection $sameDate) => $sameDate->firstWhere(
                    'pricing_basis',
                    FixedTermPriceForecastService::CANONICAL_PRICING_BASIS,
                ) ?? $sameDate->first())
                ->sortBy(fn (ContractPriceDailyStatistic $stat) => $stat->stat_date)
                ->values();

            return [
                'duration_months' => $duration,
                'label' => $this->durationShortLabels[$duration] ?? ($duration.' kk'),
                'x' => $points->map(fn (ContractPriceDailyStatistic $stat) => Carbon::parse($stat->stat_date->toDateString(), 'UTC')->getTimestamp())->all(),
                'current' => $points->map(fn (ContractPriceDailyStatistic $stat) => (float) $stat->median_value)->all(),
                'pricing_bases' => $points->pluck('pricing_basis')->all(),
            ];
        })->sortBy('duration_months')->values();
    }

    private function pctChange(?float $base, ?float $change): ?float
    {
        if ($base === null || $change === null || $base == 0.0) {
            return null;
        }

        return ($change / $base) * 100.0;
    }

    /**
     * @return array<string,mixed>
     */
    /**
     * Pre-formatted citation strings (plain / markdown / html) for the
     * "Viittaa tähän" block. Cited as Voltikan sähkön hintaennuste so it is
     * clear to readers that the figure is a model-derived forecast, not a
     * measured market price.
     *
     * @return array{plain:string,markdown:string,html:string}
     */
    private function citations(?Carbon $forecastDate): array
    {
        $date = $forecastDate ?? Carbon::today();
        $dateFi = $date->translatedFormat('j.n.Y');
        $dateIso = $date->toDateString();
        $title = 'Sähkön hintaennuste';
        $url = config('app.url').'/sahkosopimus/sahkon-hintaennuste';

        return [
            'plain' => "Lähde: Voltikka, {$title}, päivitetty {$dateFi}. {$url}",
            'markdown' => "Lähde: [Voltikka, {$title}]({$url}), päivitetty {$dateFi}.",
            'html' => '<a href="'.htmlspecialchars($url, ENT_QUOTES, 'UTF-8').'">Voltikka, '.htmlspecialchars($title).'</a>, päivitetty <time datetime="'.$dateIso.'">'.$dateFi.'</time>.',
        ];
    }

    /**
     * Dataset JSON-LD schema. The forecast is a derived data product, so
     * temporalCoverage spans from the first stored forecast date to today.
     *
     * @param  Collection<int, array<string,mixed>>|null  $history
     * @return array<string,mixed>
     */
    private function jsonLd(?Carbon $forecastDate, ?Collection $history): array
    {
        $url = config('app.url').'/sahkosopimus/sahkon-hintaennuste';
        $date = $forecastDate?->toDateString() ?? Carbon::today()->toDateString();

        $historyDates = collect($history ?? [])->flatMap(fn ($series) => $series['x'] ?? []);
        $earliestTs = $historyDates->min();
        $latestTs = $historyDates->max();
        $temporalCoverage = $earliestTs && $latestTs
            ? Carbon::createFromTimestampUTC($earliestTs)->toDateString().'/'.Carbon::createFromTimestampUTC($latestTs)->toDateString()
            : null;

        return [
            '@context' => 'https://schema.org',
            '@type' => 'Dataset',
            'name' => 'Voltikka — Sähkön hintaennuste määräaikaisille sähkösopimuksille',
            'description' => 'Sähkön hintaennuste 6, 12 ja 24 kuukauden määräaikaisille sähkösopimuksille. Ennuste perustuu sähkösopimusten hintakehitykseen ja sähkön tukkumarkkinoiden hintoihin. Sopimuspituudet ja hintatasot lasketaan erikseen.',
            'url' => $url,
            'license' => 'https://creativecommons.org/licenses/by/4.0/',
            'isAccessibleForFree' => true,
            'keywords' => ['sähkön hintaennuste', 'sähkösopimus', 'määräaikainen sähkösopimus', 'hintahistoria', 'ennustemalli', 'Suomi'],
            'inLanguage' => 'fi',
            'creator' => [
                '@type' => 'Organization',
                'name' => 'Voltikka',
                'url' => config('app.url'),
            ],
            'temporalCoverage' => $temporalCoverage,
            'dateModified' => $date,
            'variableMeasured' => [
                'Tarjottu mediaanihinta (c/kWh)',
                'Ennustettu hinta ilmoitettuna kohdepäivänä (c/kWh)',
                'Hintanäkymä (nousua / laskua / suunnilleen ennallaan)',
            ],
        ];
    }
}
