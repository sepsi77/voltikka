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

    $deltaClass = function (?float $delta) {
        if ($delta === null) {
            return 'text-slate-400';
        }
        if ($delta < -0.5) {
            return 'text-emerald-700';
        }
        if ($delta > 0.5) {
            return 'text-slate-700';
        }
        return 'text-slate-500';
    };

    $fiDate = function ($date) {
        if (! $date) return '–';
        return Cb::parse($date)->translatedFormat('j.n.Y');
    };

    $indexPayload = $sellerSetEnergyPriceIndexPayload ?? ['has_data' => false, 'chart' => ['x' => [], 'series' => []]];
    $hasIndexData = ! empty($indexPayload['has_data']);
    $hasData = $hasIndexData || ! empty($leadChartPayload['x']);
    $isSparse = ($dataWindow['days'] ?? 0) > 0 && ($dataWindow['days'] ?? 0) < 180;
    $consumptionLabel = number_format($consumption, 0, ',', ' ');
    $csvHref = route('contract.price-statistics.csv');
@endphp

<div class="bg-white">
    <x-schema-markup :schemas="[$jsonLd]" />

    <article class="mx-auto max-w-[68rem] px-4 sm:px-6 lg:px-8 pb-24">

        {{-- Breadcrumb --}}
        <nav class="pt-8 pb-6" aria-label="Breadcrumb">
            <ol class="flex items-center gap-2 text-xs text-slate-500">
                <li><a href="/" class="hover:text-slate-900">Etusivu</a></li>
                <li aria-hidden="true" class="text-slate-300">/</li>
                <li><a href="/sahkosopimus" class="hover:text-slate-900">Sähkösopimukset</a></li>
                <li aria-hidden="true" class="text-slate-300">/</li>
                <li class="text-slate-900 font-medium" aria-current="page">Hintatilastot</li>
            </ol>
        </nav>

        {{-- Editorial header strip --}}
        <header class="border-b border-slate-200 pb-10">
            <p class="text-[11px] font-semibold tracking-[0.16em] uppercase text-slate-500 mb-4">
                Voltikka tilastot
            </p>
            <h1 class="text-3xl md:text-5xl font-extrabold text-slate-900 leading-[1.05] tracking-tight max-w-[28ch]">
                Sähkösopimusten hintakehitys: mitä suomalaiset oikeasti maksavat sähköstä
            </h1>
            <p class="mt-5 max-w-[62ch] text-lg text-slate-600 leading-relaxed">
                Voltikka seuraa sähkösopimusten hintakehitystä päivittäin: pörssi-, kvartaali-, määräaikaisia, joustosähkö- ja toistaiseksi voimassa olevia sopimuksia. Tilastot näyttävät, miten energiahinnat, perusmaksut ja vuosikustannukset ovat muuttuneet eri sopimustyypeissä.
            </p>

            {{-- Meta strip --}}
            <dl class="mt-8 grid grid-cols-2 sm:grid-cols-4 gap-x-6 gap-y-4 text-sm">
                <div>
                    <dt class="text-[11px] font-semibold tracking-[0.12em] uppercase text-slate-400">Aineisto</dt>
                    <dd class="mt-1 font-semibold text-slate-900 tabular-nums">
                        @if ($dataWindow['from'] && $dataWindow['to'])
                            {{ $fiDate($dataWindow['from']) }}&nbsp;&ndash;&nbsp;{{ $fiDate($dataWindow['to']) }}
                        @else
                            <span class="font-medium text-slate-500">Ei dataa</span>
                        @endif
                    </dd>
                </div>
                <div>
                    <dt class="text-[11px] font-semibold tracking-[0.12em] uppercase text-slate-400">Päivitetty</dt>
                    <dd class="mt-1 font-semibold text-slate-900 tabular-nums">
                        {{ $latestSnapshotDate ?? '–' }}
                    </dd>
                </div>
                <div>
                    <dt class="text-[11px] font-semibold tracking-[0.12em] uppercase text-slate-400">Sopimuksia</dt>
                    <dd class="mt-1 font-semibold text-slate-900 tabular-nums">
                        {{ $latestSnapshotCount ? number_format($latestSnapshotCount, 0, ',', ' ') : '–' }}
                    </dd>
                </div>
                <div>
                    <dt class="text-[11px] font-semibold tracking-[0.12em] uppercase text-slate-400">Sisältää</dt>
                    <dd class="mt-1 font-semibold text-slate-900">ALV 25,5 %</dd>
                </div>
            </dl>

            <p class="mt-5 max-w-[72ch] text-sm text-slate-500 leading-relaxed">
                @if ($latestPricingBasis === 'canonical_calculation')
                    Uusin piste perustuu nyt myynnissä olevien sopimusten ehtoihin. Vanhemmat pisteet perustuvat kunakin päivänä kerättyihin tietoihin, eikä niitä muuteta jälkikäteen nykyisillä tiedoilla.
                @else
                    Jokainen piste perustuu kyseisenä päivänä kerättyihin sopimushintoihin.
                @endif
            </p>

            <div class="mt-7 flex flex-wrap items-center gap-x-5 gap-y-2 text-sm">
                <a href="/sahkosopimus/sahkon-hintaennuste"
                   class="inline-flex items-center gap-2 font-semibold text-slate-900 underline decoration-slate-300 decoration-2 underline-offset-4 hover:decoration-coral-500">
                    <svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                        <path d="M3 17l6-6 4 4 8-8M14 7h7v7"/>
                    </svg>
                    Sähkön hintaennuste
                </a>
                <a href="{{ $csvHref }}"
                   class="inline-flex items-center gap-2 font-semibold text-slate-900 underline decoration-slate-300 decoration-2 underline-offset-4 hover:decoration-coral-500"
                   download>
                    <svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                        <path d="M12 4v12m0 0l-4-4m4 4l4-4M4 20h16"/>
                    </svg>
                    Lataa CSV
                </a>
                <a href="#viittaa"
                   data-no-nav-loading
                   class="inline-flex items-center gap-2 font-semibold text-slate-900 underline decoration-slate-300 decoration-2 underline-offset-4 hover:decoration-coral-500">
                    <svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                        <path d="M7 7h3v3H7zM14 7h3v3h-3zM7 14h3v3H7zM14 14h3v3h-3z"/>
                    </svg>
                    Viittaa tähän
                </a>
                <a href="/tietoa#menetelma"
                   class="inline-flex items-center gap-2 font-semibold text-slate-900 underline decoration-slate-300 decoration-2 underline-offset-4 hover:decoration-coral-500">
                    <svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                        <path d="M12 9v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
                    </svg>
                    Näin laskemme
                </a>
                <span class="text-slate-400">CC&nbsp;BY&nbsp;4.0</span>
            </div>

            {{-- Page-level consumption switcher: lead chart, callouts, and consumption table all reflect this --}}
            <div class="mt-8 flex flex-wrap items-center gap-x-5 gap-y-3">
                <span class="text-[11px] font-semibold tracking-[0.16em] uppercase text-slate-500">
                    Vuosikulutus
                </span>
                <div class="flex flex-col items-start gap-2">
                    <div role="group" aria-label="Vuosikulutuksen valinta" class="inline-flex bg-slate-50 border border-slate-200 rounded-lg p-0.5 tabular-nums">
                        @foreach ($consumptionLevels as $level)
                            <button
                                type="button"
                                wire:click="setConsumption({{ $level }})"
                                wire:loading.attr="disabled"
                                wire:target="setConsumption"
                                class="px-3.5 py-1.5 rounded-md text-xs font-semibold tracking-wide transition-colors disabled:cursor-wait disabled:opacity-60 {{ $consumption === $level ? 'bg-slate-900 text-white' : 'text-slate-600 hover:text-slate-900' }}"
                                aria-pressed="{{ $consumption === $level ? 'true' : 'false' }}"
                            >
                                {{ number_format($level, 0, ',', ' ') }}&nbsp;kWh
                            </button>
                        @endforeach
                    </div>
                    <div wire:loading.delay.flex wire:target="setConsumption" class="hidden items-center gap-2 text-xs font-semibold text-slate-500" role="status" aria-live="polite">
                        <svg class="h-3.5 w-3.5 animate-spin text-slate-400" viewBox="0 0 24 24" fill="none" aria-hidden="true">
                            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4" />
                            <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v4a4 4 0 00-4 4H4z" />
                        </svg>
                        Päivitetään kulutusta
                    </div>
                </div>
                <span class="text-xs text-slate-500 max-w-[40ch]">
                    Tyypillinen kulutus: kerrostalokaksio noin 2&nbsp;000, omakotitalo ilman sähkölämmitystä noin 5&nbsp;000, sähkölämmitteinen omakotitalo noin 18&nbsp;000&nbsp;kWh vuodessa.
                </span>
            </div>
        </header>

        <x-page-action-strip class="mb-12" />

        @if (! $hasData)
            {{-- Empty state. Public-safe; no operator instructions. --}}
            <section class="border border-slate-200 rounded-2xl px-8 py-16 text-center">
                <p class="text-base text-slate-700 max-w-[40ch] mx-auto">
                    Tilastoja ei ole vielä saatavilla. Aineiston keruu on käynnissä, palaa myöhemmin.
                </p>
            </section>
        @else

            @if ($hasIndexData)
                {{-- Seller-set energy-price index: direct General c/kWh, independent of consumption. --}}
                <section class="mb-20 border-b border-slate-200 pb-20" aria-labelledby="seller-set-index-heading">
                    <div class="grid gap-8 lg:grid-cols-[minmax(0,1fr)_minmax(18rem,23rem)] lg:gap-14">
                        <div>
                            <h2 id="seller-set-index-heading" class="text-2xl font-bold tracking-tight text-slate-900 md:text-3xl">
                                Sähkösopimusten energianhintaindeksi
                            </h2>
                            <p class="mt-3 max-w-[68ch] text-base leading-relaxed text-slate-600">
                                Indeksi seuraa koko Suomessa saatavilla olevien kotitaloussopimusten suoria yleissähkön energiahintoja. Pörssisähkö ei ole mukana, eikä vuosikulutuksen valinta vaikuta tähän indeksiin.
                            </p>

                            <div class="mt-8 flex flex-wrap items-baseline gap-x-5 gap-y-2">
                                <p class="text-5xl font-extrabold leading-none tracking-tight text-slate-900 tabular-nums">
                                    {{ $fmtNum($indexPayload['current'], 2) }}<span class="ml-2 text-base font-semibold tracking-normal text-slate-500">c/kWh</span>
                                </p>
                                <p class="text-base font-semibold text-slate-700 tabular-nums">
                                    @if ($indexPayload['delta_30d_pct'] !== null)
                                        {{ $fmtPct($indexPayload['delta_30d_pct']) }} 30 päivässä
                                    @else
                                        Ei vertailupistettä täsmälleen 30 päivän takaa
                                    @endif
                                </p>
                            </div>
                            <p class="mt-3 text-sm text-slate-500 tabular-nums">
                                Tilanne {{ $fiDate($indexPayload['as_of']) }}
                                @if ($indexPayload['comparison_date'])
                                    <span aria-hidden="true"> · </span>Vertailupäivä {{ $fiDate($indexPayload['comparison_date']) }}
                                @endif
                            </p>
                        </div>

                        <dl class="grid grid-cols-2 gap-x-6 gap-y-6 border-t border-slate-200 pt-5 lg:border-t-0 lg:border-l lg:pl-8 lg:pt-0">
                            <div>
                                <dt class="text-sm font-semibold text-slate-500">Ehdot täyttäviä tarjouksia</dt>
                                <dd class="mt-1 text-2xl font-bold text-slate-900 tabular-nums">{{ number_format($indexPayload['contract_count'], 0, ',', ' ') }}</dd>
                            </div>
                            <div>
                                <dt class="text-sm font-semibold text-slate-500">Myyjiä</dt>
                                <dd class="mt-1 text-2xl font-bold text-slate-900 tabular-nums">
                                    {{ $indexPayload['supplier_count'] !== null ? number_format($indexPayload['supplier_count'], 0, ',', ' ') : '–' }}
                                </dd>
                            </div>
                            <div class="col-span-2">
                                <dt class="text-sm font-semibold text-slate-500">Kiinteät perhepainot</dt>
                                <dd class="mt-2 flex flex-wrap gap-x-4 gap-y-1 text-sm text-slate-700 tabular-nums">
                                    <span>Määräaikainen {{ number_format((float) ($indexPayload['family_weights']['fixed_term'] ?? 0) * 100, 0, ',', ' ') }} %</span>
                                    <span>Toistaiseksi {{ number_format((float) ($indexPayload['family_weights']['open_ended'] ?? 0) * 100, 0, ',', ' ') }} %</span>
                                    <span>Jaksoittain vaihtuva {{ number_format((float) ($indexPayload['family_weights']['market_reset'] ?? 0) * 100, 0, ',', ' ') }} %</span>
                                </dd>
                            </div>
                            @if ($indexPayload['hybrid_base'] !== null)
                                <div class="col-span-2 border-t border-slate-200 pt-5">
                                    <dt class="text-sm font-semibold text-slate-500">Hybridin perushinta, ei mukana indeksissä</dt>
                                    <dd class="mt-1 text-xl font-bold text-slate-900 tabular-nums">
                                        {{ $fmtNum($indexPayload['hybrid_base'], 2) }}<span class="text-sm font-normal text-slate-500"> c/kWh</span>
                                    </dd>
                                    @if ($indexPayload['hybrid_contract_count'] !== null && $indexPayload['hybrid_supplier_count'] !== null)
                                        <p class="mt-1 text-sm text-slate-500 tabular-nums">
                                            {{ number_format($indexPayload['hybrid_contract_count'], 0, ',', ' ') }} tarjousta, {{ number_format($indexPayload['hybrid_supplier_count'], 0, ',', ' ') }} myyjää
                                        </p>
                                    @endif
                                </div>
                            @endif
                        </dl>
                    </div>

                    <figure class="mt-10">
                        <div
                            wire:key="seller-set-index-{{ $indexPayload['as_of'] }}"
                            wire:ignore
                            data-line-chart="seller-set-index"
                            class="relative h-72 w-full select-none"
                            role="img"
                            aria-label="Sähkösopimusten energianhintaindeksi ja sen kolme sopimusryhmää sentteinä kilowattitunnilta."
                            aria-describedby="seller-set-index-method"
                        >
                            <script type="application/json">{!! json_encode($indexPayload['chart'], JSON_UNESCAPED_UNICODE) !!}</script>
                        </div>
                        <ul class="mt-2 flex flex-wrap justify-center gap-x-5 gap-y-2 text-sm text-slate-700" aria-hidden="true">
                            @foreach ($indexPayload['chart']['series'] as $i => $series)
                                @php
                                    $indexLineStyles = [
                                        ['stroke' => '#f97316', 'dash' => null, 'width' => 2.5],
                                        ['stroke' => '#1e293b', 'dash' => null, 'width' => 1.8],
                                        ['stroke' => '#64748b', 'dash' => '10,4', 'width' => 1.8],
                                        ['stroke' => '#334155', 'dash' => '4,3', 'width' => 2.0],
                                    ];
                                    $lineStyle = $indexLineStyles[$i] ?? $indexLineStyles[3];
                                @endphp
                                <li class="inline-flex items-center gap-2 {{ $i === 0 ? 'font-semibold text-coral-700' : '' }}">
                                    <svg width="22" height="10" viewBox="0 0 22 10" aria-hidden="true">
                                        <line x1="0" y1="5" x2="22" y2="5"
                                              stroke="{{ $lineStyle['stroke'] }}"
                                              stroke-width="{{ $lineStyle['width'] }}"
                                              stroke-linecap="round"
                                              @if ($lineStyle['dash']) stroke-dasharray="{{ $lineStyle['dash'] }}" @endif />
                                    </svg>
                                    <span>{{ $series['label'] }}</span>
                                </li>
                            @endforeach
                        </ul>
                    </figure>

                    <div id="seller-set-index-method" class="mt-8 grid gap-5 border-t border-slate-200 pt-6 text-base leading-relaxed text-slate-600 md:grid-cols-2 md:gap-10">
                        <p>
                            Jokainen sähköyhtiö saa sopimusryhmän sisällä saman painon riippumatta siitä, kuinka monta sopimusta sillä on.
                        </p>
                        <p>
                            Kokonaisindeksi muodostuu määräaikaisista (50 %), toistaiseksi voimassa olevista (30 %) ja jaksoittain vaihtuvista sopimuksista (20 %). Pörssi-, aika- ja kausisähkö, energiapaketit ja kulutusvaikutussopimukset eivät ole mukana.
                        </p>
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
            <section class="mb-16" aria-labelledby="lead-chart-heading">
                <div class="flex flex-col sm:flex-row sm:items-end sm:justify-between gap-4 mb-6">
                    <div>
                        <h2 id="lead-chart-heading" class="text-2xl font-bold text-slate-900 tracking-tight">
                            Vuosikustannus {{ $consumptionLabel }}&nbsp;kWh kulutuksella
                        </h2>
                        <p class="mt-2 text-base text-slate-600 leading-relaxed max-w-[68ch]">
                            Viivat näyttävät kunkin sopimustyypin tyypillisen 12 kuukauden kustannusarvion valitulla kulutuksella. Arvio vastaa kyseisen ajankohdan myynnissä olleita sopimuksia ja sisältää energian sekä perusmaksut.
                            Näkymä: <span class="font-semibold text-slate-900">{{ $periods[$period] ?? $period }}</span>.
                        </p>
                    </div>

                    {{-- Period switcher --}}
                    <div class="flex flex-col items-start gap-2 self-start">
                        <div role="group" aria-label="Aikajakson valinta" class="inline-flex bg-slate-50 border border-slate-200 rounded-lg p-0.5">
                            @foreach ($periods as $key => $label)
                                <button
                                    type="button"
                                    wire:click="setPeriod('{{ $key }}')"
                                    wire:loading.attr="disabled"
                                    wire:target="setPeriod"
                                    class="px-3.5 py-1.5 rounded-md text-xs font-semibold tracking-wide transition-colors disabled:cursor-wait disabled:opacity-60 {{ $period === $key ? 'bg-slate-900 text-white' : 'text-slate-600 hover:text-slate-900' }}"
                                    aria-pressed="{{ $period === $key ? 'true' : 'false' }}"
                                >
                                    {{ $label }}
                                </button>
                            @endforeach
                        </div>
                        <div wire:loading.delay.flex wire:target="setPeriod" class="hidden items-center gap-2 text-xs font-semibold text-slate-500" role="status" aria-live="polite">
                            <svg class="h-3.5 w-3.5 animate-spin text-slate-400" viewBox="0 0 24 24" fill="none" aria-hidden="true">
                                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4" />
                                <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v4a4 4 0 00-4 4H4z" />
                            </svg>
                            Päivitetään jaksoa
                        </div>
                    </div>
                </div>

                <div class="relative">
                    <div wire:loading.delay.flex wire:target="setPeriod,setConsumption" class="absolute inset-0 z-10 hidden items-center justify-center rounded-xl bg-white/70 backdrop-blur-[1px]" aria-hidden="true">
                        <div class="inline-flex items-center gap-2 rounded-full border border-slate-200 bg-white px-3 py-1.5 text-xs font-semibold text-slate-600 shadow-sm">
                            <svg class="h-3.5 w-3.5 animate-spin text-slate-400" viewBox="0 0 24 24" fill="none" aria-hidden="true">
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
                        class="relative w-full h-80 select-none transition-opacity"
                        role="img"
                        aria-label="Vuosikustannus {{ $consumptionLabel }} kWh kulutuksella sopimustyypeittäin, viivakaavio."
                        aria-describedby="lead-chart-description"
                    >
                        <script type="application/json">{!! json_encode($leadChartPayload, JSON_UNESCAPED_UNICODE) !!}</script>
                    </div>
                </div>

                {{-- Compact legend, shown only when end-labels can't fit. Swatches mirror chart styles. --}}
                @php
                    // Must match NON_LEAD_STYLES in resources/js/contract-price-statistics.js
                    $nonLeadStyles = [
                        ['stroke' => '#1e293b', 'dash' => null,        'width' => 1.8], // solid charcoal
                        ['stroke' => '#64748b', 'dash' => '10,4',      'width' => 1.8], // long dashes
                        ['stroke' => '#334155', 'dash' => '4,3',       'width' => 2.0], // dense small dashes
                        ['stroke' => '#64748b', 'dash' => '6,3,2,3',   'width' => 1.8], // dash-dot
                    ];
                @endphp
                <ul class="mt-1 flex flex-wrap justify-center gap-x-5 gap-y-2 text-xs text-slate-700" aria-hidden="true">
                    @foreach ($leadChartPayload['series'] as $i => $s)
                        @php
                            $isLead = $i === 0;
                            $style = $isLead ? null : $nonLeadStyles[($i - 1) % count($nonLeadStyles)];
                            $stroke = $isLead ? '#f97316' : $style['stroke'];
                            $dash = $isLead ? null : $style['dash'];
                        @endphp
                        <li class="inline-flex items-center gap-2">
                            <svg width="22" height="10" viewBox="0 0 22 10" aria-hidden="true">
                                <line x1="0" y1="5" x2="22" y2="5"
                                      stroke="{{ $stroke }}"
                                      stroke-width="{{ $isLead ? 2.5 : ($style['width'] ?? 1.8) }}"
                                      stroke-linecap="round"
                                      @if ($dash) stroke-dasharray="{{ $dash }}" @endif />
                            </svg>
                            <span class="{{ $isLead ? 'font-semibold text-coral-700' : '' }}">{{ $s['label'] }}</span>
                        </li>
                    @endforeach
                </ul>

                {{-- Editorial caption and chart-reading guide --}}
                <div id="lead-chart-description" class="mt-10 text-slate-700 leading-relaxed">
                    @if (! empty($caption))
                        <div class="max-w-[58ch] text-base">
                            @foreach ($caption as $sentence)
                                <span class="block">{{ $sentence }}</span>
                            @endforeach
                        </div>
                    @endif

                    <div class="{{ ! empty($caption) ? 'mt-7' : '' }} border-t border-slate-200 pt-5" aria-labelledby="chart-reading-heading">
                        <h3 id="chart-reading-heading" class="text-base font-bold text-slate-900">
                            Näin luet kuvaajaa
                        </h3>
                        <dl class="mt-3 grid gap-4 md:grid-cols-3 md:gap-7 text-base text-slate-600">
                            <div>
                                <dt class="font-semibold text-slate-900">Katkos viivassa</dt>
                                <dd class="mt-1">Vertailukelpoista hintaa ei ollut saatavilla tai vuosihinnan arviointitapa muuttui. Katkos estää yhdistämästä keskenään erilaisia arvioita.</dd>
                            </div>
                            <div>
                                <dt class="font-semibold text-slate-900">Yksittäinen hyppy</dt>
                                <dd class="mt-1">Hyppy ei aina tarkoita yleistä hinnanmuutosta. Se voi syntyä myös siitä, että myynnissä olevien sopimusten joukko muuttuu.</dd>
                            </div>
                            <div>
                                <dt class="font-semibold text-slate-900">Uusin piste</dt>
                                <dd class="mt-1">Viimeinen piste näyttää uusimman saatavilla olevan päivän tilanteen. Viikko- ja kuukausinäkymät tasoittavat sitä edeltävää päivittäistä vaihtelua.</dd>
                            </div>
                        </dl>
                    </div>

                    <span class="block mt-5 text-sm text-slate-500">
                        Lähde: Voltikka, päivittäin kerätyt sähkösopimukset. Sisältää arvonlisäveron 25,5 %.
                    </span>
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

            {{-- Editorial callouts --}}
            @if (! empty($callouts))
                <section class="grid grid-cols-1 md:grid-cols-3 gap-x-10 gap-y-8 mb-20" aria-label="Hintamuutokset lyhyesti">
                    @foreach ($callouts as $callout)
                        <div class="border-t-2 {{ $callout['is_lead'] ? 'border-coral-500' : 'border-slate-300' }} pt-4">
                            <p class="text-[11px] font-semibold tracking-[0.16em] uppercase {{ $callout['is_lead'] ? 'text-coral-600' : 'text-slate-500' }}">
                                {{ $callout['segment_label'] }}
                            </p>
                            <p class="mt-3 text-base leading-relaxed text-slate-700">
                                Nyt
                                <span class="font-bold text-slate-900 tabular-nums">{{ $fmtNum($callout['current_price'], 2) }}&nbsp;{{ $callout['unit'] }}</span>,
                                @if ($callout['delta_30d_pct'] !== null)
                                    <span class="tabular-nums {{ $deltaClass($callout['delta_30d_pct']) }}">{{ $fmtPct($callout['delta_30d_pct']) }}</span> 30 päivässä
                                @endif
                                @if ($callout['delta_30d_pct'] !== null && $callout['delta_since_start_pct'] !== null), @endif
                                @if ($callout['delta_since_start_pct'] !== null)
                                    <span class="tabular-nums {{ $deltaClass($callout['delta_since_start_pct']) }}">{{ $fmtPct($callout['delta_since_start_pct']) }}</span> aineiston alusta
                                @endif.
                            </p>
                        </div>
                    @endforeach
                </section>
            @endif

            {{-- Segment table --}}
            <section class="mb-20" aria-labelledby="segment-table-heading">
                <div class="mb-5 grid grid-cols-1 lg:grid-cols-[minmax(0,1fr)_minmax(18rem,24rem)] gap-x-12 gap-y-4 items-start">
                    <div>
                        <h2 id="segment-table-heading" class="text-2xl font-bold text-slate-900 tracking-tight">
                            Hinnat sopimustyypeittäin
                        </h2>
                        <p class="mt-2 text-sm text-slate-600 leading-relaxed max-w-[68ch]">
                            Taulukko näyttää kunkin sopimustyypin uusimman saatavilla olevan tyypillisen energiahinnan sekä miten hinta on muuttunut aineiston aikana. Jos rivin tiedot ovat muita vanhempia, päivämäärä näkyy sopimustyypin alla. Luvut perustuvat päiväkohtaisiin mediaaneihin, eli yksittäiset poikkeavat tarjoukset eivät ohjaa tulosta. Sopimustyypit, joissa on alle 10 sopimusta, jätetään pois. Sopimuksia-luku kertoo, monenko sopimuksen julkaistusta energiahinnasta rivin luvut on laskettu. Sopimus jää tästä taulukosta pois, jos sen hinnastossa ilmoitettu yksikköhinta koskee vain kampanjajaksoa tai on muuten ristiriidassa sopimusehtojen kanssa: silloin luku ei kertoisi, mitä sopimus maksaa. Näidenkin sopimusten koko vuoden hinta lasketaan kaikista hintajaksoista, joten ne ovat mukana vuosikustannustaulukossa.
                        </p>
                    </div>
                    <div class="text-sm text-slate-500 leading-relaxed lg:border-l lg:border-slate-200 lg:pl-6">
                        <p>
                            Päivä: <span class="font-semibold text-slate-700 tabular-nums">{{ $latestSnapshotDate ?? $fiDate($dataWindow['to']) }}</span>.
                            Aineisto: <span class="font-semibold text-slate-700 tabular-nums">{{ $fiDate($dataWindow['from']) }}–{{ $fiDate($dataWindow['to']) }}</span>.
                        </p>
                        <p class="mt-2">
                            Pörssisähkön energiahinta on viimeisen 12 kuukauden toteutunut päiväkeskiarvo + tyypillinen marginaali. Vaihteluväli näyttää saman 12 kuukauden päivähintojen tavanomaisen vaihtelun. Muiden sopimusten energiahinta on sopimuksen oma julkaistu hinta; aika- ja kausisähköissä se painotetaan tyypilliseksi yleishinnaksi.
                        </p>
                        <p class="mt-2">
                            Trendi näyttää energiahinnan mediaanin kehityksen. Pörssisähkössä trendi seuraa 12 kuukauden liukuvaa päiväkeskiarvoa + tyypillistä marginaalia.
                        </p>
                    </div>
                </div>

                <div class="-mx-4 sm:mx-0 overflow-x-auto">
                    <table class="w-full text-sm border-collapse">
                        <thead>
                            <tr class="text-left text-[11px] font-semibold tracking-[0.12em] uppercase text-slate-500 border-b border-slate-300">
                                <th class="py-3 pr-3 pl-4 sm:pl-0 font-semibold">Sopimustyyppi</th>
                                <th class="py-3 px-3 font-semibold text-right">Sopimuksia</th>
                                <th class="py-3 px-3 font-semibold text-right">Energiahinta</th>
                                <th class="py-3 px-3 font-semibold text-right">Δ&nbsp;30&nbsp;pv</th>
                                <th class="py-3 px-3 font-semibold text-right">Δ&nbsp;alusta</th>
                                <th class="py-3 px-3 font-semibold text-right">Perusmaksu</th>
                                <th class="py-3 pl-3 pr-4 sm:pr-0 font-semibold">Energiahinnan trendi</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100">
                            @foreach ($segmentRows as $row)
                                <tr class="{{ $row['is_lead'] ? 'bg-coral-50/40' : '' }}">
                                    <td class="py-3 pr-3 pl-4 sm:pl-0">
                                        <span class="font-semibold text-slate-900 {{ $row['is_lead'] ? 'text-coral-700' : '' }}">
                                            {{ $row['segment_label'] }}
                                        </span>
                                        @if (! $row['is_current'])
                                            <span class="block text-sm font-medium text-slate-500 mt-0.5 tabular-nums">Aineisto {{ $fiDate($row['latest_observation_date']) }} asti</span>
                                        @elseif ($row['is_spot'])
                                            <span class="block text-[11px] text-slate-400 mt-0.5">12 kk keskihinta + marginaali</span>
                                        @endif
                                    </td>
                                    <td class="py-3 px-3 text-right tabular-nums text-slate-500">
                                        {{ $row['contract_count'] !== null ? number_format($row['contract_count'], 0, ',', ' ') : '–' }}
                                    </td>
                                    <td class="py-3 px-3 text-right tabular-nums font-semibold text-slate-900">
                                        {{ $fmtNum($row['current_price'], 2) }}<span class="text-slate-400 font-normal">&nbsp;{{ $row['unit'] }}</span>
                                        @if ($row['is_spot'] && $row['spot_range_p20'] !== null && $row['spot_range_p80'] !== null)
                                            <span class="mt-0.5 block text-[11px] font-medium text-slate-400">
                                                {{ $fmtNum($row['spot_range_p20'], 2) }}–{{ $fmtNum($row['spot_range_p80'], 2) }}&nbsp;{{ $row['unit'] }}
                                            </span>
                                        @endif
                                    </td>
                                    <td class="py-3 px-3 text-right tabular-nums {{ $deltaClass($row['delta_30d_pct']) }}">
                                        {{ $fmtPct($row['delta_30d_pct']) }}
                                    </td>
                                    <td class="py-3 px-3 text-right tabular-nums {{ $deltaClass($row['delta_since_start_pct']) }}">
                                        {{ $fmtPct($row['delta_since_start_pct']) }}
                                    </td>
                                    <td class="py-3 px-3 text-right tabular-nums text-slate-700">
                                        @if ($row['monthly_fee'] !== null)
                                            {{ $fmtNum($row['monthly_fee'], 2) }}<span class="text-slate-400 font-normal">&nbsp;€/kk</span>
                                        @else
                                            <span class="text-slate-400">–</span>
                                        @endif
                                    </td>
                                    <td class="py-3 pl-3 pr-4 sm:pr-0">
                                        @if ($row['sparkline_path'] ?? null)
                                            <svg viewBox="0 0 80 24" width="80" height="24" class="block"
                                                 aria-label="{{ $row['segment_label'] }}, hintatrendi"
                                                 preserveAspectRatio="none">
                                                <path d="{{ $row['sparkline_path'] }}"
                                                      fill="none"
                                                      stroke="{{ $row['is_lead'] ? '#f97316' : '#94a3b8' }}"
                                                      stroke-width="1.5"
                                                      stroke-linecap="round"
                                                      stroke-linejoin="round" />
                                            </svg>
                                        @else
                                            <span class="text-slate-300">–</span>
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </section>

            {{-- Consumption section: hintahaarukka at the page-level kulutus --}}
            <section class="mb-20" aria-labelledby="consumption-heading">
                <div class="mb-5 grid grid-cols-1 lg:grid-cols-[minmax(0,1fr)_minmax(18rem,24rem)] gap-x-12 gap-y-4 items-start">
                    <div>
                        <h2 id="consumption-heading" class="text-2xl font-bold text-slate-900 tracking-tight">
                            Hintahaarukka {{ $consumptionLabel }}&nbsp;kWh kulutuksella
                        </h2>
                        <p class="mt-2 text-sm text-slate-600 leading-relaxed max-w-[68ch]">
                            Taulukko näyttää kunkin sopimustyypin uusimman saatavilla olevan vuosikustannusten jakauman. Jos rivin tiedot ovat muita vanhempia, päivämäärä näkyy sopimustyypin alla. Laskenta käyttää valittua {{ $consumptionLabel }}&nbsp;kWh vuosikulutusta ja sisältää energiahinnan sekä perusmaksut 12 kuukaudelta. Sopimustyypit, joissa on alle 10 sopimusta, jätetään pois.
                        </p>
                        <p class="mt-3 text-xs text-slate-500">
                            Katso myös:
                            @foreach ($consumptionLevels as $level)
                                @if ($level !== $consumption)
                                    <a href="?kulutus={{ $level }}" class="underline decoration-slate-300 decoration-2 underline-offset-4 hover:decoration-coral-500">
                                        {{ number_format($level, 0, ',', ' ') }}&nbsp;kWh
                                    </a>{{ ! $loop->last ? ' · ' : '' }}
                                @endif
                            @endforeach
                        </p>
                    </div>
                    <div class="text-sm text-slate-500 leading-relaxed lg:border-l lg:border-slate-200 lg:pl-6">
                        <p>
                            Päivä: <span class="font-semibold text-slate-700 tabular-nums">{{ $latestSnapshotDate ?? $fiDate($dataWindow['to']) }}</span>.
                            Mukana ovat sopimukset, joille voidaan laskea vuosikustannus valitulla {{ $consumptionLabel }}&nbsp;kWh kulutuksella.
                        </p>
                        <p class="mt-2">
                            Halvempi&nbsp;20&nbsp;% on raja, jonka alle viidennes saman tyypin sopimuksista jää. Mediaani kuvaa tyypillistä sopimusta, ja kalliimpi&nbsp;20&nbsp;% on raja, jonka yli viidennes nousee.
                        </p>
                        <p class="mt-2">
                            @if ($activeAnnualMethod === 'annual_cost_as_of_v1')
                                Jokainen päivä käyttää vain silloin saatavilla olleita tietoja. Tulevia hintoja arvioidaan vain silloin, kun sopimuksen ehdot antavat siihen riittävät tiedot. Jos vertailukelpoista vuosihintaa ei voida muodostaa, sopimus jää kyseisen päivän tilastosta pois.
                            @else
                                Pörssisähkön nykyinen vuosikustannus käyttää tulevan 12 kuukauden tukkumarkkinan ennakkohintoja, historiallista päivä–yö-eroa ja sopimuksen marginaalia. Historialliset Spot-vuosikustannukset käyttävät kyseisestä päivästä taaksepäin laskettua 12 kuukauden toteutunutta pörssitasoa. Kuukausi- ja kvartaalihinnoissa sekä hinnaltaan muutettavissa toistaiseksi voimassa olevissa yleissähkösopimuksissa tulevat kuukaudet ovat arvioita. Muissa kiinteissä sopimuksissa käytetään julkaistuja hintoja. Trendi näyttää vuosikustannuksen mediaanin kehityksen valitulla kulutuksella.
                            @endif
                            Sopimusmäärä voi poiketa ylemmästä hintataulukosta kumpaankin suuntaan: sopimus jää pois, jos se ei ole tarjolla valitulle kulutukselle tai vuosihintaa ei voi laskea, ja mukana on myös sopimuksia, joiden ilmoitettua yksikköhintaa ei voi julkaista energiahintana mutta joiden koko vuoden hinta voidaan laskea hintajaksoista.
                        </p>
                    </div>
                </div>

                <div class="-mx-4 sm:mx-0 overflow-x-auto">
                    <table class="w-full text-sm border-collapse">
                        <caption class="sr-only">Vuosikustannus {{ $consumptionLabel }} kWh kulutuksella sopimustyypeittäin.</caption>
                        <thead>
                            <tr class="text-left text-[11px] font-semibold tracking-[0.12em] uppercase text-slate-500 border-b border-slate-300">
                                <th class="py-3 pr-3 pl-4 sm:pl-0 font-semibold">Sopimustyyppi</th>
                                <th class="py-3 px-3 font-semibold text-right">
                                    <abbr title="20. persentiili: viidennes sopimuksista on tätä halvempia." class="no-underline cursor-help">Halvempi&nbsp;20&nbsp;%</abbr>
                                </th>
                                <th class="py-3 px-3 font-semibold text-right">
                                    <abbr title="Mediaani: tasan puolet sopimuksista on tätä halvempia ja puolet kalliimpia. Robustimpi kuin keskiarvo, koska yksittäiset poikkeavat sopimukset eivät vinouta sitä." class="no-underline cursor-help">Mediaani</abbr>
                                </th>
                                <th class="py-3 px-3 font-semibold text-right">
                                    <abbr title="80. persentiili: viidennes sopimuksista on tätä kalliimpia." class="no-underline cursor-help">Kalliimpi&nbsp;20&nbsp;%</abbr>
                                </th>
                                <th class="py-3 px-3 font-semibold text-right">Sopimuksia</th>
                                <th class="py-3 pl-3 pr-4 sm:pr-0 font-semibold">Vuosihinnan trendi</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100">
                            @foreach ($consumptionRows as $row)
                                <tr class="{{ $row['is_lead'] ? 'bg-coral-50/40' : '' }}">
                                    <td class="py-3 pr-3 pl-4 sm:pl-0">
                                        <span class="font-semibold text-slate-900 {{ $row['is_lead'] ? 'text-coral-700' : '' }}">
                                            {{ $row['segment_label'] }}
                                        </span>
                                        @if (! $row['is_current'])
                                            <span class="block text-sm font-medium text-slate-500 mt-0.5 tabular-nums">Aineisto {{ $fiDate($row['latest_observation_date']) }} asti</span>
                                        @endif
                                    </td>
                                    <td class="py-3 px-3 text-right tabular-nums text-slate-700">{{ $fmtNum($row['p20'], 0) }}<span class="text-slate-400 font-normal">&nbsp;€</span></td>
                                    <td class="py-3 px-3 text-right tabular-nums font-bold text-slate-900">{{ $fmtNum($row['median'], 0) }}<span class="text-slate-400 font-normal">&nbsp;€</span></td>
                                    <td class="py-3 px-3 text-right tabular-nums text-slate-700">{{ $fmtNum($row['p80'], 0) }}<span class="text-slate-400 font-normal">&nbsp;€</span></td>
                                    <td class="py-3 px-3 text-right tabular-nums text-slate-500">{{ number_format($row['contract_count'], 0, ',', ' ') }}</td>
                                    <td class="py-3 pl-3 pr-4 sm:pr-0">
                                        @if ($row['sparkline_path'] ?? null)
                                            <svg viewBox="0 0 80 24" width="80" height="24" class="block"
                                                 aria-label="{{ $row['segment_label'] }}, vuosihinnan trendi"
                                                 preserveAspectRatio="none">
                                                <path d="{{ $row['sparkline_path'] }}"
                                                      fill="none"
                                                      stroke="{{ $row['is_lead'] ? '#f97316' : '#94a3b8' }}"
                                                      stroke-width="1.5"
                                                      stroke-linecap="round"
                                                      stroke-linejoin="round" />
                                            </svg>
                                        @else
                                            <span class="text-slate-300">–</span>
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </section>

            {{-- Per-segment deep-dive sections --}}
            @if (! empty($deepDivePayloads))
                @php
                    // Tone classes for the quotable headline metric and the highlighted span in
                    // the sentence. "down" reads as "cheaper / lower" which is good news for the
                    // reader, hence emerald; "up" stays slate (no shouting reds — see DESIGN.md
                    // bans on decorative red/amber/green).
                    $toneHeadline = [
                        'down' => 'text-emerald-700',
                        'up' => 'text-slate-900',
                        'neutral' => 'text-slate-900',
                    ];
                    $toneHighlight = [
                        'down' => 'text-emerald-700',
                        'up' => 'text-slate-900',
                        'neutral' => 'text-slate-900',
                    ];
                @endphp

                <section class="mb-20" aria-labelledby="deepdive-heading">
                    <h2 id="deepdive-heading" class="text-2xl font-bold text-slate-900 tracking-tight mb-2">
                        Tarkemmin sopimustyypeittäin
                    </h2>
                    <p class="text-sm text-slate-500 max-w-[60ch] mb-7">
                        Kaavioissa viiva näyttää energiahinnan mediaanin ja vaalea alue tavanomaisen vaihteluvälin. Pörssisähkössä viiva on 12 kuukauden liukuva keskiarvo toteutuneista päivähinnoista + tyypillinen marginaali; vaalea alue näyttää saman liukuvan 12 kuukauden jakson päivähintojen vaihteluvälin. Muissa sopimustyypeissä viiva ja alue perustuvat sopimusten julkaistuihin energiahintoihin.
                    </p>

                    {{-- In-page TOC: chip-style anchors --}}
                    <nav aria-label="Sopimustyypit" class="mb-14 flex flex-wrap gap-2">
                        @foreach ($deepDivePayloads as $dive)
                            <a href="#{{ $dive['anchor'] }}"
                               class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-full bg-slate-50 border border-slate-200 text-xs font-semibold tracking-wide text-slate-700 hover:bg-white hover:border-slate-400 hover:text-slate-900 transition-colors">
                                {{ $dive['segment_label'] }}
                                @if ($dive['quotable'] && in_array($dive['quotable']['tone'], ['up', 'down'], true))
                                    <span class="tabular-nums text-[11px] font-bold {{ $dive['quotable']['tone'] === 'down' ? 'text-emerald-700' : 'text-slate-500' }}">
                                        {{ $dive['quotable']['headline'] }}
                                    </span>
                                @endif
                            </a>
                        @endforeach
                    </nav>

                    <div class="relative">
                        <div wire:loading.delay.flex wire:target="setPeriod,setConsumption" class="absolute inset-x-0 top-0 z-10 hidden justify-center pt-4" role="status" aria-live="polite">
                            <div class="inline-flex items-center gap-2 rounded-full border border-slate-200 bg-white/90 px-3 py-1.5 text-xs font-semibold text-slate-600 shadow-sm backdrop-blur">
                                <svg class="h-3.5 w-3.5 animate-spin text-slate-400" viewBox="0 0 24 24" fill="none" aria-hidden="true">
                                    <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4" />
                                    <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v4a4 4 0 00-4 4H4z" />
                                </svg>
                                Päivitetään sopimustyyppejä
                            </div>
                        </div>
                        <div wire:loading.delay.class="opacity-50" wire:target="setPeriod,setConsumption" class="divide-y divide-slate-200 transition-opacity">
                        @foreach ($deepDivePayloads as $dive)
                            <article id="{{ $dive['anchor'] }}" class="scroll-mt-24 pt-20 first:pt-0 pb-20 last:pb-0">
                                <header class="flex flex-wrap items-baseline justify-between gap-x-6 gap-y-2 mb-3">
                                    <h3 class="text-xl font-bold text-slate-900 tracking-tight">
                                        {{ $dive['heading'] }}
                                    </h3>
                                    @if ($dive['contract_count'] !== null)
                                        <span class="text-[11px] font-semibold tracking-[0.16em] uppercase text-slate-400">
                                            {{ number_format($dive['contract_count'], 0, ',', ' ') }} sopimusta
                                        </span>
                                    @elseif (! empty($dive['latest_observation_date']))
                                        <span class="text-sm font-semibold text-slate-500 tabular-nums">
                                            Aineisto {{ $fiDate($dive['latest_observation_date']) }} asti
                                        </span>
                                    @endif
                                </header>

                                @if ($dive['description'])
                                    <p class="text-base text-slate-600 leading-relaxed max-w-[64ch] mb-8">
                                        {{ $dive['description'] }}
                                    </p>
                                @endif

                                @if (! $dive['has_data'])
                                    <p class="text-sm text-slate-500">Aineistoa ei vielä riitä tämän sopimustyypin trendin näyttämiseen.</p>
                                @else

                                    {{-- Quotable pull-quote: the AI-citable claim --}}
                                    @if ($dive['quotable'])
                                        <figure class="mb-8 grid grid-cols-1 md:grid-cols-[minmax(0,11rem)_1fr] gap-x-10 gap-y-4 items-baseline"
                                                x-data="voltikkaQuote({
                                                    sentence: @js($dive['quotable']['sentence_plain']),
                                                    citation: @js($citations['plain']),
                                                    segment: @js($dive['anchor']),
                                                })">
                                            <div class="md:border-l-0 md:pl-0">
                                                <p class="text-[11px] font-semibold tracking-[0.16em] uppercase text-slate-500">
                                                    {{ $dive['quotable']['headline_label'] }}
                                                </p>
                                                <p class="mt-1.5 text-3xl md:text-4xl font-extrabold tabular-nums leading-none {{ $toneHeadline[$dive['quotable']['tone']] }}">
                                                    {{ $dive['quotable']['headline'] }}
                                                </p>
                                            </div>
                                            <div>
                                                <blockquote class="text-lg text-slate-700 leading-snug max-w-[58ch]">
                                                    {{ $dive['quotable']['sentence_before'] }}<span class="font-bold tabular-nums {{ $toneHighlight[$dive['quotable']['tone']] }}">{{ $dive['quotable']['sentence_highlight'] }}</span>{{ $dive['quotable']['sentence_after'] }}
                                                </blockquote>
                                                <button type="button"
                                                        @click="copy()"
                                                        class="mt-3 inline-flex items-center gap-1.5 text-[11px] font-semibold tracking-wide uppercase text-slate-500 hover:text-coral-600 transition-colors"
                                                        :aria-label="copied ? 'Kopioitu' : 'Kopioi sitaatti'">
                                                    <svg x-show="!copied" class="w-3.5 h-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                                                        <rect x="9" y="9" width="13" height="13" rx="2"/><path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"/>
                                                    </svg>
                                                    <svg x-show="copied" x-cloak class="w-3.5 h-3.5 text-emerald-600" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                                                        <path d="M20 6L9 17l-5-5"/>
                                                    </svg>
                                                    <span x-text="copied ? 'Kopioitu' : 'Kopioi sitaatti'"></span>
                                                </button>
                                            </div>
                                        </figure>
                                    @endif

                                    {{-- Stats strip: scannable metric grid --}}
                                    <dl class="mb-10 grid grid-cols-2 sm:grid-cols-4 gap-x-10 gap-y-8 sm:gap-x-6 sm:gap-y-5 pt-5 sm:pt-4 border-t border-slate-200">
                                        <div>
                                            <dt class="text-[11px] font-semibold tracking-[0.12em] uppercase text-slate-400">Energiahinta</dt>
                                            <dd class="mt-1 text-base font-bold text-slate-900 tabular-nums">
                                                {{ $fmtNum($dive['current'], 2) }}<span class="text-slate-400 font-normal">&nbsp;{{ $dive['unit'] }}</span>
                                            </dd>
                                        </div>
                                        <div>
                                            <dt class="text-[11px] font-semibold tracking-[0.12em] uppercase text-slate-400">30 päivän muutos</dt>
                                            <dd class="mt-1 text-base font-semibold tabular-nums {{ $deltaClass($dive['delta_30d_pct']) }}">
                                                {{ $fmtPct($dive['delta_30d_pct']) }}
                                            </dd>
                                        </div>
                                        <div>
                                            <dt class="text-[11px] font-semibold tracking-[0.12em] uppercase text-slate-400">Aineiston alusta</dt>
                                            <dd class="mt-1 text-base font-semibold tabular-nums {{ $deltaClass($dive['delta_since_start_pct']) }}">
                                                {{ $fmtPct($dive['delta_since_start_pct']) }}
                                            </dd>
                                        </div>
                                        <div>
                                            @if ($dive['is_current'])
                                                <dt class="text-[11px] font-semibold tracking-[0.12em] uppercase text-slate-400">Tarjolla nyt</dt>
                                                <dd class="mt-1 text-base font-semibold tabular-nums text-slate-700">
                                                    @if ($dive['contract_count'] !== null)
                                                        {{ number_format($dive['contract_count'], 0, ',', ' ') }}<span class="text-slate-400 font-normal">&nbsp;sopimusta</span>
                                                    @else
                                                        <span class="text-slate-400">–</span>
                                                    @endif
                                                </dd>
                                            @else
                                                <dt class="text-[11px] font-semibold tracking-[0.12em] uppercase text-slate-400">Viimeinen havainto</dt>
                                                <dd class="mt-1 text-base font-semibold tabular-nums text-slate-700">
                                                    {{ $fiDate($dive['latest_observation_date']) }}
                                                </dd>
                                            @endif
                                        </div>
                                    </dl>

                                    @if ($dive['is_spot'] && ! empty($spotMarginPayload['x']))
                                        <div class="grid grid-cols-1 lg:grid-cols-3 gap-10">
                                            <figure class="lg:col-span-2">
                                                <figcaption class="text-[11px] font-semibold tracking-[0.16em] uppercase text-slate-500 mb-3">
                                                    Energiahinta (12 ka.) &amp; vaihteluväli
                                                </figcaption>
                                                <div wire:key="dive-{{ $dive['anchor'] }}-{{ $period }}-{{ $dataWindow['to'] }}"
                                                     wire:ignore
                                                     data-line-chart="{{ $dive['segment_key'] }}"
                                                     class="relative w-full h-64"
                                                     role="img"
                                                     aria-label="{{ $dive['segment_label'] }} hinnan kehitys ja markkinahaarukka aikajaksoittain.">
                                                    <script type="application/json">{!! json_encode($dive['chart'], JSON_UNESCAPED_UNICODE) !!}</script>
                                                </div>
                                            </figure>
                                            <figure>
                                                <figcaption class="text-[11px] font-semibold tracking-[0.16em] uppercase text-slate-500 mb-3">
                                                    Marginaali ka.
                                                </figcaption>
                                                <div wire:key="spot-margin-{{ $period }}-{{ $dataWindow['to'] }}"
                                                     wire:ignore
                                                     data-line-chart="spot-margin"
                                                     class="relative w-full h-64"
                                                     role="img"
                                                     aria-label="Pörssisähkön supplier-marginaalin mediaani aikajaksoittain.">
                                                    <script type="application/json">{!! json_encode($spotMarginPayload, JSON_UNESCAPED_UNICODE) !!}</script>
                                                </div>
                                            </figure>
                                        </div>
                                    @else
                                        <figure>
                                            <div wire:key="dive-{{ $dive['anchor'] }}-{{ $period }}-{{ $dataWindow['to'] }}"
                                                 wire:ignore
                                                 data-line-chart="{{ $dive['segment_key'] }}"
                                                 class="relative w-full h-64"
                                                 role="img"
                                                 aria-label="{{ $dive['segment_label'] }} hinnan kehitys ja markkinahaarukka aikajaksoittain.">
                                                <script type="application/json">{!! json_encode($dive['chart'], JSON_UNESCAPED_UNICODE) !!}</script>
                                            </div>
                                        </figure>
                                    @endif
                                @endif
                            </article>
                        @endforeach
                        </div>
                    </div>
                </section>
            @endif

            {{-- Methodology + citation --}}
            <section id="viittaa" class="grid grid-cols-1 lg:grid-cols-2 gap-12 lg:gap-16 pt-12 border-t border-slate-200">
                <div>
                    <h2 class="text-2xl font-bold text-slate-900 tracking-tight mb-4">Mistä luvut tulevat</h2>
                    <div class="space-y-4 text-base text-slate-600 leading-relaxed max-w-[58ch]">
                        <p>
                            Voltikka kerää sähkösopimusten lähdetiedot päivittäin. Nykyisen päivän julkiset hinta-arvot lasketaan validoidusta kanonisesta hinnoittelusta. Jos kanonisella tuloksella ei ole merkityksellistä yksikköhintaa, kenttä jätetään tyhjäksi. Esimerkiksi energiapaketin ylityshintaa ei esitetä paketin keskimääräisenä energiahintana.
                        </p>
                        <p>
                            Historiallinen takaisin täyttö käyttää kyseisenä päivänä havaittuja myyjän hintakomponentteja. Tämän päivän kanonista tulkintaa ei käytetä vanhan havainnon muuttamiseen. CSV-tiedoston <code>pricing_basis</code>-sarake erottaa kanonisen laskelman ja havaitun myyjäaineiston. Hinnat sisältävät arvonlisäveron 25,5 %.
                        </p>
                        <p>
                            Pörssipohjaisille sopimuksille käytetään kahta eri näkymää. Sopimustyyppien c/kWh-taulukko ja historialliset kuvaajat näyttävät viimeisen 12 kuukauden toteutuneen päiväkeskiarvon + tyypillisen marginaalin. P20–P80-väli lasketaan saman jakson päivähinnoista.
                            @if ($activeAnnualMethod === 'annual_cost_as_of_v1')
                                Vuosikustannussarja on erillinen as-of-laskelma. Jokainen päivä käyttää kyseisen päivän ennakkohintakäyrää, toteutunutta päivä–yö-eroa sekä sopimuksen silloin julkaistua marginaalia ja perusmaksua. Puuttuva täysi ennakkohintajakso käyttää erikseen merkittyä toteutunutta 12 kuukauden tasoa.
                            @else
                                Nykyinen kanoninen vuosikustannus käyttää sen sijaan tulevan 12 kuukauden tukkumarkkinan ennakkohintoja, toteutunutta päivä–yö-eroa ja sopimuksen tarkkaa marginaalia sekä perusmaksua. Jos koko ennakkohintajaksoa ei voi käyttää, vuosikustannus palaa erikseen merkittyyn toteutuneen 12 kuukauden tasoon.
                            @endif
                        </p>
                        <p>
                            Nykyiset vuosikustannukset tulevat samasta kanonisesta 12 kuukauden laskelmasta kuin Voltikan sopimusvertailussa. Laskelma huomioi hinnoitteluvaiheet, tarjoukset, aika- ja kausihinnat, pörssimarginaalit, markkinahinnan päivitykset ja kuukausittaiset energiapaketit silloin, kun ne koskevat sopimusta.
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

                <div x-data="voltikkaCite({
                    plain: @js($citations['plain']),
                    markdown: @js($citations['markdown']),
                    html: @js($citations['html']),
                })">
                    <h2 class="text-2xl font-bold text-slate-900 tracking-tight mb-4">Viittaa tähän</h2>
                    <p class="text-sm text-slate-500 mb-5 max-w-[48ch]">
                        Käytä alla olevaa lähdemerkintää, jos lainaat tilastoa artikkelissa, tutkimuksessa tai sosiaalisessa mediassa. Aineisto on lisensoitu CC&nbsp;BY&nbsp;4.0.
                    </p>

                    <div role="tablist" class="inline-flex bg-slate-50 border border-slate-200 rounded-lg p-0.5 mb-4">
                        <template x-for="format in ['plain','markdown','html']" :key="format">
                            <button
                                type="button"
                                role="tab"
                                :aria-selected="active === format"
                                @click="active = format"
                                class="px-3.5 py-1.5 rounded-md text-xs font-semibold tracking-wide transition-colors capitalize"
                                :class="active === format ? 'bg-slate-900 text-white' : 'text-slate-600 hover:text-slate-900'"
                                x-text="format === 'plain' ? 'Teksti' : (format === 'markdown' ? 'Markdown' : 'HTML')"
                            ></button>
                        </template>
                    </div>

                    <pre x-text="formats[active]" class="bg-slate-50 border border-slate-200 rounded-xl p-4 text-sm text-slate-700 whitespace-pre-wrap break-words leading-relaxed font-sans"></pre>

                    <button
                        type="button"
                        @click="copy()"
                        class="mt-4 inline-flex items-center gap-2 rounded-lg bg-slate-900 text-white text-sm font-semibold px-4 py-2 hover:bg-slate-800 transition-colors"
                    >
                        <svg x-show="!copied" class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                            <rect x="9" y="9" width="13" height="13" rx="2"/><path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"/>
                        </svg>
                        <svg x-show="copied" x-cloak class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                            <path d="M20 6L9 17l-5-5"/>
                        </svg>
                        <span x-text="copied ? 'Kopioitu' : 'Kopioi viittaus'"></span>
                    </button>

                    <div class="mt-8 text-sm">
                        <a href="{{ $csvHref }}"
                           class="inline-flex items-center gap-2 font-semibold text-slate-900 underline decoration-slate-300 decoration-2 underline-offset-4 hover:decoration-coral-500"
                           download>
                            <svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                                <path d="M12 4v12m0 0l-4-4m4 4l4-4M4 20h16"/>
                            </svg>
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
