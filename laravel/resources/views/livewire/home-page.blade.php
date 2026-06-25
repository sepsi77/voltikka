<div>
    <!-- Hero: comprehensive energy service with independence frame -->
    <section class="bg-slate-950 -mx-4 sm:-mx-6 lg:-mx-8 mb-20 lg:mb-28">
        <div class="max-w-5xl mx-auto px-4 sm:px-6 lg:px-8 pt-20 pb-24 lg:pt-28 lg:pb-32">
            @if($currentSpotPrice)
                <p class="inline-flex items-center gap-2.5 text-sm font-semibold text-slate-300 uppercase tracking-wider mb-10">
                    <span class="relative flex h-2 w-2">
                        <span class="animate-ping absolute inline-flex h-full w-full rounded-full bg-coral-500 opacity-60"></span>
                        <span class="relative inline-flex rounded-full h-2 w-2 bg-coral-500"></span>
                    </span>
                    <span class="tabular-nums">Pörssi nyt {{ number_format($currentSpotPrice['price_with_tax'], 2, ',', ' ') }} c/kWh</span>
                </p>
            @endif
            <h1 class="text-4xl md:text-5xl lg:text-6xl font-extrabold tracking-tight leading-[1.1] mb-8 max-w-4xl">
                <span class="text-white">Sähkö, aurinko, lämpö.</span><br>
                <span class="text-coral-400">Yksi riippumaton palvelu.</span>
            </h1>
            <p class="text-lg lg:text-xl text-slate-300 mb-10 max-w-2xl leading-relaxed">
                Vertaile sähkösopimuksia, seuraa pörssihintoja, arvioi aurinkopaneelien tuotto ja lämpöpumpun säästöt. {{ $contractCount }}+ sopimusta. Päivittäin päivittyvät hinnat. Ei mainosrahaa.
            </p>
            <a href="/sahkosopimus" class="inline-flex items-center px-7 py-4 bg-coral-500 text-white font-bold rounded-xl hover:bg-coral-400 transition-colors shadow-lg shadow-coral-500/25">
                Vertaile sopimuksia
                <svg class="w-5 h-5 ml-2.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 8l4 4m0 0l-4 4m4-4H3"/>
                </svg>
            </a>
        </div>
    </section>

    <!-- Palvelut: one bento grid with featured tile + supporting tiles -->
    <section class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 mb-24 lg:mb-32">
        <p class="text-sm font-semibold text-slate-500 uppercase tracking-wider mb-4">
            Palvelut
        </p>
        <h2 class="text-3xl lg:text-4xl font-extrabold text-slate-900 tracking-tight mb-12 max-w-3xl leading-tight">
            Vertaile sopimuksia. Seuraa pörssiä. Laske säästöt.
        </h2>

        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
            <!-- Featured: Sähkösopimukset (wide, dark) -->
            <a href="/sahkosopimus" class="group md:col-span-2 lg:col-span-2 bg-slate-900 rounded-2xl p-8 lg:p-10 text-white block transition-transform hover:-translate-y-0.5">
                <p class="text-sm font-semibold text-coral-400 uppercase tracking-wider mb-4">Sähkösopimukset</p>
                <h3 class="text-2xl lg:text-3xl font-extrabold mb-4 leading-tight">
                    Vertaa {{ $contractCount }} sähkösopimusta puolueettomasti.
                </h3>
                <p class="text-slate-300 mb-6 max-w-xl leading-relaxed">
                    Pörssi, määräaikainen, hybridi. Suodata kotisi kulutuksen mukaan ja näe vuosikustannukset samalla logiikalla kaikille sopimuksille.
                </p>
                <span class="inline-flex items-center gap-2 font-bold text-coral-400 group-hover:text-coral-300 transition-colors">
                    Aloita vertailu
                    <svg class="w-4 h-4 group-hover:translate-x-1 transition-transform" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M17 8l4 4m0 0l-4 4m4-4H3"/>
                    </svg>
                </span>
            </a>

            <!-- Pörssisähkö: with live price -->
            <a href="/spot-price" class="group bg-white border border-slate-200 rounded-2xl p-8 hover:border-slate-300 transition-colors block">
                <p class="text-sm font-semibold text-slate-500 uppercase tracking-wider mb-4">Pörssisähkö</p>
                @if($currentSpotPrice)
                    @php
                        $price = $currentSpotPrice['price_with_tax'];
                        $tier = $price < 10 ? 'low' : ($price < 20 ? 'medium' : 'high');
                        $tierColor = $tier === 'low' ? 'text-emissions-low' : ($tier === 'medium' ? 'text-emissions-medium' : 'text-emissions-high');
                        $tierLabel = $tier === 'low' ? 'Halpa hetki' : ($tier === 'medium' ? 'Keskihinta' : 'Kallis hetki');
                    @endphp
                    <div class="flex items-baseline mb-1 tabular-nums leading-none">
                        <span class="text-5xl font-extrabold {{ $tierColor }}">{{ number_format($price, 2, ',', ' ') }}</span>
                        <span class="text-lg text-slate-500 font-bold ml-2">c/kWh</span>
                    </div>
                    <p class="text-sm text-slate-500 font-medium mb-4">{{ $tierLabel }} &middot; juuri nyt</p>
                @else
                    <h3 class="text-xl font-extrabold text-slate-900 mb-6 leading-tight">
                        Päivän pörssihinnat ja laitelaskurit
                    </h3>
                @endif

                @if(!empty($todaysSpotPrices))
                    @php
                        $tierFill = ['low' => '#22c55e', 'medium' => '#f59e0b', 'high' => '#ef4444'];
                        $prices = array_column($todaysSpotPrices, 'price');
                        $maxPrice = max($prices);
                        $minBase = min(0, min($prices));
                        $range = max(1, $maxPrice - $minBase);
                        $barWidth = 9;
                        $barGap = 3;
                        $chartHeight = 48;
                        $totalWidth = count($todaysSpotPrices) * ($barWidth + $barGap) - $barGap;
                        $svgHeight = $chartHeight + 10;
                    @endphp
                    <svg viewBox="0 0 {{ $totalWidth }} {{ $svgHeight }}" class="w-full h-14 mb-6" preserveAspectRatio="none" aria-label="Tämän päivän pörssihinnat tunneittain">
                        @foreach($todaysSpotPrices as $i => $h)
                            @php
                                $heightRatio = max(0.05, ($h['price'] - $minBase) / $range);
                                $barHeight = $heightRatio * $chartHeight;
                                $x = $i * ($barWidth + $barGap);
                                $y = $chartHeight - $barHeight;
                                $fill = $tierFill[$h['tier']];
                            @endphp
                            <rect x="{{ $x }}" y="{{ $y }}" width="{{ $barWidth }}" height="{{ $barHeight }}" rx="1.5" fill="{{ $fill }}" opacity="{{ $h['is_current'] ? '1' : '0.55' }}"/>
                            @if($h['is_current'])
                                <circle cx="{{ $x + $barWidth / 2 }}" cy="{{ $svgHeight - 3 }}" r="2.5" fill="#f97316"/>
                            @endif
                        @endforeach
                    </svg>
                @endif
                <span class="inline-flex items-center gap-2 font-bold text-slate-900">
                    Näe hintakehitys
                    <svg class="w-4 h-4 text-coral-500 group-hover:translate-x-1 transition-transform" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M17 8l4 4m0 0l-4 4m4-4H3"/>
                    </svg>
                </span>
            </a>

            <!-- Sähkölaskuri -->
            <a href="/sahkosopimus/laskuri" class="group bg-white border border-slate-200 rounded-2xl p-6 lg:p-7 hover:border-slate-300 transition-colors block">
                <h3 class="text-lg font-extrabold text-slate-900 mb-2 leading-tight">
                    Sähkölaskuri
                </h3>
                <p class="text-slate-600 mb-4 text-sm leading-relaxed">
                    Arvioi kotisi vuosikulutus ja löydä sopivan kokoinen sopimus.
                </p>
                <span class="inline-flex items-center gap-2 text-sm font-bold text-slate-900">
                    Laske kulutus
                    <svg class="w-4 h-4 text-coral-500 group-hover:translate-x-1 transition-transform" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M17 8l4 4m0 0l-4 4m4-4H3"/>
                    </svg>
                </span>
            </a>

            <!-- Aurinkopaneelit -->
            <a href="/aurinkopaneelit/laskuri" class="group bg-white border border-slate-200 rounded-2xl p-6 lg:p-7 hover:border-slate-300 transition-colors block">
                <h3 class="text-lg font-extrabold text-slate-900 mb-2 leading-tight">
                    Aurinkopaneelit
                </h3>
                <p class="text-slate-600 mb-4 text-sm leading-relaxed">
                    Laske paneelien tuotto ja takaisinmaksuaika osoitteellesi.
                </p>
                <span class="inline-flex items-center gap-2 text-sm font-bold text-slate-900">
                    Laske tuotto
                    <svg class="w-4 h-4 text-coral-500 group-hover:translate-x-1 transition-transform" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M17 8l4 4m0 0l-4 4m4-4H3"/>
                    </svg>
                </span>
            </a>

            <!-- Lämpöpumput -->
            <a href="/lampopumput/laskuri" class="group bg-white border border-slate-200 rounded-2xl p-6 lg:p-7 hover:border-slate-300 transition-colors block">
                <h3 class="text-lg font-extrabold text-slate-900 mb-2 leading-tight">
                    Lämpöpumput
                </h3>
                <p class="text-slate-600 mb-4 text-sm leading-relaxed">
                    Vertaile pumppuja ja arvioi säästöt nykyiseen lämmitykseen.
                </p>
                <span class="inline-flex items-center gap-2 text-sm font-bold text-slate-900">
                    Vertaile vaihtoehtoja
                    <svg class="w-4 h-4 text-coral-500 group-hover:translate-x-1 transition-transform" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M17 8l4 4m0 0l-4 4m4-4H3"/>
                    </svg>
                </span>
            </a>

            <!-- Maksatko liikaa: full-width closing tile -->
            <a href="{{ route('bill.comparison') }}" class="group md:col-span-2 lg:col-span-3 bg-white border border-slate-200 rounded-2xl p-6 lg:p-8 hover:border-slate-300 transition-colors flex flex-col sm:flex-row sm:items-center gap-5 sm:gap-7">
                <span class="flex-shrink-0 flex items-center justify-center w-12 h-12 rounded-xl bg-coral-50">
                    <svg class="w-6 h-6 text-coral-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/>
                    </svg>
                </span>
                <div class="flex-1 min-w-0">
                    <h3 class="text-lg lg:text-xl font-extrabold text-slate-900 mb-1.5 leading-tight">
                        Maksatko sähköstäsi liikaa?
                    </h3>
                    <p class="text-slate-600 text-sm leading-relaxed">
                        Syötä yhden sähkölaskusi tiedot, niin näet mitä olisit maksanut muilla sopimuksilla samalla laskutusjaksolla.
                    </p>
                </div>
                <span class="inline-flex items-center gap-2 font-bold text-coral-600 group-hover:text-coral-500 transition-colors whitespace-nowrap">
                    Vertaa laskuasi
                    <svg class="w-4 h-4 group-hover:translate-x-1 transition-transform" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M17 8l4 4m0 0l-4 4m4-4H3"/>
                    </svg>
                </span>
            </a>
        </div>
    </section>

    <!-- Analyysit: editorial asymmetric layout (distinct treatment from grid above) -->
    <section class="bg-slate-50 -mx-4 sm:-mx-6 lg:-mx-8 py-20 lg:py-24 mb-24 lg:mb-32">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
            <p class="text-sm font-semibold text-slate-500 uppercase tracking-wider mb-4">
                Analyysit
            </p>
            <h2 class="text-3xl lg:text-4xl font-extrabold text-slate-900 tracking-tight mb-6 max-w-3xl leading-tight">
                Tutki mihin sähkön hinta on menossa.
            </h2>
            <p class="text-lg text-slate-600 max-w-2xl mb-12 leading-relaxed">
                Riippumattomat analyysit Suomen sähkömarkkinoista. Päätä faktojen, ei mutu-tuntuman pohjalta.
            </p>

            <div class="grid grid-cols-1 lg:grid-cols-3 gap-x-12 gap-y-12">
                <!-- Featured: hintakehitys takes 2 columns -->
                <a href="/sahkosopimus/tilastot" class="group lg:col-span-2 lg:border-r lg:border-slate-200 lg:pr-12 block">
                    <p class="text-sm font-semibold text-slate-500 uppercase tracking-wider mb-3">Hintakehitys &middot; 6 kk &middot; viikoittain</p>
                    <h3 class="text-2xl lg:text-3xl font-extrabold text-slate-900 mb-4 group-hover:text-coral-600 transition-colors leading-tight">
                        Sähkösopimusten energiahinta sopimustyypeittäin
                    </h3>
                    <p class="text-slate-600 mb-8 max-w-xl leading-relaxed">
                        {{ $contractPriceTrend['caption'] ?? 'Vertaa pörssin, määräaikaisten ja toistaiseksi voimassa olevien sopimusten energiahintaa viimeisten kuuden kuukauden ajalta. Tarkemmat luvut, vuosikustannukset ja kulutustasot löytyvät tilastosivulta.' }}
                    </p>

                    @if(!empty($contractPriceTrend['x']) && count($contractPriceTrend['x']) >= 2)
                        <div class="mb-12">
                            <div
                                wire:ignore
                                wire:key="home-contract-price-trend"
                                data-line-chart="spot"
                                class="relative h-72 sm:h-80 w-full select-none"
                                role="img"
                                aria-label="Sähkösopimusten viikoittaiset keskihinnat sopimustyypeittäin, viimeiset 6 kuukautta"
                            >
                                <script type="application/json">{!! json_encode([
                                    'x' => $contractPriceTrend['x'],
                                    'series' => $contractPriceTrend['series'],
                                    'unit' => 'cent',
                                    'decimals' => 1,
                                ], JSON_UNESCAPED_UNICODE) !!}</script>
                            </div>

                            @php
                                // Must match NON_LEAD_STYLES in resources/js/contract-price-statistics.js
                                $nonLeadStyles = [
                                    ['stroke' => '#1e293b', 'dash' => null,        'width' => 1.8],
                                    ['stroke' => '#64748b', 'dash' => '10,4',      'width' => 1.8],
                                    ['stroke' => '#334155', 'dash' => '4,3',       'width' => 2.0],
                                    ['stroke' => '#64748b', 'dash' => '6,3,2,3',   'width' => 1.8],
                                ];
                            @endphp
                            <ul class="mt-1 flex flex-wrap gap-x-6 gap-y-2 text-sm text-slate-700">
                                @foreach($contractPriceTrend['series'] as $i => $s)
                                    @php
                                        $isLead = $i === 0;
                                        $style = $isLead ? null : $nonLeadStyles[($i - 1) % count($nonLeadStyles)];
                                        $stroke = $isLead ? '#f97316' : $style['stroke'];
                                        $dash = $isLead ? null : $style['dash'];
                                        $width = $isLead ? 2.5 : ($style['width'] ?? 1.8);
                                    @endphp
                                    <li class="inline-flex items-center gap-2">
                                        <svg width="24" height="10" viewBox="0 0 24 10" aria-hidden="true" class="shrink-0">
                                            <line x1="0" y1="5" x2="24" y2="5"
                                                  stroke="{{ $stroke }}"
                                                  stroke-width="{{ $width }}"
                                                  stroke-linecap="round"
                                                  @if($dash) stroke-dasharray="{{ $dash }}" @endif />
                                        </svg>
                                        <span class="{{ $isLead ? 'font-semibold text-coral-700' : '' }}">{{ $s['label'] }}</span>
                                    </li>
                                @endforeach
                            </ul>
                        </div>
                    @endif

                    <span class="inline-flex items-center gap-2 text-slate-900 font-bold">
                        Avaa tilastot
                        <svg class="w-4 h-4 text-coral-500 group-hover:text-coral-400 group-hover:translate-x-1 transition-all" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M17 8l4 4m0 0l-4 4m4-4H3"/>
                        </svg>
                    </span>
                </a>

                <!-- 3 supporting analyses stacked -->
                <div class="space-y-10">
                    <a href="/sahkosopimus/sahkon-hintaennuste" class="group block">
                        <p class="text-sm font-semibold text-slate-500 uppercase tracking-wider mb-2">Ennuste</p>
                        <h3 class="text-lg font-extrabold text-slate-900 mb-2 group-hover:text-coral-600 transition-colors leading-tight">
                            Sähkön hintaennuste
                        </h3>
                        <p class="text-base text-slate-600 leading-snug">Mihin määräaikaisten sopimusten hinnat ovat menossa.</p>
                    </a>
                    <a href="/sahkosopimus/kannattaako-porssisahko" class="group block">
                        <p class="text-sm font-semibold text-slate-500 uppercase tracking-wider mb-2">Vertailu</p>
                        <h3 class="text-lg font-extrabold text-slate-900 mb-2 group-hover:text-coral-600 transition-colors leading-tight">
                            Kannattaako pörssisähkö?
                        </h3>
                        <p class="text-base text-slate-600 leading-snug">Pörssin ja määräaikaisten todelliset kustannukset pidemmällä aikavälillä.</p>
                    </a>
                    <a href="/sahkosopimus/kannattaako-maaraaikainen" class="group block">
                        <p class="text-sm font-semibold text-slate-500 uppercase tracking-wider mb-2">Vertailu</p>
                        <h3 class="text-lg font-extrabold text-slate-900 mb-2 group-hover:text-coral-600 transition-colors leading-tight">
                            Kannattaako määräaikainen?
                        </h3>
                        <p class="text-base text-slate-600 leading-snug">Miten lukitut hinnat ovat pärjänneet pörssisähköä vastaan historiassa.</p>
                    </a>
                </div>
            </div>
        </div>
    </section>

    @if(!empty($contractPriceTrend['x']) && count($contractPriceTrend['x']) >= 2)
        @vite('resources/js/contract-price-statistics.js')
    @endif

    <!-- Independence statement -->
    <section class="max-w-3xl mx-auto px-4 sm:px-6 lg:px-8 pb-32 lg:pb-40">
        <p class="text-xl lg:text-2xl text-slate-700 leading-relaxed text-center">
            Voltikka ei ota provisiota sähköyhtiöiltä eikä mainosta yksittäisiä sopimuksia. Vertailut perustuvat samaan, julkisesti saatavilla olevaan sopimusdataan kaikille yhtiöille tasapuolisesti.
        </p>
        <p class="text-base text-slate-500 leading-relaxed text-center mt-5">
            Voltikka on yksityishenkilön ylläpitämä riippumaton harrasteprojekti, jota ei rahoiteta mainoksilla.
            <a href="/tietoa" class="text-coral-600 font-medium hover:text-coral-700 underline underline-offset-2">Tietoa Voltikasta ja laskentatavasta</a>.
        </p>
    </section>
</div>
