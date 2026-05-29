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

    $directionLabels = [
        'rising' => 'Nouseva',
        'slightly_rising' => 'Lievästi nouseva',
        'flat' => 'Tasainen',
        'slightly_falling' => 'Lievästi laskeva',
        'falling' => 'Laskeva',
    ];

    $directionTone = function (string $direction) {
        return match ($direction) {
            'rising' => 'up',
            'falling' => 'down',
            default => 'neutral',
        };
    };

    $toneBadgeClass = [
        'up' => 'bg-slate-900 text-white',
        'down' => 'bg-emerald-700 text-white',
        'neutral' => 'bg-slate-100 text-slate-700 border border-slate-200',
    ];

    $toneAccentBorder = [
        'up' => 'border-slate-900',
        'down' => 'border-emerald-600',
        'neutral' => 'border-slate-300',
    ];

    $toneAccentText = [
        'up' => 'text-slate-900',
        'down' => 'text-emerald-700',
        'neutral' => 'text-slate-700',
    ];

    $confidenceLabels = [
        'high' => 'Korkea luotettavuus',
        'medium' => 'Keskimääräinen luotettavuus',
        'low' => 'Matala luotettavuus',
    ];

    $coverageLabels = [
        'all_monthly' => 'Tarkkuus: kuukausi',
        'mixed_with_quarter_fallback' => 'Tarkkuus: kuukausi + kvartaali',
        'mixed_with_year_fallback' => 'Tarkkuus: kuukausi + vuosi',
        'partial_missing' => 'Tarkkuus: osittainen',
    ];

    // Shared eyebrow class. 14px / weight 600 per DESIGN.md Readable-By-Default rule.
    $eyebrow = 'text-sm font-semibold tracking-[0.14em] uppercase';
    // Dense table column header eyebrow stays at 12px; tabular dense context is a sanctioned exception.
    $colEyebrow = 'text-[12px] font-semibold tracking-[0.12em] uppercase';
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
                <li class="text-slate-900 font-medium" aria-current="page">Sähkön hintaennuste</li>
            </ol>
        </nav>

        {{-- Editorial header strip --}}
        <header class="border-b border-slate-200 pb-10 mb-12">
            <p class="{{ $eyebrow }} text-slate-500 mb-4">
                Voltikka sähkön hintaennuste
            </p>
            <h1 class="text-3xl md:text-5xl font-extrabold text-slate-900 leading-[1.05] tracking-tight max-w-[28ch]">
                Sähkön hintaennuste: kannattaako sähkösopimus lukita nyt?
            </h1>
            <p class="mt-5 max-w-[62ch] text-lg text-slate-600 leading-relaxed">
                Voltikan sähkön hintaennuste seuraa päivittäin määräaikaisten sähkösopimusten hintakehitystä. Malli yhdistää suomalaisten EEX-pörssifutuurien hinnat ja tarjottujen sopimusten tämänhetkisen tason, ja arvioi onko hinta nyt poikkeuksellisen korkea, matala vai tavanomainen seuraavaa kuukautta varten.
            </p>

            {{-- Meta strip --}}
            <dl class="mt-8 grid grid-cols-2 sm:grid-cols-4 gap-x-6 gap-y-4 text-sm">
                <div>
                    <dt class="{{ $colEyebrow }} text-slate-500">Ennustepäivä</dt>
                    <dd class="mt-1 font-semibold text-slate-900 tabular-nums">
                        {{ $forecastDate ? $fiDate($forecastDate) : '–' }}
                    </dd>
                </div>
                <div>
                    <dt class="{{ $colEyebrow }} text-slate-500">Tähtäin</dt>
                    <dd class="mt-1 font-semibold text-slate-900 tabular-nums">
                        @if ($targetDate)
                            {{ $horizonDays }}&nbsp;pv ({{ $fiDate($targetDate) }})
                        @else
                            –
                        @endif
                    </dd>
                </div>
                <div>
                    <dt class="{{ $colEyebrow }} text-slate-500">Sopimuspituudet</dt>
                    <dd class="mt-1 font-semibold text-slate-900">6, 12 ja 24 kk</dd>
                </div>
                <div>
                    <dt class="{{ $colEyebrow }} text-slate-500">Sisältää</dt>
                    <dd class="mt-1 font-semibold text-slate-900">ALV 25,5 %</dd>
                </div>
            </dl>

            <div class="mt-7 flex flex-wrap items-center gap-x-5 gap-y-2 text-sm">
                <a href="/sahkosopimus/tilastot"
                   class="inline-flex items-center gap-2 font-semibold text-slate-900 underline decoration-slate-300 decoration-2 underline-offset-4 hover:decoration-coral-500">
                    <svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                        <path d="M3 3v18h18M7 14l4-4 4 4 6-6"/>
                    </svg>
                    Katso myös hintatilastot
                </a>
                <a href="#menetelma"
                   data-no-nav-loading
                   class="inline-flex items-center gap-2 font-semibold text-slate-900 underline decoration-slate-300 decoration-2 underline-offset-4 hover:decoration-coral-500">
                    <svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                        <circle cx="12" cy="12" r="9"/><path d="M12 8v4M12 16h.01"/>
                    </svg>
                    Miten ennuste lasketaan
                </a>
                <a href="#viittaa"
                   data-no-nav-loading
                   class="inline-flex items-center gap-2 font-semibold text-slate-900 underline decoration-slate-300 decoration-2 underline-offset-4 hover:decoration-coral-500">
                    <svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                        <path d="M7 7h3v3H7zM14 7h3v3h-3zM7 14h3v3H7zM14 14h3v3h-3z"/>
                    </svg>
                    Viittaa tähän
                </a>
                <span class="text-slate-400">CC&nbsp;BY&nbsp;4.0</span>
            </div>
        </header>

        @if (! $hasData)
            <section class="border border-slate-200 rounded-2xl px-8 py-16 text-center">
                <p class="text-base text-slate-700 max-w-[46ch] mx-auto">
                    Ennusteita ei ole vielä saatavilla. Aineiston keruu on käynnissä, palaa myöhemmin.
                </p>
            </section>
        @else

            {{-- Top-line consumer signal: editorial lead, no card chrome. --}}
            @if (! empty($overall))
                @php
                    $overallTone = $overall['tone'];
                    $overallHeadlineClass = $overallTone === 'up'
                        ? 'text-coral-700'
                        : ($overallTone === 'down' ? 'text-emerald-700' : 'text-slate-900');
                @endphp
                <section class="mb-16" aria-labelledby="signal-eyebrow">
                    <p id="signal-eyebrow" class="{{ $eyebrow }} text-slate-500 mb-3">
                        30 päivän näkymä
                    </p>
                    <p class="text-2xl md:text-3xl font-extrabold leading-tight tracking-tight max-w-[44ch] {{ $overallHeadlineClass }}">
                        {{ $overall['headline'] }}
                    </p>
                    <p class="mt-4 max-w-[60ch] text-base text-slate-700 leading-relaxed">
                        {{ $overall['body'] }}
                    </p>
                </section>
            @endif

            {{-- Cross-duration comparison table. Three sopimuspituutta side-by-side so reader can scan
                 "which lock-in length looks best right now" without descending into the deep dives. --}}
            <section class="mb-16" aria-labelledby="comparison-heading">
                <div class="mb-5">
                    <h2 id="comparison-heading" class="text-2xl font-bold text-slate-900 tracking-tight">
                        Vertailu sopimuspituuksittain
                    </h2>
                    <p class="mt-1.5 text-sm text-slate-500 max-w-[68ch]">
                        Mediaanihinta tarkoittaa tyypillistä markkinoilla olevaa tarjousta. Ennustettu muutos kertoo, miten mediaanin odotetaan liikkuvan seuraavan {{ $horizonDays }}&nbsp;päivän aikana.
                    </p>
                </div>

                <div class="-mx-4 sm:mx-0 overflow-x-auto">
                    <table class="w-full text-sm border-collapse">
                        <thead>
                            <tr class="text-left {{ $colEyebrow }} text-slate-500 border-b border-slate-300">
                                <th class="py-3 pr-3 pl-4 sm:pl-0 font-semibold">Sopimuspituus</th>
                                <th class="py-3 px-3 font-semibold text-right">Mediaanihinta nyt</th>
                                <th class="py-3 px-3 font-semibold text-right">Ennuste {{ $horizonDays }}&nbsp;pv</th>
                                <th class="py-3 px-3 font-semibold text-right">Muutos</th>
                                <th class="py-3 px-3 font-semibold text-right">Markkinatason hinta</th>
                                <th class="py-3 pl-3 pr-4 sm:pr-0 font-semibold">Suositus</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100">
                            @foreach ($durations as $duration)
                                @php
                                    $payload = $rowsByDuration->get($duration);
                                    $signal = $payload['signal'] ?? null;
                                    $median = $payload['lanes'] ? collect($payload['lanes'])->firstWhere('quantile', 'median') : null;
                                    $tone = $signal['tone'] ?? 'neutral';
                                    $textClass = $toneAccentText[$tone] ?? 'text-slate-700';
                                    $anchor = $durationAnchors[$duration] ?? '';
                                @endphp
                                <tr>
                                    <td class="py-4 pr-3 pl-4 sm:pl-0">
                                        <a href="#{{ $anchor }}" data-no-nav-loading
                                           class="font-semibold text-slate-900 hover:text-coral-700 underline decoration-slate-200 decoration-2 underline-offset-4 hover:decoration-coral-400">
                                            {{ $durationLabels[$duration] ?? ($duration . ' kk') }}
                                        </a>
                                        @if ($payload && $payload['contract_count'])
                                            <span class="block text-xs text-slate-400 mt-0.5 tabular-nums">
                                                {{ number_format($payload['contract_count'], 0, ',', ' ') }} sopimusta
                                            </span>
                                        @endif
                                    </td>

                                    @if ($payload && $median)
                                        <td class="py-4 px-3 text-right tabular-nums font-semibold text-slate-900">
                                            {{ $fmtNum($median['current_price'], 2) }}<span class="text-slate-400 font-normal">&nbsp;c/kWh</span>
                                        </td>
                                        <td class="py-4 px-3 text-right tabular-nums text-slate-700">
                                            {{ $fmtNum($median['forecast_price'], 2) }}<span class="text-slate-400 font-normal">&nbsp;c/kWh</span>
                                        </td>
                                        <td class="py-4 px-3 text-right tabular-nums {{ $textClass }}">
                                            {{ $fmtSignedCents($median['expected_change'], 2) }}<span class="text-slate-400 font-normal">&nbsp;c/kWh</span>
                                            <span class="block text-xs text-slate-400 mt-0.5">{{ $fmtPct($median['expected_change_pct']) }}</span>
                                        </td>
                                        <td class="py-4 px-3 text-right tabular-nums text-slate-700">
                                            {{ $fmtNum($median['fair_price'], 2) }}<span class="text-slate-400 font-normal">&nbsp;c/kWh</span>
                                        </td>
                                        <td class="py-4 pl-3 pr-4 sm:pr-0">
                                            <span class="inline-flex items-center gap-1 rounded-full px-2.5 py-1 text-xs font-semibold tracking-wide {{ $toneBadgeClass[$tone] ?? $toneBadgeClass['neutral'] }}">
                                                @if ($tone === 'up')
                                                    <svg class="w-3 h-3" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M5 17l7-7 4 4 5-5"/></svg>
                                                @elseif ($tone === 'down')
                                                    <svg class="w-3 h-3" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M5 7l7 7 4-4 5 5"/></svg>
                                                @else
                                                    <svg class="w-3 h-3" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M4 12h16"/></svg>
                                                @endif
                                                {{ $signal['label'] ?? 'Ei tietoa' }}
                                            </span>
                                        </td>
                                    @else
                                        <td colspan="5" class="py-4 px-3 text-sm text-slate-400">Ei tarpeeksi aineistoa juuri nyt.</td>
                                    @endif
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </section>

            {{-- Per-duration deep-dive --}}
            <section class="mb-20" aria-labelledby="deepdive-heading">
                <h2 id="deepdive-heading" class="text-2xl font-bold text-slate-900 tracking-tight mb-2">
                    Sopimuspituudet tarkemmin
                </h2>
                <p class="text-sm text-slate-500 max-w-[60ch] mb-7">
                    Markkinatason hinta on pörssifutuurien hinta + tämän sopimustyypin tavanomainen myyjän kate. Jos tarjottu hinta on selvästi markkinatason yläpuolella, malli odottaa laskua kohti sitä; jos selvästi alapuolella, malli odottaa nousua. Liikkeet ovat aina maltillisia, sillä retail-hinnat seuraavat futuureja vain hitaasti.
                </p>

                {{-- TOC chips, matching /sahkosopimus/tilastot deep-dive nav. --}}
                <nav aria-label="Sopimuspituudet" class="mb-12 flex flex-wrap gap-2">
                    @foreach ($durations as $duration)
                        @php
                            $payload = $rowsByDuration->get($duration);
                            $signal = $payload['signal'] ?? null;
                            $tone = $signal['tone'] ?? 'neutral';
                            $anchor = $durationAnchors[$duration] ?? '';
                        @endphp
                        <a href="#{{ $anchor }}"
                           data-no-nav-loading
                           class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-full bg-slate-50 border border-slate-200 text-xs font-semibold tracking-wide text-slate-700 hover:bg-white hover:border-slate-400 hover:text-slate-900 transition-colors">
                            {{ $durationLabels[$duration] ?? ($duration . ' kk') }}
                            @if ($signal && in_array($tone, ['up', 'down'], true))
                                <span class="text-[11px] font-bold {{ $tone === 'down' ? 'text-emerald-700' : 'text-coral-700' }}">
                                    {{ $signal['label'] }}
                                </span>
                            @endif
                        </a>
                    @endforeach
                </nav>

                <div class="divide-y divide-slate-200">
                    @foreach ($durations as $duration)
                        @php
                            $payload = $rowsByDuration->get($duration);
                            $anchor = $durationAnchors[$duration] ?? '';
                            $signal = $payload['signal'] ?? null;
                            $tone = $signal['tone'] ?? 'neutral';
                        @endphp
                        <article id="{{ $anchor }}" class="scroll-mt-24 pt-16 first:pt-0 pb-16 last:pb-0">
                            <header class="flex flex-wrap items-baseline justify-between gap-x-6 gap-y-2 mb-3">
                                <h3 class="text-2xl md:text-[1.75rem] font-bold text-slate-900 tracking-tight leading-tight">
                                    {{ $durationLabels[$duration] ?? ($duration . ' kk') }}: hintaennuste
                                </h3>
                                @if ($payload && $payload['contract_count'])
                                    <span class="{{ $colEyebrow }} text-slate-500">
                                        {{ number_format($payload['contract_count'], 0, ',', ' ') }} sopimusta
                                    </span>
                                @endif
                            </header>

                            <p class="text-base text-slate-600 leading-relaxed max-w-[64ch] mb-8">
                                {{ $durationDescriptions[$duration] ?? '' }}
                            </p>

                            @if (! $payload || empty($payload['lanes']))
                                <p class="text-sm text-slate-500">Ei tarpeeksi aineistoa tämän sopimuspituuden ennusteeseen.</p>
                            @else

                                {{-- Compact signal row: eyebrow + badge + body sentence.
                                     Stays subordinate to the H3 above so the document outline reads correctly. --}}
                                @if ($signal)
                                    <div class="mb-10 max-w-[64ch]">
                                        <div class="flex flex-wrap items-center gap-3 mb-3">
                                            <p class="{{ $colEyebrow }} text-slate-500">
                                                30 päivän suositus
                                            </p>
                                            <span class="inline-flex items-center gap-1.5 rounded-full px-3 py-1 text-xs font-semibold tracking-wide {{ $toneBadgeClass[$tone] ?? $toneBadgeClass['neutral'] }}">
                                                @if ($tone === 'up')
                                                    <svg class="w-3 h-3" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M5 17l7-7 4 4 5-5"/></svg>
                                                @elseif ($tone === 'down')
                                                    <svg class="w-3 h-3" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M5 7l7 7 4-4 5 5"/></svg>
                                                @else
                                                    <svg class="w-3 h-3" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M4 12h16"/></svg>
                                                @endif
                                                {{ $signal['label'] }}
                                            </span>
                                        </div>
                                        <p class="text-base text-slate-700 leading-relaxed">
                                            {{ $signal['body'] }}
                                        </p>
                                    </div>
                                @endif

                                {{-- Lanes table: p20 / median / p80 --}}
                                <div class="-mx-4 sm:mx-0 overflow-x-auto">
                                    <table class="w-full text-sm border-collapse">
                                        <thead>
                                            <tr class="text-left {{ $colEyebrow }} text-slate-500 border-b border-slate-300">
                                                <th class="py-3 pr-3 pl-4 sm:pl-0 font-semibold">Hintataso</th>
                                                <th class="py-3 px-3 font-semibold text-right">Hinta nyt</th>
                                                <th class="py-3 px-3 font-semibold text-right">Ennuste {{ $horizonDays }}&nbsp;pv</th>
                                                <th class="py-3 px-3 font-semibold text-right">Muutos</th>
                                                <th class="py-3 px-3 font-semibold text-right">Markkinatason hinta</th>
                                                <th class="py-3 pl-3 pr-4 sm:pr-0 font-semibold text-center">Suunta</th>
                                            </tr>
                                        </thead>
                                        <tbody class="divide-y divide-slate-100">
                                            @foreach ($payload['lanes'] as $lane)
                                                @php
                                                    $isLead = $lane['quantile'] === 'median';
                                                    $laneTone = $directionTone($lane['direction']);
                                                    $laneAccentText = $toneAccentText[$laneTone] ?? 'text-slate-700';
                                                @endphp
                                                <tr class="{{ $isLead ? 'bg-coral-50/40' : '' }}">
                                                    <td class="py-3 pr-3 pl-4 sm:pl-0">
                                                        <span class="font-semibold text-slate-900 {{ $isLead ? 'text-coral-700' : '' }}">
                                                            {{ $lane['label'] }}
                                                        </span>
                                                        <span class="block text-xs text-slate-500 mt-0.5">{{ $lane['sublabel'] }}</span>
                                                    </td>
                                                    <td class="py-3 px-3 text-right tabular-nums font-semibold text-slate-900">
                                                        {{ $fmtNum($lane['current_price'], 2) }}<span class="text-slate-400 font-normal">&nbsp;c/kWh</span>
                                                    </td>
                                                    <td class="py-3 px-3 text-right tabular-nums text-slate-700">
                                                        {{ $fmtNum($lane['forecast_price'], 2) }}<span class="text-slate-400 font-normal">&nbsp;c/kWh</span>
                                                    </td>
                                                    <td class="py-3 px-3 text-right tabular-nums {{ $laneAccentText }}">
                                                        {{ $fmtSignedCents($lane['expected_change'], 2) }}&nbsp;c/kWh
                                                        <span class="block text-xs text-slate-500 mt-0.5">{{ $fmtPct($lane['expected_change_pct']) }}</span>
                                                    </td>
                                                    <td class="py-3 px-3 text-right tabular-nums text-slate-700">
                                                        {{ $fmtNum($lane['fair_price'], 2) }}<span class="text-slate-400 font-normal">&nbsp;c/kWh</span>
                                                        <span class="block text-xs text-slate-500 mt-0.5">
                                                            ero {{ $fmtSignedCents(-$lane['gap'], 2) }}&nbsp;c/kWh
                                                        </span>
                                                    </td>
                                                    <td class="py-3 pl-3 pr-4 sm:pr-0 text-center">
                                                        <span class="inline-flex items-center gap-1 rounded-full px-2.5 py-1 text-xs font-semibold tracking-wide {{ $toneBadgeClass[$laneTone] }}">
                                                            {{ $directionLabels[$lane['direction']] ?? $lane['direction'] }}
                                                        </span>
                                                    </td>
                                                </tr>
                                            @endforeach
                                        </tbody>
                                    </table>
                                </div>

                                {{-- Footnote strip --}}
                                <dl class="mt-8 grid grid-cols-2 sm:grid-cols-4 gap-x-8 gap-y-5 text-sm">
                                    <div>
                                        <dt class="{{ $colEyebrow }} text-slate-500">Futuurien hinta</dt>
                                        <dd class="mt-1 font-semibold text-slate-900 tabular-nums">
                                            {{ $fmtNum($payload['hedge_cost'], 2) }}<span class="text-slate-400 font-normal">&nbsp;c/kWh</span>
                                        </dd>
                                    </div>
                                    <div>
                                        <dt class="{{ $colEyebrow }} text-slate-500">Futuuripäivä</dt>
                                        <dd class="mt-1 font-semibold text-slate-900 tabular-nums">
                                            {{ $payload['futures_trade_date'] ? $fiDate($payload['futures_trade_date']) : '–' }}
                                        </dd>
                                    </div>
                                    <div>
                                        <dt class="{{ $colEyebrow }} text-slate-500">Futuurikatto</dt>
                                        <dd class="mt-1 font-semibold text-slate-900">
                                            {{ $coverageLabels[$payload['coverage_quality'] ?? ''] ?? '–' }}
                                        </dd>
                                    </div>
                                    <div>
                                        <dt class="{{ $colEyebrow }} text-slate-500">Luotettavuus</dt>
                                        <dd class="mt-1 font-semibold text-slate-900">
                                            {{ $confidenceLabels[$payload['confidence']] ?? '–' }}
                                        </dd>
                                    </div>
                                </dl>
                            @endif
                        </article>
                    @endforeach
                </div>
            </section>

            {{-- Historical forecast trend --}}
            @if ($history->isNotEmpty())
                @php
                    // Aineiston aikaväli intro-tekstiin. $history on koottu vain median-kvantiilista,
                    // joten päivämäärät ovat samat sopimuspituuksien välillä; käytetään ensimmäistä sarjaa.
                    $historyDates = $history->first()['x'] ?? [];
                    $historyFirstDate = ! empty($historyDates) ? Cb::createFromTimestamp(min($historyDates))->translatedFormat('j.n.Y') : null;
                    $historyLastDate = ! empty($historyDates) ? Cb::createFromTimestamp(max($historyDates))->translatedFormat('j.n.Y') : null;
                @endphp
                <section class="mb-20" aria-labelledby="history-heading">
                    <h2 id="history-heading" class="text-2xl font-bold text-slate-900 tracking-tight mb-2">
                        Mediaanihinta viime kuukausina
                    </h2>
                    <p class="text-sm text-slate-500 max-w-[64ch] mb-8">
                        @if ($historyFirstDate && $historyLastDate)
                            Aineisto on kerätty {{ $historyFirstDate }}–{{ $historyLastDate }}.
                        @endif
                        Taulukko näyttää, miten tarjottujen määräaikaisten sopimusten mediaanihinta on muuttunut Voltikan ennustehistorian aikana. Jokainen mittauspäivä on yksi piste oikealla näkyvässä trendiviivassa.
                    </p>

                    <div class="-mx-4 sm:mx-0 overflow-x-auto">
                        <table class="w-full text-sm border-collapse">
                            <thead>
                                <tr class="text-left {{ $colEyebrow }} text-slate-500 border-b border-slate-300">
                                    <th class="py-3 pr-3 pl-4 sm:pl-0 font-semibold">Sopimuspituus</th>
                                    <th class="py-3 px-3 font-semibold text-right">Aineiston alussa</th>
                                    <th class="py-3 px-3 font-semibold text-right">Tuorein</th>
                                    <th class="py-3 px-3 font-semibold text-right">Muutos koko jaksolla</th>
                                    <th class="py-3 pl-3 pr-4 sm:pr-0 font-semibold">Trendi</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-slate-100">
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
                                    @endphp
                                    <tr>
                                        <td class="py-3 pr-3 pl-4 sm:pl-0 font-semibold text-slate-900">
                                            {{ $durationLabels[$series['duration_months']] ?? $series['label'] }}
                                            @if ($count)
                                                <span class="block text-xs text-slate-500 mt-0.5 tabular-nums">
                                                    {{ number_format($count, 0, ',', ' ') }} mittausta
                                                </span>
                                            @endif
                                        </td>
                                        <td class="py-3 px-3 text-right tabular-nums text-slate-700">{{ $fmtNum($first, 2) }}<span class="text-slate-400 font-normal">&nbsp;c/kWh</span></td>
                                        <td class="py-3 px-3 text-right tabular-nums font-semibold text-slate-900">{{ $fmtNum($last, 2) }}<span class="text-slate-400 font-normal">&nbsp;c/kWh</span></td>
                                        <td class="py-3 px-3 text-right tabular-nums {{ $delta !== null && $delta < -0.5 ? 'text-emerald-700' : ($delta !== null && $delta > 0.5 ? 'text-slate-700' : 'text-slate-500') }}">
                                            {{ $fmtPct($delta) }}
                                        </td>
                                        <td class="py-3 pl-3 pr-4 sm:pr-0">
                                            @if ($sparklinePath)
                                                <svg viewBox="0 0 120 28" width="120" height="28" class="block" preserveAspectRatio="none" aria-label="{{ $series['label'] }} trendi">
                                                    <path d="{{ $sparklinePath }}" fill="none" stroke="#64748b" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" />
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
            @endif

            {{-- Methodology --}}
            <section id="menetelma" class="grid grid-cols-1 lg:grid-cols-2 gap-12 lg:gap-16 pt-12 border-t border-slate-200">
                <div>
                    <h2 class="text-2xl font-bold text-slate-900 tracking-tight mb-4">Miten ennuste lasketaan</h2>
                    <div class="space-y-4 text-base text-slate-600 leading-relaxed max-w-[58ch]">
                        <p>
                            Malli on tarkoitettu yksinkertaiseksi kuluttajan apuvälineeksi, ei markkinaennusteeksi. Se vastaa kysymykseen: <em>onko tämänhetkinen tarjottu hinta poikkeuksellisen korkea, matala vai tavanomainen seuraavaa kuukautta varten?</em>
                        </p>
                        <p>
                            Mallin syötteet ovat (1) tämänhetkinen mediaani-/p20-/p80-hinta määräaikaisille sopimuksille Voltikan päivittäisestä aineistosta, ja (2) suomalaisten EEX Base -sähköfutuurien settlement-hinnat. Futuurien hinnat muunnetaan kuluttajan hintayksiköihin (sentit/kWh sis. ALV) ja painotetaan sopimuksen toimitusjakson yli.
                        </p>
                        <p>
                            Malli arvioi sopimustyypin tavanomaisen myyjän katteen historiallisesta erosta tarjotun hinnan ja futuurien välillä (eksponentiaalinen liukuva keskiarvo). Markkinatason hinta on futuurien hinta + tavanomainen kate. Jos tarjottu hinta on tämän yläpuolella, malli odottaa tasaantumista alaspäin; jos alapuolella, ylöspäin. Yksi 30 päivän ennuste sulkee noin 30 % näistä erosta.
                        </p>
                        <p>
                            Pieniin liikkeisiin (alle 0,15&nbsp;c/kWh) sovelletaan "lievästi nouseva" / "lievästi laskeva" -leimaa, joka kääntyy kuluttajan suosituksessa neutraaliksi. Vain selvä nouseva tai laskeva näkymä antaa "lukitse pian" tai "voit odottaa" -suosituksen.
                        </p>
                    </div>
                </div>

                <div>
                    <h2 class="text-2xl font-bold text-slate-900 tracking-tight mb-4">Tärkeää huomioida</h2>
                    <div class="space-y-4 text-base text-slate-600 leading-relaxed max-w-[58ch]">
                        <p>
                            Ennuste perustuu julkisesti saatavilla olevien futuurien hintoihin. Yksittäisten sähköntoimittajien hinnoittelu voi muuttua eri logiikalla ja eri ajoituksella kuin malli olettaa.
                        </p>
                        <p>
                            Lyhyellä aikavälillä (alle viikon) malli ei yritä ennustaa yksittäisten tarjousten muutoksia tai kampanjoita. Päätös sopimuksen tekemisestä kannattaa aina perustaa myös sopimusehtoihin, irtisanomisaikoihin ja perusmaksuun, ei vain energiahintaan.
                        </p>
                        <p>
                            Aineiston pituuden mukaan ennusteen luotettavuus on luokiteltu matalaksi, keskimääräiseksi tai korkeaksi. Matala luotettavuus tarkoittaa, että historiallista vertailuaineistoa on toistaiseksi vähän, joten lukua kannattaa pitää suuntaa-antavana.
                        </p>
                        <p>
                            Voltikka ei anna sijoitus- tai sopimusneuvontaa. Tämä sivu on tarkoitettu auttamaan kuluttajaa hahmottamaan, missä määräaikaisten sopimusten hinnat liikkuvat juuri nyt.
                        </p>
                    </div>
                </div>
            </section>

            {{-- Citation block. Forecast is a model-derived data product; citation framing makes that explicit
                 by naming "Voltikan sähkön hintaennuste" rather than presenting it as a market measurement. --}}
            <section id="viittaa" class="grid grid-cols-1 lg:grid-cols-2 gap-12 lg:gap-16 pt-12 mt-20 border-t border-slate-200">
                <div>
                    <h2 class="text-2xl font-bold text-slate-900 tracking-tight mb-4">Viittaa tähän</h2>
                    <p class="text-base text-slate-600 leading-relaxed max-w-[58ch] mb-3">
                        Käytä alla olevaa lähdemerkintää, jos lainaat tätä ennustetta artikkelissa, tutkimuksessa tai sosiaalisessa mediassa. Aineisto on lisensoitu CC&nbsp;BY&nbsp;4.0.
                    </p>
                    <p class="text-sm text-slate-500 max-w-[58ch]">
                        Huomioi, että kyseessä on Voltikan oma mallipohjainen ennuste, ei pörssistä mitattu hinta. Mainitse aina lähteenä "Voltikan sähkön hintaennuste" tai vastaava, jotta lukija tietää luvun olevan ennuste.
                    </p>
                </div>

                <div x-data="voltikkaCite({
                    plain: @js($citations['plain']),
                    markdown: @js($citations['markdown']),
                    html: @js($citations['html']),
                })">
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
