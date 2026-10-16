@php
    use Illuminate\Support\Carbon as Cb;

    $fmtNum = fn ($value, $decimals = 2) => $value === null
        ? '–'
        : number_format((float) $value, $decimals, ',', ' ');

    $fmtPct = function (?float $delta) {
        if ($delta === null) {
            return '–';
        }
        $sign = $delta > 0 ? '+' : ($delta < 0 ? '−' : '±');
        return $sign . number_format(abs($delta), 0, ',', ' ') . ' %';
    };

    // Change colour (design spec §2 "Price and data states"): price up = price-high,
    // price down = saving, about unchanged = ink-body. Same ±0.5 % limits as before.
    $deltaClass = function (?float $delta) {
        if ($delta === null) {
            return 'data-delta--none';
        }
        if ($delta < -0.5) {
            return 'data-delta--down';
        }
        if ($delta > 0.5) {
            return 'data-delta--up';
        }
        return 'data-delta--flat';
    };

    $fiDate = function ($date) {
        if (! $date) return '–';
        return Cb::parse($date)->translatedFormat('j.n.Y');
    };

    // Pricing category of a statistics segment: glyph bucket and category colour
    // (spec §2 "Map colours and pricing categories"). Open-ended contracts have no
    // category glyph; their colour is the dashed ink-muted line of the charts.
    $segmentBucket = fn (?string $key) => match (true) {
        $key === 'spot' => 'porssisahko',
        $key === 'hybrid' => 'kulutusvaikutus',
        in_array($key, ['quarterly', 'market_reset'], true) => 'paivittyva',
        is_string($key) && str_starts_with($key, 'fixed_term') => 'kiintea',
        default => null,
    };
    $segmentColor = fn (?string $key) => match ($segmentBucket($key)) {
        'porssisahko' => 'var(--color-cat-spot)',
        'kulutusvaikutus' => 'var(--color-cat-usage)',
        'paivittyva' => 'var(--color-cat-periodic)',
        'kiintea' => 'var(--color-cat-fixed)',
        default => 'var(--color-ink-muted)',
    };

    // Chart series style by label. Must match inkSeriesStyle() in
    // resources/js/contract-price-statistics.js (the legend swatches mirror the lines).
    $seriesStyle = function (string $label, bool $isIndexTotal = false) {
        return match (true) {
            $isIndexTotal => ['color' => 'var(--color-fox)', 'dash' => false, 'width' => 4],
            (bool) preg_match('/toistaiseksi/iu', $label) => ['color' => 'var(--color-ink-muted)', 'dash' => true, 'width' => 3],
            (bool) preg_match('/pörssi|marginaali/iu', $label) => ['color' => 'var(--color-cat-spot)', 'dash' => false, 'width' => 3],
            (bool) preg_match('/määräaikai/iu', $label) => ['color' => 'var(--color-cat-fixed)', 'dash' => false, 'width' => 3],
            (bool) preg_match('/jousto|hybrid|kulutusvaikut/iu', $label) => ['color' => 'var(--color-cat-usage)', 'dash' => false, 'width' => 3],
            (bool) preg_match('/kvartaali|jaksoittain/iu', $label) => ['color' => 'var(--color-cat-periodic)', 'dash' => false, 'width' => 3],
            (bool) preg_match('/mediaani|markkina/iu', $label) => ['color' => 'var(--color-cat-fixed)', 'dash' => true, 'width' => 3],
            default => ['color' => 'var(--color-ink)', 'dash' => false, 'width' => 3],
        };
    };

    $indexPayload = $sellerSetEnergyPriceIndexPayload ?? ['has_data' => false, 'chart' => ['x' => [], 'series' => []]];
    $hasIndexData = ! empty($indexPayload['has_data']);
    $hasData = $hasIndexData || ! empty($leadChartPayload['x']);
    $isSparse = ($dataWindow['days'] ?? 0) > 0 && ($dataWindow['days'] ?? 0) < 180;
    $consumptionLabel = number_format($consumption, 0, ',', ' ');
    $csvHref = route('contract.price-statistics.csv');
    $indexDelta = $hasIndexData ? $indexPayload['delta_30d_pct'] : null;
@endphp

