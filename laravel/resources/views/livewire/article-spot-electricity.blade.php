<div>
    {{-- JSON-LD Structured Data --}}
    <script type="application/ld+json">
        {!! json_encode($jsonLdSchema, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) !!}
    </script>

    {{-- Hero Section --}}
    <section class="relative -mx-4 overflow-hidden bg-slate-950 sm:-mx-6 lg:-mx-8 mb-8">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 relative">
            <div class="py-12 lg:py-16">
                <div class="max-w-3xl mx-auto text-center">
                    <div class="inline-flex items-center gap-2 rounded-full border border-coral-500/20 bg-coral-500/10 px-4 py-2 text-sm font-medium text-coral-300 mb-6">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z"/>
                        </svg>
                        Sähkösopimusvertailu
                    </div>
                    <h1 class="text-4xl font-extrabold text-white tracking-tight leading-tight md:text-5xl xl:text-6xl mb-6">
                        Kannattaako pörssisähkö?
                    </h1>
                    <p class="text-slate-300 text-lg md:text-xl max-w-2xl mx-auto">
                        Pörssisähkö on Suomen suosituin sähkösopimustyyppi. Mutta kannattaako se juuri sinulle? Katso mitä markkinadata kertoo.
                    </p>
                </div>
            </div>
        </div>
    </section>

    <div class="max-w-4xl mx-auto px-4 sm:px-6 lg:px-8 py-8">

        {{-- Breadcrumb --}}
        <nav class="mb-6" aria-label="Breadcrumb">
            <ol class="flex items-center space-x-2 text-sm text-slate-500">
                <li><a href="/" class="hover:text-coral-600 transition-colors">Etusivu</a></li>
                <li><svg class="w-4 h-4 mx-1" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"></path></svg></li>
                <li><a href="/sahkosopimus" class="hover:text-coral-600 transition-colors">Sähkösopimukset</a></li>
                <li><svg class="w-4 h-4 mx-1" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"></path></svg></li>
                <li class="font-medium text-slate-900" aria-current="page">Kannattaako pörssisähkö</li>
            </ol>
        </nav>

        {{-- In-page TOC --}}
        <nav class="mb-12 border-l-2 border-slate-200 pl-5" aria-label="Artikkelin sisältö">
            <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-slate-500 mb-3">Tässä artikkelissa</p>
            <ol class="space-y-1.5 text-[15px] text-slate-700">
                <li><a href="#mika-on" class="hover:text-coral-600 hover:underline underline-offset-4 decoration-coral-300">Mikä on pörssisähkö?</a></li>
                <li><a href="#sopimushinnat" class="hover:text-coral-600 hover:underline underline-offset-4 decoration-coral-300">Mitä sopimushinnat kertovat juuri nyt</a></li>
                <li><a href="#hintavaihtelu" class="hover:text-coral-600 hover:underline underline-offset-4 decoration-coral-300">Tuntihintojen vaihtelu ja riskit</a></li>
                <li><a href="#vertailu" class="hover:text-coral-600 hover:underline underline-offset-4 decoration-coral-300">Vertaile omalla kulutuksella</a></li>
                <li><a href="#kenelle" class="hover:text-coral-600 hover:underline underline-offset-4 decoration-coral-300">Kenelle pörssisähkö sopii</a></li>
                <li><a href="#yhteenveto" class="hover:text-coral-600 hover:underline underline-offset-4 decoration-coral-300">Yhteenveto</a></li>
            </ol>
        </nav>

        {{-- Market Snapshot --}}
        @if(!empty($marketSnapshot['spot']))
        @php
            $diff = $marketSnapshot['diff'] ?? 0;
            $diffPct = $marketSnapshot['diffPercent'] ?? 0;
            $diffAbs = abs($diff);
            $diffPctAbs = abs($diffPct);
            $diffSign = $diff > 0 ? '−' : ($diff < 0 ? '+' : '');
            $diffLabel = $diff > 0
                ? "Pörssisähkö {$diffPctAbs} % edullisempi"
                : ($diff < 0 ? "Kiinteä 12 kk {$diffPctAbs} % edullisempi" : 'Sama hintataso');
        @endphp
        <div id="markkinatilanne" class="not-prose mb-10 scroll-mt-24">
            <p class="text-xs font-semibold uppercase tracking-[0.16em] text-coral-700 mb-3">Markkinatilanne nyt</p>
            <div class="grid grid-cols-1 sm:grid-cols-3 gap-3">
                <div class="rounded-xl border border-slate-200 bg-white p-4">
                    <p class="text-xs font-medium text-slate-500 uppercase tracking-wide">Pörssisähkö</p>
                    <p class="mt-1 text-2xl font-extrabold text-slate-900 tabular-nums">{{ number_format($marketSnapshot['spot'], 0, ',', ' ') }} €<span class="text-sm font-medium text-slate-500">/v</span></p>
                    <p class="mt-1 text-xs text-slate-500">Mediaani, 5 000 kWh</p>
                </div>
                <div class="rounded-xl border border-slate-200 bg-white p-4">
                    <p class="text-xs font-medium text-slate-500 uppercase tracking-wide">Kiinteä 12 kk</p>
                    <p class="mt-1 text-2xl font-extrabold text-slate-900 tabular-nums">{{ number_format($marketSnapshot['fixed'], 0, ',', ' ') }} €<span class="text-sm font-medium text-slate-500">/v</span></p>
                    <p class="mt-1 text-xs text-slate-500">Mediaani, 5 000 kWh</p>
                </div>
                <div class="rounded-xl border border-coral-200 bg-coral-50 p-4">
                    <p class="text-xs font-medium text-coral-700 uppercase tracking-wide">Ero</p>
                    <p class="mt-1 text-2xl font-extrabold text-coral-700 tabular-nums">{{ $diffSign }}{{ number_format($diffAbs, 0, ',', ' ') }} €<span class="text-sm font-medium text-coral-700">/v</span></p>
                    <p class="mt-1 text-xs text-coral-700">{{ $diffLabel }}</p>
                </div>
            </div>
            <p class="mt-2 text-xs text-slate-500">Tilanne {{ $marketSnapshot['date'] }}. Hinnat sisältävät ALV 25,5 %.</p>
        </div>
        @endif

        {{-- Lead paragraph --}}
        <article class="prose prose-slate prose-lg max-w-prose">
            <p class="lead">
                Pörssisähkö on Suomen yleisin sopimustyyppi. Sen hinta seuraa pörssin tuntihintaa, joten lasku voi olla selvästi kiinteähintaista halvempi, mutta talvipakkasilla yksittäiset tunnit ovat välillä moninkertaisia. Tällä sivulla katsotaan, milloin riski kannattaa ottaa.
            </p>
        </article>

        {{-- Section: Mikä on pörssisähkö? --}}
        <section class="mt-14 pt-10 border-t border-slate-100">
            <article class="prose prose-slate max-w-prose">
                <h2 id="mika-on" class="scroll-mt-24">Mikä on pörssisähkö?</h2>
                <p>
                    Pörssisähkön hinta määräytyy tunneittain Nord Pool -sähköpörssissä ja muodostuu kolmesta osasta:
                </p>
                <ul>
                    <li><strong>Pörssihinta</strong>: vaihtelee kysynnän ja tarjonnan mukaan tunneittain</li>
                    <li><strong>Marginaali</strong>: sähköyhtiön kiinteä lisä (tyypillisesti 0,2–0,5 c/kWh)</li>
                    <li><strong>Kuukausimaksu</strong>: kiinteä perusmaksu (tyypillisesti 2–5 €/kk)</li>
                </ul>
                <p>
                    Pörssisähkön hinta voi vaihdella merkittävästi vuorokauden ja vuodenajan mukaan. Yöllä ja viikonloppuisin hinta on usein matalampi, kun taas kylminä talvipäivinä kysyntäpiikit nostavat hintaa.
                </p>
            </article>
        </section>

        {{-- Section: Mediaanihinta-vertailu --}}
        <section class="mt-14 pt-10 border-t border-slate-100">
            <article class="prose prose-slate max-w-prose">
                <h2 id="sopimushinnat" class="scroll-mt-24">Mitä sopimushinnat kertovat juuri nyt?</h2>
                <p>
                    Alla oleva kuvaaja perustuu Voltikan keräämiin todellisiin sähkösopimusten hintoihin. Se näyttää viikkotasolla mediaanikustannuksen 5&nbsp;000 kWh vuosikulutuksella eri sopimustyypeissä. Mediaani tarkoittaa keskimmäistä sopimusta: puolet tarjolla olleista sopimuksista oli tätä halvempia ja puolet kalliimpia.
                </p>
            </article>

            <div class="mt-8">
                <livewire:article-contract-price-comparison-chart />
            </div>

            <article class="prose prose-slate max-w-prose mt-8">
                <p>
                    Kuvaajaa ei kannata tulkita lupauksena tulevasta hinnasta tai halvimpana tarjouksena, vaan taustakuvana päätökselle: pörssisähkön kustannus elää markkinan mukana, kun taas kiinteähintaiset sopimukset tarjoavat ennustettavuutta usein eri hintatasolla.
                </p>
                <p>
                    Vuotuiset luvut piilottavat kolme asiaa: vuodenaikojen vaikutuksen hintaan, kuinka usein pörssisähkö on käytännössä ollut edullisempi, ja kuinka rajusti tuntihinnat heittelevät pörssissä. Katsotaan ne järjestyksessä.
                </p>
            </article>
        </section>

        {{-- Section: Seasonality --}}
        <section class="mt-14 pt-10 border-t border-slate-100">
            <livewire:article-spot-seasonality-chart />
        </section>

        {{-- Section: Win rate --}}
        <section class="mt-14 pt-10 border-t border-slate-100">
            <livewire:article-spot-win-rate-chart />
        </section>

        {{-- Section: Volatility --}}
        <section id="hintavaihtelu" class="mt-14 pt-10 border-t border-slate-100 scroll-mt-24">
            <livewire:article-spot-volatility-chart />
        </section>

        {{-- Section: Vertailulaskuri --}}
        <section id="vertailu" class="not-prose mt-14 pt-10 border-t border-slate-100 scroll-mt-24">
            <p class="text-xs font-semibold uppercase tracking-[0.16em] text-coral-700">Kokeile omalla kulutuksellasi</p>
            <h2 class="mt-2 text-2xl font-bold tracking-tight text-slate-900">Vertaile pörssisähköä määräaikaisiin ja kiinteähintaisiin sopimuksiin</h2>
            <div class="mt-3 space-y-3 max-w-prose text-base leading-7 text-slate-600">
                <p>
                    Laskuri vertaa pörssisähköä joko kiinteähintaiseen sopimukseen tai määräaikaiseen sopimukseen valitsemallasi kulutuksella.
                    Pörssisähkön hinta-arvio perustuu viime vuoden saman kuukauden toteutuneisiin spot-hintoihin.
                </p>
                <p>
                    Valitse välilehdistä, haluatko tarkastella pörssisähköä suhteessa kiinteään hintaan vai määräaikaiseen sopimukseen. Määräaikaiset ja kiinteähintaiset sopimukset tarjoavat ennustettavuutta eri tavoin, joten molempia vertailuja kannattaa kokeilla.
                </p>
            </div>
            <div class="mt-10">
                <livewire:contract-type-comparison comparison-mode="pricing_model" comparison-context="spot_article" :show-mode-tabs="true" />
            </div>
        </section>

        {{-- Section: Kenelle pörssisähkö sopii --}}
        <section class="mt-14 pt-10 border-t border-slate-100">
            <article class="prose prose-slate max-w-prose">
                <h2 id="kenelle" class="scroll-mt-24">Kenelle pörssisähkö sopii?</h2>
                <p>
                    Pörssisähkö on parhaimmillaan kuluttajille, jotka voivat hyödyntää hinnan vaihtelua.
                    Erityisesti näille ryhmille se voi tuoda merkittäviä säästöjä:
                </p>
                <ul>
                    <li><strong>Voit ajoittaa kulutusta</strong>: esimerkiksi pyykinpesu, astianpesukone ja sähköauton lataus yöllä tai viikonloppuisin</li>
                    <li><strong>Sinulla on lämpöpumppu</strong>: voit lämmittää taloa edullisimpien tuntien aikana ja hyödyntää talon lämpövarastoa</li>
                    <li><strong>Lataat sähköautoa kotona</strong>: yölataus on usein merkittävästi edullisempaa</li>
                    <li><strong>Kulutuksesi on suuri</strong>: omakotitalossa ja suuressa kulutuksessa euromääräiset säästöt ovat suuremmat</li>
                </ul>
            </article>
        </section>

        {{-- Section: Milloin kiinteä on parempi --}}
        <section class="mt-14 pt-10 border-t border-slate-100">
            <article class="prose prose-slate max-w-prose">
                <h2>Milloin kiinteä hinta on parempi?</h2>
                <p>
                    Kiinteähintainen sähkösopimus ei ole automaattisesti kalliimpi. Tietyissä tilanteissa se voi olla parempi valinta:
                </p>
                <ul>
                    <li><strong>Haluat ennustettavan sähkölaskun</strong>: tiedät tarkalleen, mitä maksat kuukausittain</li>
                    <li><strong>Et voi siirtää kulutusta</strong>: jos sähköä kuluu tasaisesti ympäri vuorokauden</li>
                    <li><strong>Kulutuksesi on pieni</strong>: kerrostaloasunnossa euromääräiset säästöt jäävät pieniksi</li>
                    <li><strong>Haluat suojautua hintapiikeiltä</strong>: kiinteä hinta suojaa markkinaheilahteluilta</li>
                </ul>
            </article>
        </section>

        {{-- Section: Yhteenveto --}}
        <section id="yhteenveto" class="mt-14 pt-10 border-t border-slate-100 scroll-mt-24">
            <article class="prose prose-slate max-w-prose">
                <h2>Yhteenveto</h2>
            </article>
            <div class="mt-5 rounded-xl border border-coral-200 bg-coral-50 p-6">
                <h3 class="font-bold text-coral-900 mb-4 text-lg">Pörssisähkö vs. kiinteä hinta tiivistettynä</h3>
                <div class="space-y-3">
                    <div class="flex items-start gap-3">
                        <span class="text-coral-700 font-bold text-lg leading-none mt-0.5">1.</span>
                        <span class="text-slate-700"><strong>Pörssisähkö kannattaa</strong>, jos voit ajoittaa kulutusta tai sinulla on suuri kulutus. Aineisto näyttää, että edullisin viidennes pörssisopimuksia on toistuvasti voittanut vastaavan kiinteähintaisen.</span>
                    </div>
                    <div class="flex items-start gap-3">
                        <span class="text-coral-700 font-bold text-lg leading-none mt-0.5">2.</span>
                        <span class="text-slate-700"><strong>Kiinteä hinta kannattaa</strong>, jos haluat ennustettavuutta tai kulutuksesi on pieni</span>
                    </div>
                    <div class="flex items-start gap-3">
                        <span class="text-coral-700 font-bold text-lg leading-none mt-0.5">3.</span>
                        <span class="text-slate-700"><strong>Kokeile laskuria</strong> omalla kulutuksellasi: todelliset säästöt riippuvat tilanteestasi</span>
                    </div>
                </div>
            </div>
        </section>

        {{-- Internal Links Section --}}
        <section class="mt-16 pt-8 border-t border-slate-200">
            <h2 class="text-xl font-bold text-slate-900 mb-6">Lue lisää</h2>
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-x-6 gap-y-3">
                <a href="/sahkosopimus/porssisahko" class="group flex items-center gap-3 py-2 text-slate-700 hover:text-coral-600 transition-colors">
                    <svg class="w-5 h-5 text-coral-500 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z"/>
                    </svg>
                    <span class="font-medium">Pörssisähkösopimukset</span>
                </a>
                <a href="/sahkosopimus/yleissahko" class="group flex items-center gap-3 py-2 text-slate-700 hover:text-coral-600 transition-colors">
                    <svg class="w-5 h-5 text-slate-500 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/>
                    </svg>
                    <span class="font-medium">Yleissähkösopimukset</span>
                </a>
                <a href="/sahkosopimus/kannattaako-maaraaikainen" class="group flex items-center gap-3 py-2 text-slate-700 hover:text-coral-600 transition-colors">
                    <svg class="w-5 h-5 text-slate-500 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"/>
                    </svg>
                    <span class="font-medium">Määräaikainen vs toistaiseksi</span>
                </a>
                <a href="/spot-price" class="group flex items-center gap-3 py-2 text-slate-700 hover:text-coral-600 transition-colors">
                    <svg class="w-5 h-5 text-slate-500 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 12l3-3 3 3 4-4M8 21l4-4 4 4M3 4h18M4 4h16v12a1 1 0 01-1 1H5a1 1 0 01-1-1V4z"/>
                    </svg>
                    <span class="font-medium">Spot-hinta nyt</span>
                </a>
            </div>
        </section>

    </div>
</div>
