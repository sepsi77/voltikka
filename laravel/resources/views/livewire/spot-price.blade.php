<div>
    <x-schema-markup :schemas="[$jsonLd, $faqJsonLd]" />
    @php
        // Earliest chronologically cheap hour, NOT the absolute cheapest 24+ h away.
        $heroNextCheap = $nextCheapHour ?? null;
        $heroNextCheapEta = null;
        if ($heroNextCheap) {
            $heroNcDate = \Carbon\Carbon::parse($heroNextCheap['helsinki_date'].' '.str_pad($heroNextCheap['helsinki_hour'], 2, '0', STR_PAD_LEFT).':00:00', 'Europe/Helsinki');
            $heroHoursDiff = max(0, $heroNcDate->diffInMinutes(now('Europe/Helsinki'), false) * -1 / 60);
            $heroNcIsTomorrow = $heroNextCheap['helsinki_date'] !== now('Europe/Helsinki')->format('Y-m-d');
            if ($heroHoursDiff < 1) {
                $heroNextCheapEta = 'alle tunnin kuluttua';
            } elseif ($heroNcIsTomorrow) {
                $heroNextCheapEta = 'huomenna';
            } else {
                $heroNextCheapEta = 'noin ' . round($heroHoursDiff) . ' h kuluttua';
            }
        }
        $heroVerdict = $todayVerdict['verdict'] ?? null;
        $heroVerdictChipColors = match ($heroVerdict) {
            'cheap' => 'bg-emerald-400/10 text-emerald-300 ring-1 ring-emerald-400/20',
            'expensive' => 'bg-red-400/10 text-red-300 ring-1 ring-red-400/20',
            default => 'bg-amber-400/10 text-amber-300 ring-1 ring-amber-400/20',
        };
    @endphp

    <!-- Hero Section - Dark slate "moment of commitment" -->
    <section class="relative bg-slate-950 mb-0 overflow-hidden">
        <!-- ambient warm wash, low saturation, far corner -->
        <div class="pointer-events-none absolute -top-32 -right-32 w-[480px] h-[480px] rounded-full bg-coral-500/10 blur-3xl"></div>
        <div class="pointer-events-none absolute -bottom-40 -left-20 w-[360px] h-[360px] rounded-full bg-coral-600/5 blur-3xl"></div>

        <div class="relative max-w-7xl mx-auto px-4 sm:px-6 lg:px-8" style="font-variant-numeric: tabular-nums;">
            <div class="pt-10 lg:pt-14 pb-10 lg:pb-12">
                <h1 class="text-4xl md:text-5xl xl:text-6xl font-extrabold text-white tracking-tight leading-[1.05]">
                    <span class="text-coral-400">Pörssisähkön</span> hinta tänään ja huomenna
                </h1>
                <p class="mt-4 text-slate-200 text-base md:text-lg max-w-2xl leading-relaxed">
                    Tuntihinnat tänään ja huomenna sekä pörssisähkön hintaennuste seuraaville päiville. Katso, kannattaako sauna, pyykinpesu tai auton lataus käynnistää nyt vai odottaa halvempaa tuntia.
                </p>

                @if ($currentPrice)
                    <div class="mt-8 lg:mt-10 flex flex-col lg:flex-row lg:items-start gap-8 lg:gap-10">
                        <!-- Live price + verdict (main) -->
                        <div class="flex-1 min-w-0" x-data="{ showTooltip: false }">
                            <div class="flex items-center gap-2 mb-3">
                                <span class="text-sm font-semibold uppercase tracking-[0.08em] text-slate-300">Tämänhetkinen hinta</span>
                                <span class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full text-sm font-semibold bg-coral-500/20 text-coral-200 ring-1 ring-coral-400/40">
                                    <span class="relative flex w-2 h-2">
                                        <span class="absolute inline-flex w-full h-full rounded-full bg-coral-400 opacity-75 animate-ping"></span>
                                        <span class="relative inline-flex w-2 h-2 rounded-full bg-coral-300"></span>
                                    </span>
                                    Nyt
                                </span>
                                <div class="relative">
                                    <button
                                        type="button"
                                        @click="showTooltip = !showTooltip"
                                        @click.outside="showTooltip = false"
                                        class="text-slate-300 hover:text-white focus:outline-none transition-colors"
                                        aria-label="Lisätietoja hinnasta"
                                    >
                                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                                        </svg>
                                    </button>
                                    <div
                                        x-show="showTooltip"
                                        x-transition.opacity
                                        class="absolute left-0 top-full mt-2 z-50 w-72 p-3 bg-white text-slate-700 text-sm rounded-lg shadow-xl ring-1 ring-slate-200"
                                    >
                                        <p class="font-medium text-slate-900 mb-2">Mitä hinta sisältää?</p>
                                        <p class="text-slate-600">Spot-hinta (Nord Pool) + ALV 25,5 %.</p>
                                        <p class="text-slate-500 mt-2 text-xs">Ei sisällä siirtoa (~3–5 c/kWh) eikä sopimuksesi marginaalia (~0,3–0,5 c/kWh).</p>
                                    </div>
                                </div>
                            </div>

                            <p class="text-white leading-[0.9] font-extrabold tracking-tight whitespace-nowrap text-6xl sm:text-7xl lg:text-8xl">
                                {{ number_format($currentPrice['price_with_tax'] ?? 0, 2, ',', ' ') }}<span class="text-2xl sm:text-3xl lg:text-4xl font-bold text-slate-300 ml-2 sm:ml-3 align-baseline">c/kWh</span>
                            </p>

                            <p class="mt-5 text-base text-slate-200 font-medium">
                                <span class="whitespace-nowrap">{{ $currentPrice['time_label'] ?? now('Europe/Helsinki')->format('H') . ':00 - ' . now('Europe/Helsinki')->addHour()->format('H') . ':00' }}</span>
                                <span class="text-slate-500 mx-2">·</span>
                                <span class="text-slate-300 font-normal">spot sis. ALV, ei siirtoa tai marginaalia</span>
                            </p>

                            @if ($heroVerdict !== null)
                                @php
                                    $vDiff = $todayVerdict['percent_diff'];
                                    if ($vDiff === null) {
                                        $diffText = null;
                                    } elseif ($vDiff > 0) {
                                        $diffText = '+' . number_format($vDiff, 1, ',', ' ') . ' % yli ka.';
                                    } elseif ($vDiff < 0) {
                                        $diffText = number_format(abs($vDiff), 1, ',', ' ') . ' % alle ka.';
                                    } else {
                                        $diffText = 'lähellä ka.';
                                    }
                                @endphp
                                <div class="mt-7 flex flex-wrap items-center gap-x-4 gap-y-3 text-base text-slate-200">
                                    <span class="inline-flex items-center gap-2 px-4 py-2 rounded-full text-base font-semibold {{ $heroVerdictChipColors }}">
                                        @if ($heroVerdict === 'cheap')
                                            <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="3" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M19 14l-7 7m0 0l-7-7m7 7V3"/></svg>
                                        @elseif ($heroVerdict === 'expensive')
                                            <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="3" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M5 10l7-7m0 0l7 7m-7-7v18"/></svg>
                                        @endif
                                        {{ $todayVerdict['verdict_label'] }}
                                        @if ($diffText !== null)
                                            <span class="opacity-90">· {{ $diffText }}</span>
                                        @endif
                                    </span>
                                    <span class="text-slate-300">
                                        Tänään keskimäärin <span class="font-bold text-white">{{ number_format($todayVerdict['today_avg_with_vat'], 2, ',', ' ') }} c/kWh</span>,
                                        30 päivän keskiarvo <span class="font-bold text-white">{{ number_format($todayVerdict['avg_30d_with_vat'], 2, ',', ' ') }} c/kWh</span>.
                                        <span class="font-semibold text-white">{{ $todayVerdict['hours_above_avg'] }} tuntia {{ $todayVerdict['total_hours'] }}:stä</span> yli keskiarvon.
                                    </span>
                                </div>
                            @endif
                        </div>

                        <!-- Next cheap window — glass card on dark hero (sanctioned by DESIGN.md) -->
                        @if ($heroNextCheap)
                            @php
                                $hncStart = str_pad($heroNextCheap['helsinki_hour'], 2, '0', STR_PAD_LEFT);
                                $hncEnd = str_pad(($heroNextCheap['helsinki_hour'] + 1) % 24, 2, '0', STR_PAD_LEFT);
                            @endphp
                            <a
                                href="#tunnit"
                                data-no-nav-loading
                                @click.prevent="document.getElementById('tunnit')?.scrollIntoView({ behavior: 'smooth', block: 'start' })"
                                class="block group w-full lg:w-80 lg:shrink-0"
                            >
                                <div class="relative rounded-2xl bg-white/[0.08] backdrop-blur-sm ring-1 ring-white/15 p-6 transition-all duration-200 hover:bg-white/[0.12] hover:ring-white/25">
                                    <div class="flex items-center justify-between mb-4">
                                        <span class="inline-flex items-center gap-2 text-sm font-semibold uppercase tracking-[0.08em] text-emerald-300">
                                            <span class="relative flex w-2 h-2">
                                                <span class="absolute inline-flex w-full h-full rounded-full bg-emerald-400 opacity-60 animate-ping"></span>
                                                <span class="relative inline-flex w-2 h-2 rounded-full bg-emerald-400"></span>
                                            </span>
                                            Seuraava halpa tunti
                                        </span>
                                        <svg class="w-5 h-5 text-slate-300 group-hover:text-white group-hover:translate-x-0.5 transition-all" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" d="M17 8l4 4m0 0l-4 4m4-4H3"/>
                                        </svg>
                                    </div>
                                    <p class="text-3xl lg:text-4xl font-extrabold text-white leading-none tracking-tight">
                                        {{ $hncStart }}:00<span class="text-slate-400">–</span>{{ $hncEnd }}:00
                                    </p>
                                    <div class="mt-4 flex items-baseline justify-between gap-2">
                                        <span class="text-2xl font-bold text-emerald-300">
                                            {{ number_format($heroNextCheap['price_with_tax'], 2, ',', ' ') }}<span class="text-base font-semibold text-slate-300 ml-1.5">c/kWh</span>
                                        </span>
                                        <span class="text-sm font-medium text-slate-300">{{ $heroNextCheapEta }}</span>
                                    </div>
                                    @if (isset($currentPrice['price_with_tax']) && $heroNextCheap['price_with_tax'] < $currentPrice['price_with_tax'])
                                        @php
                                            $savePct = (($currentPrice['price_with_tax'] - $heroNextCheap['price_with_tax']) / $currentPrice['price_with_tax']) * 100;
                                        @endphp
                                        <p class="mt-4 pt-4 border-t border-white/15 text-sm text-slate-200">
                                            <span class="font-bold text-emerald-300">{{ number_format($savePct, 0, ',', ' ') }} % halvempi</span> kuin nyt — odota jos voit.
                                        </p>
                                    @endif
                                </div>
                            </a>
                        @endif
                    </div>
                @endif
            </div>
        </div>

        <!-- Daily stats strip — light slab below hero, snug joint -->
        @if ($currentPrice && ($cheapestHour || $mostExpensiveHour))
            <div class="relative bg-white border-t border-slate-100">
                <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-5 lg:py-6">
                    <div class="grid grid-cols-1 sm:grid-cols-3 divide-y sm:divide-y-0 sm:divide-x divide-slate-200" style="font-variant-numeric: tabular-nums;">
                        <div class="py-3 sm:py-0 sm:pr-4 lg:pr-8 flex flex-row items-baseline justify-between gap-3 sm:block">
                            <p class="text-sm font-semibold uppercase tracking-[0.08em] text-slate-500 shrink-0 sm:mb-2">Päivän alin</p>
                            @if ($cheapestHour)
                                <div class="text-right sm:text-left">
                                    <p class="text-2xl lg:text-3xl font-bold text-emerald-700">
                                        {{ number_format($cheapestHour['price_with_tax'] ?? 0, 2, ',', ' ') }}<span class="text-base text-slate-500 font-semibold ml-1.5">c/kWh</span>
                                    </p>
                                    @if (isset($cheapestHour['helsinki_hour']))
                                        <p class="text-sm text-slate-600 mt-1 font-medium">klo {{ str_pad($cheapestHour['helsinki_hour'], 2, '0', STR_PAD_LEFT) }}:00</p>
                                    @endif
                                </div>
                            @else
                                <p class="text-2xl lg:text-3xl font-bold text-slate-300">–</p>
                            @endif
                        </div>
                        <div class="py-3 sm:py-0 sm:px-4 lg:px-8 flex flex-row items-baseline justify-between gap-3 sm:block">
                            <p class="text-sm font-semibold uppercase tracking-[0.08em] text-slate-500 shrink-0 sm:mb-2">Päivän ylin</p>
                            @if ($mostExpensiveHour)
                                <div class="text-right sm:text-left">
                                    <p class="text-2xl lg:text-3xl font-bold text-rose-700">
                                        {{ number_format($mostExpensiveHour['price_with_tax'] ?? 0, 2, ',', ' ') }}<span class="text-base text-slate-500 font-semibold ml-1.5">c/kWh</span>
                                    </p>
                                    @if (isset($mostExpensiveHour['helsinki_hour']))
                                        <p class="text-sm text-slate-600 mt-1 font-medium">klo {{ str_pad($mostExpensiveHour['helsinki_hour'], 2, '0', STR_PAD_LEFT) }}:00</p>
                                    @endif
                                </div>
                            @else
                                <p class="text-2xl lg:text-3xl font-bold text-slate-300">–</p>
                            @endif
                        </div>
                        <div class="py-3 sm:py-0 sm:pl-4 lg:pl-8 flex flex-row items-baseline justify-between gap-3 sm:block">
                            <p class="text-sm font-semibold uppercase tracking-[0.08em] text-slate-500 shrink-0 sm:mb-2"><x-info-tip text="Liukuva 30 vuorokauden keskihinta (sis. ALV 25,5 %). Toimii vertailutasona 'edullinen/kallis päivä' -arviossa ja energiavinkkien prosenttierossa.">30 pv keskiarvo</x-info-tip></p>
                            <div class="text-right sm:text-left">
                                <p class="text-2xl lg:text-3xl font-bold text-slate-900">
                                    {{ number_format($todayVerdict['avg_30d_with_vat'] ?? 0, 2, ',', ' ') }}<span class="text-base text-slate-500 font-semibold ml-1.5">c/kWh</span>
                                </p>
                                <p class="text-sm text-slate-600 mt-1 font-medium">vertailutaso</p>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        @endif
    </section>

    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 pt-10 lg:pt-12 pb-8 spot-price-page" style="font-variant-numeric: tabular-nums;">

    @if ($loading)
        <div class="flex items-center justify-center py-12">
            <svg class="animate-spin h-8 w-8 text-coral-500" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
            </svg>
            <span class="ml-3 text-slate-600">Ladataan hintatietoja...</span>
        </div>
    @elseif ($error)
        <div class="bg-red-50 border border-red-200 rounded-lg p-4 mb-8">
            <p class="text-red-700">{{ $error }}</p>
        </div>
    @elseif (empty($hourlyPrices))
        <div class="bg-yellow-50 border border-yellow-200 rounded-lg p-4 mb-8">
            <p class="text-yellow-700">Hintatietoja ei ole vielä saatavilla. Tiedot päivitetään automaattisesti.</p>
        </div>
    @else
        <!-- Kodin energiavinkit - Home Energy Tips Section -->
        <section class="mb-8">
            <div class="flex items-baseline justify-between mb-4 gap-4 flex-wrap">
                <div>
                    <h2 class="text-xl md:text-2xl font-bold text-slate-900">Kodin energiavinkit</h2>
                    <p class="text-sm text-slate-600 mt-0.5">Seuraavat edullisimmat ajat yleisimmille energiaa kuluttaville tehtäville.</p>
                </div>
            </div>

            <div class="grid grid-cols-2 md:grid-cols-3 lg:grid-cols-5 gap-3">
                <!-- EV Charging — full-day window, 3 h consecutive -->
                @if ($bestConsecutiveHours)
                    @include('partials.appliance-tip-card', [
                        'title'            => 'Sähköauton lataus',
                        'assumption'       => '3 h putkeen, 3,7 kW',
                        'startHour'        => $bestConsecutiveHours['start_hour'],
                        'endHour'          => $bestConsecutiveHours['end_hour'],
                        'startDate'        => $bestConsecutiveHours['start_date'] ?? null,
                        'endDate'          => $bestConsecutiveHours['end_date'] ?? null,
                        'cost'             => ['type' => 'rate', 'value' => $bestConsecutiveHours['average_price']],
                        'diffPercent'      => $bestConsecutiveHours['diff_from_30d_percent'] ?? null,
                        'savingsEuros'     => $potentialSavings['savings_euros'] ?? null,
                        'comparisonLabel'  => 'vs päivän kalleimmat 3 h',
                        'icon'             => 'M13 10V3L4 14h7v7l9-11h-7z',
                    ])
                @endif

                <!-- Sauna — bounded to 17–22 -->
                @if ($saunaCost)
                    @include('partials.appliance-tip-card', [
                        'title'            => 'Saunan lämmitys',
                        'assumption'       => 'Illalla 17–22, 8 kW kiuas, 1 h',
                        'startHour'        => $saunaCost['cheapest_hour'],
                        'endHour'          => $saunaCost['cheapest_hour'],
                        'startDate'        => $saunaCost['start_date'] ?? null,
                        'endDate'          => $saunaCost['end_date'] ?? null,
                        'cost'             => ['type' => 'cents', 'value' => $saunaCost['cheapest_cost']],
                        'diffPercent'      => $saunaCost['diff_from_30d_percent'] ?? null,
                        'savingsEuros'     => $saunaCost['cost_difference_euros'] ?? null,
                        'comparisonLabel'  => 'vs kallein tunti klo 17–22',
                        'icon'             => 'M17.657 18.657A8 8 0 016.343 7.343S7 9 9 10c0-2 .5-5 2.986-7C14 5 16.09 5.777 17.656 7.343A7.975 7.975 0 0120 13a7.975 7.975 0 01-2.343 5.657z M9.879 16.121A3 3 0 1012.015 11L11 14H9c0 .768.293 1.536.879 2.121z',
                    ])
                @endif

                <!-- Laundry — bounded to 07–22 -->
                @if (isset($laundryCost) && $laundryCost)
                    @include('partials.appliance-tip-card', [
                        'title'            => 'Pyykinpesu',
                        'assumption'       => 'Päivällä 07–22, 2 h, 2 kW',
                        'startHour'        => $laundryCost['start_hour'],
                        'endHour'          => $laundryCost['end_hour'] - 1,
                        'startDate'        => $laundryCost['start_date'] ?? null,
                        'endDate'          => $laundryCost['end_date'] ?? null,
                        'cost'             => ['type' => 'cents', 'value' => $laundryCost['cheapest_cost']],
                        'diffPercent'      => $laundryCost['diff_from_30d_percent'] ?? null,
                        'savingsEuros'     => $laundryCost['cost_difference_euros'] ?? null,
                        'comparisonLabel'  => 'vs kallein 2 h klo 07–22',
                        'icon'             => 'M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15',
                    ])
                @endif

                <!-- Dishwasher — bounded to 18–08 -->
                @if (isset($dishwasherCost) && $dishwasherCost)
                    @include('partials.appliance-tip-card', [
                        'title'            => 'Astianpesukone',
                        'assumption'       => 'Yöllä 18–08, 2 h, 1,5 kW',
                        'startHour'        => $dishwasherCost['start_hour'],
                        'endHour'          => $dishwasherCost['end_hour'] - 1,
                        'startDate'        => $dishwasherCost['start_date'] ?? null,
                        'endDate'          => $dishwasherCost['end_date'] ?? null,
                        'cost'             => ['type' => 'cents', 'value' => $dishwasherCost['cheapest_cost']],
                        'diffPercent'      => $dishwasherCost['diff_from_30d_percent'] ?? null,
                        'savingsEuros'     => $dishwasherCost['cost_difference_euros'] ?? null,
                        'comparisonLabel'  => 'vs kallein 2 h klo 18–08',
                        'icon'             => 'M19 11H5m14 0a2 2 0 012 2v6a2 2 0 01-2 2H5a2 2 0 01-2-2v-6a2 2 0 012-2m14 0V9a2 2 0 00-2-2M5 11V9a2 2 0 012-2m0 0V5a2 2 0 012-2h6a2 2 0 012 2v2M7 7h10',
                    ])
                @endif

                <!-- Water heater — full-day window, 1 h -->
                @if (isset($waterHeaterCost) && $waterHeaterCost)
                    @include('partials.appliance-tip-card', [
                        'title'            => 'Lämminvesivaraaja',
                        'assumption'       => 'Mikä tahansa tunti, 2,5 kW',
                        'startHour'        => $waterHeaterCost['start_hour'],
                        'endHour'          => $waterHeaterCost['start_hour'],
                        'startDate'        => $waterHeaterCost['start_date'] ?? null,
                        'endDate'          => $waterHeaterCost['end_date'] ?? null,
                        'cost'             => ['type' => 'cents', 'value' => $waterHeaterCost['cheapest_cost']],
                        'diffPercent'      => $waterHeaterCost['diff_from_30d_percent'] ?? null,
                        'savingsEuros'     => $waterHeaterCost['cost_difference_euros'] ?? null,
                        'comparisonLabel'  => 'vs päivän kallein tunti',
                        'icon'             => 'M19.428 15.428a2 2 0 00-1.022-.547l-2.387-.477a6 6 0 00-3.86.517l-.318.158a6 6 0 01-3.86.517L6.05 15.21a2 2 0 00-1.806.547M8 4h8l-1 1v5.172a2 2 0 00.586 1.414l5 5c1.26 1.26.367 3.414-1.415 3.414H4.828c-1.782 0-2.674-2.154-1.414-3.414l5-5A2 2 0 009 10.172V5L8 4z',
                    ])
                @endif
            </div>
            <p class="mt-3 text-xs text-slate-500 leading-relaxed max-w-3xl">
                Laitteiden tehot ja kestot (esim. 3,7 kW · 3 h auton lataus, 8 kW kiuas) ovat kiinteitä esimerkkioletuksia havainnollistamiseen — oma laitteesi voi poiketa. Hinnat ovat spot-hintaa sis. ALV 25,5 % (ei siirtoa eikä marginaalia). Prosenttiero (↓ %) verrataan liukuvaan 30 vrk keskihintaan ja euromääräinen säästö saman päivän kalleimpaan vastaavaan jaksoon.
            </p>
        </section>


        <!-- Column-bar timeline: each day = one horizontal strip of 24 columns -->
        @if (!empty($dayStrips))
            <div
                x-data="{
                    expandedHour: null,
                    expandedStripKey: null,
                    selectedMeta: null,
                    quarterPricesByHour: {{ Js::from($quarterPricesByHour) }},
                    avg30d: {{ $rolling30DayAvgWithVat ?? 'null' }},
                    selectHour(timestamp, stripKey, meta) {
                        if (this.expandedHour === timestamp && this.expandedStripKey === stripKey) {
                            this.closeDetail();
                        } else {
                            this.expandedHour = timestamp;
                            this.expandedStripKey = stripKey;
                            this.selectedMeta = meta;
                        }
                    },
                    closeDetail() {
                        this.expandedHour = null;
                        this.expandedStripKey = null;
                        this.selectedMeta = null;
                    }
                }"
                id="tunnit"
                class="bg-white rounded-2xl border border-slate-200 p-4 md:p-6 mb-10 scroll-mt-24"
            >
                <header class="flex flex-col gap-2 sm:flex-row sm:items-baseline sm:justify-between mb-5">
                    <div>
                        <h2 class="text-xl font-bold text-slate-900">Pörssisähkön hintaennuste ja toteutuneet tuntihinnat</h2>
                        <p class="text-sm text-slate-500 mt-0.5 flex flex-wrap items-center gap-x-3 gap-y-1">
                            <span class="inline-flex items-center gap-1.5"><span class="inline-block w-2.5 h-2.5 rounded-sm bg-slate-500"></span> Toteutunut</span>
                            <span class="inline-flex items-center gap-1.5"><span class="inline-block w-2.5 h-2.5 rounded-sm bg-slate-300"></span> Ennuste</span>
                            <span class="inline-flex items-center gap-1.5"><span class="inline-block w-2.5 h-2.5 rounded-sm bg-coral-500"></span> Nyt</span>
                            <span class="inline-flex items-center gap-1.5">
                                <svg class="w-3 h-3 text-amber-500" fill="currentColor" viewBox="0 0 20 20"><path d="M9.049 2.927c.3-.921 1.603-.921 1.902 0l1.286 3.957a1 1 0 00.95.69h4.162c.969 0 1.371 1.24.588 1.81l-3.368 2.447a1 1 0 00-.364 1.118l1.287 3.957c.3.921-.755 1.688-1.54 1.118L10.588 14.7a1 1 0 00-1.176 0l-3.368 2.447c-.784.57-1.838-.197-1.539-1.118l1.287-3.957a1 1 0 00-.364-1.118L2.06 8.507c-.783-.57-.38-1.81.588-1.81h4.162a1 1 0 00.95-.69l1.289-3.957z"/></svg>
                                Päivän halvin
                            </span>
                            @if ($rolling30DayAvgWithVat !== null)
                                <span class="inline-flex items-center gap-1.5"><span class="inline-block w-3 border-t border-dashed border-slate-400"></span> 30 pv ka {{ number_format($rolling30DayAvgWithVat, 2, ',', ' ') }} c</span>
                            @endif
                        </p>
                    </div>
                    <a
                        href="{{ route('spot-price.csv') }}"
                        download
                        class="inline-flex items-center gap-1.5 self-start sm:self-auto text-sm font-semibold text-slate-700 hover:text-coral-600 transition-colors"
                    >
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4"/>
                        </svg>
                        <span>Lataa CSV</span>
                    </a>
                </header>

                <div class="space-y-5 md:space-y-6">
                    @foreach ($dayStrips as $strip)
                        @php
                            $stripStats = $strip['stats'];
                            $isToday = $strip['key'] === 'today';
                            $scaleMin = $strip['scaleMin'] ?? 0;
                            $scaleMax = $strip['scaleMax'] ?? 20;
                            $zeroPercent = $strip['zeroPercent'] ?? 0;
                            $axisMagnitude = max(abs($scaleMin), abs($scaleMax));
                            $axisDecimals = $axisMagnitude >= 10 ? 0 : 1;
                            $scaleMinLabel = number_format($scaleMin, $axisDecimals, ',', ' ');
                            $scaleMaxLabel = number_format($scaleMax, $axisDecimals, ',', ' ');
                        @endphp
                        @php
                            $baselinePercent = $strip['avgBaselinePercent'] ?? null;
                            $forecastStartHour = $strip['forecastStartHour'] ?? null;
                            $forecastStartLabel = $forecastStartHour !== null
                                ? str_pad((string) $forecastStartHour, 2, '0', STR_PAD_LEFT) . ':00'
                                : null;
                        @endphp
                        <div>
                            <div class="flex items-baseline justify-between gap-3 mb-2 flex-wrap">
                                <div class="flex items-baseline gap-2 min-w-0 flex-wrap">
                                    <h3 class="text-base font-bold text-slate-900">{{ $strip['label'] }}</h3>
                                    @if ($strip['provenance'] === 'uudet')
                                        <span class="text-[10px] font-semibold uppercase tracking-[0.06em] text-white bg-slate-900 px-2 py-0.5 rounded">Uudet</span>
                                    @elseif ($strip['provenance'] === 'ennuste')
                                        <span class="text-[10px] font-semibold uppercase tracking-[0.06em] text-coral-700 bg-coral-50 border border-coral-100 px-2 py-0.5 rounded">Ennuste</span>
                                    @elseif ($strip['provenance'] === 'hybrid' && $forecastStartLabel)
                                        <span class="text-[10px] font-medium text-coral-700 bg-coral-50 border border-coral-100 px-2 py-0.5 rounded">Ennuste klo {{ $forecastStartLabel }} alkaen</span>
                                    @endif
                                </div>
                                @if ($stripStats['min'] !== null)
                                    <p class="text-xs text-slate-500 font-medium tabular-nums whitespace-nowrap shrink-0">
                                        min <span class="text-slate-700 font-semibold">{{ number_format($stripStats['min'], 1, ',', ' ') }}</span>
                                        · ka <span class="text-slate-700 font-semibold">{{ number_format($stripStats['avg'], 1, ',', ' ') }}</span>
                                        · max <span class="text-slate-700 font-semibold">{{ number_format($stripStats['max'], 1, ',', ' ') }}</span> c
                                    </p>
                                @endif
                            </div>

                            <div class="flex items-stretch h-32 md:h-40 bg-slate-50 rounded-lg ring-1 ring-slate-100 overflow-hidden">
                                <!-- Signed Y-axis aligned to the shared chart domain -->
                                <div class="relative w-10 sm:w-12 shrink-0 text-[10px] font-medium text-slate-400 tabular-nums select-none" aria-hidden="true">
                                    <span class="absolute right-1.5 top-1 leading-none">{{ $scaleMaxLabel }} c</span>
                                    @if ($zeroPercent > 7 && $zeroPercent < 93)
                                        <span class="absolute right-1.5 -translate-y-1/2 leading-none" style="bottom: {{ $zeroPercent }}%">0</span>
                                    @endif
                                    <span class="absolute right-1.5 bottom-1 leading-none">{{ $scaleMinLabel }}{{ $scaleMin != 0 ? ' c' : '' }}</span>
                                </div>
                                <!-- Bar area with signed zero and 30-day-average baselines -->
                                <div class="relative flex-1 min-w-0 flex items-stretch gap-1 sm:gap-1.5 py-2 pr-2 pl-1">
                                    <span
                                        class="pointer-events-none absolute left-0 right-2 border-t border-slate-300"
                                        style="bottom: {{ $zeroPercent }}%;"
                                        aria-hidden="true"
                                        data-chart-zero-baseline
                                    ></span>
                                    @if ($baselinePercent !== null && abs($baselinePercent - $zeroPercent) > 0.5)
                                        <span
                                            class="pointer-events-none absolute left-0 right-2 border-t border-dashed border-slate-400/70"
                                            style="bottom: {{ $baselinePercent }}%;"
                                            aria-hidden="true"
                                        ></span>
                                        <span
                                            class="pointer-events-none absolute right-1 text-[9px] font-medium text-slate-500 bg-slate-50 px-1 leading-none tabular-nums"
                                            style="bottom: calc({{ $baselinePercent }}% + 2px);"
                                            aria-hidden="true"
                                        >30 pv ka</span>
                                    @endif
                                @foreach ($strip['prices'] as $price)
                                    @php
                                        $isPlaceholder = !empty($price['isPlaceholder']);
                                        $hourNum = $price['hour'] ?? $price['helsinki_hour'] ?? 0;
                                        $priceWithVat = $price['price_with_vat'] ?? $price['price_with_tax'] ?? 0;
                                        $colorClass = $price['colorClass'] ?? 'bg-yellow-400';
                                        $barBottomPercent = $price['barBottomPercent'] ?? $zeroPercent;
                                        $barHeightPercent = $price['barHeightPercent'] ?? 0;
                                        $barEndPercent = $price['barEndPercent'] ?? $zeroPercent;
                                        $direction = $price['direction'] ?? 'zero';
                                        $isCurrent = !empty($price['isCurrentHour']);
                                        $timestamp = $price['timestamp'] ?? 0;
                                        $hourStart = str_pad($hourNum, 2, '0', STR_PAD_LEFT);
                                        $hourEnd = str_pad(($hourNum + 1) % 24, 2, '0', STR_PAD_LEFT);
                                        $isRank1 = ($price['todayRank'] ?? null) === 1;
                                        $stripPriceLabel = "{$strip['label']} · {$hourStart}:00–{$hourEnd}:00";
                                        $metaJson = json_encode([
                                            'timestamp' => $timestamp,
                                            'label' => $stripPriceLabel,
                                            'price' => $priceWithVat,
                                            'isForecast' => $price['isForecast'] ?? $strip['isForecast'],
                                            'badge' => $price['badge']['label'] ?? null,
                                            'badgeType' => $price['badge']['type'] ?? null,
                                            'isCurrent' => $isCurrent,
                                        ]);
                                    @endphp
                                    @if ($isPlaceholder)
                                        <div class="flex-1 min-w-0" aria-hidden="true"></div>
                                    @else
                                        <button
                                            type="button"
                                            @click="selectHour({{ $timestamp }}, '{{ $strip['key'] }}', {{ $metaJson }})"
                                            class="group relative flex-1 min-w-0 h-full rounded-sm hover:bg-white/60 focus:bg-white/80 focus:outline-none focus:ring-2 focus:ring-coral-400 transition-colors"
                                            :class="expandedHour === {{ $timestamp }} && expandedStripKey === '{{ $strip['key'] }}' ? 'bg-white ring-2 ring-coral-400' : ''"
                                            aria-label="{{ $stripPriceLabel }} · {{ number_format($priceWithVat, 2, ',', ' ') }} c/kWh"
                                            title="{{ $hourStart }}:00 · {{ number_format($priceWithVat, 2, ',', ' ') }} c/kWh"
                                        >
                                            <span
                                                class="absolute left-0 right-0 rounded-sm {{ $colorClass }} {{ $strip['isForecast'] ? 'opacity-80' : '' }} {{ $isCurrent ? 'ring-2 ring-coral-500 ring-offset-1 ring-offset-slate-50' : '' }}"
                                                style="bottom: {{ $barBottomPercent }}%; height: {{ $barHeightPercent }}%"
                                                data-price-direction="{{ $direction }}"
                                            ></span>
                                            @if ($isRank1)
                                                <span
                                                    class="pointer-events-none absolute left-1/2 -translate-x-1/2 text-amber-500"
                                                    style="bottom: calc({{ $barEndPercent }}% + 2px);"
                                                    title="Päivän halvin"
                                                >
                                                    <svg class="w-2.5 h-2.5" fill="currentColor" viewBox="0 0 20 20"><path d="M9.049 2.927c.3-.921 1.603-.921 1.902 0l1.286 3.957a1 1 0 00.95.69h4.162c.969 0 1.371 1.24.588 1.81l-3.368 2.447a1 1 0 00-.364 1.118l1.287 3.957c.3.921-.755 1.688-1.54 1.118L10.588 14.7a1 1 0 00-1.176 0l-3.368 2.447c-.784.57-1.838-.197-1.539-1.118l1.287-3.957a1 1 0 00-.364-1.118L2.06 8.507c-.783-.57-.38-1.81.588-1.81h4.162a1 1 0 00.95-.69l1.289-3.957z"/></svg>
                                                </span>
                                            @endif
                                        </button>
                                    @endif
                                @endforeach
                                </div>
                            </div>

                            <div class="flex justify-between mt-1 pl-10 sm:pl-12 pr-2 text-[10px] font-medium text-slate-400 tabular-nums">
                                <span>00</span><span>06</span><span>12</span><span>18</span><span>23</span>
                            </div>

                            @if ($isToday && $strip['provenance'] === null)
                                @php $hasTomorrow = collect($dayStrips)->contains(fn($s) => $s['key'] === 'tomorrow'); @endphp
                                @if (!$hasTomorrow)
                                    <p class="mt-2 text-xs text-slate-500">Huomisen viralliset hinnat julkaistaan noin klo 13.45.</p>
                                @endif
                            @endif

                            <!-- Per-strip detail panel: opens directly below this day's bars -->
                            <div x-show="expandedStripKey === '{{ $strip['key'] }}'" x-collapse class="mt-3">
                                <div class="rounded-xl border border-slate-200 bg-slate-50 p-4">
                                    <div class="flex items-start justify-between gap-3 mb-3">
                                        <div>
                                            <p class="text-xs font-semibold uppercase tracking-[0.06em] text-slate-500" x-text="selectedMeta?.label"></p>
                                            <p class="mt-1 text-2xl font-extrabold text-slate-900 tabular-nums" x-text="(selectedMeta?.price ?? 0).toFixed(2).replace('.', ',') + ' c/kWh'"></p>
                                            <p class="mt-1 text-xs text-slate-500" x-show="selectedMeta?.isForecast">Kolmannen osapuolen ennuste, ei virallinen spot-hinta.</p>
                                        </div>
                                        <button
                                            type="button"
                                            @click="closeDetail()"
                                            class="text-slate-400 hover:text-slate-700 transition-colors"
                                            aria-label="Sulje"
                                        >
                                            <svg class="w-5 h-5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/></svg>
                                        </button>
                                    </div>

                                    <template x-if="quarterPricesByHour[expandedHour] && quarterPricesByHour[expandedHour].length > 0">
                                        <div>
                                            <p class="text-xs font-semibold uppercase tracking-[0.06em] text-slate-500 mb-2">15 minuutin hinnat</p>
                                            <div class="space-y-1.5">
                                                <template x-for="(quarter, idx) in quarterPricesByHour[expandedHour]" :key="idx">
                                                    <div class="flex items-center gap-2">
                                                        <span
                                                            class="w-24 sm:w-28 text-xs font-medium flex items-center gap-1 tabular-nums"
                                                            :class="quarter.is_current_slot ? 'text-coral-600' : 'text-slate-600'"
                                                        >
                                                            <span x-text="quarter.time_label"></span>
                                                            <template x-if="quarter.is_current_slot">
                                                                <span class="bg-coral-100 text-coral-700 px-1 py-0.5 rounded text-[10px]">Nyt</span>
                                                            </template>
                                                        </span>
                                                        <div class="flex-1 h-4 bg-white rounded relative overflow-hidden ring-1 ring-slate-200">
                                                            <span
                                                                class="absolute top-0 bottom-0 w-px bg-slate-300"
                                                                :style="'left: ' + quarter.zero_percent + '%'"
                                                                aria-hidden="true"
                                                            ></span>
                                                            <div
                                                                class="absolute top-0 bottom-0 rounded transition-all duration-200"
                                                                :class="quarter.is_current_slot ? 'bg-coral-500' : 'bg-slate-500'"
                                                                :style="'left: ' + quarter.bar_left_percent + '%; width: ' + quarter.bar_width_percent + '%'"
                                                                :data-price-direction="quarter.direction"
                                                            ></div>
                                                        </div>
                                                        <span
                                                            class="w-16 text-xs text-right font-semibold tabular-nums"
                                                            :class="quarter.is_current_slot ? 'text-coral-600' : 'text-slate-700'"
                                                            x-text="quarter.price_with_tax.toFixed(2).replace('.', ',') + ' c'"
                                                        ></span>
                                                    </div>
                                                </template>
                                            </div>
                                        </div>
                                    </template>
                                    <template x-if="!quarterPricesByHour[expandedHour] || quarterPricesByHour[expandedHour].length === 0">
                                        <p class="text-sm text-slate-500">15 minuutin hintoja ei ole saatavilla tälle tunnille.</p>
                                    </template>
                                </div>
                            </div>
                        </div>
                    @endforeach
                </div>

                @if (!empty($forecastSource))
                    <p class="mt-5 pt-4 border-t border-slate-100 text-xs text-slate-500">
                        Ennusteen lähde:
                        <a
                            href="{{ $forecastSource['url'] ?? '#' }}"
                            target="_blank"
                            rel="noopener noreferrer"
                            class="font-semibold text-slate-700 underline decoration-slate-300 underline-offset-2 hover:text-coral-600"
                        >nordpool-predict-fi</a>
                        ({{ $forecastSource['author'] ?? 'vividfog' }}, {{ $forecastSource['license'] ?? 'MIT' }})@if (!empty($forecastSource['fetched_at'])) · päivitetty {{ $forecastSource['fetched_at'] }}@endif. Hinnat sis. ALV.
                    </p>
                @endif
            </div>
        @endif

        <!-- Historical Data Section: 30-day trend + monthly + multi-year May comparison -->
        <section class="mb-8 grid grid-cols-1 lg:grid-cols-3 gap-5">
                <!-- 30-day Price Trend Chart: 2/3 width on desktop -->
                @if (!empty($weeklyChartData['labels']))
                    <div class="bg-white rounded-2xl border border-slate-200 p-4 md:p-5 lg:col-span-2">
                        <div class="flex items-baseline justify-between mb-3 flex-wrap gap-2">
                            <h3 class="text-base font-bold text-slate-900">30 päivän hintakehitys</h3>
                            <p class="text-xs text-slate-500">Päivittäiset keskihinnat ja vaihteluväli (ALV 0 %)</p>
                        </div>
                        <div class="h-56 md:h-80">
                            <canvas id="weeklyPriceChart" data-chart="{{ json_encode($weeklyChartData) }}"></canvas>
                        </div>
                    </div>
                @endif

                <!-- Monthly + Multi-year stacked in col 3 on desktop -->
                <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-1 gap-5">
                    <!-- Monthly Comparison: this month vs last month -->
                    @if (!empty($monthlyComparison))
                        @php
                            $change = $monthlyComparison['change_percent'] ?? null;
                            $monthIsPositive = $change !== null && $change > 0;
                        @endphp
                        <div class="bg-white rounded-2xl border border-slate-200 p-4 md:p-5">
                            <div class="flex items-baseline justify-between mb-3">
                                <h3 class="text-base font-bold text-slate-900">Kuukausivertailu</h3>
                                @if ($change !== null)
                                    <span class="text-xs font-semibold tabular-nums {{ $monthIsPositive ? 'text-rose-700' : 'text-emerald-700' }}">
                                        {{ $monthIsPositive ? '+' : '' }}{{ number_format($change, 1, ',', ' ') }} %
                                    </span>
                                @endif
                            </div>
                            <dl class="grid grid-cols-2 gap-3 tabular-nums">
                                <div>
                                    <dt class="text-xs text-slate-500">{{ $monthlyComparison['current_month_name'] }}</dt>
                                    <dd class="text-xl font-extrabold text-slate-900 mt-0.5">
                                        @if ($monthlyComparison['current_month_average'] !== null)
                                            {{ number_format($monthlyComparison['current_month_average'], 2, ',', ' ') }}<span class="text-xs font-semibold text-slate-500 ml-0.5">c</span>
                                        @else
                                            <span class="text-slate-300">–</span>
                                        @endif
                                    </dd>
                                    <p class="text-[11px] text-slate-400 mt-0.5">{{ $monthlyComparison['current_month_days'] }} päivää</p>
                                </div>
                                <div>
                                    <dt class="text-xs text-slate-500">{{ $monthlyComparison['last_month_name'] }}</dt>
                                    <dd class="text-xl font-extrabold text-slate-500 mt-0.5">
                                        @if ($monthlyComparison['last_month_average'] !== null)
                                            {{ number_format($monthlyComparison['last_month_average'], 2, ',', ' ') }}<span class="text-xs font-semibold text-slate-400 ml-0.5">c</span>
                                        @else
                                            <span class="text-slate-300">–</span>
                                        @endif
                                    </dd>
                                    <p class="text-[11px] text-slate-400 mt-0.5">{{ $monthlyComparison['last_month_days'] }} päivää</p>
                                </div>
                            </dl>
                        </div>
                    @endif

                    <!-- Multi-year monthly comparison: this calendar month across past years -->
                    @php
                        $multiYear = collect($multiYearMonthly ?? [])->filter(fn ($row) => $row['has_data']);
                        $multiYearMax = $multiYear->max('average_with_vat') ?: 0;
                    @endphp
                    @if ($multiYear->count() >= 2)
                        @php $monthName = $monthlyComparison['current_month_name'] ?? ''; @endphp
                        <div class="bg-white rounded-2xl border border-slate-200 p-4 md:p-5">
                            <div class="flex items-baseline justify-between mb-3 flex-wrap gap-2">
                                <h3 class="text-base font-bold text-slate-900">{{ $monthName }} vuosittain</h3>
                                <span class="text-[11px] text-slate-500">sis. ALV</span>
                            </div>
                            <div class="space-y-1.5">
                                @foreach ($multiYearMonthly as $row)
                                    @php
                                        $value = $row['average_with_vat'];
                                        $widthPct = $value !== null && $multiYearMax > 0
                                            ? max(4, round(($value / $multiYearMax) * 100))
                                            : 0;
                                        $isCurrent = !empty($row['is_current']);
                                        $barColor = $isCurrent ? 'bg-coral-500' : 'bg-slate-400';
                                        $valueColor = $isCurrent ? 'text-slate-900' : 'text-slate-600';
                                    @endphp
                                    <div class="flex items-center gap-2 tabular-nums">
                                        <span class="w-10 text-xs font-medium text-slate-500 shrink-0">{{ $row['label'] }}</span>
                                        <div class="flex-1 h-4 bg-slate-50 rounded ring-1 ring-slate-100 relative overflow-hidden">
                                            @if ($value !== null)
                                                <div class="absolute inset-y-0 left-0 rounded {{ $barColor }}" style="width: {{ $widthPct }}%"></div>
                                            @endif
                                        </div>
                                        <span class="w-16 text-right text-sm font-bold {{ $valueColor }} shrink-0">
                                            @if ($value !== null)
                                                {{ number_format($value, 2, ',', ' ') }}<span class="text-[10px] font-semibold text-slate-400 ml-0.5">c</span>
                                            @else
                                                <span class="text-slate-300 text-xs">ei dataa</span>
                                            @endif
                                        </span>
                                    </div>
                                @endforeach
                            </div>
                        </div>
                    @endif
                </div>
        </section>
    @endif

    <!-- Information Section -->
    <section class="max-w-3xl border-t border-slate-200 pt-8 mt-4">
        <h2 class="text-lg font-bold text-slate-900 mb-3">Usein kysyttyä</h2>
        <div class="divide-y divide-slate-200 border-y border-slate-200">
            @foreach ($this->faqItems as $faq)
                <details class="group py-3">
                    <summary class="flex items-center justify-between cursor-pointer list-none gap-3">
                        <span class="text-base font-semibold text-slate-900">{{ $faq['question'] }}</span>
                        <svg class="w-4 h-4 text-slate-500 shrink-0 transition-transform group-open:rotate-180" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M19 9l-7 7-7-7"/></svg>
                    </summary>
                    <div class="mt-3 space-y-3 text-sm text-slate-700 leading-relaxed">
                        @foreach (preg_split("/\n\n+/", $faq['answer']) as $paragraph)
                            <p>{{ $paragraph }}</p>
                        @endforeach
                    </div>
                </details>
            @endforeach
        </div>
        <p class="mt-4 text-xs text-slate-500 leading-relaxed">
            Menetelmäkuvauksen ja vastausten ylläpidosta vastaa Voltikka, riippumaton harrasteprojekti.
            Päivitetty {{ $methodologyReviewedAt }}.
            <a href="/tietoa#menetelma" class="font-medium text-slate-700 underline decoration-slate-300 underline-offset-2 hover:text-coral-600">Tietoa Voltikasta ja menetelmästä</a>.
        </p>
    </section>
    </div>