<div class="data-page">
    <x-schema-markup :schemas="[$jsonLd]" />

    {{-- Hero: the observatory on the hill; the energy price index on the sky. --}}
    <x-site.data-hero art="stats-hero" variant="stats" :tone="\App\Support\Season::heroTone('stats-hero')">
        <nav class="data-crumbs" aria-label="Breadcrumb">
            <ol>
                <li><a href="/" class="vk-focus">Etusivu</a></li>
                <li aria-hidden="true">/</li>
                <li><a href="/sahkosopimus" class="vk-focus">Sähkösopimukset</a></li>
                <li aria-hidden="true">/</li>
                <li aria-current="page">Hintatilastot</li>
            </ol>
        </nav>

        <p class="data-hero-kicker">Voltikka tilastot</p>
        <h1 class="vk-hero-h1 data-hero-h1">
            Sähkösopimusten hintakehitys: mitä suomalaiset oikeasti maksavat sähköstä
        </h1>
        <p class="vk-hero-lead data-hero-lead">
            Voltikka seuraa sähkösopimusten hintakehitystä päivittäin: pörssi-, kvartaali-, määräaikaisia, joustosähkö- ja toistaiseksi voimassa olevia sopimuksia. Tilastot näyttävät, miten energiahinnat, perusmaksut ja vuosikustannukset ovat muuttuneet eri sopimustyypeissä.
        </p>

        {{-- Facts row --}}
        <dl class="data-facts">
            <div>
                <dt>Aineisto</dt>
                <dd>
                    @if ($dataWindow['from'] && $dataWindow['to'])
                        {{ $fiDate($dataWindow['from']) }}&nbsp;&ndash;&nbsp;{{ $fiDate($dataWindow['to']) }}
                    @else
                        Ei dataa
                    @endif
                </dd>
            </div>
            <div>
                <dt>Päivitetty</dt>
                <dd>{{ $latestSnapshotDate ?? '–' }}</dd>
            </div>
            <div>
                <dt>Sopimuksia</dt>
                <dd>{{ $latestSnapshotCount ? number_format($latestSnapshotCount, 0, ',', ' ') : '–' }}</dd>
            </div>
            <div>
                <dt>Sisältää</dt>
                <dd>ALV 25,5 %</dd>
            </div>
        </dl>

        <p class="data-hero-note">
            @if ($latestPricingBasis === 'canonical_calculation')
                Uusin piste perustuu nyt myynnissä olevien sopimusten ehtoihin. Vanhemmat pisteet perustuvat kunakin päivänä kerättyihin tietoihin, eikä niitä muuteta jälkikäteen nykyisillä tiedoilla.
            @else
                Jokainen piste perustuu kyseisenä päivänä kerättyihin sopimushintoihin.
            @endif
        </p>

        @if ($hasIndexData)
            <x-slot:aside>
                <div class="data-sky-figure">
                    <p class="data-sky-kicker">Energianhintaindeksi</p>
                    <p class="data-sky-number-row">
                        <span class="data-sky-number">{{ $fmtNum($indexPayload['current'], 2) }}</span>
                        <span class="data-sky-unit">c/kWh</span>
                    </p>
                    @if ($indexDelta !== null)
                        <p class="data-sky-change {{ $indexDelta > 0.5 ? 'is-up' : ($indexDelta < -0.5 ? 'is-down' : 'is-flat') }}">
                            @if ($indexDelta > 0.5)
                                <svg width="14" height="14" viewBox="0 0 14 14" aria-hidden="true" focusable="false"><path d="M7 12 V2.5 M3 6.5 L7 2.5 L11 6.5" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" /></svg>
                            @elseif ($indexDelta < -0.5)
                                <svg width="14" height="14" viewBox="0 0 14 14" aria-hidden="true" focusable="false"><path d="M7 2 V11.5 M3 7.5 L7 11.5 L11 7.5" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" /></svg>
                            @endif
                            <span>{{ $fmtPct($indexDelta) }} 30 päivässä</span>
                        </p>
                        <x-ink.wave width="200" stroke="2.4" color="currentColor" class="data-sky-wave {{ $indexDelta > 0.5 ? 'is-up' : ($indexDelta < -0.5 ? 'is-down' : 'is-flat') }}" />
                    @else
                        <p class="data-sky-text">Ei vertailupistettä täsmälleen 30 päivän takaa</p>
                    @endif
                </div>
            </x-slot:aside>
        @endif
    </x-site.data-hero>

    <x-page-action-strip />

    <article class="vk-wrap data-body tabular-nums">

        {{-- Controls: the page-level consumption choice (lead chart, callouts and the consumption
             table follow it) and the page actions. --}}
        <div class="data-controls">
            <div class="data-controls-row">
                <span class="data-controls-label">Vuosikulutus</span>
                <div class="flex flex-col items-start gap-2">
                    <div role="group" aria-label="Vuosikulutuksen valinta" class="data-choices">
                        @foreach ($consumptionLevels as $level)
                            <button
                                type="button"
                                wire:click="setConsumption({{ $level }})"
                                wire:loading.attr="disabled"
                                wire:target="setConsumption"
                                class="data-choice {{ $consumption === $level ? 'is-selected' : '' }}"
                                aria-pressed="{{ $consumption === $level ? 'true' : 'false' }}"
                            >
                                @if ($consumption === $level)
                                    <svg class="vk-mark" viewBox="0 0 22 22" aria-hidden="true" focusable="false"><circle cx="11" cy="11" r="9.5" style="fill: var(--color-fox)" /><path d="M6.5 11.5 L9.6 14.3 L15.6 7.8" fill="none" style="stroke: var(--color-snow-white)" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round" /></svg>
                                @else
                                    <svg class="vk-mark" viewBox="0 0 22 22" aria-hidden="true" focusable="false"><circle cx="11" cy="11" r="9" style="fill: var(--color-snow-white); stroke: var(--color-field-edge)" stroke-width="2" /></svg>
                                @endif
                                <span class="data-choice-text">{{ number_format($level, 0, ',', ' ') }}&nbsp;kWh</span>
                            </button>
                        @endforeach
                    </div>
                    <div wire:loading.delay.flex wire:target="setConsumption" class="data-busy hidden" role="status" aria-live="polite">
                        <svg class="h-3.5 w-3.5 animate-spin" viewBox="0 0 24 24" fill="none" aria-hidden="true">
                            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4" />
                            <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v4a4 4 0 00-4 4H4z" />
                        </svg>
                        Päivitetään kulutusta
                    </div>
                </div>
                <span class="data-controls-note">
                    Tyypillinen kulutus: kerrostalokaksio noin 2&nbsp;000, omakotitalo ilman sähkölämmitystä noin 5&nbsp;000, sähkölämmitteinen omakotitalo noin 18&nbsp;000&nbsp;kWh vuodessa.
                </span>
            </div>
            <div class="data-controls-row data-controls-row--links">
                <a href="/sahkosopimus/sahkon-hintaennuste" class="data-link vk-focus">Sähkön hintaennuste →</a>
                <a href="{{ $csvHref }}" class="data-link vk-focus" download>
                    <svg width="16" height="16" viewBox="0 0 16 16" aria-hidden="true" focusable="false"><path d="M8 2v8m0 0l-3-3m3 3l3-3M3 13h10" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" /></svg>
                    Lataa CSV
                </a>
                <a href="#viittaa" data-no-nav-loading class="data-link vk-focus">Viittaa tähän</a>
                <a href="/tietoa#menetelma" class="data-link vk-focus">Näin laskemme</a>
                <span class="data-license">CC&nbsp;BY&nbsp;4.0</span>
            </div>
        </div>

        @if (! $hasData)
            {{-- Empty state. Public-safe; no operator instructions. --}}
            <section class="data-empty">
                <p>
                    Tilastoja ei ole vielä saatavilla. Aineiston keruu on käynnissä, palaa myöhemmin.
                </p>
            </section>
        @else

            @if ($hasIndexData)
                {{-- Seller-set energy-price index: direct General c/kWh, independent of consumption. --}}
                <section class="data-section" aria-labelledby="seller-set-index-heading">
                    <div class="data-section-head">
                        <x-site.section-heading id="seller-set-index-heading" :wave="320" class="data-heading">
                            Sähkösopimusten energianhintaindeksi
                        </x-site.section-heading>
                        <p class="data-section-aside">
                            Indeksi seuraa koko Suomessa saatavilla olevien kotitaloussopimusten suoria yleissähkön energiahintoja. Pörssisähkö ei ole mukana, eikä vuosikulutuksen valinta vaikuta tähän indeksiin.
                        </p>
                    </div>

                    <div class="data-index">
                        <div class="data-index-facts">
                            <div>
                                <p class="data-index-figure">
                                    <span class="data-index-number">{{ $fmtNum($indexPayload['current'], 2) }}</span>
                                    <span class="data-index-unit">c/kWh</span>
                                </p>
                                <p class="data-index-change {{ $deltaClass($indexDelta) }}">
                                    @if ($indexDelta !== null)
                                        @if ($indexDelta > 0.5)
                                            <svg width="14" height="14" viewBox="0 0 14 14" aria-hidden="true" focusable="false"><path d="M7 12 V2.5 M3 6.5 L7 2.5 L11 6.5" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" /></svg>
                                        @elseif ($indexDelta < -0.5)
                                            <svg width="14" height="14" viewBox="0 0 14 14" aria-hidden="true" focusable="false"><path d="M7 2 V11.5 M3 7.5 L7 11.5 L11 7.5" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" /></svg>
                                        @endif
                                        {{ $fmtPct($indexDelta) }} 30 päivässä
                                    @else
                                        Ei vertailupistettä täsmälleen 30 päivän takaa
                                    @endif
                                </p>
                                <p class="data-index-date">
                                    Tilanne {{ $fiDate($indexPayload['as_of']) }}
                                    @if ($indexPayload['comparison_date'])
                                        <span aria-hidden="true"> · </span>Vertailupäivä {{ $fiDate($indexPayload['comparison_date']) }}
                                    @endif
                                </p>
                            </div>

                            <dl class="data-index-counts">
                                <div>
                                    <dt>Ehdot täyttäviä tarjouksia</dt>
                                    <dd>{{ number_format($indexPayload['contract_count'], 0, ',', ' ') }}</dd>
                                </div>
                                <div>
                                    <dt>Myyjiä</dt>
                                    <dd>{{ $indexPayload['supplier_count'] !== null ? number_format($indexPayload['supplier_count'], 0, ',', ' ') : '–' }}</dd>
                                </div>
                            </dl>

                            @php
                                $weights = [
                                    ['label' => 'Määräaikainen', 'key' => 'fixed_term', 'color' => 'var(--color-cat-fixed)'],
                                    ['label' => 'Toistaiseksi', 'key' => 'open_ended', 'color' => 'var(--color-ink-muted)'],
                                    ['label' => 'Jaksoittain vaihtuva', 'key' => 'market_reset', 'color' => 'var(--color-cat-periodic)'],
                                ];
                            @endphp
                            <div class="data-index-block">
                                <p class="data-index-block-title">Kiinteät perhepainot</p>
                                <div class="data-weights" aria-hidden="true">
                                    @foreach ($weights as $weight)
                                        <span style="width: {{ max(0, (float) ($indexPayload['family_weights'][$weight['key']] ?? 0) * 100) }}%; background-color: {{ $weight['color'] }}"></span>
                                    @endforeach
                                </div>
                                <p class="data-weight-labels">
                                    @foreach ($weights as $weight)
                                        <span style="color: {{ $weight['color'] }}">{{ $weight['label'] }} {{ number_format((float) ($indexPayload['family_weights'][$weight['key']] ?? 0) * 100, 0, ',', ' ') }} %</span>
                                    @endforeach
                                </p>
                            </div>

                            @if ($indexPayload['hybrid_base'] !== null)
                                <div class="data-index-block">
                                    <p class="data-index-block-title">Hybridin perushinta, ei mukana indeksissä</p>
                                    <p class="flex flex-wrap items-baseline gap-x-2.5">
                                        <span class="data-index-hybrid">{{ $fmtNum($indexPayload['hybrid_base'], 2) }} c/kWh</span>
                                        @if ($indexPayload['hybrid_contract_count'] !== null && $indexPayload['hybrid_supplier_count'] !== null)
                                            <span class="data-muted-caption">
                                                {{ number_format($indexPayload['hybrid_contract_count'], 0, ',', ' ') }} tarjousta, {{ number_format($indexPayload['hybrid_supplier_count'], 0, ',', ' ') }} myyjää
                                            </span>
                                        @endif
                                    </p>
                                </div>
                            @endif
                        </div>

                        <figure class="data-index-chart">
                            <ul class="data-legend" aria-hidden="true">
                                @foreach ($indexPayload['chart']['series'] as $i => $series)
                                    @php $style = $seriesStyle($series['label'], $i === 0); @endphp
                                    <li class="{{ $i === 0 ? 'is-lead' : '' }}">
                                        <span class="data-swatch {{ $style['dash'] ? 'is-dashed' : '' }}" style="--swatch: {{ $style['color'] }}; --swatch-width: {{ $style['width'] }}px"></span>
                                        <span>{{ $series['label'] }}</span>
                                    </li>
                                @endforeach
                            </ul>
                            <div
                                wire:key="seller-set-index-{{ $indexPayload['as_of'] }}"
                                wire:ignore
                                data-line-chart="seller-set-index"
                                data-chart-skin="ink"
                                data-chart-now="{{ $fmtNum($indexPayload['current'], 2) }}"
                                class="relative h-72 w-full select-none"
                                role="img"
                                aria-label="Sähkösopimusten energianhintaindeksi ja sen kolme sopimusryhmää sentteinä kilowattitunnilta."
                                aria-describedby="seller-set-index-method"
                            >
                                <script type="application/json">{!! json_encode($indexPayload['chart'], JSON_UNESCAPED_UNICODE) !!}</script>
                            </div>
                            <div id="seller-set-index-method" class="data-index-method">
                                <p>
                                    Jokainen sähköyhtiö saa sopimusryhmän sisällä saman painon riippumatta siitä, kuinka monta sopimusta sillä on.
                                </p>
                                <p class="data-muted">
                                    Kokonaisindeksi muodostuu määräaikaisista (50 %), toistaiseksi voimassa olevista (30 %) ja jaksoittain vaihtuvista sopimuksista (20 %). Pörssi-, aika- ja kausisähkö, energiapaketit ja kulutusvaikutussopimukset eivät ole mukana.
                                </p>
                            </div>
                        </figure>
                    </div>

                    <div class="sr-only">
                        <table>
                            <caption>Sähkösopimusten energianhintaindeksi ja sopimusryhmät, c/kWh.</caption>
                            <thead>
                                <tr>
                                    <th>Päivämäärä</th>
                                    @foreach ($indexPayload['chart']['series'] as $series)
                                        <th>{{ $series['label'] }}</th>
                                    @endforeach
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($indexPayload['chart']['x'] as $i => $timestamp)
                                    <tr>
                                        <td>{{ Cb::createFromTimestamp($timestamp)->translatedFormat('j.n.Y') }}</td>
                                        @foreach ($indexPayload['chart']['series'] as $series)
                                            <td>{{ $fmtNum($series['values'][$i] ?? null, 2) }} c/kWh</td>
                                        @endforeach
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </section>
            @endif

            {{-- Lead chart --}}
            <section class="data-section" aria-labelledby="lead-chart-heading">
                <div class="data-section-head">
                    <x-site.section-heading id="lead-chart-heading" :wave="320" class="data-heading">
                        Vuosikustannus {{ $consumptionLabel }}&nbsp;kWh kulutuksella
                    </x-site.section-heading>

                    {{-- Period switcher --}}
                    <div class="flex flex-col items-start gap-2 lg:items-end">
                        <div class="flex items-end gap-4">
                            <span class="data-tabs-label" aria-hidden="true">Näkymä</span>
                            <div role="group" aria-label="Aikajakson valinta" class="data-tabs">
                                @foreach ($periods as $key => $label)
                                    <button
                                        type="button"
                                        wire:click="setPeriod('{{ $key }}')"
                                        wire:loading.attr="disabled"
                                        wire:target="setPeriod"
                                        class="data-tab {{ $period === $key ? 'is-current' : '' }}"
                                        aria-pressed="{{ $period === $key ? 'true' : 'false' }}"
                                    >
                                        <span>{{ $label }}</span>
                                        <x-ink.wave width="full" stroke="2.6" class="data-tab-wave" />
                                    </button>
                                @endforeach
                            </div>
                        </div>
                        <div wire:loading.delay.flex wire:target="setPeriod" class="data-busy hidden" role="status" aria-live="polite">
                            <svg class="h-3.5 w-3.5 animate-spin" viewBox="0 0 24 24" fill="none" aria-hidden="true">
                                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4" />
                                <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v4a4 4 0 00-4 4H4z" />
                            </svg>
                            Päivitetään jaksoa
                        </div>
                    </div>
                </div>

                <p class="data-lead-text">
                    @if ($annualDataIsRetained)
                        Vuosihinnat ovat päivältä {{ $fiDate($annualDataDate) }}. Ne ovat historiallisia arvioita, eivät tämän päivän hintavertailu.
                    @endif
                    Viivat näyttävät kunkin sopimustyypin tyypillisen 12 kuukauden kustannusarvion valitulla kulutuksella. Arvio vastaa kyseisen ajankohdan myynnissä olleita sopimuksia ja sisältää energian sekä perusmaksut.
                    Näkymä: <span class="font-bold text-ink">{{ $periods[$period] ?? $period }}</span>.
                </p>

                {{-- Legend above the chart. Swatches mirror the chart styles. --}}
                <ul class="data-legend" aria-hidden="true">
                    @foreach ($leadChartPayload['series'] as $i => $s)
                        @php $style = $seriesStyle($s['label']); @endphp
                        <li>
                            <span class="data-swatch {{ $style['dash'] ? 'is-dashed' : '' }}" style="--swatch: {{ $style['color'] }}; --swatch-width: {{ $style['width'] }}px"></span>
                            <span class="font-extrabold" style="color: {{ $style['color'] }}">{{ $s['label'] }}</span>
                        </li>
                    @endforeach
                </ul>

                <div class="relative">
                    <div wire:loading.delay.flex wire:target="setPeriod,setConsumption" class="data-chart-busy hidden" aria-hidden="true">
                        <div class="data-busy">
                            <svg class="h-3.5 w-3.5 animate-spin" viewBox="0 0 24 24" fill="none" aria-hidden="true">
                                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4" />
                                <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v4a4 4 0 00-4 4H4z" />
                            </svg>
                            Päivitetään
                        </div>
                    </div>
                    <div
                        wire:key="lead-chart-{{ $period }}-{{ $dataWindow['to'] }}"
                        wire:ignore
                        wire:loading.delay.class="opacity-40"
                        wire:target="setPeriod,setConsumption"
                        data-line-chart
                        data-chart-skin="ink"
                        class="relative w-full h-80 select-none transition-opacity"
                        role="img"
                        aria-label="Vuosikustannus {{ $consumptionLabel }} kWh kulutuksella sopimustyypeittäin, viivakaavio."
                        aria-describedby="lead-chart-description"
                    >
                        <script type="application/json">{!! json_encode($leadChartPayload, JSON_UNESCAPED_UNICODE) !!}</script>
                    </div>
                </div>

                {{-- Now figures: the editorial callouts in one row, inked hairlines between them. --}}
                @if (! empty($callouts))
                    <section class="data-now-figures" aria-label="Hintamuutokset lyhyesti">
                        @foreach ($callouts as $callout)
                            @php $calloutStyle = $seriesStyle($callout['segment_label']); @endphp
                            <div class="data-now-figure">
                                <p class="data-now-label" style="color: {{ $calloutStyle['color'] }}">
                                    {{ $callout['segment_label'] }}
                                </p>
                                <p class="data-now-value">
                                    <span class="data-now-word">Nyt</span>
                                    {{ $fmtNum($callout['current_price'], 2) }}&nbsp;{{ $callout['unit'] }}
                                </p>
                                @php
                                    $calloutParts = [];
                                    if ($callout['delta_30d_pct'] !== null) {
                                        $calloutParts[] = '<span class="'.$deltaClass($callout['delta_30d_pct']).'">'.e($fmtPct($callout['delta_30d_pct'])).'</span> 30 päivässä';
                                    }
                                    if ($callout['delta_since_start_pct'] !== null) {
                                        $calloutParts[] = '<span class="'.$deltaClass($callout['delta_since_start_pct']).'">'.e($fmtPct($callout['delta_since_start_pct'])).'</span> aineiston alusta';
                                    }
                                @endphp
                                @if ($calloutParts)
                                    <p class="data-now-change">
                                        {!! implode(', ', $calloutParts) !!}.
                                    </p>
                                @endif
                            </div>
                        @endforeach
                    </section>
                @endif

                {{-- Editorial caption and chart-reading guide --}}
                <div id="lead-chart-description" class="data-quotes-guide">
                    @if (! empty($caption))
                        <div class="data-quotes">
                            @foreach ($caption as $sentence)
                                <p class="data-quote">
                                    <span class="data-quote-mark" aria-hidden="true">“</span>
                                    <span class="data-quote-text">{{ $sentence }}</span>
                                </p>
                            @endforeach
                        </div>
                    @endif

                    <div class="data-guide" aria-labelledby="chart-reading-heading">
                        <h3 id="chart-reading-heading" class="data-guide-title">
                            Näin luet kuvaajaa
                        </h3>
                        <dl class="data-guide-list">
                            <div>
                                <dt>Katkos viivassa</dt>
                                <dd>Vertailukelpoista hintaa ei ollut saatavilla tai vuosihinnan arviointitapa muuttui. Katkos estää yhdistämästä keskenään erilaisia arvioita.</dd>
                            </div>
                            <div>
                                <dt>Yksittäinen hyppy</dt>
                                <dd>Hyppy ei aina tarkoita yleistä hinnanmuutosta. Se voi syntyä myös siitä, että myynnissä olevien sopimusten joukko muuttuu.</dd>
                            </div>
                            <div>
                                <dt>Uusin piste</dt>
                                <dd>Viimeinen piste näyttää uusimman saatavilla olevan päivän tilanteen. Viikko- ja kuukausinäkymät tasoittavat sitä edeltävää päivittäistä vaihtelua.</dd>
                            </div>
                        </dl>
                        <span class="data-source">
                            Lähde: Voltikka, päivittäin kerätyt sähkösopimukset. Sisältää arvonlisäveron 25,5 %.
                        </span>
                    </div>
                </div>

                {{-- Visually-hidden accessible table mirror of the chart.
                     Wrapper carries `sr-only` (not the table itself) — `display: table`
                     ignores the 1px width and would otherwise overflow the viewport. --}}
                <div class="sr-only">
                    <table>
                        <caption>Vuosikustannus {{ $consumptionLabel }} kWh kulutuksella sopimustyypeittäin (€/v).</caption>
                        <thead>
                            <tr>
                                <th>Päivämäärä tai jakson alku</th>
                                @foreach ($leadChartPayload['series'] as $s)
                                    <th>{{ $s['label'] }}</th>
                                @endforeach
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($leadChartPayload['x'] as $i => $ts)
                                <tr>
                                    <td>{{ Cb::createFromTimestamp($ts)->translatedFormat('j.n.Y') }}</td>
                                    @foreach ($leadChartPayload['series'] as $s)
                                        <td>{{ $fmtNum($s['values'][$i] ?? null, 0) }} €</td>
                                    @endforeach
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </section>

            {{-- Segment table --}}
            <section class="data-section" aria-labelledby="segment-table-heading">
                <x-site.section-heading id="segment-table-heading" :wave="280" class="data-heading">
                    Hinnat sopimustyypeittäin
                </x-site.section-heading>
                <p class="data-lead-text data-lead-text--wide">
                    Taulukko näyttää kunkin sopimustyypin uusimman saatavilla olevan tyypillisen energiahinnan sekä miten hinta on muuttunut aineiston aikana. Jos rivin tiedot ovat muita vanhempia, päivämäärä näkyy sopimustyypin alla. Luvut perustuvat päiväkohtaisiin mediaaneihin, eli yksittäiset poikkeavat tarjoukset eivät ohjaa tulosta. Sopimustyypit, joissa on alle 10 sopimusta, jätetään pois. Sopimuksia-luku kertoo, monenko sopimuksen julkaistusta energiahinnasta rivin luvut on laskettu. Sopimus jää tästä taulukosta pois, jos sen hinnastossa ilmoitettu yksikköhinta koskee vain kampanjajaksoa tai on muuten ristiriidassa sopimusehtojen kanssa: silloin luku ei kertoisi, mitä sopimus maksaa. Näidenkin sopimusten koko vuoden hinta lasketaan kaikista hintajaksoista, joten ne ovat mukana vuosikustannustaulukossa.
                </p>
                <p class="data-dateline">
                    Päivä: <span class="tabular-nums">{{ $latestSnapshotDate ?? $fiDate($dataWindow['to']) }}</span>.
                    Aineisto: <span class="tabular-nums">{{ $fiDate($dataWindow['from']) }}–{{ $fiDate($dataWindow['to']) }}</span>.
                </p>

                <div class="data-table-scroll">
                    <table class="data-table data-table--segments">
                        <thead>
                            <tr>
                                <th scope="col">Sopimustyyppi</th>
                                <th scope="col">Sopimuksia</th>
                                <th scope="col">Energiahinta</th>
                                <th scope="col">Δ&nbsp;30&nbsp;pv</th>
                                <th scope="col">Δ&nbsp;alusta</th>
                                <th scope="col">Perusmaksu</th>
                                <th scope="col">Energiahinnan trendi</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($segmentRows as $row)
                                @php
                                    $bucket = $segmentBucket($row['segment_key'] ?? null);
                                    $color = $segmentColor($row['segment_key'] ?? null);
                                @endphp
                                <tr>
                                    <td>
                                        <span class="data-type">
                                            @if ($bucket)
                                                <x-ink.glyph :bucket="$bucket" size="30" />
                                            @else
                                                <span class="data-type-blank" aria-hidden="true"></span>
                                            @endif
                                            <span class="flex flex-col">
                                                <span class="data-type-name" style="color: {{ $color }}">{{ $row['segment_label'] }}</span>
                                                @if (! $row['is_current'])
                                                    <span class="data-type-note tabular-nums">Aineisto {{ $fiDate($row['latest_observation_date']) }} asti</span>
                                                @elseif ($row['is_spot'])
                                                    <span class="data-type-note">12 kk keskihinta + marginaali</span>
                                                @endif
                                            </span>
                                        </span>
                                    </td>
                                    <td class="data-cell-num">
                                        {{ $row['contract_count'] !== null ? number_format($row['contract_count'], 0, ',', ' ') : '–' }}
                                    </td>
                                    <td>
                                        <span class="data-cell-price">{{ $fmtNum($row['current_price'], 2) }}&nbsp;{{ $row['unit'] }}</span>
                                        @if ($row['is_spot'] && $row['spot_range_p20'] !== null && $row['spot_range_p80'] !== null)
                                            <span class="data-type-note block">
                                                {{ $fmtNum($row['spot_range_p20'], 2) }}–{{ $fmtNum($row['spot_range_p80'], 2) }}&nbsp;{{ $row['unit'] }}
                                            </span>
                                        @endif
                                    </td>
                                    <td class="data-cell-delta {{ $deltaClass($row['delta_30d_pct']) }}">
                                        {{ $fmtPct($row['delta_30d_pct']) }}
                                    </td>
                                    <td class="data-cell-delta {{ $deltaClass($row['delta_since_start_pct']) }}">
                                        {{ $fmtPct($row['delta_since_start_pct']) }}
                                    </td>
                                    <td class="data-cell-num">
                                        @if ($row['monthly_fee'] !== null)
                                            {{ $fmtNum($row['monthly_fee'], 2) }}&nbsp;€/kk
                                        @else
                                            –
                                        @endif
                                    </td>
                                    <td>
                                        @if ($row['sparkline_path'] ?? null)
                                            <svg viewBox="0 0 80 24" width="120" height="36" class="block"
                                                 aria-label="{{ $row['segment_label'] }}, hintatrendi"
                                                 preserveAspectRatio="none">
                                                <path d="{{ $row['sparkline_path'] }}"
                                                      fill="none"
                                                      style="stroke: {{ $color }}"
                                                      stroke-width="1.8"
                                                      @if (! $bucket) stroke-dasharray="4 3" @endif
                                                      vector-effect="non-scaling-stroke"
                                                      stroke-linecap="round"
                                                      stroke-linejoin="round" />
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

                <div class="data-notes">
                    <p>
                        Pörssisähkön energiahinta on viimeisen 12 kuukauden toteutunut päiväkeskiarvo + tyypillinen marginaali. Vaihteluväli näyttää saman 12 kuukauden päivähintojen tavanomaisen vaihtelun. Muiden sopimusten energiahinta on sopimuksen oma julkaistu hinta; aika- ja kausisähköissä se painotetaan tyypilliseksi yleishinnaksi.
                    </p>
                    <p>
                        Trendi näyttää energiahinnan mediaanin kehityksen. Pörssisähkössä trendi seuraa 12 kuukauden liukuvaa päiväkeskiarvoa + tyypillistä marginaalia.
                    </p>
                </div>
            </section>

            {{-- Consumption section: hintahaarukka at the page-level kulutus --}}
            @php
                // One € scale for all range bars (p20–p80 with a median tick), presentation only.
                $rangeValues = collect($consumptionRows)->flatMap(fn ($r) => [$r['p20'] ?? null, $r['p80'] ?? null])->filter(fn ($v) => $v !== null);
                $rangeScale = null;
                if ($rangeValues->isNotEmpty()) {
                    $rangeMinRaw = (float) $rangeValues->min();
                    $rangeMaxRaw = (float) $rangeValues->max();
                    $rangeSpanRaw = max(1.0, $rangeMaxRaw - $rangeMinRaw);
                    $rangeStep = collect([10, 20, 25, 50, 100, 200, 250, 500, 1000, 2000, 5000])
                        ->first(fn ($step) => $rangeSpanRaw / $step <= 4) ?? 10000;
                    $rangeMin = floor($rangeMinRaw / $rangeStep) * $rangeStep;
                    $rangeMax = ceil($rangeMaxRaw / $rangeStep) * $rangeStep;
                    if ($rangeMax - $rangeMin < $rangeStep) {
                        $rangeMax = $rangeMin + $rangeStep;
                    }
                    $rangeScale = [
                        'min' => $rangeMin,
                        'max' => $rangeMax,
                        'ticks' => range($rangeMin, $rangeMax, $rangeStep),
                    ];
                }
                $rangeX = fn (float $value) => $rangeScale
                    ? round(($value - $rangeScale['min']) / ($rangeScale['max'] - $rangeScale['min']) * 100, 2)
                    : 0;
            @endphp
            <section class="data-section" aria-labelledby="consumption-heading">
                <div class="data-section-head">
                    <x-site.section-heading id="consumption-heading" :wave="320" class="data-heading">
                        Hintahaarukka {{ $consumptionLabel }}&nbsp;kWh kulutuksella
                    </x-site.section-heading>
                    <p class="data-see-also">
                        Katso myös:
                        @foreach ($consumptionLevels as $level)
                            @if ($level !== $consumption)
                                <a href="?kulutus={{ $level }}" class="data-link vk-focus">
                                    {{ number_format($level, 0, ',', ' ') }}&nbsp;kWh
                                </a>{{ ! $loop->last ? ' · ' : '' }}
                            @endif
                        @endforeach
                    </p>
                </div>
                <p class="data-lead-text data-lead-text--wide">
                    @if ($annualDataIsRetained)
                        Vuosihinnat ovat päivältä {{ $fiDate($annualDataDate) }}. Ne ovat historiallisia arvioita, eivät tämän päivän hintavertailu.
                    @endif
                    Taulukko näyttää kunkin sopimustyypin uusimman saatavilla olevan vuosikustannusten jakauman. Jos rivin tiedot ovat muita vanhempia, päivämäärä näkyy sopimustyypin alla. Laskenta käyttää valittua {{ $consumptionLabel }}&nbsp;kWh vuosikulutusta ja sisältää energiahinnan sekä perusmaksut 12 kuukaudelta. Sopimustyypit, joissa on alle 10 sopimusta, jätetään pois.
                </p>
                <p class="data-dateline">
                    Päivä: <span class="tabular-nums">{{ $latestSnapshotDate ?? $fiDate($dataWindow['to']) }}</span>.
                    Mukana ovat sopimukset, joille voidaan laskea vuosikustannus valitulla {{ $consumptionLabel }}&nbsp;kWh kulutuksella.
                </p>

                <div class="data-table-scroll">
                    <table class="data-table data-table--range">
                        <caption class="sr-only">Vuosikustannus {{ $consumptionLabel }} kWh kulutuksella sopimustyypeittäin.</caption>
                        <thead>
                            <tr>
                                <th scope="col">Sopimustyyppi</th>
                                <th scope="col">
                                    <abbr title="20. persentiili: viidennes sopimuksista on tätä halvempia." class="no-underline cursor-help">Halvempi&nbsp;20&nbsp;%</abbr>
                                </th>
                                <th scope="col">
                                    <abbr title="Mediaani: tasan puolet sopimuksista on tätä halvempia ja puolet kalliimpia. Robustimpi kuin keskiarvo, koska yksittäiset poikkeavat sopimukset eivät vinouta sitä." class="no-underline cursor-help">Mediaani</abbr>
                                </th>
                                <th scope="col">
                                    <abbr title="80. persentiili: viidennes sopimuksista on tätä kalliimpia." class="no-underline cursor-help">Kalliimpi&nbsp;20&nbsp;%</abbr>
                                </th>
                                <th scope="col" class="data-range-col">
                                    @if ($rangeScale)
                                        <span class="data-range-axis" aria-hidden="true">
                                            @foreach ($rangeScale['ticks'] as $tick)
                                                <span style="left: {{ $rangeX($tick) }}%">{{ number_format($tick, 0, ',', ' ') }}&nbsp;€</span>
                                            @endforeach
                                        </span>
                                    @endif
                                </th>
                                <th scope="col">Sopimuksia</th>
                                <th scope="col">Vuosihinnan trendi</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($consumptionRows as $row)
                                @php
                                    $bucket = $segmentBucket($row['segment_key'] ?? null);
                                    $color = $segmentColor($row['segment_key'] ?? null);
                                @endphp
                                <tr>
                                    <td>
                                        <span class="data-type">
                                            @if ($bucket)
                                                <x-ink.glyph :bucket="$bucket" size="28" />
                                            @else
                                                <span class="data-type-blank data-type-blank--small" aria-hidden="true"></span>
                                            @endif
                                            <span class="flex flex-col">
                                                <span class="data-type-name" style="color: {{ $color }}">{{ $row['segment_label'] }}</span>
                                                @if (! $row['is_current'])
                                                    <span class="data-type-note tabular-nums">Aineisto {{ $fiDate($row['latest_observation_date']) }} asti</span>
                                                @endif
                                            </span>
                                        </span>
                                    </td>
                                    <td class="data-cell-num">{{ $fmtNum($row['p20'], 0) }}&nbsp;€</td>
                                    <td><span class="data-cell-price">{{ $fmtNum($row['median'], 0) }}&nbsp;€</span></td>
                                    <td class="data-cell-num">{{ $fmtNum($row['p80'], 0) }}&nbsp;€</td>
                                    <td class="data-range-col">
                                        @if ($rangeScale && $row['p20'] !== null && $row['p80'] !== null && $row['median'] !== null)
                                            <span class="data-range" aria-hidden="true" style="--range-color: {{ $color }}">
                                                @foreach ($rangeScale['ticks'] as $tick)
                                                    <span class="data-range-grid" style="left: {{ $rangeX($tick) }}%"></span>
                                                @endforeach
                                                <span class="data-range-bar" style="left: {{ $rangeX((float) $row['p20']) }}%; width: {{ max(0.6, $rangeX((float) $row['p80']) - $rangeX((float) $row['p20'])) }}%"></span>
                                                <span class="data-range-median" style="left: {{ $rangeX((float) $row['median']) }}%"></span>
                                            </span>
                                        @endif
                                    </td>
                                    <td class="data-cell-num">{{ number_format($row['contract_count'], 0, ',', ' ') }}</td>
                                    <td>
                                        @if ($row['sparkline_path'] ?? null)
                                            <svg viewBox="0 0 80 24" width="110" height="32" class="block"
                                                 aria-label="{{ $row['segment_label'] }}, vuosihinnan trendi"
                                                 preserveAspectRatio="none">
                                                <path d="{{ $row['sparkline_path'] }}"
                                                      fill="none"
                                                      style="stroke: {{ $color }}"
                                                      stroke-width="1.8"
                                                      @if (! $bucket) stroke-dasharray="4 3" @endif
                                                      vector-effect="non-scaling-stroke"
                                                      stroke-linecap="round"
                                                      stroke-linejoin="round" />
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

                <div class="data-notes">
                    <div class="flex flex-col gap-2">
                        @if ($rangeScale)
                            <p class="data-range-key" aria-hidden="true">
                                <svg width="64" height="20" viewBox="0 0 64 20" focusable="false"><rect x="8" y="6" width="44" height="8" style="fill: color-mix(in srgb, var(--color-cat-fixed) 32%, transparent); stroke: var(--color-cat-fixed)" /><path d="M24 2 V18" style="stroke: var(--color-ink)" stroke-width="2.5" stroke-linecap="round" /></svg>
                                <span>Palkki = halvempi 20 % – kalliimpi 20 %, viiva = mediaani</span>
                            </p>
                        @endif
                        <p>
                            Halvempi&nbsp;20&nbsp;% on raja, jonka alle viidennes saman tyypin sopimuksista jää. Mediaani kuvaa tyypillistä sopimusta, ja kalliimpi&nbsp;20&nbsp;% on raja, jonka yli viidennes nousee.
                        </p>
                    </div>
                    <p>
                        @if ($activeAnnualIsAsOf)
                            Jokainen päivä käyttää vain silloin saatavilla olleita tietoja. Tulevia hintoja arvioidaan vain silloin, kun sopimuksen ehdot antavat siihen riittävät tiedot. Jos vertailukelpoista vuosihintaa ei voida muodostaa, sopimus jää kyseisen päivän tilastosta pois.
                        @else
                            Pörssisähkön nykyinen vuosikustannus käyttää tulevan 12 kuukauden tukkumarkkinan ennakkohintoja, historiallista päivä–yö-eroa ja sopimuksen marginaalia. Historialliset Spot-vuosikustannukset käyttävät kyseisestä päivästä taaksepäin laskettua 12 kuukauden toteutunutta pörssitasoa. Kuukausi- ja kvartaalihinnoissa sekä hinnaltaan muutettavissa toistaiseksi voimassa olevissa yleissähkösopimuksissa tulevat kuukaudet ovat arvioita. Muissa kiinteissä sopimuksissa käytetään julkaistuja hintoja. Trendi näyttää vuosikustannuksen mediaanin kehityksen valitulla kulutuksella.
                        @endif
                        Sopimusmäärä voi poiketa ylemmästä hintataulukosta kumpaankin suuntaan: sopimus jää pois, jos se ei ole tarjolla valitulle kulutukselle tai vuosihintaa ei voi laskea, ja mukana on myös sopimuksia, joiden ilmoitettua yksikköhintaa ei voi julkaista energiahintana mutta joiden koko vuoden hinta voidaan laskea hintajaksoista.
                    </p>
                </div>
            </section>

            {{-- Per-segment deep dives (round 3, Paper "R3 · Data · Tilastot"): every contract type
                 in the same cell anatomy, two cells per row from 1024px, one column below. --}}
            @if (! empty($deepDivePayloads))
                @php
                    // Headline tone: "down" is cheaper (saving), "up" is a price rise (price-high).
                    $toneClass = [
                        'down' => 'data-delta--down',
                        'up' => 'data-delta--up',
                        'neutral' => 'data-delta--ink',
                    ];
                @endphp

                <section class="data-section data-section--roomy" aria-labelledby="deepdive-heading">
                    <x-site.section-heading id="deepdive-heading" :wave="320" class="data-heading">
                        Tarkemmin sopimustyypeittäin
                    </x-site.section-heading>
                    <p class="data-lead-text data-lead-text--intro">
                        Kaavioissa viiva näyttää energiahinnan mediaanin ja vaalea alue tavanomaisen vaihteluvälin. Pörssisähkössä viiva on 12 kuukauden liukuva keskiarvo toteutuneista päivähinnoista + tyypillinen marginaali; vaalea alue näyttää saman liukuvan 12 kuukauden jakson päivähintojen vaihteluvälin. Muissa sopimustyypeissä viiva ja alue perustuvat sopimusten julkaistuihin energiahintoihin.
                    </p>

                    {{-- In-page contents: inked anchors to each contract type. --}}
                    <nav aria-label="Sopimustyypit" class="data-toc">
                        @foreach ($deepDivePayloads as $dive)
                            <a href="#{{ $dive['anchor'] }}" class="data-toc-link vk-focus">
                                {{ $dive['segment_label'] }}
                                @if ($dive['quotable'] && in_array($dive['quotable']['tone'], ['up', 'down'], true))
                                    <span class="data-toc-change {{ $toneClass[$dive['quotable']['tone']] }}">
                                        {{ $dive['quotable']['headline'] }}
                                    </span>
                                @endif
                            </a>
                        @endforeach
                    </nav>

                    <div class="relative">
                        <div wire:loading.delay.flex wire:target="setPeriod,setConsumption" class="absolute inset-x-0 top-0 z-10 hidden justify-center pt-4" role="status" aria-live="polite">
                            <div class="data-busy data-busy--float">
                                <svg class="h-3.5 w-3.5 animate-spin" viewBox="0 0 24 24" fill="none" aria-hidden="true">
                                    <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4" />
                                    <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v4a4 4 0 00-4 4H4z" />
                                </svg>
                                Päivitetään sopimustyyppejä
                            </div>
                        </div>
                        <div wire:loading.delay.class="opacity-50" wire:target="setPeriod,setConsumption" class="data-types transition-opacity">
                            @foreach ($deepDivePayloads as $dive)
                                @php $diveColor = $segmentColor($dive['segment_key']); @endphp
                                <article id="{{ $dive['anchor'] }}" class="data-type-cell scroll-mt-24">
                                    <header class="data-type-head">
                                        <h3 class="data-type-title">{{ $dive['heading'] }}</h3>
                                        @if ($dive['contract_count'] !== null)
                                            <span class="data-type-count" style="color: {{ $diveColor }}">{{ number_format($dive['contract_count'], 0, ',', ' ') }} sopimusta</span>
                                        @elseif (! empty($dive['latest_observation_date']))
                                            <span class="data-type-count tabular-nums">Aineisto {{ $fiDate($dive['latest_observation_date']) }} asti</span>
                                        @endif
                                    </header>

                                    @if (! $dive['has_data'])
                                        @if ($dive['description'])
                                            <p class="data-type-note">{{ $dive['description'] }}</p>
                                        @endif
                                        <p class="data-muted-caption">Aineistoa ei vielä riitä tämän sopimustyypin trendin näyttämiseen.</p>
                                    @else
                                        <x-site.data-dive-stats :dive="$dive" />

                                        <figure class="data-type-chart">
                                            <figcaption class="data-type-chart-head">
                                                <span>{{ $dive['is_spot'] ? 'Energiahinta (12 ka.) & vaihteluväli' : 'Mediaani & tavanomainen vaihteluväli' }}</span>
                                                <span class="data-type-chart-unit">{{ $dive['unit'] }}</span>
                                            </figcaption>
                                            <div wire:key="dive-{{ $dive['anchor'] }}-{{ $period }}-{{ $dataWindow['to'] }}"
                                                 wire:ignore
                                                 data-line-chart="{{ $dive['segment_key'] }}"
                                                 data-chart-skin="ink"
                                                 data-chart-unit-badge="off"
                                                 data-chart-end="{{ $fmtNum($dive['current'], 2) }}"
                                                 class="data-type-plot"
                                                 role="img"
                                                 aria-label="{{ $dive['segment_label'] }} hinnan kehitys ja markkinahaarukka aikajaksoittain.">
                                                <script type="application/json">{!! json_encode($dive['chart'], JSON_UNESCAPED_UNICODE) !!}</script>
                                            </div>
                                        </figure>

                                        <x-site.data-dive-quote :dive="$dive" :citation="$citations['plain']" :description="$dive['description']">
                                            @if ($dive['is_spot'] && ! empty($spotMarginPayload['x']))
                                                <figure class="data-type-margin">
                                                    <figcaption class="data-type-chart-head">
                                                        <span class="data-type-margin-title">Marginaali, mediaani</span>
                                                        <span class="data-type-chart-unit">c/kWh</span>
                                                    </figcaption>
                                                    <div wire:key="spot-margin-{{ $period }}-{{ $dataWindow['to'] }}"
                                                         wire:ignore
                                                         data-line-chart="spot-margin"
                                                         data-chart-skin="ink"
                                                         data-chart-unit-badge="off"
                                                         data-chart-end=""
                                                         class="data-type-margin-plot"
                                                         role="img"
                                                         aria-label="Pörssisähkön supplier-marginaalin mediaani aikajaksoittain.">
                                                        <script type="application/json">{!! json_encode($spotMarginPayload, JSON_UNESCAPED_UNICODE) !!}</script>
                                                    </div>
                                                </figure>
                                            @endif
                                        </x-site.data-dive-quote>
                                    @endif
                                </article>
                            @endforeach
                        </div>
                    </div>
                </section>
            @endif

            <x-site.interlude art="stats-interlude" />

            {{-- Methodology + citation --}}
            <section id="viittaa" class="data-method">
                <div class="data-method-main">
                    <x-site.section-heading :wave="260" class="data-heading">Mistä luvut tulevat</x-site.section-heading>
                    <div class="data-prose">
                        <p>
                            Voltikka kerää sähkösopimusten lähdetiedot päivittäin. Nykyisen päivän julkiset hinta-arvot lasketaan validoidusta kanonisesta hinnoittelusta. Jos kanonisella tuloksella ei ole merkityksellistä yksikköhintaa, kenttä jätetään tyhjäksi. Esimerkiksi energiapaketin ylityshintaa ei esitetä paketin keskimääräisenä energiahintana.
                        </p>
                        <p>
                            Historiallinen takaisin täyttö käyttää kyseisenä päivänä havaittuja myyjän hintakomponentteja. Tämän päivän kanonista tulkintaa ei käytetä vanhan havainnon muuttamiseen. CSV-tiedoston <code>pricing_basis</code>-sarake erottaa kanonisen laskelman ja havaitun myyjäaineiston. Hinnat sisältävät arvonlisäveron 25,5 %.
                        </p>
                        <p>
                            Pörssipohjaisille sopimuksille käytetään kahta eri näkymää. Sopimustyyppien c/kWh-taulukko ja historialliset kuvaajat näyttävät viimeisen 12 kuukauden toteutuneen päiväkeskiarvon + tyypillisen marginaalin. P20–P80-väli lasketaan saman jakson päivähinnoista.
                            @if ($activeAnnualIsAsOf)
                                Vuosikustannussarja on erillinen as-of-laskelma. Jokainen päivä käyttää kyseisen päivän ennakkohintakäyrää, toteutunutta päivä–yö-eroa sekä sopimuksen silloin julkaistua marginaalia ja perusmaksua. Puuttuva täysi ennakkohintajakso käyttää erikseen merkittyä toteutunutta 12 kuukauden tasoa.
                            @else
                                Nykyinen kanoninen vuosikustannus käyttää sen sijaan tulevan 12 kuukauden tukkumarkkinan ennakkohintoja, toteutunutta päivä–yö-eroa ja sopimuksen tarkkaa marginaalia sekä perusmaksua. Jos koko ennakkohintajaksoa ei voi käyttää, vuosikustannus palaa erikseen merkittyyn toteutuneen 12 kuukauden tasoon.
                            @endif
                        </p>
                        <p>
                            Vuosikustannukset ovat kyseisen päivän tiedoilla laskettuja 12 kuukauden arvioita. Vanhemman laskentatavan luvut voivat poiketa nykyisestä sopimusvertailusta. Laskelma huomioi hinnoitteluvaiheet, tarjoukset, aika- ja kausihinnat, pörssimarginaalit, markkinahinnan päivitykset ja kuukausittaiset energiapaketit silloin, kun ne koskevat sopimusta.
                        </p>
                        <p>
                            Vuosikustannukset 2&nbsp;000, 5&nbsp;000 ja 18&nbsp;000&nbsp;kWh kulutuksilla sisältävät kaikki kanonisessa tuloksessa laskettavat energia- ja kuukausimaksut. Puuttuva tai poissuljettu tulos jätetään pois tilastosta, eikä sitä täytetä havaitulla komponenttihinnalla.
                        </p>
                        <p>
                            Tämän sivun keskiluku on mediaani: kuvaa tyypillistä sopimusta paremmin kuin keskiarvo, koska yksittäiset poikkeavat tarjoukset tai virheellinen aineisto eivät vinouta sitä. Hintahaarukan rajat ovat 20.&nbsp;ja 80.&nbsp;persentiilit: halvempi&nbsp;20&nbsp;% on raja jonka alle viidennes saman tyypin sopimuksista jää, ja kalliimpi&nbsp;20&nbsp;% on raja jonka yli viidennes nousee.
                        </p>
                        <p>
                            Aineisto alkaa {{ $fiDate($dataWindow['from']) }}. Kuvaajaan jätetään katkos, jos päivästä ei saada vertailukelpoista hintaa tai vuosihinnan arviointitapa muuttuu. Katkoksia ei yhdistetä keinotekoisesti.
                        </p>
                    </div>
                </div>

                <div class="data-cite" x-data="voltikkaCite({
                    plain: @js($citations['plain']),
                    markdown: @js($citations['markdown']),
                    html: @js($citations['html']),
                })">
                    <x-site.section-heading :wave="200" class="data-heading">Viittaa tähän</x-site.section-heading>
                    <p class="data-cite-text">
                        Käytä alla olevaa lähdemerkintää, jos lainaat tilastoa artikkelissa, tutkimuksessa tai sosiaalisessa mediassa. Aineisto on lisensoitu CC&nbsp;BY&nbsp;4.0.
                    </p>

                    <x-site.data-cite-formats />

                    <div class="pt-2.5">
                        <a href="{{ $csvHref }}" class="data-link vk-focus" download>
                            <svg width="16" height="16" viewBox="0 0 16 16" aria-hidden="true" focusable="false"><path d="M8 2v8m0 0l-3-3m3 3l3-3M3 13h10" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" /></svg>
                            Lataa koko aineisto CSV-muodossa
                        </a>
                    </div>
                </div>
            </section>

        @endif
    </article>

    @push('scripts')
        @vite('resources/js/contract-price-statistics.js')
        <script>
            window.voltikkaQuote = function ({ sentence, citation, segment }) {
                const text = citation ? `${sentence}\n\n${citation}` : sentence;
                return {
                    copied: false,
                    async copy() {
                        try {
                            await navigator.clipboard.writeText(text);
                        } catch (err) {
                            const textarea = document.createElement('textarea');
                            textarea.value = text;
                            document.body.appendChild(textarea);
                            textarea.select();
                            try { document.execCommand('copy'); } catch {}
                            textarea.remove();
                        }
                        if (typeof window.voltikkaTrack === 'function') {
                            window.voltikkaTrack('Quote Copied', { props: { segment: segment || 'unknown' } });
                        }
                        this.copied = true;
                        setTimeout(() => { this.copied = false; }, 1800);
                    },
                };
            };
            window.voltikkaCite = function ({ plain, markdown, html }) {
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
