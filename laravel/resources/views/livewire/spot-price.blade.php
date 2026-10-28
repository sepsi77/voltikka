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
        $heroVerdictClass = match ($heroVerdict) {
            'cheap' => 'spot-verdict--cheap',
            'expensive' => 'spot-verdict--expensive',
            default => 'spot-verdict--normal',
        };
        $fmt = fn ($value, int $decimals = 2) => number_format((float) $value, $decimals, ',', ' ');
    @endphp

    {{-- Hero: picture-book village, the fox looks up at the current price on the sky. --}}
    <x-site.spot-hero :tone="\App\Support\Season::heroTone('spot-hero')">
        <div class="flex max-w-[612px] flex-col gap-3.5 tabular-nums">
            <h1 class="vk-hero-h1 spot-hero-h1">Pörssisähkön hinta tänään ja huomenna</h1>
            <p class="vk-hero-lead spot-hero-lead">
                Tuntihinnat tänään ja huomenna sekä pörssisähkön hintaennuste seuraaville päiville. Katso, kannattaako sauna, pyykinpesu tai auton lataus käynnistää nyt vai odottaa halvempaa tuntia.
            </p>

            @if ($currentPrice)
                <div class="flex flex-col gap-1 pt-2">
                    <div class="flex flex-wrap items-center gap-x-3.5 gap-y-1">
                        <span class="text-[13px] font-extrabold uppercase leading-4 tracking-[0.08em] text-[color:var(--tone-soft)]">Tämänhetkinen hinta · Nyt</span>
                        <span class="text-[13px] font-bold leading-4 text-[color:var(--tone-soft)]">
                            <x-info-tip text="Spot-hinta (Nord Pool) + ALV 25,5 %. Ei sisällä siirtoa (~3–5 c/kWh) eikä sopimuksesi marginaalia (~0,3–0,5 c/kWh).">Mitä hinta sisältää?</x-info-tip>
                        </span>
                    </div>

                    <p class="flex items-baseline gap-2.5 whitespace-nowrap">
                        <span class="spot-poster-number">{{ $fmt($currentPrice['price_with_tax'] ?? 0) }}</span>
                        <span class="text-[22px] font-extrabold leading-7 text-[color:var(--tone-soft)]">c/kWh</span>
                    </p>
                    <x-ink.wave width="170" stroke="3.5" />

                    <p class="text-[15px] leading-[18px] text-[color:var(--tone-soft)]">
                        <span class="whitespace-nowrap">{{ $currentPrice['time_label'] ?? now('Europe/Helsinki')->format('H') . ':00 - ' . now('Europe/Helsinki')->addHour()->format('H') . ':00' }}</span>
                        · spot sis. ALV, ei siirtoa tai marginaalia
                    </p>

                    @if ($heroVerdict !== null)
                        @php
                            $vDiff = $todayVerdict['percent_diff'];
                            if ($vDiff === null) {
                                $diffText = null;
                            } elseif ($vDiff > 0) {
                                $diffText = '+' . $fmt($vDiff, 1) . ' % yli ka.';
                            } elseif ($vDiff < 0) {
                                $diffText = $fmt(abs($vDiff), 1) . ' % alle ka.';
                            } else {
                                $diffText = 'lähellä ka.';
                            }
                        @endphp
                        <div class="spot-verdict {{ $heroVerdictClass }} flex flex-col pt-2.5">
                            <p class="flex flex-wrap items-baseline gap-x-2">
                                <x-ink.sparkle size="18" color="var(--spot-verdict)" class="self-center" />
                                <span class="font-display text-[20px] font-extrabold leading-6 text-[color:var(--spot-verdict)]">{{ $todayVerdict['verdict_label'] }}</span>
                                @if ($diffText !== null)
                                    <span class="text-[15px] font-bold leading-[18px] text-[color:var(--tone-soft)]">· {{ $diffText }}</span>
                                @endif
                            </p>
                            <x-ink.wave width="168" stroke="2.5" color="var(--spot-verdict)" class="ml-[26px]" />
                        </div>
                        <p class="max-w-[500px] pt-1.5 text-[15px] leading-[23px] text-[color:var(--tone-soft)]">
                            Tänään keskimäärin <strong class="font-bold text-[color:var(--tone-strong)]">{{ $fmt($todayVerdict['today_avg_with_vat']) }} c/kWh</strong>,
                            30 päivän keskiarvo <strong class="font-bold text-[color:var(--tone-strong)]">{{ $fmt($todayVerdict['avg_30d_with_vat']) }} c/kWh</strong>.
                            <strong class="font-bold text-[color:var(--tone-strong)]">{{ $todayVerdict['hours_above_avg'] }} tuntia {{ $todayVerdict['total_hours'] }}:stä</strong> yli keskiarvon.
                        </p>
                    @endif
                </div>
            @endif
        </div>
    </x-site.spot-hero>

    <x-page-action-strip />

    <div class="vk-wrap spot-price-page flex flex-col gap-[30px] pb-10 pt-4 tabular-nums">

    {{-- Day figures: key numbers in one row, small inked snowflakes between them. --}}
    @if ($currentPrice && ($cheapestHour || $mostExpensiveHour))
        <div class="spot-figures">
            @if ($heroNextCheap)
                @php
                    $hncStart = str_pad($heroNextCheap['helsinki_hour'], 2, '0', STR_PAD_LEFT);
                    $hncEnd = str_pad(($heroNextCheap['helsinki_hour'] + 1) % 24, 2, '0', STR_PAD_LEFT);
                @endphp
                <a
                    href="#tunnit"
                    data-no-nav-loading
                    @click.prevent="document.getElementById('tunnit')?.scrollIntoView({ behavior: 'smooth', block: 'start' })"
                    class="spot-figure vk-focus group"
                >
                    <span class="spot-figure-label">Seuraava halpa tunti</span>
                    <span class="flex items-baseline gap-1.5">
                        <span class="spot-figure-number text-aurora">{{ $fmt($heroNextCheap['price_with_tax']) }}</span>
                        <span class="spot-figure-unit">c/kWh</span>
                    </span>
                    <span class="spot-figure-note">{{ $hncStart }}:00–{{ $hncEnd }}:00 · {{ $heroNextCheapEta }}</span>
                    @if (isset($currentPrice['price_with_tax']) && $heroNextCheap['price_with_tax'] < $currentPrice['price_with_tax'])
                        @php
                            $savePct = (($currentPrice['price_with_tax'] - $heroNextCheap['price_with_tax']) / $currentPrice['price_with_tax']) * 100;
                        @endphp
                        <span class="spot-figure-note">
                            <span class="font-extrabold text-aurora">{{ $fmt($savePct, 0) }} % halvempi</span> kuin nyt — odota jos voit.
                        </span>
                    @endif
                </a>
                <x-site.spot-snowflake class="spot-figure-ornament" />
            @endif

            <div class="spot-figure">
                <span class="spot-figure-label">Päivän alin</span>
                @if ($cheapestHour)
                    <span class="flex items-baseline gap-1.5">
                        <span class="spot-figure-number text-aurora">{{ $fmt($cheapestHour['price_with_tax'] ?? 0) }}</span>
                        <span class="spot-figure-unit">c/kWh</span>
                    </span>
                    @if (isset($cheapestHour['helsinki_hour']))
                        <span class="spot-figure-note">klo {{ str_pad($cheapestHour['helsinki_hour'], 2, '0', STR_PAD_LEFT) }}:00</span>
                    @endif
                @else
                    <span class="spot-figure-number text-ink-muted">–</span>
                @endif
            </div>
            <x-site.spot-snowflake class="spot-figure-ornament" />

            <div class="spot-figure">
                <span class="spot-figure-label">Päivän ylin</span>
                @if ($mostExpensiveHour)
                    <span class="flex items-baseline gap-1.5">
                        <span class="spot-figure-number text-price-high">{{ $fmt($mostExpensiveHour['price_with_tax'] ?? 0) }}</span>
                        <span class="spot-figure-unit">c/kWh</span>
                    </span>
                    @if (isset($mostExpensiveHour['helsinki_hour']))
                        <span class="spot-figure-note">klo {{ str_pad($mostExpensiveHour['helsinki_hour'], 2, '0', STR_PAD_LEFT) }}:00</span>
                    @endif
                @else
                    <span class="spot-figure-number text-ink-muted">–</span>
                @endif
            </div>
            <x-site.spot-snowflake class="spot-figure-ornament" />

            <div class="spot-figure">
                <span class="spot-figure-label"><x-info-tip text="Liukuva 30 vuorokauden keskihinta (sis. ALV 25,5 %). Toimii vertailutasona 'edullinen/kallis päivä' -arviossa ja energiavinkkien prosenttierossa.">30 pv keskiarvo</x-info-tip></span>
                <span class="flex items-baseline gap-1.5">
                    <span class="spot-figure-number text-ink">{{ $fmt($todayVerdict['avg_30d_with_vat'] ?? 0) }}</span>
                    <span class="spot-figure-unit">c/kWh</span>
                </span>
                <span class="spot-figure-note">vertailutaso</span>
            </div>
        </div>
    @endif

    @if ($loading)
        <div class="flex items-center justify-center gap-3 py-12 text-ink-body" role="status">
            <svg class="h-7 w-7 animate-spin motion-reduce:animate-none" style="color: var(--color-fox)" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" aria-hidden="true">
                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
            </svg>
            <span>Ladataan hintatietoja...</span>
        </div>
    @elseif ($error)
        <p class="spot-notice text-price-high" role="alert"><span class="font-display text-[20px] font-extrabold" aria-hidden="true">!</span> {{ $error }}</p>
    @elseif (empty($hourlyPrices))
        <p class="spot-notice text-warning-ink"><span class="font-display text-[20px] font-extrabold" aria-hidden="true">!</span> Hintatietoja ei ole vielä saatavilla. Tiedot päivitetään automaattisesti.</p>
    @else
        <x-site.spot-paw-divider />

        {{-- Kodin energiavinkit: villagers do the household tasks above the cheapest upcoming windows. --}}
        <section class="flex flex-col gap-3.5">
            <x-site.spot-section-heading
                title="Kodin energiavinkit"
                sub="Seuraavat edullisimmat ajat yleisimmille energiaa kuluttaville tehtäville."
            />

            <div class="spot-tips">
                <x-site.spot-ground variant="row" class="spot-tips-ground hidden lg:block" />

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
                        'art'              => 'villager-ev',
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
                        'art'              => 'villager-sauna',
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
                        'art'              => 'villager-laundry',
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
                        'art'              => 'villager-dishwasher',
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
                        'art'              => 'villager-waterheater',
                    ])
                @endif
            </div>
            <p class="max-w-[880px] pt-1.5 text-[13px] leading-5 text-ink-muted">
                Laitteiden tehot ja kestot (esim. 3,7 kW · 3 h auton lataus, 8 kW kiuas) ovat kiinteitä esimerkkioletuksia havainnollistamiseen — oma laitteesi voi poiketa. Hinnat ovat spot-hintaa sis. ALV 25,5 % (ei siirtoa eikä marginaalia). Prosenttiero (↓ %) verrataan liukuvaan 30 vrk keskihintaan ja euromääräinen säästö saman päivän kalleimpaan vastaavaan jaksoon.
            </p>
        </section>

        {{-- Hourly prices: official today/tomorrow as large bar charts, third-party forecast days as
             separate mini charts in the forecast colour with their own source line. --}}
        @if (!empty($dayStrips))
            @php
                $officialStrips = array_values(array_filter($dayStrips, fn ($s) => $s['provenance'] !== 'ennuste'));
                $forecastStrips = array_values(array_filter($dayStrips, fn ($s) => $s['provenance'] === 'ennuste'));
                $hasTomorrow = collect($dayStrips)->contains(fn ($s) => $s['key'] === 'tomorrow');
            @endphp
            <x-site.spot-paw-divider />

            <section
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
                class="flex scroll-mt-24 flex-col gap-[22px]"
            >
                <x-site.spot-section-heading title="Pörssisähkön hintaennuste ja toteutuneet tuntihinnat" art="villager-owl-watch">
                    <x-slot:action>
                        <a
                            href="{{ route('spot-price.csv') }}"
                            download
                            class="vk-focus inline-flex items-center gap-1.5 text-[15px] font-extrabold leading-[18px] text-ink underline decoration-1 underline-offset-2 hover:text-fox-deep"
                        >
                            <svg width="16" height="16" viewBox="0 0 16 16" aria-hidden="true" focusable="false"><path d="M8 2v8m0 0l-3-3m3 3l3-3M3 13h10" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" /></svg>
                            <span>Lataa CSV</span>
                        </a>
                    </x-slot:action>
                </x-site.spot-section-heading>

                <p class="flex flex-wrap items-center gap-x-[18px] gap-y-1.5 text-[14px] font-bold leading-[18px] text-ink-body">
                    <span class="inline-flex items-center gap-1.5"><span class="spot-swatch bg-granite"></span> Toteutunut</span>
                    <span class="inline-flex items-center gap-1.5"><span class="spot-swatch bg-forecast"></span> Ennuste</span>
                    <span class="inline-flex items-center gap-1.5"><span class="spot-swatch bg-fox"></span> Nyt</span>
                    <span class="inline-flex items-center gap-1.5"><x-site.spot-star size="13" /> Päivän halvin</span>
                    @if ($rolling30DayAvgWithVat !== null)
                        <span class="inline-flex items-center gap-1.5"><span class="spot-dash-swatch"></span> 30 pv ka {{ $fmt($rolling30DayAvgWithVat) }} c</span>
                    @endif
                </p>

                @foreach ($officialStrips as $strip)
                    @include('partials.spot-day-chart', ['strip' => $strip, 'mini' => false, 'hasTomorrow' => $hasTomorrow])
                @endforeach

                @if (!empty($forecastStrips))
                    <div class="spot-forecast-days">
                        @foreach ($forecastStrips as $strip)
                            @include('partials.spot-day-chart', ['strip' => $strip, 'mini' => true, 'hasTomorrow' => $hasTomorrow])
                        @endforeach
                    </div>
                @endif

                @if (!empty($forecastSource))
                    <p class="text-[13px] leading-4 text-ink-muted">
                        Ennusteen lähde:
                        <a
                            href="{{ $forecastSource['url'] ?? '#' }}"
                            target="_blank"
                            rel="noopener noreferrer"
                            class="vk-focus font-bold text-ink-body underline decoration-1 underline-offset-2 hover:text-fox-deep"
                        >nordpool-predict-fi</a>
                        ({{ $forecastSource['author'] ?? 'vividfog' }}, {{ $forecastSource['license'] ?? 'MIT' }})@if (!empty($forecastSource['fetched_at'])) · päivitetty {{ $forecastSource['fetched_at'] }}@endif. Hinnat sis. ALV.
                    </p>
                @endif
            </section>
        @endif

        {{-- "Löysitkö etsimäsi?" after the price now, the appliance tips and the hourly prices. --}}
        <x-page-feedback page-type="spot_price" />

        {{-- Historical data: 30-day trend, this month vs last month, this calendar month across years. --}}
        @php
            $multiYear = collect($multiYearMonthly ?? [])->filter(fn ($row) => $row['has_data']);
            $multiYearMax = $multiYear->max('average_with_vat') ?: 0;
            $hasTrend = !empty($weeklyChartData['labels']);
        @endphp
        @if ($hasTrend || !empty($monthlyComparison) || $multiYear->count() >= 2)
            <x-site.spot-paw-divider />

            <section class="flex flex-col gap-4">
                @if ($hasTrend)
                    <x-site.spot-section-heading title="30 päivän hintakehitys" sub="Päivittäiset keskihinnat ja vaihteluväli (ALV 0 %)" />
                @endif

                <div class="grid grid-cols-1 gap-x-[62px] gap-y-10 lg:grid-cols-[minmax(0,760px)_minmax(0,410px)]">
                    @if ($hasTrend)
                        <div class="flex min-w-0 flex-col gap-2.5">
                            <p class="flex flex-wrap items-center gap-x-4 gap-y-1 text-[13px] font-bold leading-4 text-ink-body">
                                <span class="inline-flex items-center gap-1.5"><span class="spot-range-swatch"></span> Päivän alin–ylin (c/kWh)</span>
                                <span class="inline-flex items-center gap-1.5"><span class="spot-line-swatch"></span> Päivän keskihinta (c/kWh)</span>
                                @if ($rolling30DayAvgWithVat !== null)
                                    <span class="inline-flex items-center gap-1.5"><span class="spot-dash-swatch"></span> 30 pv keskiarvo (c/kWh)</span>
                                @endif
                            </p>
                            <div class="h-56 md:h-[276px]">
                                <canvas id="weeklyPriceChart" data-chart="{{ json_encode($weeklyChartData) }}" aria-label="30 päivän hintakehitys: päivän keskihinta sekä alin ja ylin hinta" role="img"></canvas>
                            </div>
                        </div>
                    @endif

                    <div class="flex min-w-0 flex-col gap-[30px] {{ $hasTrend ? '' : 'lg:col-start-1' }}">
                        @if (!empty($monthlyComparison))
                            @php
                                $change = $monthlyComparison['change_percent'] ?? null;
                                $monthIsPositive = $change !== null && $change > 0;
                            @endphp
                            <div class="flex flex-col gap-2.5">
                                <div class="flex items-baseline justify-between gap-3">
                                    <h3 class="font-display text-[24px] font-extrabold leading-[30px] text-ink">Kuukausivertailu</h3>
                                    @if ($change !== null)
                                        <span class="text-[16px] font-extrabold leading-5 {{ $monthIsPositive ? 'text-price-high' : 'text-aurora' }}">
                                            {{ $monthIsPositive ? '+' : '' }}{{ $fmt($change, 1) }} %
                                        </span>
                                    @endif
                                </div>
                                <dl class="flex gap-7">
                                    <div class="flex flex-col">
                                        <dt class="text-[14px] font-bold leading-[18px] text-ink-body">{{ $monthlyComparison['current_month_name'] }}</dt>
                                        <dd class="font-display text-[36px] font-extrabold leading-10 text-ink">
                                            @if ($monthlyComparison['current_month_average'] !== null)
                                                {{ $fmt($monthlyComparison['current_month_average']) }} c
                                            @else
                                                –
                                            @endif
                                        </dd>
                                        <dd class="text-[13px] leading-4 text-ink-muted">{{ $monthlyComparison['current_month_days'] }} päivää</dd>
                                    </div>
                                    <div class="flex flex-col">
                                        <dt class="text-[14px] font-bold leading-[18px] text-ink-body">{{ $monthlyComparison['last_month_name'] }}</dt>
                                        <dd class="font-display text-[36px] font-extrabold leading-10 text-ink-body">
                                            @if ($monthlyComparison['last_month_average'] !== null)
                                                {{ $fmt($monthlyComparison['last_month_average']) }} c
                                            @else
                                                –
                                            @endif
                                        </dd>
                                        <dd class="text-[13px] leading-4 text-ink-muted">{{ $monthlyComparison['last_month_days'] }} päivää</dd>
                                    </div>
                                </dl>
                            </div>
                        @endif

                        @if ($multiYear->count() >= 2)
                            @php $monthName = $monthlyComparison['current_month_name'] ?? ''; @endphp
                            @if (!empty($monthlyComparison))
                                <x-site.spot-snowflake />
                            @endif
                            <div class="flex flex-col gap-2.5">
                                <div class="flex items-baseline justify-between gap-3">
                                    <h3 class="font-display text-[24px] font-extrabold leading-[30px] text-ink">{{ $monthName }} vuosittain</h3>
                                    <span class="text-[13px] leading-4 text-ink-muted">sis. ALV</span>
                                </div>
                                @foreach ($multiYearMonthly as $row)
                                    @php
                                        $value = $row['average_with_vat'];
                                        $widthPct = $value !== null && $multiYearMax > 0
                                            ? max(4, round(($value / $multiYearMax) * 100))
                                            : 0;
                                        $isCurrent = !empty($row['is_current']);
                                    @endphp
                                    <div class="flex items-center gap-3">
                                        <span class="w-10 shrink-0 text-[14px] font-bold leading-[18px] text-ink-body">{{ $row['label'] }}</span>
                                        <span class="spot-year-track">
                                            @if ($value !== null)
                                                <span class="spot-year-bar {{ $isCurrent ? 'bg-fox' : 'bg-granite' }}" style="width: {{ $widthPct }}%"></span>
                                            @endif
                                        </span>
                                        <span class="w-16 shrink-0 text-right text-[15px] font-extrabold leading-[18px] text-ink">
                                            @if ($value !== null)
                                                {{ $fmt($value) }} c
                                            @else
                                                <span class="text-[13px] font-normal text-ink-muted">ei dataa</span>
                                            @endif
                                        </span>
                                    </div>
                                @endforeach
                            </div>
                        @endif
                    </div>
                </div>
            </section>
        @endif
    @endif

    <x-site.spot-paw-divider />

    {{-- FAQ --}}
    <section class="flex max-w-[820px] flex-col gap-2.5">
        <x-site.spot-section-heading title="Usein kysyttyä" :wave="240" />
        <div class="spot-faq">
            @foreach ($this->faqItems as $faq)
                <details class="group spot-faq-row">
                    <summary class="vk-focus flex cursor-pointer list-none items-center justify-between gap-3 py-4 [&::-webkit-details-marker]:hidden">
                        <span class="text-[17px] font-extrabold leading-[22px] text-ink md:text-[18px]">{{ $faq['question'] }}</span>
                        <svg width="18" height="18" viewBox="0 0 18 18" class="shrink-0 transition-transform group-open:rotate-180 motion-reduce:transition-none" aria-hidden="true" focusable="false"><path d="M4 7l5 5 5-5" fill="none" style="stroke: var(--color-ink)" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" /></svg>
                    </summary>
                    <div class="flex max-w-[680px] flex-col gap-2.5 pb-4 pt-1 text-[16px] leading-[25px] text-ink-body">
                        @foreach (preg_split("/\n\n+/", $faq['answer']) as $paragraph)
                            <p>{{ $paragraph }}</p>
                        @endforeach
                    </div>
                </details>
            @endforeach
        </div>
        <p class="pt-1.5 text-[13px] leading-5 text-ink-muted">
            Menetelmäkuvauksen ja vastausten ylläpidosta vastaa Voltikka, riippumaton harrasteprojekti.
            Päivitetty {{ $methodologyReviewedAt }}.
            <a href="/tietoa#menetelma" class="vk-focus font-bold text-ink-body underline decoration-1 underline-offset-2 hover:text-fox-deep">Tietoa Voltikasta ja menetelmästä</a>.
        </p>
    </section>
    </div>