</div>

@push('scripts')
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script>
    // Weekly chart initialization function
    function initWeeklyChart() {
        const weeklyCtx = document.getElementById('weeklyPriceChart');
        if (!weeklyCtx) return;

        // Get data from data attribute
        const dataAttr = weeklyCtx.getAttribute('data-chart');
        if (!dataAttr) return;

        // Destroy existing chart if it exists
        if (weeklyCtx.chartInstance) {
            weeklyCtx.chartInstance.destroy();
        }

        try {
            const chartData = JSON.parse(dataAttr);
            if (!chartData.labels || chartData.labels.length === 0) return;

            weeklyCtx.chartInstance = new Chart(weeklyCtx, {
                type: 'line',
                data: chartData,
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    interaction: {
                        mode: 'index',
                        intersect: false,
                    },
                    plugins: {
                        legend: {
                            display: true,
                            position: 'top',
                        },
                        tooltip: {
                            callbacks: {
                                label: function(context) {
                                    return context.dataset.label + ': ' + context.parsed.y.toFixed(2).replace('.', ',') + ' c/kWh';
                                }
                            }
                        }
                    },
                    scales: {
                        y: {
                            beginAtZero: false,
                            title: {
                                display: true,
                                text: 'c/kWh (ALV 0%)'
                            },
                            ticks: {
                                callback: function(value) {
                                    return value.toFixed(1).replace('.', ',');
                                }
                            }
                        },
                        x: {
                            title: {
                                display: true,
                                text: 'Päivämäärä'
                            }
                        }
                    }
                }
            });
        } catch (e) {
            console.error('Failed to initialize weekly chart:', e);
        }
    }

    // Initialize on page load
    document.addEventListener('DOMContentLoaded', function() {
        initWeeklyChart();
    });

    // Reinitialize charts when Livewire updates the component
    document.addEventListener('livewire:initialized', function() {
        Livewire.hook('commit', ({ component, commit, respond, succeed, fail }) => {
            succeed(({ snapshot, effect }) => {
                // After successful update, re-init charts
                setTimeout(() => {
                    initWeeklyChart();
                }, 100);
            });
        });
    });
</script>
@endpush
