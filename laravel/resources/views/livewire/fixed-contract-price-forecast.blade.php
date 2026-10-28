@php
    use Illuminate\Support\Carbon as Cb;

    $fmtNum = fn ($value, $decimals = 2) => $value === null
        ? '–'
        : number_format((float) $value, $decimals, ',', ' ');

    $fmtSignedCents = function (?float $value, int $decimals = 2) {
        if ($value === null) return '–';
        $rounded = round($value, $decimals);
        if ($rounded == 0.0) {
            return '±' . number_format(0.0, $decimals, ',', ' ');
        }
        $sign = $rounded > 0 ? '+' : '−';
        return $sign . number_format(abs($rounded), $decimals, ',', ' ');
    };

    $fmtPct = function (?float $delta) {
        if ($delta === null) {
            return '–';
        }
        $rounded = round($delta, 1);
        if ($rounded == 0.0) {
            return '±0,0 %';
        }
        $sign = $rounded > 0 ? '+' : '−';
        return $sign . number_format(abs($rounded), 1, ',', ' ') . ' %';
    };

    $fiDate = function ($date) {
        if (! $date) return '–';
        return Cb::parse($date)->translatedFormat('j.n.Y');
    };

    $directionLabels = collect(['rising', 'slightly_rising', 'flat', 'slightly_falling', 'falling'])
        ->mapWithKeys(fn ($direction) => [$direction => \App\Services\PriceForecasting\ForecastOutlook::presentation($direction)['label']])
        ->all();

    // Change colour by the saved outlook tone (spec §2): rise = price-high, fall = saving,
    // about unchanged = ink-body.
    $toneText = [
        'up' => 'data-delta--up',
        'down' => 'data-delta--down',
        'neutral' => 'data-delta--flat',
    ];

    // Inked direction arrow (Paper D8Q-0 "Hintojen suunta"): up-right, down-right or level.
    $arrowPath = fn (string $tone) => match ($tone) {
        'up' => 'M3 14.5 C 11 12.5, 19 8.5, 27 4 M20 3.2 L27.6 3.6 L25.6 10.8',
        'down' => 'M3 4 C 11 6, 19 10, 27 14.5 M25.6 7.7 L27.6 14.9 L20 15.3',
        default => 'M3 9.5 C 11 8.8, 20 10, 28 9.2 M23 4 L29 9.2 L23 14.4',
    };

    $confidenceLabels = [
        'high' => 'Tietopohja: pitkä hintahistoria',
        'medium' => 'Tietopohja: kohtalainen hintahistoria',
        'low' => 'Tietopohja: rajallinen hintahistoria',
    ];

    // Slope chart of one term (Paper "Term · 6 kk"): p20, median and p80 from "nyt" to the
    // forecast date on a shared c/kWh scale. Presentation only; the values are the lane values.
    $slopeChart = function (array $lanes) {
        $values = collect($lanes)->flatMap(fn ($lane) => [$lane['current_price'], $lane['forecast_price']])
            ->filter(fn ($v) => $v !== null)->map(fn ($v) => (float) $v);
        if ($values->isEmpty()) {
            return null;
        }
        $min = $values->min();
        $max = $values->max();
        $span = $max - $min;
        if ($span < 0.0001) {
            $min -= 0.5;
            $max += 0.5;
            $span = 1.0;
        }
        $top = 18.0;
        $bottom = 180.0;
        $y = fn (float $v) => round($bottom - ($v - $min) / $span * ($bottom - $top), 1);

        return collect($lanes)->map(fn ($lane) => [
            'quantile' => $lane['quantile'],
            'y1' => $y((float) $lane['current_price']),
            'y2' => $y((float) $lane['forecast_price']),
            'current' => $lane['current_price'],
            'forecast' => $lane['forecast_price'],
        ])->all();
    };
@endphp

