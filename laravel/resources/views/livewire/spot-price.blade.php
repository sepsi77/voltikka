<div>
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
                    <span class="text-coral-400">Pörssisähkön</span> hinta tänään
                </h1>
                <p class="mt-4 text-slate-200 text-base md:text-lg max-w-2xl leading-relaxed">
                    Katso, kannattaako sauna, pyykinpesu tai auton lataus käynnistää nyt vai odottaa halvempaa tuntia.
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
                            <p class="text-sm font-semibold uppercase tracking-[0.08em] text-slate-500 shrink-0 sm:mb-2">30 pv keskiarvo</p>
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
        <section class="mb-10">
            <div class="flex items-baseline justify-between mb-6 gap-4 flex-wrap">
                <div>
                    <h2 class="text-2xl font-bold text-slate-900">Kodin energiavinkit</h2>
                    <p class="text-base text-slate-600 mt-1">Paras aika tänään yleisimmille energiaa kuluttaville tehtäville.</p>
                </div>
                <p class="text-sm font-medium text-slate-500">Vertailu: 30 pv keskiarvo</p>
            </div>

            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
                <!-- EV Charging — full-day window, 3 h consecutive -->
                @if ($bestConsecutiveHours)
                    @include('partials.appliance-tip-card', [
                        'title'            => 'Sähköauton lataus',
                        'assumption'       => '3 h putkeen, 3,7 kW',
                        'startHour'        => $bestConsecutiveHours['start_hour'],
                        'endHour'          => $bestConsecutiveHours['end_hour'],
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
                        'cost'             => ['type' => 'cents', 'value' => $waterHeaterCost['cheapest_cost']],
                        'diffPercent'      => $waterHeaterCost['diff_from_30d_percent'] ?? null,
                        'savingsEuros'     => $waterHeaterCost['cost_difference_euros'] ?? null,
                        'comparisonLabel'  => 'vs päivän kallein tunti',
                        'icon'             => 'M19.428 15.428a2 2 0 00-1.022-.547l-2.387-.477a6 6 0 00-3.86.517l-.318.158a6 6 0 01-3.86.517L6.05 15.21a2 2 0 00-1.806.547M8 4h8l-1 1v5.172a2 2 0 00.586 1.414l5 5c1.26 1.26.367 3.414-1.415 3.414H4.828c-1.782 0-2.674-2.154-1.414-3.414l5-5A2 2 0 009 10.172V5L8 4z',
                    ])
                @endif
            </div>
        </section>

        <!-- Seuraavat halvat tunnit - inline strip, no card chrome -->
        @if (!empty($cheapestRemainingHours))
            <section class="mb-10">
                <div class="flex items-baseline justify-between mb-4">
                    <h3 class="text-xl font-bold text-slate-900">Seuraavat halvat tunnit</h3>
                    <p class="text-sm font-semibold text-slate-500 uppercase tracking-[0.05em]">Tänään ja huomenna</p>
                </div>
                <div class="grid grid-cols-2 md:grid-cols-5 gap-px bg-slate-200 border border-slate-200 rounded-xl overflow-hidden">
                    @foreach ($cheapestRemainingHours as $index => $hour)
                        @php
                            $hourNum = $hour['helsinki_hour'];
                            $nextHourNum = ($hourNum + 1) % 24;
                            $isTomorrow = $hour['helsinki_date'] !== now('Europe/Helsinki')->format('Y-m-d');
                        @endphp
                        <div class="bg-white p-4">
                            <p class="text-sm font-semibold uppercase tracking-[0.05em] text-slate-500 mb-1.5">
                                {{ $isTomorrow ? 'Huomenna' : 'Tänään' }}
                            </p>
                            <p class="text-lg font-bold text-slate-900 tabular-nums">
                                {{ str_pad($hourNum, 2, '0', STR_PAD_LEFT) }}:00–{{ str_pad($nextHourNum, 2, '0', STR_PAD_LEFT) }}:00
                            </p>
                            <p class="mt-1 text-base font-semibold tabular-nums {{ $index === 0 ? 'text-emerald-700' : 'text-slate-700' }}">
                                {{ number_format($hour['price_with_tax'], 2, ',', ' ') }} c/kWh
                            </p>
                        </div>
                    @endforeach
                </div>
            </section>
        @endif

        <!-- Horizontal Bar Chart with Accordion Quarters -->
        @if (!empty($todayPricesWithMeta))
            @php
                // Calculate scale max for absolute bar widths
                $allPrices = array_column($hourlyPrices, 'price_with_tax');
                $barScaleMax = !empty($allPrices) ? max(max($allPrices), 20) : 20;
            @endphp
            <div
                x-data="{
                    expandedHour: null,
                    quarterPricesByHour: {{ Js::from($quarterPricesByHour) }},
                    avg30d: {{ $rolling30DayAvgWithVat ?? 'null' }},
                    scaleMax: {{ $barScaleMax }},
                    toggleHour(timestamp) {
                        this.expandedHour = this.expandedHour === timestamp ? null : timestamp;
                    },
                    // Get color class based on % difference from 30d average (7-tier scale)
                    getColorFromAvg(price) {
                        if (!this.avg30d || this.avg30d <= 0) return 'bg-yellow-400';
                        const percentDiff = ((price - this.avg30d) / this.avg30d) * 100;
                        if (percentDiff <= -30) return 'bg-green-700';
                        if (percentDiff <= -15) return 'bg-green-500';
                        if (percentDiff <= -5) return 'bg-green-300';
                        if (percentDiff <= 5) return 'bg-yellow-400';
                        if (percentDiff <= 15) return 'bg-orange-400';
                        if (percentDiff <= 30) return 'bg-red-500';
                        return 'bg-red-700';
                    },
                    // Get bar width as absolute percentage (price / scaleMax)
                    getBarWidth(price) {
                        return Math.max(3, Math.min(100, Math.round((price / this.scaleMax) * 100)));
                    }
                }"
                id="tunnit"
                class="bg-white rounded-2xl border border-slate-200 p-4 md:p-6 mb-10 scroll-mt-24"
            >
                <!-- Today's prices -->
                <div class="mb-6">
                    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between mb-4">
                        <h3 class="text-lg font-semibold text-slate-900">Tänään</h3>
                        <p class="text-sm text-slate-500 mt-1 sm:mt-0">
                            Väri suhteessa 30 pv keskiarvoon ({{ number_format($rolling30DayAvgWithVat ?? 0, 2, ',', ' ') }} c/kWh)
                        </p>
                    </div>
                    <div class="space-y-2">
                        @foreach ($todayPricesWithMeta as $price)
                            @php
                                $hourStart = str_pad($price['hour'], 2, '0', STR_PAD_LEFT);
                                $hourEnd = str_pad(($price['hour'] + 1) % 24, 2, '0', STR_PAD_LEFT);
                            @endphp
                            <div class="price-bar-row">
                                <!-- Clickable bar row -->
                                <button
                                    type="button"
                                    class="w-full flex items-center gap-3 p-2 rounded-lg hover:bg-slate-50 transition-colors group"
                                    @click="toggleHour({{ $price['timestamp'] }})"
                                >
                                    <!-- Hour label -->
                                    <span class="w-20 text-sm font-medium text-slate-700 {{ $price['isCurrentHour'] ? 'text-orange-600' : '' }}">
                                        {{ $hourStart }}:00
                                        @if ($price['isCurrentHour'])
                                            <span class="ml-1 text-xs bg-orange-100 text-orange-600 px-1.5 py-0.5 rounded">Nyt</span>
                                        @endif
                                    </span>

                                    <!-- Bar container -->
                                    <div class="flex-1 h-8 bg-slate-100 rounded-lg relative {{ $price['isCurrentHour'] ? 'ring-2 ring-orange-500' : '' }}">
                                        <div
                                            class="h-full {{ $price['colorClass'] }} rounded-lg transition-all duration-300 flex items-center justify-end pr-2"
                                            style="width: {{ $price['widthPercent'] }}%"
                                        >
                                            <span class="text-xs font-semibold text-white drop-shadow-sm">
                                                {{ number_format($price['price_with_vat'], 1, ',', '') }}
                                            </span>
                                        </div>
                                    </div>

                                    <!-- Price value -->
                                    <span class="w-24 text-sm text-right {{ $price['isCurrentHour'] ? 'font-bold text-orange-600' : 'text-slate-600' }}">
                                        {{ number_format($price['price_with_vat'], 2, ',', ' ') }} c
                                    </span>

                                    <!-- Price badges - fixed width container to keep bars consistent -->
                                    <div class="hidden sm:flex items-center justify-end gap-1 w-32 shrink-0">
                                        {{-- Today's rank badge (relative to today) --}}
                                        @if (($price['todayRank'] ?? 0) === 1)
                                            <span class="inline-flex items-center gap-0.5 px-1.5 py-0.5 rounded-full text-xs font-medium bg-emerald-100 text-emerald-700 border border-emerald-300" title="Päivän halvin">
                                                <svg class="w-3 h-3" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M10.868 2.884c-.321-.772-1.415-.772-1.736 0l-1.83 4.401-4.753.381c-.833.067-1.171 1.107-.536 1.651l3.62 3.102-1.106 4.637c-.194.813.691 1.456 1.405 1.02L10 15.591l4.069 2.485c.713.436 1.598-.207 1.404-1.02l-1.106-4.637 3.62-3.102c.635-.544.297-1.584-.536-1.65l-4.752-.382-1.831-4.401z" clip-rule="evenodd"/></svg>
                                                <span>1.</span>
                                            </span>
                                        @elseif (($price['todayRank'] ?? 0) <= 3)
                                            <span class="inline-flex items-center gap-0.5 px-1.5 py-0.5 rounded-full text-xs font-medium bg-emerald-50 text-emerald-600 border border-emerald-200" title="Top 3 halvin">
                                                <svg class="w-3 h-3" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.857-9.809a.75.75 0 00-1.214-.882l-3.236 4.457L7.73 10.09a.75.75 0 10-1.06 1.061l2.5 2.5a.75.75 0 001.137-.089l4-5.5z" clip-rule="evenodd"/></svg>
                                                <span>{{ $price['todayRank'] }}.</span>
                                            </span>
                                        @elseif (($price['todayRank'] ?? 0) >= 22)
                                            <span class="inline-flex items-center gap-0.5 px-1.5 py-0.5 rounded-full text-xs font-medium bg-rose-50 text-rose-600 border border-rose-200" title="Päivän kallein">
                                                <svg class="w-3 h-3" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M10 17a.75.75 0 01-.75-.75V5.612L5.29 9.77a.75.75 0 01-1.08-1.04l5.25-5.5a.75.75 0 011.08 0l5.25 5.5a.75.75 0 11-1.08 1.04l-3.96-4.158V16.25A.75.75 0 0110 17z" clip-rule="evenodd"/></svg>
                                                <span>{{ $price['todayRank'] }}.</span>
                                            </span>
                                        @endif

                                        {{-- 30d average badge --}}
                                        <span class="inline-flex w-14 justify-center items-center px-1.5 py-0.5 rounded-full text-xs font-medium
                                            {{ $price['badge']['type'] === 'cheap' ? 'bg-green-100 text-green-800' : '' }}
                                            {{ $price['badge']['type'] === 'normal' ? 'bg-yellow-100 text-yellow-800' : '' }}
                                            {{ $price['badge']['type'] === 'expensive' ? 'bg-red-100 text-red-800' : '' }}
                                        ">
                                            {{ $price['badge']['label'] }}
                                        </span>
                                    </div>

                                    <!-- Expand icon -->
                                    <svg
                                        class="w-4 h-4 text-slate-400 transition-transform duration-200"
                                        :class="{ 'rotate-180': expandedHour === {{ $price['timestamp'] }} }"
                                        fill="none"
                                        stroke="currentColor"
                                        viewBox="0 0 24 24"
                                    >
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"></path>
                                    </svg>
                                </button>

                                <!-- Quarter prices accordion - Mini bar charts -->
                                <div
                                    x-show="expandedHour === {{ $price['timestamp'] }}"
                                    x-collapse
                                    class="mt-2 ml-4 sm:ml-20 mr-2 sm:mr-8"
                                >
                                    <template x-if="quarterPricesByHour[{{ $price['timestamp'] }}] && quarterPricesByHour[{{ $price['timestamp'] }}].length > 0">
                                        <div class="space-y-1.5 py-2">
                                            <template x-for="(quarter, idx) in quarterPricesByHour[{{ $price['timestamp'] }}]" :key="idx">
                                                <div class="flex items-center gap-2">
                                                    <!-- Time label -->
                                                    <span
                                                        class="w-24 sm:w-28 text-xs font-medium flex items-center gap-1"
                                                        :class="quarter.is_current_slot ? 'text-orange-600' : 'text-slate-600'"
                                                    >
                                                        <span x-text="quarter.time_label"></span>
                                                        <template x-if="quarter.is_current_slot">
                                                            <span class="bg-orange-200 text-orange-700 px-1 py-0.5 rounded text-xs">Nyt</span>
                                                        </template>
                                                    </span>

                                                    <!-- Mini bar -->
                                                    <div class="flex-1 h-5 bg-slate-100 rounded relative overflow-hidden">
                                                        <div
                                                            class="h-full rounded transition-all duration-200"
                                                            :class="quarter.is_current_slot ? 'bg-orange-500' : getColorFromAvg(quarter.price_with_tax)"
                                                            :style="'width: ' + getBarWidth(quarter.price_with_tax) + '%'"
                                                        ></div>
                                                    </div>

                                                    <!-- Price value -->
                                                    <span
                                                        class="w-16 sm:w-20 text-xs text-right font-medium"
                                                        :class="quarter.is_current_slot ? 'text-orange-600' : 'text-slate-700'"
                                                        x-text="quarter.price_with_tax.toFixed(2).replace('.', ',') + ' c'"
                                                    ></span>
                                                </div>
                                            </template>
                                        </div>
                                    </template>
                                    <template x-if="!quarterPricesByHour[{{ $price['timestamp'] }}] || quarterPricesByHour[{{ $price['timestamp'] }}].length === 0">
                                        <p class="text-sm text-slate-500 py-2">15 min hinnat eivät saatavilla</p>
                                    </template>
                                </div>
                            </div>
                        @endforeach
                    </div>
                </div>

                <!-- Tomorrow's prices (if available) -->
                @if ($hasTomorrowPrices)
                    <div class="border-t border-slate-200 pt-6">
                        <h3 class="text-lg font-semibold text-slate-900 mb-4 flex items-center gap-2">
                            Huomenna
                            <span class="text-[11px] bg-slate-100 text-slate-600 px-2 py-0.5 rounded-full font-medium uppercase tracking-[0.05em]">Uudet hinnat</span>
                        </h3>
                        <div class="space-y-2">
                            @foreach ($tomorrowPricesWithMeta as $price)
                                @php
                                    $hourStart = str_pad($price['hour'], 2, '0', STR_PAD_LEFT);
                                    $hourEnd = str_pad(($price['hour'] + 1) % 24, 2, '0', STR_PAD_LEFT);
                                @endphp
                                <div class="price-bar-row">
                                    <!-- Clickable bar row -->
                                    <button
                                        type="button"
                                        class="w-full flex items-center gap-3 p-2 rounded-lg hover:bg-slate-50 transition-colors group"
                                        @click="toggleHour({{ $price['timestamp'] }})"
                                    >
                                        <!-- Hour label -->
                                        <span class="w-20 text-sm font-medium text-slate-700">
                                            {{ $hourStart }}:00
                                        </span>

                                        <!-- Bar container -->
                                        <div class="flex-1 h-8 bg-slate-100 rounded-lg relative overflow-hidden">
                                            <div
                                                class="h-full {{ $price['colorClass'] }} rounded-lg transition-all duration-300 flex items-center justify-end pr-2"
                                                style="width: {{ $price['widthPercent'] }}%"
                                            >
                                                <span class="text-xs font-semibold text-white drop-shadow-sm">
                                                    {{ number_format($price['price_with_vat'], 1, ',', '') }}
                                                </span>
                                            </div>
                                        </div>

                                        <!-- Price value -->
                                        <span class="w-24 text-sm text-right text-slate-600">
                                            {{ number_format($price['price_with_vat'], 2, ',', ' ') }} c
                                        </span>

                                        <!-- Price badge (Edullinen/Normaali/Kallis) -->
                                        <span class="hidden sm:inline-flex w-20 justify-center items-center px-2 py-0.5 rounded-full text-xs font-medium
                                            {{ $price['badge']['type'] === 'cheap' ? 'bg-green-100 text-green-800' : '' }}
                                            {{ $price['badge']['type'] === 'normal' ? 'bg-yellow-100 text-yellow-800' : '' }}
                                            {{ $price['badge']['type'] === 'expensive' ? 'bg-red-100 text-red-800' : '' }}
                                        ">
                                            {{ $price['badge']['label'] }}
                                        </span>

                                        <!-- Expand icon -->
                                        <svg
                                            class="w-4 h-4 text-slate-400 transition-transform duration-200"
                                            :class="{ 'rotate-180': expandedHour === {{ $price['timestamp'] }} }"
                                            fill="none"
                                            stroke="currentColor"
                                            viewBox="0 0 24 24"
                                        >
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"></path>
                                        </svg>
                                    </button>

                                    <!-- Quarter prices accordion - Mini bar charts -->
                                    <div
                                        x-show="expandedHour === {{ $price['timestamp'] }}"
                                        x-collapse
                                        class="mt-2 ml-4 sm:ml-20 mr-2 sm:mr-8"
                                    >
                                        <template x-if="quarterPricesByHour[{{ $price['timestamp'] }}] && quarterPricesByHour[{{ $price['timestamp'] }}].length > 0">
                                            <div class="space-y-1.5 py-2">
                                                <template x-for="(quarter, idx) in quarterPricesByHour[{{ $price['timestamp'] }}]" :key="idx">
                                                    <div class="flex items-center gap-2">
                                                        <!-- Time label -->
                                                        <span class="w-24 sm:w-28 text-xs font-medium text-slate-600">
                                                            <span x-text="quarter.time_label"></span>
                                                        </span>

                                                        <!-- Mini bar -->
                                                        <div class="flex-1 h-5 bg-slate-100 rounded relative overflow-hidden">
                                                            <div
                                                                class="h-full rounded transition-all duration-200"
                                                                :class="getColorFromAvg(quarter.price_with_tax)"
                                                                :style="'width: ' + getBarWidth(quarter.price_with_tax) + '%'"
                                                            ></div>
                                                        </div>

                                                        <!-- Price value -->
                                                        <span
                                                            class="w-16 sm:w-20 text-xs text-right font-medium text-slate-700"
                                                            x-text="quarter.price_with_tax.toFixed(2).replace('.', ',') + ' c'"
                                                        ></span>
                                                    </div>
                                                </template>
                                            </div>
                                        </template>
                                        <template x-if="!quarterPricesByHour[{{ $price['timestamp'] }}] || quarterPricesByHour[{{ $price['timestamp'] }}].length === 0">
                                            <p class="text-sm text-slate-500 py-2">15 min hinnat eivät saatavilla</p>
                                        </template>
                                    </div>
                                </div>
                            @endforeach
                        </div>
                    </div>
                @endif
            </div>
        @endif

    <!-- CSV Download Button -->
        <div class="flex justify-end mb-8">
            <button
                wire:click="downloadCsv"
                wire:loading.attr="disabled"
                class="inline-flex items-center px-4 py-2 border border-slate-300 rounded-lg shadow-sm text-sm font-medium text-slate-700 bg-white hover:bg-slate-50 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-coral-500 disabled:opacity-50"
            >
                <svg class="w-5 h-5 mr-2 text-slate-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4"></path>
                </svg>
                <span wire:loading.remove wire:target="downloadCsv">Lataa CSV</span>
                <span wire:loading wire:target="downloadCsv">Ladataan...</span>
            </button>
        </div>

        <!-- Historical Data Section -->
        <section class="mb-8">
                <!-- Weekly Price Chart -->
                @if (!empty($weeklyChartData['labels']))
                    <div class="bg-white rounded-2xl shadow-sm border border-slate-100 p-4 md:p-6 mb-6">
                        <h3 class="text-lg font-semibold text-slate-900 mb-4">Viikon hintakehitys</h3>
                        <p class="text-sm text-slate-500 mb-4">Päivittäiset keskihinnat viimeiseltä 7 päivältä</p>
                        <div class="h-64 md:h-80">
                            <canvas id="weeklyPriceChart" data-chart="{{ json_encode($weeklyChartData) }}"></canvas>
                        </div>
                    </div>
                @endif

                <!-- Monthly and Year Comparison -->
                <div class="grid grid-cols-1 lg:grid-cols-2 gap-6 mb-6">
                    <!-- Monthly Comparison -->
                    @if (!empty($monthlyComparison))
                        <div class="bg-white rounded-2xl shadow-sm border border-slate-100 p-5">
                            <h3 class="text-lg font-semibold text-slate-900 mb-4">Kuukausivertailu</h3>
                            <div class="grid grid-cols-2 gap-4">
                                <!-- Current Month -->
                                <div class="bg-coral-50 border border-coral-100 p-4 rounded-lg">
                                    <p class="text-sm text-slate-500">{{ $monthlyComparison['current_month_name'] }}</p>
                                    <p class="text-2xl font-bold text-slate-900 tabular-nums">
                                        @if ($monthlyComparison['current_month_average'] !== null)
                                            {{ number_format($monthlyComparison['current_month_average'], 2, ',', ' ') }}
                                        @else
                                            -
                                        @endif
                                        <span class="text-sm font-normal">c/kWh</span>
                                    </p>
                                    <p class="text-xs text-slate-400">{{ $monthlyComparison['current_month_days'] }} päivää</p>
                                </div>

                                <!-- Last Month -->
                                <div class="bg-slate-50 p-4 rounded-lg">
                                    <p class="text-sm text-slate-500">{{ $monthlyComparison['last_month_name'] }}</p>
                                    <p class="text-2xl font-bold text-slate-700">
                                        @if ($monthlyComparison['last_month_average'] !== null)
                                            {{ number_format($monthlyComparison['last_month_average'], 2, ',', ' ') }}
                                        @else
                                            -
                                        @endif
                                        <span class="text-sm font-normal">c/kWh</span>
                                    </p>
                                    <p class="text-xs text-slate-400">{{ $monthlyComparison['last_month_days'] }} päivää</p>
                                </div>
                            </div>

                            @if ($monthlyComparison['change_percent'] !== null)
                                @php
                                    $change = $monthlyComparison['change_percent'];
                                    $isPositive = $change > 0;
                                @endphp
                                <div class="mt-4 p-3 rounded-lg {{ $isPositive ? 'bg-red-50' : 'bg-green-50' }}">
                                    <p class="text-sm {{ $isPositive ? 'text-red-700' : 'text-green-700' }}">
                                        <span class="font-medium">{{ $isPositive ? '+' : '' }}{{ number_format($change, 1, ',', ' ') }}%</span>
                                        verrattuna edelliseen kuukauteen
                                    </p>
                                </div>
                            @endif
                        </div>
                    @endif

                    <!-- Year-over-Year Comparison -->
                    @if (!empty($yearOverYearComparison) && $yearOverYearComparison['has_last_year_data'])
                        <div class="bg-white rounded-2xl shadow-sm border border-slate-100 p-5">
                            <h3 class="text-lg font-semibold text-slate-900 mb-4">Vuosivertailu</h3>
                            <p class="text-sm text-slate-500 mb-4">{{ $yearOverYearComparison['month_name'] }}</p>
                            <div class="grid grid-cols-2 gap-4">
                                <!-- Current Year -->
                                <div class="bg-coral-50 border border-coral-100 p-4 rounded-lg">
                                    <p class="text-sm text-slate-500">{{ $yearOverYearComparison['current_year'] }}</p>
                                    <p class="text-2xl font-bold text-slate-900 tabular-nums">
                                        @if ($yearOverYearComparison['current_year_average'] !== null)
                                            {{ number_format($yearOverYearComparison['current_year_average'], 2, ',', ' ') }}
                                        @else
                                            -
                                        @endif
                                        <span class="text-sm font-normal">c/kWh</span>
                                    </p>
                                </div>

                                <!-- Last Year -->
                                <div class="bg-slate-50 p-4 rounded-lg">
                                    <p class="text-sm text-slate-500">{{ $yearOverYearComparison['last_year'] }}</p>
                                    <p class="text-2xl font-bold text-slate-700">
                                        @if ($yearOverYearComparison['last_year_average'] !== null)
                                            {{ number_format($yearOverYearComparison['last_year_average'], 2, ',', ' ') }}
                                        @else
                                            -
                                        @endif
                                        <span class="text-sm font-normal">c/kWh</span>
                                    </p>
                                </div>
                            </div>

                            @if ($yearOverYearComparison['change_percent'] !== null)
                                @php
                                    $yoyChange = $yearOverYearComparison['change_percent'];
                                    $isYoyPositive = $yoyChange > 0;
                                @endphp
                                <div class="mt-4 p-3 rounded-lg {{ $isYoyPositive ? 'bg-red-50' : 'bg-green-50' }}">
                                    <p class="text-sm {{ $isYoyPositive ? 'text-red-700' : 'text-green-700' }}">
                                        <span class="font-medium">{{ $isYoyPositive ? '+' : '' }}{{ number_format($yoyChange, 1, ',', ' ') }}%</span>
                                        verrattuna samaan kuukauteen vuonna {{ $yearOverYearComparison['last_year'] }}
                                    </p>
                                </div>
                            @endif
                        </div>
                    @endif
                </div>
        </section>
    @endif

    <!-- Information Section -->
    <section class="max-w-3xl border-t border-slate-200 pt-10 mt-4">
        <h3 class="text-2xl font-bold text-slate-900 mb-4">
            Mikä on pörssisähkö ja miten hinta muodostuu?
        </h3>
        <p class="text-base text-slate-700 mb-4 leading-relaxed">
            Tällä sivulla esitetyt hintatiedot ovat Pohjoismaiden ja Baltian maiden sähköpörssi Nord Poolin määrittämiä sähkön spot-hintoja.
            Kaupankäynnissä jokaisella päivän tunnilla on aina oma hintansa.
        </p>
        <p class="text-base text-slate-700 mb-6 leading-relaxed">
            Hinnan määräytyminen Pohjoismaissa perustuu energialähteiden (vesivoima, tuulivoima, ydinvoima ja voimapolttoaineet: hiili, öljy, maakaasu)
            tuotantoon neljällä markkina-alueella (Suomi, Norja, Ruotsi, Tanska) sekä niihin liittyvien päästöoikeuksien (päästökauppa) sääntelyyn,
            sähkönkulutukseen ja markkinapsykologiaan.
        </p>

        <h3 class="text-xl font-bold text-slate-900 mt-8 mb-3">
            Milloin seuraavan päivän hinnat julkaistaan?
        </h3>
        <p class="text-base text-slate-700 leading-relaxed">
            Seuraavan päivän hinnat julkaistaan noin klo 13.45 Suomen aikaa. Uudet hinnat päivitetään tälle sivulle pian julkaisun jälkeen.
        </p>

        <h3 class="text-xl font-bold text-slate-900 mt-8 mb-3">
            ALV-muutokset
        </h3>
        <p class="text-base text-slate-700 leading-relaxed">
            1.9.2024 alkaen sähkön arvonlisävero on 25,5 %. Hinnat ajalta 1.12.2022–30.4.2023 sisältävät ALV:n 10 % (väliaikainen alennus).
            Hinnat ajalta 1.5.2023–31.8.2024 sisältävät ALV:n 24 %.
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
