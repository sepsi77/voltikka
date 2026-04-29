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

    $hasData = ! empty($leadChartPayload['x']);
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
        <header class="border-b border-slate-200 pb-10 mb-12">
            <p class="text-[11px] font-semibold tracking-[0.16em] uppercase text-slate-500 mb-4">
                Voltikka tilastot
            </p>
            <h1 class="text-3xl md:text-5xl font-extrabold text-slate-900 leading-[1.05] tracking-tight max-w-[28ch]">
                Sähkön hintatilastot: mitä suomalaiset oikeasti maksavat
            </h1>
            <p class="mt-5 max-w-[62ch] text-lg text-slate-600 leading-relaxed">
                Voltikka seuraa sähkösopimusten todellista hintakehitystä päivittäin: pörssi-, määräaikaisia, joustosähkö- ja toistaiseksi voimassa olevia sopimuksia. Aineisto kasvaa kuukausittain, näytämme sen mitä on kerätty.
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

            <div class="mt-7 flex flex-wrap items-center gap-x-5 gap-y-2 text-sm">
                <a href="{{ $csvHref }}"
                   class="inline-flex items-center gap-2 font-semibold text-slate-900 underline decoration-slate-300 decoration-2 underline-offset-4 hover:decoration-coral-500"
                   download>
                    <svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                        <path d="M12 4v12m0 0l-4-4m4 4l4-4M4 20h16"/>
                    </svg>
                    Lataa CSV
                </a>
                <a href="#viittaa"
                   class="inline-flex items-center gap-2 font-semibold text-slate-900 underline decoration-slate-300 decoration-2 underline-offset-4 hover:decoration-coral-500">
                    <svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                        <path d="M7 7h3v3H7zM14 7h3v3h-3zM7 14h3v3H7zM14 14h3v3h-3z"/>
                    </svg>
                    Viittaa tähän
                </a>
                <span class="text-slate-400">CC&nbsp;BY&nbsp;4.0</span>
            </div>

            {{-- Page-level consumption switcher: lead chart, callouts, and consumption table all reflect this --}}
            <div class="mt-8 flex flex-wrap items-center gap-x-5 gap-y-3">
                <span class="text-[11px] font-semibold tracking-[0.16em] uppercase text-slate-500">
                    Vuosikulutus
                </span>
                <div role="group" aria-label="Vuosikulutuksen valinta" class="inline-flex bg-slate-50 border border-slate-200 rounded-lg p-0.5 tabular-nums">
                    @foreach ($consumptionLevels as $level)
                        <button
                            type="button"
                            wire:click="setConsumption({{ $level }})"
                            class="px-3.5 py-1.5 rounded-md text-xs font-semibold tracking-wide transition-colors {{ $consumption === $level ? 'bg-slate-900 text-white' : 'text-slate-600 hover:text-slate-900' }}"
                            aria-pressed="{{ $consumption === $level ? 'true' : 'false' }}"
                        >
                            {{ number_format($level, 0, ',', ' ') }}&nbsp;kWh
                        </button>
                    @endforeach
                </div>
                <span class="text-xs text-slate-500 max-w-[40ch]">
                    Tyypillinen kulutus: kerrostalokaksio noin 2&nbsp;000, omakotitalo ilman sähkölämmitystä noin 5&nbsp;000, sähkölämmitteinen omakotitalo noin 18&nbsp;000&nbsp;kWh vuodessa.
                </span>
            </div>
        </header>

        @if (! $hasData)
            {{-- Empty state. Public-safe; no operator instructions. --}}
            <section class="border border-slate-200 rounded-2xl px-8 py-16 text-center">
                <p class="text-base text-slate-700 max-w-[40ch] mx-auto">
                    Tilastoja ei ole vielä saatavilla. Aineiston keruu on käynnissä, palaa myöhemmin.
                </p>
            </section>
        @else

            {{-- Lead chart --}}
            <section class="mb-16" aria-labelledby="lead-chart-heading">
                <div class="flex flex-col sm:flex-row sm:items-end sm:justify-between gap-4 mb-6">
                    <div>
                        <h2 id="lead-chart-heading" class="text-2xl font-bold text-slate-900 tracking-tight">
                            Vuosikustannus {{ $consumptionLabel }}&nbsp;kWh kulutuksella
                        </h2>
                        <p class="mt-1 text-sm text-slate-500 max-w-[68ch]">
                            Eri sopimustyyppien tyypillinen vuosikustannus, jos sopimus tehtäisiin tämän jakson aikana.
                            Pörssipohjaisille sopimuksille luku perustuu edeltävän 12 kuukauden pörssin keskihintaan, ei yksittäisten päivien hintaan.
                            Jakso&nbsp;= <span class="font-semibold text-slate-900">{{ $periods[$period] ?? $period }}</span>.
                        </p>
                    </div>

                    {{-- Period switcher --}}
                    <div role="group" aria-label="Aikajakson valinta" class="inline-flex bg-slate-50 border border-slate-200 rounded-lg p-0.5 self-start">
                        @foreach ($periods as $key => $label)
                            <button
                                type="button"
                                wire:click="setPeriod('{{ $key }}')"
                                class="px-3.5 py-1.5 rounded-md text-xs font-semibold tracking-wide transition-colors {{ $period === $key ? 'bg-slate-900 text-white' : 'text-slate-600 hover:text-slate-900' }}"
                                aria-pressed="{{ $period === $key ? 'true' : 'false' }}"
                            >
                                {{ $label }}
                            </button>
                        @endforeach
                    </div>
                </div>

                <div class="relative">
                    <div
                        wire:key="lead-chart-{{ $period }}-{{ $dataWindow['to'] }}"
                        wire:ignore
                        data-line-chart
                        class="relative w-full h-80 select-none"
                        role="img"
                        aria-label="Vuosikustannus 5&nbsp;000 kWh kulutuksella sopimustyypeittäin, viivakaavio."
                    >
                        <script type="application/json">{!! json_encode($leadChartPayload, JSON_UNESCAPED_UNICODE) !!}</script>
                    </div>
                </div>

                {{-- Compact legend, shown only when end-labels can't fit. Swatches mirror chart styles. --}}
                @php
                    // Must match NON_LEAD_STYLES in resources/js/contract-price-statistics.js
                    $nonLeadStyles = [
                        ['stroke' => '#1e293b', 'dash' => null,        'width' => 1.8], // solid charcoal
                        ['stroke' => '#64748b', 'dash' => '8,3',       'width' => 1.8], // long dashes
                        ['stroke' => '#334155', 'dash' => '3,2',       'width' => 2.0], // dense small dashes
                        ['stroke' => '#64748b', 'dash' => '5,2,2,2',   'width' => 1.8], // dash-dot
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

                {{-- Editorial caption --}}
                @if (! empty($caption))
                    <figcaption class="mt-10 max-w-[58ch] text-base text-slate-700 leading-relaxed">
                        @foreach ($caption as $sentence)
                            <span class="block">{{ $sentence }}</span>
                        @endforeach
                        <span class="block mt-3 text-sm text-slate-500">
                            Spot-sopimusten vuosikustannus heijastaa edeltävän 12 kuukauden pörssin keskihintaa kustakin jaksosta taaksepäin laskettuna. Yksittäisten päivien hintapiikit eivät siten vääristä lukua.
                        </span>
                        <span class="block mt-2 text-xs text-slate-400">
                            Lähde: Voltikka, päivittäin kerätyt sähkösopimukset. Sis. ALV 25,5 %.
                        </span>
                    </figcaption>
                @endif

                {{-- Visually-hidden accessible table mirror of the chart --}}
                <table class="sr-only">
                    <caption>Vuosikustannus {{ $consumptionLabel }} kWh kulutuksella sopimustyypeittäin (€/v).</caption>
                    <thead>
                        <tr>
                            <th>Jakso alkaa</th>
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
                <div class="flex items-baseline justify-between gap-4 flex-wrap mb-5">
                    <h2 id="segment-table-heading" class="text-2xl font-bold text-slate-900 tracking-tight">
                        Hinnat sopimustyypeittäin
                    </h2>
                    <p class="text-sm text-slate-500 max-w-[40ch]">
                        Energiahinta tarkoittaa pörssisopimuksilla pörssin keskihintaa lisättynä sopimuksen marginaalilla.
                    </p>
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
                                <th class="py-3 pl-3 pr-4 sm:pr-0 font-semibold">Trendi</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100">
                            @foreach ($segmentRows as $row)
                                <tr class="{{ $row['is_lead'] ? 'bg-coral-50/40' : '' }}">
                                    <td class="py-3 pr-3 pl-4 sm:pl-0">
                                        <span class="font-semibold text-slate-900 {{ $row['is_lead'] ? 'text-coral-700' : '' }}">
                                            {{ $row['segment_label'] }}
                                        </span>
                                        @if ($row['is_spot'])
                                            <span class="block text-[11px] text-slate-400 mt-0.5">pörssin keskihinta + marginaali</span>
                                        @endif
                                    </td>
                                    <td class="py-3 px-3 text-right tabular-nums text-slate-500">
                                        {{ $row['contract_count'] !== null ? number_format($row['contract_count'], 0, ',', ' ') : '–' }}
                                    </td>
                                    <td class="py-3 px-3 text-right tabular-nums font-semibold text-slate-900">
                                        {{ $fmtNum($row['current_price'], 2) }}<span class="text-slate-400 font-normal">&nbsp;{{ $row['unit'] }}</span>
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
                                        @if ($row['sparkline_path'])
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
                <h2 id="consumption-heading" class="text-2xl font-bold text-slate-900 tracking-tight mb-1">
                    Hintahaarukka {{ $consumptionLabel }}&nbsp;kWh kulutuksella
                </h2>
                <p class="text-sm text-slate-500 max-w-[68ch] mb-3">
                    Kuinka paljon halvimmat ja kalleimmat sopimukset eroavat tyypillisestä. Halvin näyttää markkinoiden alimman vuosikustannuksen, halvempi&nbsp;20&nbsp;% rajan jonka alle viidennes sopimuksista jää, mediaani jakaa sopimukset puoliksi, ja kalliimpi&nbsp;20&nbsp;% on raja jonka yli viidennes nousee.
                </p>

                <p class="text-xs text-slate-500 mb-5">
                    Katso myös:
                    @foreach ($consumptionLevels as $level)
                        @if ($level !== $consumption)
                            <a href="?kulutus={{ $level }}" class="underline decoration-slate-300 decoration-2 underline-offset-4 hover:decoration-coral-500">
                                {{ number_format($level, 0, ',', ' ') }}&nbsp;kWh
                            </a>{{ ! $loop->last ? ' · ' : '' }}
                        @endif
                    @endforeach
                </p>

                <div class="-mx-4 sm:mx-0 overflow-x-auto">
                    <table class="w-full text-sm border-collapse">
                        <caption class="sr-only">Vuosikustannus {{ $consumptionLabel }} kWh kulutuksella sopimustyypeittäin.</caption>
                        <thead>
                            <tr class="text-left text-[11px] font-semibold tracking-[0.12em] uppercase text-slate-500 border-b border-slate-300">
                                <th class="py-3 pr-3 pl-4 sm:pl-0 font-semibold">Sopimustyyppi</th>
                                <th class="py-3 px-3 font-semibold text-right">Halvin</th>
                                <th class="py-3 px-3 font-semibold text-right">
                                    <abbr title="20. persentiili: viidennes sopimuksista on tätä halvempia." class="no-underline cursor-help">Halvempi&nbsp;20&nbsp;%</abbr>
                                </th>
                                <th class="py-3 px-3 font-semibold text-right">
                                    <abbr title="Mediaani: tasan puolet sopimuksista on tätä halvempia ja puolet kalliimpia. Robustimpi kuin keskiarvo, koska yksittäiset poikkeavat sopimukset eivät vinouta sitä." class="no-underline cursor-help">Mediaani</abbr>
                                </th>
                                <th class="py-3 px-3 font-semibold text-right">
                                    <abbr title="80. persentiili: viidennes sopimuksista on tätä kalliimpia." class="no-underline cursor-help">Kalliimpi&nbsp;20&nbsp;%</abbr>
                                </th>
                                <th class="py-3 pl-3 pr-4 sm:pr-0 font-semibold text-right">Sopimuksia</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100">
                            @foreach ($consumptionRows as $row)
                                <tr class="{{ $row['is_lead'] ? 'bg-coral-50/40' : '' }}">
                                    <td class="py-3 pr-3 pl-4 sm:pl-0 font-semibold text-slate-900 {{ $row['is_lead'] ? 'text-coral-700' : '' }}">
                                        {{ $row['segment_label'] }}
                                    </td>
                                    <td class="py-3 px-3 text-right tabular-nums text-slate-700">{{ $fmtNum($row['min'], 0) }}<span class="text-slate-400 font-normal">&nbsp;€</span></td>
                                    <td class="py-3 px-3 text-right tabular-nums text-slate-700">{{ $fmtNum($row['p20'], 0) }}<span class="text-slate-400 font-normal">&nbsp;€</span></td>
                                    <td class="py-3 px-3 text-right tabular-nums font-bold text-slate-900">{{ $fmtNum($row['median'], 0) }}<span class="text-slate-400 font-normal">&nbsp;€</span></td>
                                    <td class="py-3 px-3 text-right tabular-nums text-slate-700">{{ $fmtNum($row['p80'], 0) }}<span class="text-slate-400 font-normal">&nbsp;€</span></td>
                                    <td class="py-3 pl-3 pr-4 sm:pr-0 text-right tabular-nums text-slate-500">{{ number_format($row['contract_count'], 0, ',', ' ') }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </section>

            {{-- Per-segment deep-dive sections --}}
            @if (! empty($deepDivePayloads))
                <section class="mb-20" aria-labelledby="deepdive-heading">
                    <h2 id="deepdive-heading" class="text-2xl font-bold text-slate-900 tracking-tight mb-2">
                        Tarkemmin sopimustyypeittäin
                    </h2>
                    <p class="text-sm text-slate-500 max-w-[60ch] mb-6">
                        Yksittäisten sopimustyyppien hintakehitys ja tämänhetkinen markkinahaarukka. Vaalea alue kuvaa hintojen keskimmäistä 60&nbsp;%, viiva on mediaani.
                    </p>

                    {{-- In-page TOC --}}
                    <nav aria-label="Sopimustyypit" class="mb-12 flex flex-wrap gap-x-4 gap-y-2 text-sm">
                        @foreach ($deepDivePayloads as $dive)
                            <a href="#{{ $dive['anchor'] }}"
                               class="inline-flex items-center gap-1.5 text-slate-700 underline decoration-slate-300 decoration-2 underline-offset-4 hover:decoration-coral-500">
                                {{ $dive['segment_label'] }}
                            </a>
                        @endforeach
                    </nav>

                    <div class="space-y-16">
                        @foreach ($deepDivePayloads as $dive)
                            <article id="{{ $dive['anchor'] }}" class="scroll-mt-24">
                                <header class="mb-3">
                                    <h3 class="text-xl font-bold text-slate-900 tracking-tight">
                                        {{ $dive['segment_label'] }}
                                    </h3>
                                    @if ($dive['description'])
                                        <p class="mt-2 text-sm text-slate-600 leading-relaxed max-w-[68ch]">
                                            {{ $dive['description'] }}
                                        </p>
                                    @endif
                                </header>

                                @if (! $dive['has_data'])
                                    <p class="text-sm text-slate-500">Aineistoa ei vielä riitä tämän sopimustyypin trendin näyttämiseen.</p>
                                @else
                                    <p class="text-base text-slate-700 leading-relaxed mb-6 max-w-[68ch]">
                                        <span>Nyt</span>
                                        <span class="font-bold text-slate-900 tabular-nums">{{ $fmtNum($dive['current'], 2) }}&nbsp;{{ $dive['unit'] }}</span><span>.</span>
                                        @if ($dive['delta_30d_pct'] !== null || $dive['delta_since_start_pct'] !== null)
                                            <span>Hinta on</span>
                                            @if ($dive['delta_30d_pct'] !== null)
                                                <span class="tabular-nums {{ $deltaClass($dive['delta_30d_pct']) }}">{{ $fmtPct($dive['delta_30d_pct']) }}</span>
                                                <span>30 päivässä</span>
                                            @endif
                                            @if ($dive['delta_30d_pct'] !== null && $dive['delta_since_start_pct'] !== null)
                                                <span>ja</span>
                                            @endif
                                            @if ($dive['delta_since_start_pct'] !== null)
                                                <span class="tabular-nums {{ $deltaClass($dive['delta_since_start_pct']) }}">{{ $fmtPct($dive['delta_since_start_pct']) }}</span>
                                                <span>aineiston alusta</span>
                                            @endif
                                            <span>.</span>
                                        @endif
                                        @if ($dive['contract_count'] !== null)
                                            <span class="text-slate-500">{{ number_format($dive['contract_count'], 0, ',', ' ') }} sopimusta tällä hetkellä.</span>
                                        @endif
                                    </p>

                                    @if ($dive['is_spot'] && ! empty($spotMarginPayload['x']))
                                        <div class="grid grid-cols-1 lg:grid-cols-3 gap-10">
                                            <figure class="lg:col-span-2">
                                                <figcaption class="text-[11px] font-semibold tracking-[0.16em] uppercase text-slate-500 mb-3">
                                                    Kokonaishinta &amp; haarukka
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
                </section>
            @endif

            {{-- Methodology + citation --}}
            <section id="viittaa" class="grid grid-cols-1 lg:grid-cols-2 gap-12 lg:gap-16 pt-12 border-t border-slate-200">
                <div>
                    <h2 class="text-2xl font-bold text-slate-900 tracking-tight mb-4">Mistä luvut tulevat</h2>
                    <div class="space-y-4 text-base text-slate-600 leading-relaxed max-w-[58ch]">
                        <p>
                            Voltikka kerää sähkösopimusten hintatiedot päivittäin ja tallentaa kotitalouksille tarjottujen sopimusten hintakomponentit omaan tietokantaan.
                        </p>
                        <p>
                            Kunkin päivän tilastoihin otetaan mukaan ne sopimukset, joilla on kyseiselle päivälle hintatiedot tietokannassa. Hinnat sisältävät arvonlisäveron 25,5 %.
                        </p>
                        <p>
                            Pörssipohjaisille sopimuksille käytetään kahta eri laskentatapaa. Sivun c/kWh-hinnat ja pörssisähkön kokonaishinta perustuvat sen päivän pörssin keskihintaan, johon lisätään sopimuksen marginaali, jolloin luku heijastaa sähkön todellista hetkellistä hintaa. Vuosikustannukset taas lasketaan edeltävän 12 kuukauden pörssin keskihinnasta plus marginaali, jolloin luku vastaa sitä, mitä spot-asiakas olisi käytännössä maksanut vuodessa, eivätkä yksittäisten päivien hintapiikit vääristä sitä.
                        </p>
                        <p>
                            Kiinteähintaisille sopimuksille (määräaikaiset, joustosähkö ja toistaiseksi voimassa olevat) käytetään sopimuksen omaa energiahintaa. Vuosikustannus on energiahinta&nbsp;×&nbsp;kulutus + perusmaksut&nbsp;×&nbsp;12, eli sama kaava kuin Voltikan sopimusvertailussa.
                        </p>
                        <p>
                            Vuosikustannukset 2&nbsp;000, 5&nbsp;000 ja 18&nbsp;000&nbsp;kWh kulutuksilla sisältävät energiahinnan ja perusmaksut.
                        </p>
                        <p>
                            Tämän sivun keskiluku on mediaani: kuvaa tyypillistä sopimusta paremmin kuin keskiarvo, koska yksittäiset poikkeavat tarjoukset tai virheellinen aineisto eivät vinouta sitä. Hintahaarukan rajat ovat 20.&nbsp;ja 80.&nbsp;persentiilit: halvempi&nbsp;20&nbsp;% on raja jonka alle viidennes saman tyypin sopimuksista jää, ja kalliimpi&nbsp;20&nbsp;% on raja jonka yli viidennes nousee.
                        </p>
                        <p>
                            Aineisto alkaa {{ $fiDate($dataWindow['from']) }}. Yksittäiset päivät voivat puuttua, jos hintatiedoissa on aukkoja, näitä ei täytetä keinotekoisesti.
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
