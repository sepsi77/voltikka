<div>
    {{-- Picture-book homepage (Paper board 6HY-0 "Etusivu — / picture book", seasons FHW-0).
         No cards: structure comes from display headings with the wavy fox underline, inked
         hairlines, big numbers and the tool vignettes at the left of their headings. --}}
    <x-site.home-hero :tone="\App\Support\Season::heroTone('home-hero')">
        @if($currentSpotPrice)
            <p class="home-hero-now">
                <x-ink.sparkle size="14" color="var(--mark-aurora-light)" />
                <span class="home-hero-now-label">Pörssi nyt</span>
                <span class="home-hero-now-price tabular-nums">{{ number_format($currentSpotPrice['price_with_tax'], 2, ',', ' ') }} c/kWh</span>
            </p>
        @endif
        <h1 class="home-hero-h1">
            <span class="block text-[var(--tone-strong)]">Sähkö, aurinko, lämpö.</span>
            <span class="block text-[var(--tone-accent)]">Yksi riippumaton palvelu.</span>
        </h1>
        <p class="home-hero-lead">
            Vertaile sähkösopimuksia, seuraa pörssihintoja, arvioi aurinkopaneelien tuotto ja lämpöpumpun säästöt. {{ $contractCount }}+ sopimusta. Päivittäin päivittyvät hinnat. Ei mainosrahaa.
        </p>
        <a href="/sahkosopimus" class="home-hero-cta vk-focus">
            <span>Vertaile sopimuksia <span aria-hidden="true">→</span></span>
            <x-ink.wave width="270" stroke="3.6" color="var(--tone-underline)" class="home-wave-fluid" />
        </a>
    </x-site.home-hero>

    <x-page-action-strip />

    <div class="vk-wrap home-body">
        {{-- Palvelut: the contract comparison with the count as a giant numeral, the live spot
             price with today's hours, the three tool vignettes and the bill comparison. --}}
        <section class="home-section" aria-labelledby="home-services-title">
            <header class="home-head">
                <p class="home-kicker">Palvelut</p>
                <h2 id="home-services-title" class="home-heading">Vertaile sopimuksia. Seuraa pörssiä. Laske säästöt.</h2>
                <x-ink.wave width="360" stroke="3.5" class="home-wave-fluid" />
            </header>

            <div class="home-featured">
                <div class="home-contracts">
                    <p class="home-kicker">Sähkösopimukset</p>
                    <h3 class="home-count-sentence">
                        <span class="home-count-word">Vertaa</span>
                        <span class="home-count tabular-nums">{{ $contractCount }}</span>
                        <span class="home-count-word home-count-tail">sähkösopimusta puolueettomasti.</span>
                    </h3>
                    <p class="home-lead">
                        Pörssi, määräaikainen, hybridi. Suodata kotisi kulutuksen mukaan ja näe vuosikustannukset samalla logiikalla kaikille sopimuksille.
                    </p>
                    <a href="/sahkosopimus" class="home-action home-action--lg vk-focus">
                        <span>Aloita vertailu <span aria-hidden="true">→</span></span>
                        <x-ink.wave width="190" stroke="3" />
                    </a>
                </div>

                <svg class="home-vrule" width="8" height="380" viewBox="0 0 8 380" preserveAspectRatio="none" aria-hidden="true" focusable="false">
                    <path d="M4 4 C 2.6 90, 5.4 190, 3.6 280 S 4.6 350, 4 376" fill="none" style="stroke: var(--color-edge-strong)" stroke-width="1.6" stroke-linecap="round" vector-effect="non-scaling-stroke" />
                </svg>

                <div class="home-spot">
                    <p class="home-kicker home-kicker--spot">Pörssisähkö</p>
                    @if($currentSpotPrice)
                        @php
                            $price = $currentSpotPrice['price_with_tax'];
                            $tier = $price < 10 ? 'low' : ($price < 20 ? 'medium' : 'high');
                            $tierLabel = $tier === 'low' ? 'Halpa hetki' : ($tier === 'medium' ? 'Keskihinta' : 'Kallis hetki');
                        @endphp
                        <p class="home-spot-figure home-spot--{{ $tier }}">
                            <span class="home-spot-number tabular-nums">{{ number_format($price, 2, ',', ' ') }}</span>
                            <span class="home-spot-unit">c/kWh</span>
                        </p>
                        <p class="home-spot-state home-spot--{{ $tier }}">
                            <x-ink.sparkle size="13" color="currentColor" />
                            <span class="font-extrabold">{{ $tierLabel }}</span>
                            <span class="text-ink-body">&middot; juuri nyt</span>
                        </p>
                    @else
                        <h3 class="home-title">Päivän pörssihinnat ja laitelaskurit</h3>
                    @endif

                    @if(!empty($todaysSpotPrices))
                        @php
                            // Plain bar chart (spec §5 "Charts", mini chart): inked baseline, the current
                            // hour in fox with a short inked arrow and "nyt". Negative prices go below the
                            // baseline at 0.
                            $prices = array_column($todaysSpotPrices, 'price');
                            $count = count($todaysSpotPrices);
                            $maxPrice = max($prices);
                            $minBase = min(0, min($prices));
                            $range = max(1, $maxPrice - $minBase);
                            $chartWidth = 400;
                            // 34px of headroom above the tallest bar keeps the "nyt" arrow inside the chart.
                            $plotTop = 34;
                            $plotBottom = 116;
                            $plotHeight = $plotBottom - $plotTop;
                            $stride = ($chartWidth - 8) / $count;
                            $barWidth = max(4, $stride * 0.7);
                            $zeroY = $plotBottom - ((0 - $minBase) / $range) * $plotHeight;
                            $currentIndex = null;
                            foreach ($todaysSpotPrices as $i => $h) {
                                if ($h['is_current']) {
                                    $currentIndex = $i;
                                }
                            }
                        @endphp
                        <div class="home-spot-chart">
                            <div class="home-spot-plot">
                                <svg viewBox="0 0 {{ $chartWidth }} 120" class="home-spot-svg" preserveAspectRatio="none" role="img" aria-label="Tämän päivän pörssihinnat tunneittain">
                                    @foreach($todaysSpotPrices as $i => $h)
                                        @php
                                            $valueY = $plotBottom - (($h['price'] - $minBase) / $range) * $plotHeight;
                                            $top = min($valueY, $zeroY - 2);
                                            $height = max(2, abs($zeroY - $valueY));
                                            $x = 4 + $i * $stride;
                                        @endphp
                                        <rect x="{{ round($x, 1) }}" y="{{ round($top, 1) }}" width="{{ round($barWidth, 1) }}" height="{{ round($height, 1) }}" rx="1.5"
                                              @if($h['is_current']) style="fill: var(--color-fox)" @else style="fill: var(--color-aurora)" opacity="0.75" @endif />
                                    @endforeach
                                    <path d="M2 {{ round($zeroY + 1.5, 1) }} C 100 {{ round($zeroY + 0.3, 1) }}, 250 {{ round($zeroY + 2.6, 1) }}, 398 {{ round($zeroY + 1.2, 1) }}" fill="none" style="stroke: var(--color-ink)" stroke-width="1.6" stroke-linecap="round" vector-effect="non-scaling-stroke" />
                                </svg>
                                @if($currentIndex !== null)
                                    @php
                                        $current = $todaysSpotPrices[$currentIndex]['price'];
                                        $currentTop = min($plotBottom - (($current - $minBase) / $range) * $plotHeight, $zeroY - 2);
                                        $currentPercent = (4 + $currentIndex * $stride + $barWidth / 2) / $chartWidth * 100;
                                    @endphp
                                    {{-- Inked arrow with "nyt" over the current hour; the label sits on the side with room. --}}
                                    <span @class(['home-spot-now', 'home-spot-now--right' => $currentPercent < 20]) style="left: {{ round($currentPercent, 2) }}%; top: {{ round($currentTop / 120 * 100, 2) }}%;" aria-hidden="true">
                                        <svg class="home-spot-arrow" width="22" height="30" viewBox="0 0 22 30" focusable="false">
                                            <path d="M11 28 C 10 18, 13 10, 4 3" fill="none" style="stroke: var(--color-fox)" stroke-width="1.8" stroke-linecap="round" />
                                            <path d="M11 28 l-4 -7 M11 28 l4.5 -6.5" fill="none" style="stroke: var(--color-fox)" stroke-width="1.8" stroke-linecap="round" />
                                        </svg>
                                        <span class="home-spot-now-label">nyt</span>
                                    </span>
                                @endif
                            </div>
                            <div class="home-spot-axis" aria-hidden="true">
                                <span>klo {{ sprintf('%02d', $todaysSpotPrices[0]['hour']) }}</span>
                                <span>{{ sprintf('%02d', $todaysSpotPrices[intdiv($count, 2)]['hour']) }}</span>
                                <span>{{ sprintf('%02d', $todaysSpotPrices[$count - 1]['hour']) }}</span>
                            </div>
                        </div>
                    @endif

                    <a href="/spot-price" class="home-action home-action--ink vk-focus">
                        <span>Näe hintakehitys <span aria-hidden="true">→</span></span>
                        <x-ink.wave width="186" stroke="2.6" color="var(--color-ink)" />
                    </a>
                </div>
            </div>

            <div class="home-tools">
                @foreach ([
                    ['art' => 'home-vignette-meter', 'title' => 'Sähkölaskuri', 'text' => 'Arvioi kotisi vuosikulutus ja löydä sopivan kokoinen sopimus.', 'href' => '/sahkosopimus/laskuri', 'action' => 'Laske kulutus', 'wave' => 134],
                    ['art' => 'home-vignette-solar', 'title' => 'Aurinkopaneelit', 'text' => 'Laske paneelien tuotto ja takaisinmaksuaika osoitteellesi.', 'href' => '/aurinkopaneelit/laskuri', 'action' => 'Laske tuotto', 'wave' => 124],
                    ['art' => 'home-vignette-heatpump', 'title' => 'Lämpöpumput', 'text' => 'Vertaile pumppuja ja arvioi säästöt nykyiseen lämmitykseen.', 'href' => '/lampopumput/laskuri', 'action' => 'Vertaile vaihtoehtoja', 'wave' => 196],
                ] as $tool)
                    <div class="home-tool">
                        <img src="{{ \App\Support\Season::art($tool['art']) }}" alt="" width="150" height="150" loading="lazy" decoding="async" class="home-tool-art">
                        <div class="home-tool-text">
                            <h3 class="home-title">{{ $tool['title'] }}</h3>
                            <p class="home-tool-desc">{{ $tool['text'] }}</p>
                            <a href="{{ $tool['href'] }}" class="home-action home-action--sm vk-focus">
                                <span>{{ $tool['action'] }} <span aria-hidden="true">→</span></span>
                                <x-ink.wave :width="$tool['wave']" stroke="2.4" />
                            </a>
                        </div>
                    </div>
                @endforeach
            </div>

            <div class="home-bill">
                <x-ink.hairline />
                <div class="home-bill-row">
                    <img src="{{ \App\Support\Season::art('home-vignette-bill') }}" alt="" width="210" height="210" loading="lazy" decoding="async" class="home-bill-art">
                    <div class="home-bill-text">
                        <h3 class="home-heading">Maksatko sähköstäsi liikaa?</h3>
                        <p class="home-lead">
                            Syötä yhden sähkölaskusi tiedot, niin näet mitä olisit maksanut muilla sopimuksilla samalla laskutusjaksolla.
                        </p>
                    </div>
                    <a href="{{ route('bill.comparison') }}" class="home-action home-action--lg home-bill-action vk-focus">
                        <span>Vertaa laskuasi <span aria-hidden="true">→</span></span>
                        <x-ink.wave width="186" stroke="3" />
                    </a>
                </div>
                <x-ink.hairline />
            </div>
        </section>

        {{-- "Löysitkö etsimäsi?" after the first section (Paper "FB · Notes"). --}}
        <x-page-feedback page-type="home" />

        {{-- Villager interlude: a pause between the services and the analyses. --}}
        <x-site.interlude art="home-interlude" class="home-interlude" />

        {{-- Analyysit: the contract-type trend chart and three analysis links. --}}
        <section class="home-section" aria-labelledby="home-analyses-title">
            <header class="home-head">
                <p class="home-kicker">Analyysit</p>
                <h2 id="home-analyses-title" class="home-heading">Tutki mihin sähkön hinta on menossa.</h2>
                <x-ink.wave width="300" stroke="3.5" class="home-wave-fluid" />
                <p class="home-lead home-lead--text">
                    Riippumattomat analyysit Suomen sähkömarkkinoista. Päätä faktojen, ei mutu-tuntuman pohjalta.
                </p>
            </header>

            <div class="home-analyses">
                <div class="home-trend">
                    <p class="home-kicker home-kicker--muted">Hintakehitys &middot; 6 kk &middot; viikoittain</p>
                    <h3 class="home-subheading">Sähkösopimusten vuosikustannus sopimustyypeittäin</h3>
                    <p class="home-text">
                        {{ $contractPriceTrend['caption'] ?? 'Vertaa pörssin, määräaikaisten ja toistaiseksi voimassa olevien sopimusten vuosikustannusta 5 000 kWh kulutuksella. Tarkemmat luvut ja kulutustasot löytyvät tilastosivulta.' }}
                    </p>

                    @if(!empty($contractPriceTrend['x']) && count($contractPriceTrend['x']) >= 2)
                        <div class="home-trend-chart">
                            <div
                                wire:ignore
                                wire:key="home-contract-price-trend"
                                data-line-chart="spot"
                                class="relative h-72 sm:h-80 w-full select-none"
                                role="img"
                                aria-label="Sähkösopimusten viikoittaiset vuosikustannukset 5 000 kilowattitunnin kulutuksella, viimeiset 6 kuukautta"
                            >
                                {{-- leadStroke #1C7A5E = --color-aurora (Pörssisähkö, the lead series):
                                     the chart draws on a canvas, which cannot read a CSS variable. --}}
                                <script type="application/json">{!! json_encode([
                                    'x' => $contractPriceTrend['x'],
                                    'series' => $contractPriceTrend['series'],
                                    'unit' => 'eur',
                                    'decimals' => 0,
                                    'leadStroke' => '#1C7A5E',
                                ], JSON_UNESCAPED_UNICODE) !!}</script>
                            </div>

                            <ul class="home-legend">
                                @foreach($contractPriceTrend['series'] as $i => $s)
                                    <li class="inline-flex items-center gap-2">
                                        <x-ink.series-line :label="$s['label']" :lead="$i === 0 ? 'var(--color-aurora)' : null" />
                                        <span @class(['font-extrabold text-aurora' => $i === 0])>{{ $s['label'] }}</span>
                                    </li>
                                @endforeach
                            </ul>
                        </div>
                    @endif

                    <a href="/sahkosopimus/tilastot" class="home-action home-action--ink vk-focus">
                        <span>Avaa tilastot <span aria-hidden="true">→</span></span>
                        <x-ink.wave width="150" stroke="2.6" color="var(--color-ink)" />
                    </a>
                </div>

                <svg class="home-vrule home-vrule--tall" width="8" height="560" viewBox="0 0 8 560" preserveAspectRatio="none" aria-hidden="true" focusable="false">
                    <path d="M4 4 C 2.6 130, 5.4 280, 3.6 410 S 4.6 520, 4 556" fill="none" style="stroke: var(--color-edge-strong)" stroke-width="1.6" stroke-linecap="round" vector-effect="non-scaling-stroke" />
                </svg>

                <ul class="home-analysis-links">
                    <li>
                        <a href="/sahkosopimus/sahkon-hintaennuste" class="home-analysis vk-focus">
                            <span class="home-analysis-mark">
                                <img src="{{ \App\Support\Season::art('villager-owl-watch') }}" alt="" width="64" height="64" loading="lazy" decoding="async" class="h-16 w-16 object-contain">
                                <span class="home-kicker home-kicker--muted">Ennuste</span>
                            </span>
                            <span class="home-title home-analysis-title">Sähkön hintaennuste</span>
                            <span class="home-text">Mihin määräaikaisten sopimusten hinnat ovat menossa.</span>
                        </a>
                    </li>
                    <li>
                        <a href="/sahkosopimus/kannattaako-porssisahko" class="home-analysis vk-focus">
                            <span class="home-analysis-mark">
                                <x-ink.glyph bucket="porssisahko" size="52" />
                                <span class="home-kicker home-kicker--muted">Vertailu</span>
                            </span>
                            <span class="home-title home-analysis-title">Kannattaako pörssisähkö?</span>
                            <span class="home-text">Pörssin ja määräaikaisten todelliset kustannukset pidemmällä aikavälillä.</span>
                        </a>
                    </li>
                    <li>
                        <a href="/sahkosopimus/kannattaako-maaraaikainen" class="home-analysis vk-focus">
                            <span class="home-analysis-mark">
                                <x-ink.glyph bucket="kiintea" size="52" />
                                <span class="home-kicker home-kicker--muted">Vertailu</span>
                            </span>
                            <span class="home-title home-analysis-title">Kannattaako määräaikainen?</span>
                            <span class="home-text">Miten lukitut hinnat ovat pärjänneet pörssisähköä vastaan historiassa.</span>
                        </a>
                    </li>
                </ul>
            </div>
        </section>

        @if(!empty($contractPriceTrend['x']) && count($contractPriceTrend['x']) >= 2)
            @vite('resources/js/contract-price-statistics.js')
        @endif

        <x-site.spot-paw-divider />

        {{-- Independence statement. --}}
        <section class="home-statement">
            <svg width="54" height="40" viewBox="0 0 54 40" aria-hidden="true" focusable="false" class="shrink-0">
                <path d="M6 36 C 4 22, 10 8, 22 4 M8 18 C 14 18, 20 22, 20 30 C 20 36, 14 38, 10 36" fill="none" style="stroke: var(--color-fox)" stroke-width="3.2" stroke-linecap="round" />
                <path d="M32 36 C 30 22, 36 8, 48 4 M34 18 C 40 18, 46 22, 46 30 C 46 36, 40 38, 36 36" fill="none" style="stroke: var(--color-fox)" stroke-width="3.2" stroke-linecap="round" />
            </svg>
            <p class="home-statement-quote">
                Voltikka ei ota provisiota sähköyhtiöiltä eikä mainosta yksittäisiä sopimuksia. Vertailut perustuvat samaan, julkisesti saatavilla olevaan sopimusdataan kaikille yhtiöille tasapuolisesti.
            </p>
            <p class="home-statement-note">
                Voltikka on Seppo Puusan riippumaton harrasteprojekti, jota ei rahoiteta mainoksilla.
            </p>
            <a href="/tietoa" class="home-statement-link vk-focus">
                <span>Tietoa Voltikasta ja laskentatavasta.</span>
                <x-ink.wave width="270" stroke="2.2" class="home-wave-fluid" />
            </a>
        </section>
    </div>
</div>
