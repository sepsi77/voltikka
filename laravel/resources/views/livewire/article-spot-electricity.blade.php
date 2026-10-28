<div>
    @php
        $spotMedian = $marketSnapshot['spot'] ?? null;
        $fixedMedian = $marketSnapshot['fixed'] ?? null;
        $hasMedianComparison = is_numeric($spotMedian) && is_numeric($fixedMedian);
        $marketResult = ! $hasMedianComparison
            ? 'unavailable'
            : ($spotMedian < $fixedMedian ? 'spot_lower' : ($fixedMedian < $spotMedian ? 'fixed_lower' : 'equal'));
        $marketConclusion = match ($marketResult) {
            'spot_lower' => 'Voltikan nykyisessä markkinavertailussa pörssisähkösopimusten vuosikustannuksen mediaani on 5 000 kWh kulutuksella pienempi kuin kiinteiden 12 kuukauden sopimusten.',
            'fixed_lower' => 'Voltikan nykyisessä markkinavertailussa kiinteiden 12 kuukauden sopimusten vuosikustannuksen mediaani on 5 000 kWh kulutuksella pienempi kuin pörssisähkösopimusten.',
            'equal' => 'Voltikan nykyisessä markkinavertailussa pörssisähkösopimusten ja kiinteiden 12 kuukauden sopimusten vuosikustannusten mediaanit ovat yhtä suuret 5 000 kWh kulutuksella.',
            default => 'Nykyistä pörssisähkösopimusten ja kiinteiden 12 kuukauden sopimusten mediaanivertailua ei ole saatavilla.',
        };
        $marketSummaryHeading = match ($marketResult) {
            'spot_lower' => 'Markkinan mediaani suosii nyt pörssisähköä.',
            'fixed_lower' => 'Markkinan mediaani suosii nyt kiinteää 12 kuukauden sopimusta.',
            'equal' => 'Markkinan mediaanit ovat nyt yhtä suuret.',
            default => 'Nykyistä mediaanivertailua ei ole saatavilla.',
        };
    @endphp

    {{-- JSON-LD Structured Data --}}
    <script type="application/ld+json">
        {!! json_encode($jsonLdSchema, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) !!}
    </script>

    {{-- Editorial header (Paper 6HX-0 "Editorial hero (fireside)"): no painted plate, so the
         page passes no heroTone and the top bar sits on its night band above it. --}}
    <x-site.longread-hero variant="article" tone="light" figure="article-fox-fireside" figure-width="560" figure-height="420" :drift="false">
        <p class="vk-lr-kicker">Sähkösopimusvertailu</p>
        <div>
            <h1 class="vk-lr-title">Kannattaako pörssisähkö?</h1>
            <x-ink.wave width="560" stroke="5" class="vk-lr-title-wave" />
        </div>
        <p class="vk-lr-hero-lead">
            {{ $marketConclusion }} Pörssisähkön hinta vaihtelee silti tunneittain, joten sähkölasku voi muuttua paljon kuukaudesta toiseen.
        </p>
    </x-site.longread-hero>

    <x-page-action-strip />

    <div class="vk-wrap vk-lr-page">

        <nav class="vk-crumbs" aria-label="Breadcrumb">
            <ol>
                <li><a href="/" class="vk-focus">Etusivu</a></li>
                <li aria-hidden="true">/</li>
                <li><a href="/sahkosopimus" class="vk-focus">Sähkösopimukset</a></li>
                <li aria-hidden="true">/</li>
                <li aria-current="page">Kannattaako pörssisähkö</li>
            </ol>
        </nav>

        {{-- Market scoreboard (Paper "Markkinatilanne nyt"): two medians, "vs." and the difference in a fox loop. --}}
        @if($hasMedianComparison)
        @php
            $diff = $fixedMedian - $spotMedian;
            $diffPct = $fixedMedian > 0 ? round(($diff / $fixedMedian) * 100, 1) : 0;
            $diffAbs = abs($diff);
            $diffPctAbs = abs($diffPct);
            $diffSign = $diff > 0 ? '−' : ($diff < 0 ? '+' : '');
            $diffLabel = $diff > 0
                ? "Pörssisähkö {$diffPctAbs} % edullisempi"
                : ($diff < 0 ? "Kiinteä 12 kk {$diffPctAbs} % edullisempi" : 'Sama hintataso');
        @endphp
        <div id="markkinatilanne" class="vk-lr-score">
            <p class="vk-lr-figure-kicker">Markkinatilanne nyt</p>
            <div class="vk-lr-score-row">
                <div class="vk-lr-score-item">
                    <x-ink.glyph bucket="porssisahko" size="56" />
                    <div class="vk-lr-score-figure">
                        <p class="vk-lr-score-label text-cat-spot">Pörssisähkö</p>
                        <p class="vk-lr-score-value tabular-nums">{{ number_format($marketSnapshot['spot'], 0, ',', ' ') }} €/v</p>
                        <p class="vk-lr-score-sub">Mediaani, 5 000 kWh</p>
                    </div>
                </div>
                <p class="vk-lr-score-vs" aria-hidden="true">vs.</p>
                <div class="vk-lr-score-item">
                    <x-ink.glyph bucket="kiintea" size="56" />
                    <div class="vk-lr-score-figure">
                        <p class="vk-lr-score-label text-cat-fixed">Kiinteä 12 kk</p>
                        <p class="vk-lr-score-value tabular-nums">{{ number_format($marketSnapshot['fixed'], 0, ',', ' ') }} €/v</p>
                        <p class="vk-lr-score-sub">Mediaani, 5 000 kWh</p>
                    </div>
                </div>
                <div class="vk-lr-score-figure vk-lr-score-diff">
                    <p class="vk-lr-score-label text-fox-deep">Ero</p>
                    <p class="vk-lr-score-value tabular-nums text-fox-deep">{{ $diffSign }}{{ number_format($diffAbs, 0, ',', ' ') }} €/v</p>
                    <p class="vk-lr-score-sub !font-extrabold {{ $diff == 0 ? '' : 'text-saving' }}">{{ $diffLabel }}</p>
                    <svg class="vk-lr-score-loop" viewBox="0 0 300 76" preserveAspectRatio="none" aria-hidden="true" focusable="false">
                        <path d="M262 10 C 214 0, 70 0, 26 12 C 2 22, 6 56, 56 66 C 120 76, 246 72, 278 54 C 300 39, 290 18, 236 7" fill="none" style="stroke: var(--color-fox)" stroke-width="2.6" stroke-linecap="round" vector-effect="non-scaling-stroke" />
                    </svg>
                </div>
            </div>
            <div class="flex max-w-[900px] flex-col gap-1">
                <p class="vk-lr-note">
                    Tilanne {{ $marketSnapshot['date'] }}. Hinnat sisältävät ALV 25,5 %.
                    {{ ($marketSnapshot['pricing_basis'] ?? null) === 'canonical_calculation' ? 'Spot-vuosikustannus käyttää tulevan 12 kuukauden tukkumarkkinan ennakkohintoja ja sopimusten tarkkoja kuluja; alempien historiallisten kuvaajien Spot-luvut ovat toteutuneita liukuvia hintoja.' : 'Nykyhinnat perustuvat havaittuun myyjädataan.' }}
                </p>
                <p class="vk-lr-note">
                    Mediaani kuvaa sopimustyypin keskitasoa, mutta yksittäinen sopimus voi olla sitä halvempi tai kalliimpi.
                </p>
            </div>
        </div>
        @endif

        <div class="vk-lr-layout vk-lr-layout--article">
            {{-- Short answer with the drop cap (spec "Long reads"). It comes before the section list in
                 the reading order; on a laptop the grid puts the list at the left. --}}
            <p class="vk-lr-dropcap vk-lr-lead">
                <strong>Lyhyt vastaus:</strong> {{ $marketConclusion }} Yksittäisten sopimusten hinnat vaihtelevat, joten markkinoilta voi löytyä mediaania halvempi kiinteä tai pörssisopimus.
            </p>

            <x-site.longread-toc title="Tässä artikkelissa" label="Artikkelin sisältö" :items="[
                ['mika-on', 'Mikä on pörssisähkö?'],
                ['sopimushinnat', 'Miten pörssisähkö vertautuu muihin sopimuksiin?'],
                ['hintavaihtelu', 'Tuntihintojen vaihtelu ja riskit'],
                ['kenelle', 'Kenelle pörssisähkö sopii'],
                ['yhteenveto', 'Yhteenveto'],
            ]" />

            <article class="vk-lr-article vk-lr-article--wide">
                {{-- Mikä on pörssisähkö? --}}
                <section class="vk-lr-section">
                    <x-site.section-heading id="mika-on" wave="250">Mikä on pörssisähkö?</x-site.section-heading>
                    <p class="vk-lr-text">
                        Pörssisähkön hinta määräytyy tunneittain Nord Pool -sähköpörssissä ja muodostuu kolmesta osasta:
                    </p>
                    <ol class="vk-lr-numlist vk-lr-text">
                        <li><strong>Pörssihinta</strong>: vaihtelee kysynnän ja tarjonnan mukaan tunneittain</li>
                        <li><strong>Marginaali</strong>: sähköyhtiön kiinteä lisä (tyypillisesti 0,2–0,5 c/kWh)</li>
                        <li><strong>Kuukausimaksu</strong>: kiinteä perusmaksu (tyypillisesti 2–5 €/kk)</li>
                    </ol>
                    <p class="vk-lr-text">
                        Pörssisähkön hinta voi vaihdella merkittävästi vuorokauden ja vuodenajan mukaan. Yöllä ja viikonloppuisin hinta on usein matalampi, kun taas kylminä talvipäivinä kysyntäpiikit nostavat hintaa.
                    </p>
                </section>

                {{-- Mediaanihinta-vertailu --}}
                <section class="vk-lr-section">
                    <x-site.section-heading id="sopimushinnat" wave="300">Miten pörssisähkö vertautuu muihin sopimuksiin?</x-site.section-heading>
                    <p class="vk-lr-text">
                        Alla oleva kuvaaja perustuu Voltikan keräämiin todellisiin sähkösopimusten hintoihin. Se näyttää, paljonko tyypillinen sopimus olisi maksanut 5&nbsp;000 kWh vuosikulutuksella. Tyypillinen tarkoittaa tässä mediaania: puolet sopimuksista oli halvempia ja puolet kalliimpia.
                    </p>
                </section>

                <livewire:article-contract-price-comparison-chart />

                <div class="flex flex-col gap-6">
                    <p class="vk-lr-text">
                        Lue kuvaajaa markkinan suuntana, ei halvimpana tarjouksena tai lupauksena tulevasta hinnasta. Pörssisähkön kustannus elää markkinan mukana, kun taas kiinteähintainen sopimus antaa enemmän ennustettavuutta.
                    </p>
                    {{-- Pull quote (Paper "Reading + pull quote"): the live paragraph set in Bricolage. --}}
                    <div class="vk-lr-quote">
                        <span class="vk-lr-quote-mark" aria-hidden="true">“</span>
                        <div class="vk-lr-quote-body">
                            <p class="vk-lr-quote-text">Vuotuiset luvut piilottavat kolme asiaa: vuodenaikojen vaikutuksen hintaan, kuinka usein pörssisähkö on käytännössä ollut edullisempi, ja kuinka rajusti tuntihinnat heittelevät pörssissä.</p>
                            <p class="vk-lr-quote-after">Katsotaan ne järjestyksessä.</p>
                        </div>
                    </div>
                </div>

                {{-- Seasonality --}}
                <livewire:article-spot-seasonality-chart />

                {{-- Win rate --}}
                <livewire:article-spot-win-rate-chart />

                {{-- Villager interlude (Paper "Villager interlude · ice fishing"): a pause between two charts, far from any contract. --}}
                <x-site.interlude art="interlude-kyykka" class="vk-lr-interlude" />

                {{-- Volatility --}}
                <section id="hintavaihtelu" class="vk-lr-section">
                    <livewire:article-spot-volatility-chart />
                </section>

                {{-- Kenelle pörssisähkö sopii / Milloin kiinteä hinta on parempi (side by side) --}}
                <div class="vk-lr-duo">
                    <section class="vk-lr-duo-col">
                        <x-ink.glyph bucket="porssisahko" size="56" />
                        <div class="flex flex-col gap-0.5">
                            <h2 id="kenelle" class="vk-lr-duo-title scroll-mt-6">Kenelle pörssisähkö sopii?</h2>
                            <x-ink.wave width="200" stroke="3" color="var(--color-cat-spot)" />
                        </div>
                        <p class="vk-lr-text vk-lr-text--small">
                            Pörssisähkö on parhaimmillaan kuluttajille, jotka voivat hyödyntää hinnan vaihtelua.
                            Erityisesti näille ryhmille se voi tuoda merkittäviä säästöjä:
                        </p>
                        <ul class="vk-lr-list vk-lr-list--spot">
                            <li><strong class="text-ink">Voit ajoittaa kulutusta</strong>: esimerkiksi pyykinpesu, astianpesukone ja sähköauton lataus yöllä tai viikonloppuisin</li>
                            <li><strong class="text-ink">Sinulla on lämpöpumppu</strong>: voit lämmittää taloa edullisimpien tuntien aikana ja hyödyntää talon lämpövarastoa</li>
                            <li><strong class="text-ink">Lataat sähköautoa kotona</strong>: yölataus on usein merkittävästi edullisempaa</li>
                            <li><strong class="text-ink">Kulutuksesi on suuri</strong>: omakotitalossa ja suuressa kulutuksessa euromääräiset säästöt ovat suuremmat</li>
                        </ul>
                    </section>
                    <section class="vk-lr-duo-col">
                        <x-ink.glyph bucket="kiintea" size="56" />
                        <div class="flex flex-col gap-0.5">
                            <h2 class="vk-lr-duo-title">Milloin kiinteä hinta on parempi?</h2>
                            <x-ink.wave width="200" stroke="3" color="var(--color-cat-fixed)" />
                        </div>
                        <p class="vk-lr-text vk-lr-text--small">
                            Kiinteähintainen sähkösopimus ei ole automaattisesti kalliimpi. Tietyissä tilanteissa se voi olla parempi valinta:
                        </p>
                        <ul class="vk-lr-list vk-lr-list--fixed">
                            <li><strong class="text-ink">Haluat ennustettavan sähkölaskun</strong>: tiedät tarkalleen, mitä maksat kuukausittain</li>
                            <li><strong class="text-ink">Et voi siirtää kulutusta</strong>: jos sähköä kuluu tasaisesti ympäri vuorokauden</li>
                            <li><strong class="text-ink">Kulutuksesi on pieni</strong>: kerrostaloasunnossa euromääräiset säästöt jäävät pieniksi</li>
                            <li><strong class="text-ink">Haluat suojautua hintapiikeiltä</strong>: kiinteä hinta suojaa markkinaheilahteluilta</li>
                        </ul>
                    </section>
                </div>

                {{-- Yhteenveto --}}
                <section id="yhteenveto" class="vk-lr-section">
                    <x-site.section-heading wave="160">Yhteenveto</x-site.section-heading>
                    <h3 class="vk-lr-steps-title">Pörssisähkö vs. kiinteä hinta tiivistettynä</h3>
                    <ol class="vk-lr-steps">
                        <li class="vk-lr-step">
                            <span class="vk-lr-step-num" aria-hidden="true">1</span>
                            <p class="vk-lr-step-body"><strong class="vk-lr-step-title">{{ $marketSummaryHeading }}</strong> <span>{{ $marketConclusion }}</span></p>
                        </li>
                        <li class="vk-lr-step">
                            <span class="vk-lr-step-num" aria-hidden="true">2</span>
                            <p class="vk-lr-step-body"><strong class="vk-lr-step-title">Vertaa yksittäisiä sopimuksia.</strong> <span>Markkinoilta voi löytyä sopimustyyppinsä mediaania halvempi kiinteä tai pörssisopimus.</span></p>
                        </li>
                        <li class="vk-lr-step">
                            <span class="vk-lr-step-num" aria-hidden="true">3</span>
                            <p class="vk-lr-step-body"><strong class="vk-lr-step-title">Vertaa nyt tarjolla olevat sopimukset.</strong> <span>Tarkista hinnat ja sopimusehdot <a href="/sahkosopimus" class="vk-text-link vk-focus">sähkösopimusvertailusta</a>.</span></p>
                        </li>
                    </ol>
                </section>

                {{-- "Löysitkö etsimäsi?" after the summary, before the further reading. --}}
                <x-page-feedback page-type="article" />

                {{-- Lue lisää + ylläpito --}}
                <section class="vk-lr-more">
                    <h2 class="vk-lr-more-title">Lue lisää</h2>
                    <div class="vk-lr-more-links">
                        <a href="/sahkosopimus/porssisahko" class="vk-focus">Pörssisähkösopimukset</a>
                        <a href="/sahkosopimus/yleissahko" class="vk-focus">Yleissähkösopimukset</a>
                        <a href="/sahkosopimus/kannattaako-maaraaikainen" class="vk-focus">Määräaikainen vs toistaiseksi</a>
                        <a href="/spot-price" class="vk-focus">Spot-hinta nyt</a>
                    </div>
                    <x-methodology-byline updated="29.5.2026" date-label="Sisältö tarkistettu" :inked="true" class="pt-2" />
                </section>

            </article>
        </div>
    </div>
</div>
