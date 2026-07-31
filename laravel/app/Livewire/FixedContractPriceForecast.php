<?php

namespace App\Livewire;

use App\Models\ContractPriceDailyStatistic;
use App\Models\FixedContractPriceForecast as ForecastModel;
use App\Services\CanonicalPricing\PricingMode;
use App\Services\PriceForecasting\FixedTermPriceForecastService;
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
        6 => 'Lyhyt määräaikainen sopimus lukitsee energiahinnan kuudeksi kuukaudeksi. Tähän sopimuspituuteen vaikuttavat eniten lähikuukausien futuurihinnat.',
        12 => 'Vuoden mittainen kiinteähintainen sopimus lukitsee energiahinnan koko sopimuskaudeksi. Hinnoittelussa heijastuu seuraavan 12 kuukauden futuurikäyrä.',
        24 => 'Kahden vuoden määräaikainen sopimus lukitsee energiahinnan pidemmäksi aikaa. Markkinoilla on yleensä vuoden sopimuksia harvempi valikoima.',
    ];

    public function render()
    {
        return view('livewire.fixed-contract-price-forecast', $this->buildViewData())
            ->layout('layouts.app', [
                'title' => 'Sähkön hintaennuste: kannattaako lukita sähkösopimus nyt? | Voltikka',
                'metaDescription' => 'Sähkön hintaennuste määräaikaisille sähkösopimuksille (6, 12 ja 24 kk). Voltikan päivittäin päivittyvä hintaennuste yhdistää pörssifutuurit ja tarjotut sopimukset, ja kertoo kannattaako hinta lukita nyt.',
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
            ->max('forecast_date');
        $latestForecastDate = $latestForecastDate ? Carbon::parse($latestForecastDate)->toDateString() : null;

        if ($latestForecastDate === null) {
            return [
                'hasData' => false,
                'forecastDate' => null,
                'targetDate' => null,
                'horizonDays' => 30,
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
            'p20' => $this->laneFromRow($p20, 'Halvempi 20 %', 'Edullisimpien sopimusten taso.'),
            'median' => $this->laneFromRow($median, 'Mediaani', 'Tyypillinen tarjottu hinta.'),
            'p80' => $this->laneFromRow($p80, 'Kalliimpi 20 %', 'Kalleimpien sopimusten taso.'),
        ];

        $duration = (int) ($median?->duration_months ?? $rows->first()->duration_months);

        return [
            'duration_months' => $duration,
            'median_row' => $median,
            'lanes' => array_values(array_filter($lanes)),
            'signal' => $this->signalFromRow($median, $duration),
            'futures_trade_date' => $median?->futures_trade_date,
            'hedge_cost' => $median?->hedge_cost_cents_per_kwh,
            'coverage_quality' => $median?->coverage_quality,
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
            'fair_price' => (float) $row->fair_price_cents_per_kwh,
            'gap' => (float) $row->gap_cents_per_kwh,
            'direction' => $row->direction,
        ];
    }

    /**
     * Consumer-facing signal derived from the median forecast row.
     *
     * The body copy is duration-aware so the three deep-dive sections don't
     * read as carbon copies when all three durations land on the same signal
     * (which happens often near "flat" market days).
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

        $neutralBody = match ($durationMonths) {
            6 => 'Lähikuukausien futuurit hinnoittelevat vakaata kehitystä. Odottamalla ei juuri voita eikä häviä, joten päätös voi perustua muihin ehtoihin kuin ajoitukseen.',
            12 => 'Seuraavan 12 kuukauden futuurikäyrä on lähellä tarjottujen sopimusten tasoa. Lukitsemisen ja odottamisen välinen ero on tällä hetkellä pieni.',
            24 => 'Pidemmän aikavälin futuurit eivät kallistu kumpaankaan suuntaan. 24 kk on muutenkin pitkä sitoumus, jonka kohdalla muut ehdot kuin ajoitus painavat eniten.',
            default => 'Markkinanäkymä on tasainen. Odottaminen ei juuri muuta hintaa kumpaankaan suuntaan, joten päätös kannattaa tehdä muiden ehtojen perusteella.',
        };

        $risingBody = match ($durationMonths) {
            6 => 'Lähikuukausien futuurit viittaavat nouseviin hintoihin. 6 kk lukitus suojaa lyhyellä aikavälillä, mutta jää alttiiksi pörssin liikkeille uusittaessa.',
            12 => 'Seuraavan 12 kuukauden futuurikäyrä on tarjottujen sopimusten yläpuolella, joten malli odottaa hintojen tasaantumista ylöspäin. Lukitus nyt voi olla perusteltu.',
            24 => 'Pidemmän aikavälin futuurit ovat tarjottujen sopimusten yläpuolella. 24 kk lukitus tarjoaa pisimmän suojan, mutta sitoo myös pisimpään.',
            default => 'Markkinanäkymä viittaa nouseviin hintoihin lähiviikkoina. Jos arvostat ennustettavuutta, määräaikaisen lukitseminen nyt voi olla perusteltua.',
        };

        $fallingBody = match ($durationMonths) {
            6 => 'Lähikuukausien futuurit ovat tarjottujen sopimusten alapuolella. Halvempi 6 kk tarjous voi olla muutaman viikon päässä.',
            12 => 'Seuraavan 12 kuukauden futuurikäyrä on tarjottujen sopimusten alapuolella, joten malli odottaa hintojen laskua kohti markkinatasoa. Jos voit joustaa, odota.',
            24 => 'Pidemmän aikavälin futuurit ovat tarjottujen sopimusten alapuolella. Odottaminen voi tuoda halvemman 24 kk lukituksen, mutta liike on yleensä hidas.',
            default => 'Lähiviikkojen näkymä on laskeva. Jos voit joustaa, halvempi sopimustarjous voi olla muutaman viikon päässä.',
        };

        return match ($row->consumer_signal) {
            'lock_sooner' => [
                'key' => 'lock_sooner',
                'label' => 'Lukitse pian',
                'headline' => 'Hinnat näyttävät nousevan',
                'tone' => 'up',
                'body' => $risingBody,
            ],
            'wait_if_flexible' => [
                'key' => 'wait_if_flexible',
                'label' => 'Kannattaa odottaa',
                'headline' => 'Hinnat näyttävät laskevan',
                'tone' => 'down',
                'body' => $fallingBody,
            ],
            default => [
                'key' => 'neutral',
                'label' => 'Ei suositusta',
                'headline' => 'Hintojen suunta epäselvä',
                'tone' => 'neutral',
                'body' => $neutralBody,
            ],
        };
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

        $signals = $rowsByDuration->pluck('signal')->pluck('key');
        $up = $signals->filter(fn ($s) => $s === 'lock_sooner')->count();
        $down = $signals->filter(fn ($s) => $s === 'wait_if_flexible')->count();

        if ($up > $down && $up >= 2) {
            return [
                'tone' => 'up',
                'headline' => 'Hinnat näyttävät nousevan lähiviikkoina',
                'body' => 'Useimmissa sopimuspituuksissa ennustemalli odottaa pientä nousua kuukauden sisällä. Jos arvostat ennustettavuutta, harkitse hinnan lukitsemista nyt.',
            ];
        }

        if ($down > $up && $down >= 2) {
            return [
                'tone' => 'down',
                'headline' => 'Hinnat näyttävät laskevan lähiviikkoina',
                'body' => 'Useimmissa sopimuspituuksissa ennustemalli odottaa pientä laskua kuukauden sisällä. Jos voit joustaa, halvempi sopimus voi olla muutaman viikon päässä.',
            ];
        }

        return [
            'tone' => 'neutral',
            'headline' => 'Markkinatilanne on neutraali',
            'body' => 'Ennustemalli ei odota merkittäviä liikkeitä kuukauden sisällä. Päätöksen voi tehdä muiden ehtojen kuin ajoituksen perusteella.',
        ];
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
            'description' => 'Päivittäin päivittyvä mallipohjainen ennuste määräaikaisten sähkösopimusten (6, 12 ja 24 kk) hintakehityksestä. Yhdistää tarjottujen sopimusten tämänhetkisen tason ja Suomen EEX-pörssifutuurien hinnat.',
            'url' => $url,
            'license' => 'https://creativecommons.org/licenses/by/4.0/',
            'isAccessibleForFree' => true,
            'keywords' => ['sähkön hintaennuste', 'sähkösopimus', 'määräaikainen sähkösopimus', 'EEX futuurit', 'ennustemalli', 'Suomi'],
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
                'Ennustettu hinta 30 päivän päästä (c/kWh)',
                'Markkinatason hinta (c/kWh)',
                'Suositus (lukitse pian / kannattaa odottaa / ei suositusta)',
            ],
        ];
    }
}