<div class="data-page">
    <x-schema-markup :schemas="[$jsonLd]" />

    {{-- Hero: the fox at the weather station; the outlook on the sky. --}}
    <x-site.data-hero art="forecast-hero" variant="forecast" :tone="\App\Support\Season::heroTone('forecast-hero')">
        <nav class="data-crumbs" aria-label="Breadcrumb">
            <ol>
                <li><a href="/" class="vk-focus">Etusivu</a></li>
                <li aria-hidden="true">/</li>
                <li><a href="/sahkosopimus" class="vk-focus">Sähkösopimukset</a></li>
                <li aria-hidden="true">/</li>
                <li aria-current="page">Sähkön hintaennuste</li>
            </ol>
        </nav>

        <p class="data-hero-kicker">Voltikka sähkön hintaennuste</p>
        <h1 class="vk-hero-h1 data-hero-h1">
            Sähkön hintaennuste: mihin määräaikaisten hinnat ovat menossa?
        </h1>
        <p class="vk-hero-lead data-hero-lead">
            Katso, miten 6, 12 ja 24 kuukauden sähkösopimusten hintojen ennustetaan muuttuvan seuraavien {{ $horizonDays }} päivän aikana. Ennuste perustuu sähkösopimusten hintakehitykseen ja sähkön tukkumarkkinoiden hintoihin. Ennuste voi muuttua markkinatilanteen mukana.
        </p>

        {{-- Facts row --}}
        <dl class="data-facts">
            <div>
                <dt>Ennustepäivä</dt>
                <dd>{{ $forecastDate ? $fiDate($forecastDate) : '–' }}</dd>
            </div>
            <div>
                <dt>Ennustejakso</dt>
                <dd>
                    @if ($targetDate)
                        {{ $horizonDays }}&nbsp;pv ({{ $fiDate($targetDate) }})
                    @else
                        –
                    @endif
                </dd>
            </div>
            <div>
                <dt>Sopimuspituudet</dt>
                <dd>6, 12 ja 24 kk</dd>
            </div>
            <div>
                <dt>Sisältää</dt>
                <dd>ALV 25,5 %</dd>
            </div>
        </dl>

        <div class="data-hero-links">
            <a href="/sahkosopimus/tilastot" class="data-hero-link vk-focus">Katso myös hintatilastot →</a>
            <a href="#menetelma" data-no-nav-loading class="data-hero-link vk-focus">Miten ennuste lasketaan</a>
            <a href="#viittaa" data-no-nav-loading class="data-hero-link vk-focus">Viittaa tähän</a>
            <span class="data-hero-license">CC&nbsp;BY&nbsp;4.0</span>
        </div>

        @if ($hasData && ! empty($overall))
            @php
                $overallTone = $overall['tone'];
                $overallKnown = in_array($overall['key'] ?? null, ['rising', 'falling', 'flat'], true);
            @endphp
            <x-slot:aside>
                <div class="data-outlook">
                    <p class="data-outlook-kicker">{{ $horizonDays }} päivän näkymä</p>
                    @if ($overallKnown)
                        <p class="data-outlook-row">
                            <svg width="64" height="40" viewBox="0 0 64 40" aria-hidden="true" focusable="false" class="shrink-0">
                                <g transform="translate(14 11)"><path d="{{ $arrowPath($overallTone) }}" fill="none" stroke="currentColor" stroke-width="3.2" stroke-linecap="round" stroke-linejoin="round" /></g>
                                <path d="M6 10 C 14 9, 22 11, 30 9.6 M8 32 C 16 31, 24 33, 32 31.6" fill="none" style="stroke: var(--color-fox)" stroke-width="2" stroke-linecap="round" />
                            </svg>
                            <span class="data-outlook-label">{{ $overall['label'] }}</span>
                        </p>
                    @endif
                    <p class="{{ $overallKnown ? 'data-outlook-headline' : 'data-outlook-label' }}">{{ $overall['headline'] }}</p>
                </div>
            </x-slot:aside>
        @endif
    </x-site.data-hero>

    <x-page-action-strip />

    <article class="vk-wrap data-body tabular-nums">

        @if (! $hasData)
            <section class="data-empty">
                <p>
                    Ennusteita ei ole vielä saatavilla. Aineiston keruu on käynnissä, palaa myöhemmin.
                </p>
            </section>
        @else

            {{-- Cross-duration comparison: the three contract lengths side by side, so the reader can
                 scan the typical price now and at the forecast date before the details. --}}
            <section class="data-section" aria-labelledby="comparison-heading">
                <div class="data-section-head">
                    <div class="flex flex-col gap-1.5">
                        @if (! empty($overall))
                            <p id="signal-eyebrow" class="fc-kicker">
                                {{ $horizonDays }} päivän näkymä · {{ $overall['headline'] }}
                            </p>
                        @endif
                        <x-site.section-heading id="comparison-heading" :wave="320" class="data-heading">
                            Vertailu sopimuspituuksittain
                        </x-site.section-heading>
                    </div>
                    <div class="fc-head-aside">
                        @if (! empty($overall))
                            <p>{{ $overall['body'] }}</p>
                        @endif
                        <p>Vertaa tyypillistä sopimushintaa nyt ja {{ $horizonDays }} päivän kuluttua.</p>
                    </div>
                </div>

                <div class="data-table-scroll">
                    <table class="data-table fc-table--compare">
                        <thead>
                            <tr>
                                <th scope="col">Sopimuspituus</th>
                                <th scope="col">Tyypillinen hinta nyt</th>
                                <th scope="col"><span class="sr-only">Muutoksen suunta</span></th>
                                <th scope="col">Ennuste {{ $horizonDays }}&nbsp;pv</th>
                                <th scope="col">Muutos</th>
                                <th scope="col">Hintojen suunta</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($durations as $duration)
                                @php
                                    $payload = $rowsByDuration->get($duration);
                                    $signal = $payload['signal'] ?? null;
                                    $median = ! empty($payload['lanes']) ? collect($payload['lanes'])->firstWhere('quantile', 'median') : null;
                                    $tone = $signal['tone'] ?? 'neutral';
                                    $anchor = $durationAnchors[$duration] ?? '';
                                @endphp
                                <tr>
                                    <td>
                                        <span class="data-type">
                                            <x-ink.glyph bucket="kiintea" size="36" />
                                            <span class="flex flex-col">
                                                <a href="#{{ $anchor }}" data-no-nav-loading class="fc-name vk-focus">
                                                    {{ $durationLabels[$duration] ?? ($duration . ' kk') }}
                                                </a>
                                                @if ($payload && $payload['contract_count'])
                                                    <span class="fc-count tabular-nums">
                                                        {{ number_format($payload['contract_count'], 0, ',', ' ') }} sopimusta
                                                    </span>
                                                @endif
                                            </span>
                                        </span>
                                    </td>

                                    @if ($payload && $median)
                                        <td>
                                            <span class="fc-big-price">{{ $fmtNum($median['current_price'], 2) }}<span class="fc-unit">&nbsp;c/kWh</span></span>
                                        </td>
                                        <td aria-hidden="true">
                                            <svg width="60" height="20" viewBox="0 0 60 20" focusable="false" class="block"><path d="M3 10.5 C 18 9.6, 34 11, 52 10 M45 4.5 L53 10 L45 15.5" fill="none" style="stroke: var(--color-fox)" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round" /></svg>
                                        </td>
                                        <td>
                                            <span class="fc-big-price">{{ $fmtNum($median['forecast_price'], 2) }}<span class="fc-unit">&nbsp;c/kWh</span></span>
                                        </td>
                                        <td>
                                            <span class="fc-change">
                                                <span class="fc-change-value {{ $toneText[$tone] ?? $toneText['neutral'] }}">{{ $fmtSignedCents($median['expected_change'], 2) }}&nbsp;c/kWh</span>
                                                <span class="fc-change-pct">{{ $fmtPct($median['expected_change_pct']) }}</span>
                                            </span>
                                        </td>
                                        <td>
                                            <span class="fc-direction">
                                                <svg width="34" height="18" viewBox="0 0 34 18" aria-hidden="true" focusable="false" class="shrink-0"><path d="{{ $arrowPath($tone) }}" fill="none" style="stroke: var(--color-ink)" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" /></svg>
                                                {{ $signal['label'] ?? 'Ei tietoa' }}
                                            </span>
                                        </td>
                                    @else
                                        <td colspan="5" class="data-muted-caption">Ei tarpeeksi aineistoa juuri nyt.</td>
                                    @endif
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </section>

            {{-- "Löysitkö etsimäsi?" after the outlook comparison. --}}
            <x-page-feedback page-type="forecast" />

            {{-- Per-duration deep dive (round 3, Paper "R3 · Data · Hintaennuste"): one full-width
                 row per term. The conclusion (the saved median outlook) comes first, then the
                 typical price, then the evidence: slope chart and the p20 / median / p80 lanes. --}}
            <section class="data-section data-section--roomy" aria-labelledby="deepdive-heading">
                <x-site.section-heading id="deepdive-heading" :wave="320" class="data-heading">
                    Sopimuspituudet tarkemmin
                </x-site.section-heading>
                <p class="data-lead-text data-lead-text--intro">
                    Valitse sopimuspituus ja katso edullisten, tyypillisten ja kalliimpien sopimusten hintaennuste.
                </p>

                {{-- In-page contents: inked anchors, same as the /sahkosopimus/tilastot types. --}}
                <nav aria-label="Sopimuspituudet" class="data-toc">
                    @foreach ($durations as $duration)
                        @php
                            $payload = $rowsByDuration->get($duration);
                            $signal = $payload['signal'] ?? null;
                            $tone = $signal['tone'] ?? 'neutral';
                            $anchor = $durationAnchors[$duration] ?? '';
                        @endphp
                        <a href="#{{ $anchor }}" data-no-nav-loading class="data-toc-link vk-focus">
                            {{ $durationLabels[$duration] ?? ($duration . ' kk') }}
                            @if ($signal && in_array($tone, ['up', 'down'], true))
                                <span class="data-toc-change {{ $toneText[$tone] }}">
                                    {{ $signal['label'] }}
                                </span>
                            @endif
                        </a>
                    @endforeach
                </nav>

                <div class="fc-terms">
                    @foreach ($durations as $duration)
                        @php
                            $payload = $rowsByDuration->get($duration);
                            $anchor = $durationAnchors[$duration] ?? '';
                            $signal = $payload['signal'] ?? null;
                            $tone = $signal['tone'] ?? 'neutral';
                            $termHorizon = $payload['median_row']?->horizon_days ?? $horizonDays;
                            $medianLane = ! empty($payload['lanes']) ? collect($payload['lanes'])->firstWhere('quantile', 'median') : null;
                            $knownSignal = $signal && in_array($signal['key'] ?? null, ['rising', 'falling', 'flat'], true);
                        @endphp
                        <article id="{{ $anchor }}" class="fc-term scroll-mt-24">
                            <header class="fc-term-head">
                                <div class="fc-term-name">
                                    <h3 class="fc-term-title">{{ $durationLabels[$duration] ?? ($duration . ' kk') }}</h3>
                                    @if ($payload && $payload['contract_count'])
                                        <span class="fc-term-count">
                                            {{ number_format($payload['contract_count'], 0, ',', ' ') }} sopimusta
                                        </span>
                                    @endif
                                </div>
                                <p class="fc-term-text">{{ $durationDescriptions[$duration] ?? '' }}</p>
                            </header>

                            @if (! $payload || empty($payload['lanes']))
                                <p class="data-muted-caption">Ei tarpeeksi aineistoa tämän sopimuspituuden ennusteeseen.</p>
                            @else
                                {{-- Conclusion: the saved median outlook label, in the tone colour. --}}
                                <div class="fc-conclusion">
                                    @if ($signal)
                                        <div class="fc-conclusion-main">
                                            <p class="fc-signal-kicker">{{ $termHorizon }} päivän hintanäkymä</p>
                                            <p class="fc-verdict fc-verdict--{{ $tone }}">
                                                @if ($knownSignal)
                                                    <svg class="fc-verdict-arrow" viewBox="0 0 34 18" aria-hidden="true" focusable="false"><path d="{{ $arrowPath($tone) }}" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round" /></svg>
                                                @endif
                                                <span>{{ $signal['label'] }}</span>
                                            </p>
                                        </div>
                                    @endif
                                    <div class="fc-conclusion-aside">
                                        @if ($medianLane)
                                            <p class="fc-typical">
                                                Tyypillinen hinta {{ $fmtNum($medianLane['current_price'], 2) }} → {{ $fmtNum($medianLane['forecast_price'], 2) }}&nbsp;c/kWh
                                            </p>
                                            <p class="fc-typical-change">
                                                {{ $fmtSignedCents($medianLane['expected_change'], 2) }}&nbsp;c/kWh · {{ $fmtPct($medianLane['expected_change_pct']) }} {{ $termHorizon }} päivässä
                                            </p>
                                        @endif
                                        @if ($signal)
                                            <p class="fc-signal-body">{{ $signal['body'] }}</p>
                                        @endif
                                    </div>
                                </div>

                                {{-- Evidence: slope chart and the lanes. --}}
                                <div class="fc-evidence">
                                    @php $slope = $slopeChart($payload['lanes']); @endphp
                                    @if ($slope)
                                        <figure class="fc-slope-figure">
                                            <figcaption class="data-type-chart-head">
                                                <span>Hinta nyt → ennuste</span>
                                                <span class="data-type-chart-unit">c/kWh</span>
                                            </figcaption>
                                            <svg class="fc-slope" viewBox="0 0 340 220" aria-hidden="true" focusable="false">
                                                <path d="M78 6 V190 M262 6 V190" fill="none" style="stroke: var(--color-edge-strong)" stroke-dasharray="3 4" />
                                                @foreach ($slope as $line)
                                                    @php
                                                        $isMedian = $line['quantile'] === 'median';
                                                        $lineColor = $isMedian ? 'var(--color-ink)' : 'var(--color-cat-fixed)';
                                                        $labelSize = $isMedian ? 15 : 13;
                                                    @endphp
                                                    <path d="M78 {{ $line['y1'] }} L262 {{ $line['y2'] }}" fill="none" style="stroke: {{ $lineColor }}" stroke-width="{{ $isMedian ? 3.6 : 2 }}" stroke-linecap="round" />
                                                    <circle cx="78" cy="{{ $line['y1'] }}" r="{{ $isMedian ? 4.5 : 3.5 }}" style="fill: {{ $lineColor }}" />
                                                    <circle cx="262" cy="{{ $line['y2'] }}" r="{{ $isMedian ? 4.5 : 3.5 }}" style="fill: var(--color-ground); stroke: {{ $lineColor }}" stroke-width="2" />
                                                    <text x="66" y="{{ $line['y1'] + 5 }}" text-anchor="end" font-size="{{ $labelSize }}" font-weight="{{ $isMedian ? 800 : 700 }}" style="fill: {{ $lineColor }}; font-family: var(--font-sans)">{{ $fmtNum($line['current'], 2) }}</text>
                                                    <text x="274" y="{{ $line['y2'] + 5 }}" font-size="{{ $labelSize }}" font-weight="{{ $isMedian ? 800 : 700 }}" style="fill: {{ $lineColor }}; font-family: var(--font-sans)">{{ $fmtNum($line['forecast'], 2) }}</text>
                                                @endforeach
                                                <text x="78" y="208" text-anchor="middle" font-size="13" font-weight="800" style="fill: var(--color-ink-muted); font-family: var(--font-sans)">nyt</text>
                                                <text x="262" y="208" text-anchor="middle" font-size="13" font-weight="800" style="fill: var(--color-ink-muted); font-family: var(--font-sans)">{{ $termHorizon }} pv</text>
                                            </svg>
                                        </figure>
                                    @endif

                                    {{-- Lanes: p20 / median / p80. The median is the lead row; p20 and p80
                                         stay secondary (they describe the price distribution, not
                                         uncertainty bounds). --}}
                                    <div class="fc-lanes">
                                        <p class="fc-lanes-head" aria-hidden="true">
                                            <span>Hintataso</span>
                                            <span>Nyt → {{ $termHorizon }} pv</span>
                                        </p>
                                        @foreach ($payload['lanes'] as $lane)
                                            @php $isLead = $lane['quantile'] === 'median'; @endphp
                                            <div class="fc-lane {{ $isLead ? 'is-lead' : '' }}">
                                                <div class="flex min-w-0 flex-1 flex-col gap-0.5">
                                                    <span class="fc-lane-name">{{ $lane['label'] }}</span>
                                                    <span class="fc-lane-sub">{{ $lane['sublabel'] }}</span>
                                                    <span class="fc-lane-sub">Suunta: {{ $directionLabels[$lane['direction']] ?? $lane['direction'] }}</span>
                                                </div>
                                                <div class="fc-lane-values">
                                                    <span class="fc-lane-prices">
                                                        <span class="sr-only">Hinta nyt </span>{{ $fmtNum($lane['current_price'], 2) }} → <span class="sr-only">ennuste {{ $termHorizon }} pv </span>{{ $fmtNum($lane['forecast_price'], 2) }}<span class="sr-only">&nbsp;c/kWh</span>
                                                    </span>
                                                    <span class="fc-lane-change">
                                                        {{ $fmtSignedCents($lane['expected_change'], 2) }}&nbsp;c/kWh · {{ $fmtPct($lane['expected_change_pct']) }}
                                                    </span>
                                                </div>
                                            </div>
                                        @endforeach

                                        @if ($payload['quantiles_crossed'])
                                            <p class="fc-crossed">Hintatasojen ennusteet menevät ristiin. Ne on laskettu erikseen, joten niitä ei voi lukea yhtenä hintahaarukkana.</p>
                                        @endif

                                        <dl class="fc-confidence">
                                            <dt>Ennusteen tietopohja</dt>
                                            <dd>{{ $confidenceLabels[$payload['confidence']] ?? '–' }}</dd>
                                        </dl>
                                    </div>
                                </div>
                            @endif
                        </article>
                    @endforeach
                </div>
            </section>

            {{-- Historical offered-price trend --}}
            @if ($history->isNotEmpty())
                @php
                    $historyDates = $history->flatMap(fn ($series) => $series['x'] ?? []);
                    $historyPricingBases = $history->flatMap(fn ($series) => $series['pricing_bases'] ?? [])->unique();
                    $historyFirstDate = $historyDates->isNotEmpty() ? Cb::createFromTimestampUTC($historyDates->min())->translatedFormat('j.n.Y') : null;
                    $historyLastDate = $historyDates->isNotEmpty() ? Cb::createFromTimestampUTC($historyDates->max())->translatedFormat('j.n.Y') : null;
                @endphp
                <section class="data-section" aria-labelledby="history-heading">
                    <div class="data-section-head">
                        <x-site.section-heading id="history-heading" :wave="320" class="data-heading">
                            Mediaanihinta viime kuukausina
                        </x-site.section-heading>
                        @if ($historyFirstDate && $historyLastDate)
                            <p class="data-see-also">Aineisto on kerätty {{ $historyFirstDate }}–{{ $historyLastDate }}.</p>
                        @endif
                    </div>
                    <p class="data-lead-text data-lead-text--wide">
                        Taulukko näyttää, miten määräaikaisten sopimusten mediaanihinta on muuttunut Voltikan hintahistorian aikana. Jokainen mittauspäivä on yksi piste oikealla näkyvässä trendiviivassa.
                    </p>

                    <div class="data-table-scroll">
                        <table class="data-table fc-table--history">
                            <thead>
                                <tr>
                                    <th scope="col">Sopimuspituus</th>
                                    <th scope="col">Aineiston alussa</th>
                                    <th scope="col">Tuorein</th>
                                    <th scope="col">Muutos koko jaksolla</th>
                                    <th scope="col">Trendi</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($history as $series)
                                    @php
                                        $values = $series['current'];
                                        $count = count($values);
                                        $first = $count ? $values[0] : null;
                                        $last = $count ? end($values) : null;
                                        $delta = ($first !== null && $first != 0.0 && $last !== null)
                                            ? (($last - $first) / $first) * 100.0
                                            : null;

                                        $sparklinePath = null;
                                        if ($count >= 2) {
                                            $min = min($values);
                                            $max = max($values);
                                            $range = ($max - $min) ?: 1.0;
                                            $width = 120;
                                            $height = 28;
                                            $padY = 2;
                                            $usableH = $height - 2 * $padY;
                                            $stepX = $width / ($count - 1);
                                            $parts = [];
                                            foreach ($values as $i => $v) {
                                                $x = round($i * $stepX, 2);
                                                $y = round($padY + ($usableH - (($v - $min) / $range) * $usableH), 2);
                                                $parts[] = ($i === 0 ? 'M' : 'L') . " {$x},{$y}";
                                            }
                                            $sparklinePath = implode(' ', $parts);
                                        }
                                        $deltaTone = $delta !== null && $delta < -0.5 ? 'down' : ($delta !== null && $delta > 0.5 ? 'up' : 'neutral');
                                    @endphp
                                    <tr>
                                        <td>
                                            <span class="flex flex-col">
                                                <span class="data-type-name text-ink">{{ $durationLabels[$series['duration_months']] ?? $series['label'] }}</span>
                                                @if ($count)
                                                    <span class="fc-count tabular-nums">
                                                        {{ number_format($count, 0, ',', ' ') }} mittausta
                                                    </span>
                                                @endif
                                            </span>
                                        </td>
                                        <td class="fc-history-first">{{ $fmtNum($first, 2) }}&nbsp;c/kWh</td>
                                        <td><span class="data-cell-price">{{ $fmtNum($last, 2) }}&nbsp;c/kWh</span></td>
                                        <td class="fc-history-change {{ $toneText[$deltaTone] }}">
                                            {{ $fmtPct($delta) }}
                                        </td>
                                        <td>
                                            @if ($sparklinePath)
                                                <svg viewBox="0 0 120 28" width="240" height="40" class="block" preserveAspectRatio="none" aria-label="{{ $series['label'] }} trendi">
                                                    <path d="{{ $sparklinePath }}" fill="none" style="stroke: var(--color-cat-fixed)" stroke-width="2" vector-effect="non-scaling-stroke" stroke-linecap="round" stroke-linejoin="round" />
                                                </svg>
                                            @else
                                                <span class="text-ink-muted">–</span>
                                            @endif
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>

                    <p class="data-muted-caption max-w-[900px]">
                        @if ($historyPricingBases->contains('observed_seller_data') && $historyPricingBases->contains('canonical_calculation'))
                            Vanhemmat pisteet perustuvat kyseisinä päivinä myyjiltä havaittuihin hintoihin. Uudemmat mediaanihinnat Voltikka laskee myyjiltä kerätyistä sopimus- ja hintatiedoista.
                        @elseif ($historyPricingBases->contains('canonical_calculation'))
                            Pisteet on laskettu myyjiltä kerätyistä sopimus- ja hintatiedoista.
                        @else
                            Pisteet perustuvat kyseisinä päivinä myyjiltä havaittuihin hintoihin.
                        @endif
                    </p>
                </section>
            @endif

            <x-site.interlude art="forecast-interlude" />

            {{-- Methodology, notes and the citation block in one row. The citation names
                 "Voltikan sähkön hintaennuste" so readers know the figure is a model-derived
                 forecast, not a measured market price. --}}
            <section class="fc-method" aria-label="Menetelmä ja lähdemerkintä">
                <div id="menetelma" class="fc-method-col">
                    <x-site.section-heading size="compact" :wave="250" class="data-heading">Miten ennuste lasketaan</x-site.section-heading>
                    <div class="data-prose">
                        <p>
                            Ennuste yhdistää aiemman sopimushintojen kehityksen ja sähköfutuurien viimeaikaiset hintamuutokset. Sähköfutuurien hinnat kuvaavat tulevien kuukausien sähkön tukkuhintaa.
                        </p>
                        <p>
                            Laskemme ennusteen erikseen 6, 12 ja 24 kuukauden sopimuksille. Tyypillisen hinnan lisäksi näet edullisempien ja kalliimpien sopimusten hintatason.
                        </p>
                        <p>
                            Jos sopimushintoja tai futuurihintoja ei ole riittävästi, ennustetta ei näytetä.
                        </p>
                        <p>
                            Edullisempi hintataso (p20) tarkoittaa hintaa, jonka alle jää noin 20 % sopimuksista. Kalliimpi hintataso (p80) on vastaava 80 %:n raja. Ne kuvaavat sopimusten hintaeroja, eivät ennusteen epävarmuusväliä.
                        </p>
                    </div>
                </div>

                <div class="fc-method-col">
                    <x-site.section-heading size="compact" :wave="220" class="data-heading">Tärkeää huomioida</x-site.section-heading>
                    <div class="fc-mark-list">
                        <p class="fc-mark-item">
                            <span class="fc-mark" aria-hidden="true">!</span>
                            <span>Ennuste kertoo markkinoiden hintasuunnasta, ei yksittäisen tarjouksen hinnasta. Pienet muutokset näkyvät muodossa ”suunnilleen ennallaan”.</span>
                        </p>
                        <p class="fc-mark-item">
                            <span class="fc-mark" aria-hidden="true">!</span>
                            <span>Kun vertaat sähkösopimuksia, tarkista energiahinnan lisäksi perusmaksu, sopimuksen kesto ja muut sopimusehdot.</span>
                        </p>
                        <p class="fc-mark-item">
                            <span class="fc-mark" aria-hidden="true">!</span>
                            <span>Ennusteen tietopohja kertoo hintahistorian määrästä, ei ennusteen osumatarkkuudesta.</span>
                        </p>
                    </div>
                </div>

                <div id="viittaa" class="fc-method-col" x-data="voltikkaCite({
                    plain: @js($citations['plain']),
                    markdown: @js($citations['markdown']),
                    html: @js($citations['html']),
                })">
                    <x-site.section-heading size="compact" :wave="180" class="data-heading">Viittaa tähän</x-site.section-heading>
                    <p class="data-cite-text">
                        Käytä alla olevaa lähdemerkintää, jos lainaat tätä ennustetta artikkelissa, tutkimuksessa tai sosiaalisessa mediassa. Aineisto on lisensoitu CC&nbsp;BY&nbsp;4.0.
                    </p>
                    <p class="data-cite-warning">
                        Huomioi, että kyseessä on Voltikan oma mallipohjainen ennuste, ei pörssistä mitattu hinta. Mainitse aina lähteenä "Voltikan sähkön hintaennuste" tai vastaava, jotta lukija tietää luvun olevan ennuste.
                    </p>

                    <x-site.data-cite-formats />
                </div>
            </section>

        @endif
    </article>

    @push('scripts')
        <script>
            window.voltikkaCite = window.voltikkaCite || function ({ plain, markdown, html }) {
                return {
                    active: 'plain',
                    copied: false,
                    formats: { plain, markdown, html },
                    async copy() {
                        try {
                            await navigator.clipboard.writeText(this.formats[this.active]);
                            this.copied = true;
                            setTimeout(() => { this.copied = false; }, 1800);
                        } catch (err) {
                            const textarea = document.createElement('textarea');
                            textarea.value = this.formats[this.active];
                            document.body.appendChild(textarea);
                            textarea.select();
                            try { document.execCommand('copy'); this.copied = true; } catch {}
                            textarea.remove();
                            setTimeout(() => { this.copied = false; }, 1800);
                        }
                    },
                };
            };
        </script>
    @endpush
</div>