</div>

@push('scripts')
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script>
    // 30-day trend chart (Paper board 11H-0 "Hintakehitys"): a light range band for the day's
    // low–high, an ink line for the daily mean and a dashed 30-day average. Colours come from the
    // design tokens; the dataset order and values come from SpotPrice::buildTrendChartData().
    function initWeeklyChart() {
        const weeklyCtx = document.getElementById('weeklyPriceChart');
        if (!weeklyCtx) return;

        const dataAttr = weeklyCtx.getAttribute('data-chart');
        if (!dataAttr) return;

        if (weeklyCtx.chartInstance) {
            weeklyCtx.chartInstance.destroy();
        }

        try {
            const chartData = JSON.parse(dataAttr);
            if (!chartData.labels || chartData.labels.length === 0) return;

            const css = getComputedStyle(document.documentElement);
            const token = (name, fallback) => (css.getPropertyValue(name) || '').trim() || fallback;
            const ink = token('--color-ink', '#16202E');
            const inkMuted = token('--color-ink-muted', '#5E6876');
            const edge = token('--color-edge', '#D9DFDC');
            const fieldEdge = token('--color-field-edge', '#838E89');
            const band = token('--spot-range-band', 'rgba(198, 221, 239, 0.7)');

            chartData.datasets.forEach((dataset, index) => {
                if (index <= 1) {
                    // Päivän alin / ylin: the range band between the two series.
                    Object.assign(dataset, { borderColor: 'transparent', backgroundColor: band, pointRadius: 0, tension: 0, order: 3 });
                } else if (dataset.borderDash) {
                    Object.assign(dataset, { borderColor: fieldEdge, borderDash: [5, 5], borderWidth: 1.5, pointRadius: 0, order: 1 });
                } else {
                    Object.assign(dataset, { borderColor: ink, backgroundColor: ink, borderWidth: 2.6, tension: 0, pointRadius: 0, pointHoverRadius: 4, order: 0 });
                }
            });

            Chart.defaults.font.family = token('--font-sans', 'Figtree').replace(/"/g, '') || 'Figtree';

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
                        legend: { display: false },
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
                            border: { display: false },
                            grid: { color: edge },
                            ticks: {
                                color: inkMuted,
                                font: { size: 11, weight: 700 },
                                callback: function(value) {
                                    return value.toFixed(1).replace('.', ',');
                                }
                            }
                        },
                        x: {
                            border: { color: ink, width: 2 },
                            grid: { display: false },
                            ticks: { color: inkMuted, font: { size: 11, weight: 700 }, maxRotation: 0, autoSkipPadding: 12 }
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
