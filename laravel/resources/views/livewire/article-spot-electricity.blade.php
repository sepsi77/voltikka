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
        <nav class="mb-8" aria-label="Breadcrumb">
            <ol class="flex items-center space-x-2 text-sm text-slate-500">
                <li><a href="/" class="hover:text-coral-600 transition-colors">Etusivu</a></li>
                <li><svg class="w-4 h-4 mx-1" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"></path></svg></li>
                <li><a href="/sahkosopimus" class="hover:text-coral-600 transition-colors">Sähkösopimukset</a></li>
                <li><svg class="w-4 h-4 mx-1" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"></path></svg></li>
                <li class="font-medium text-slate-900" aria-current="page">Kannattaako pörssisähkö</li>
            </ol>
        </nav>

        {{-- Market Snapshot --}}
        @if(!empty($marketSnapshot['spot']))
        <div class="not-prose mb-10">
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
                    <p class="mt-1 text-2xl font-extrabold text-coral-700 tabular-nums">−{{ number_format($marketSnapshot['diff'], 0, ',', ' ') }} €<span class="text-sm font-medium text-coral-700">/v</span></p>
                    <p class="mt-1 text-xs text-coral-700">Pörssisähkö {{ $marketSnapshot['diffPercent'] }} % edullisempi</p>
                </div>
            </div>
            <p class="mt-2 text-xs text-slate-500">Tilanne {{ $marketSnapshot['date'] }}. Hinnat sisältävät ALV 25,5 %.</p>
        </div>
        @endif

        {{-- Article Content --}}
        <article class="prose prose-slate max-w-prose">
            <p class="lead text-xl text-slate-600 mb-8">
                Pörssisähkösopimuksessa energiahinta määräytyy tunneittain Nord Pool -sähköpörssissä. Se voi tuoda säästöjä, mutta myös riskejä. Alla näet, mitä todelliset markkinahinnat kertovat viime kuukausilta.
            </p>

            <h2 class="text-2xl font-bold text-slate-900 mt-12 mb-4">Mitä sopimushinnat kertovat juuri nyt?</h2>

            <p>
                Alla oleva kuvaaja perustuu Voltikan keräämiin todellisiin sähkösopimusten hintoihin. Se näyttää viikkotasolla mediaanikustannuksen 5&nbsp;000 kWh vuosikulutuksella eri sopimustyypeissä. Mediaani tarkoittaa keskimmäistä sopimusta — puolet tarjolla olleista sopimuksista oli tätä halvempia ja puolet kalliimpia.
            </p>
        </article>

        <div class="my-16">
            <livewire:article-contract-price-comparison-chart />
        </div>

        <article class="prose prose-slate max-w-prose">
            <p>
                Kuvaajaa ei kannata tulkita lupauksena tulevasta hinnasta tai halvimpana tarjouksena, vaan taustakuvana päätökselle: pörssisähkön kustannus elää markkinan mukana, kun taas kiinteähintaiset sopimukset tarjoavat ennustettavuutta usein eri hintatasolla.
            </p>
        </article>

        {{-- Seasonality Chart --}}
        <div class="my-16">
            <livewire:article-spot-seasonality-chart />
        </div>

        {{-- Win Rate Chart --}}
        <div class="my-16">
            <livewire:article-spot-win-rate-chart />
        </div>

        <article class="prose prose-slate max-w-prose mt-10">
            <h2 class="text-2xl font-bold text-slate-900 mt-12 mb-4">Hintavaihtelu ja riskit</h2>

            @if($volatilityMetrics['max'])
                <p>
                    Viimeisen 12 kuukauden aikana spot-hinta on vaihdellut
                    <strong>{{ number_format($volatilityMetrics['min'], 2, ',', ' ') }} c/kWh</strong>
                    ja <strong>{{ number_format($volatilityMetrics['max'], 2, ',', ' ') }} c/kWh</strong> välillä.
                    Keskimääräinen hinta oli {{ number_format($volatilityMetrics['avg'], 2, ',', ' ') }} c/kWh.
                </p>
                <p>
                    Hintapiikkejä (yli 20 c/kWh) esiintyi <strong>{{ $volatilityMetrics['spikeDays'] }} päivänä</strong>.
                    Toisaalta negatiivisia hintoja — jolloin sähkön myyjä maksaa sinulle — oli
                    <strong>{{ $volatilityMetrics['negativeDays'] }} päivänä</strong>.
                </p>
                <p>
                    Suuri vaihteluväli tarkoittaa, että pörssisähkö sopii parhaiten niille, jotka
                    kestävät hintavaihtelua tai pystyvät hyödyntämään edullisia tunteja.
                </p>
            @else
                <p class="text-slate-500">Volatiliteettitietoja ei ole vielä saatavilla.</p>
            @endif

            <h2 class="text-2xl font-bold text-slate-900 mt-12 mb-4">Mikä on pörssisähkö?</h2>

            <p>
                Pörssisähkösopimuksessa sähkön energiahinta määräytyy tunneittain Nord Pool -sähköpörssissä. Hinta muodostuu kolmesta osasta:
            </p>
            <ul>
                <li><strong>Pörssihinta</strong> – vaihtelee kysynnän ja tarjonnan mukaan tunneittain</li>
                <li><strong>Marginaali</strong> – sähköyhtiön kiinteä lisä (tyypillisesti 0,2–0,5 c/kWh)</li>
                <li><strong>Kuukausimaksu</strong> – kiinteä perusmaksu (tyypillisesti 2–5 €/kk)</li>
            </ul>
            <p>
                Pörssisähkön hinta voi vaihdella merkittävästi vuorokauden ja vuodenajan mukaan. Yöllä ja viikonloppuisin hinta on usein matalampi, kun taas kylminä talvipäivinä kysyntäpiikit nostavat hintaa.
            </p>
        </article>

        {{-- Comparison Widget --}}
        <div class="not-prose my-16">
            <p class="text-xs font-semibold uppercase tracking-[0.16em] text-coral-700 mb-3">Kokeile omalla kulutuksellasi</p>
            <h2 class="text-2xl font-bold tracking-tight text-slate-900 mb-4">Vertaile pörssisähköä ja kiinteähintaista</h2>
            <p class="text-slate-600 mb-6 max-w-prose">
                Alla oleva laskuri vertailee pörssisähkön ja kiinteähintaisen sopimuksen hintaa valitsemallasi kulutuksella.
                Pörssisähkön hinta-arvio perustuu viime vuoden saman kuukauden toteutuneisiin spot-hintoihin.
            </p>
            <livewire:contract-type-comparison comparison-mode="pricing_model" :show-mode-tabs="false" />
        </div>

        {{-- Continue Article --}}
        <article class="prose prose-slate max-w-prose">
            <h2 class="text-2xl font-bold text-slate-900 mt-12 mb-4">Kenelle pörssisähkö sopii?</h2>

            <p>
                Pörssisähkö on parhaimmillaan kuluttajille, jotka voivat hyödyntää hinnan vaihtelua.
                Erityisesti näille ryhmille se voi tuoda merkittäviä säästöjä:
            </p>
            <ul>
                <li><strong>Voit ajoittaa kulutusta</strong> – esimerkiksi pyykinpesu, astianpesukone ja sähköauton lataus yöllä tai viikonloppuisin</li>
                <li><strong>Sinulla on lämpöpumppu</strong> – voit lämmittää taloa edullisimpien tuntien aikana ja hyödyntää talon lämpövarastoa</li>
                <li><strong>Lataat sähköautoa kotona</strong> – yölataus on usein merkittävästi edullisempaa</li>
                <li><strong>Kulutuksesi on suuri</strong> – omakotitalossa ja suuressa kulutuksessa euromääräiset säästöt ovat suuremmat</li>
            </ul>

            <h2 class="text-2xl font-bold text-slate-900 mt-12 mb-4">Milloin kiinteä hinta on parempi?</h2>

            <p>
                Kiinteähintainen sähkösopimus ei ole automaattisesti kalliimpi. Tietyissä tilanteissa se voi olla parempi valinta:
            </p>
            <ul>
                <li><strong>Haluat ennustettavan sähkölaskun</strong> – tiedät tarkalleen, mitä maksat kuukausittain</li>
                <li><strong>Et voi siirtää kulutusta</strong> – jos sähköä kuluu tasaisesti ympäri vuorokauden</li>
                <li><strong>Kulutuksesi on pieni</strong> – kerrostaloasunnossa euromääräiset säästöt jäävät pieniksi</li>
                <li><strong>Haluat suojautua hintapiikeiltä</strong> – kiinteä hinta suojaa markkinaheilahteluilta</li>
            </ul>

            <h2 class="text-2xl font-bold text-slate-900 mt-12 mb-4">Kulutuksen vaikutus säästöihin</h2>

            <p>
                Sähkönkulutus vaikuttaa merkittävästi siihen, kumpi sopimustyyppi kannattaa. Suuremmalla kulutuksella euromääräiset erot kasvavat, mutta prosentuaalinen ero pysyy samana.
            </p>
        </article>

        {{-- Consumption levels --}}
        <div class="not-prose my-12">
            <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                <div class="rounded-xl border border-slate-200 bg-white p-5">
                    <p class="text-sm font-medium text-slate-500">Kerrostalo</p>
                    <p class="mt-1 text-2xl font-extrabold text-slate-900 tabular-nums">~5 000 <span class="text-base font-medium text-slate-500">kWh/v</span></p>
                    <p class="mt-2 text-sm text-slate-600">Kodinkoneet, valaistus, viihde-elektroniikka</p>
                </div>
                <div class="rounded-xl border border-slate-200 bg-white p-5">
                    <p class="text-sm font-medium text-slate-500">Rivitalo</p>
                    <p class="mt-1 text-2xl font-extrabold text-slate-900 tabular-nums">~10 000 <span class="text-base font-medium text-slate-500">kWh/v</span></p>
                    <p class="mt-2 text-sm text-slate-600">Lisäksi osittainen sähkölämmitys</p>
                </div>
                <div class="rounded-xl border border-slate-200 bg-white p-5">
                    <p class="text-sm font-medium text-slate-500">Omakotitalo</p>
                    <p class="mt-1 text-2xl font-extrabold text-slate-900 tabular-nums">~18 000 <span class="text-base font-medium text-slate-500">kWh/v</span></p>
                    <p class="mt-2 text-sm text-slate-600">Sähkölämmitys tai lämpöpumppu</p>
                </div>
            </div>
            <p class="mt-3 text-sm text-slate-500">Kokeile yllä olevassa laskurissa eri kulutustasoja nähdäksesi, miten ero muuttuu.</p>
        </div>

        <article class="prose prose-slate max-w-prose">
            <h2 class="text-2xl font-bold text-slate-900 mt-12 mb-4">Vuodenajan vaikutus pörssisähkön hintaan</h2>

            <p>
                Pörssisähkön hinta vaihtelee voimakkaasti vuodenajan mukaan. Talvikuukausina (marras–maaliskuu) hinnat ovat tyypillisesti korkeammat kylmän sään aiheuttaman kysynnän vuoksi. Kesällä hinnat ovat usein matalat runsaan vesivoiman ja aurinkosähkön ansiosta.
            </p>
            <p>
                Yllä oleva kausikuvaaja näyttää tämän selvästi: tammikuussa ja helmikuussa 2026 spot-hinta oli keskimäärin yli 14 c/kWh, kun taas touko–kesäkuussa 2025 se painui alle 2,5 c/kWh. Ero on moninkertainen.
            </p>
            <p>
                Tämä tarkoittaa, että pörssisähkön vuosikustannus riippuu paljon siitä, miten hyvin pystyt hyödyntämään edullisia kesä- ja yöhintoja kompensoimaan kalliimpia talvi- ja päivähintoja.
            </p>

            <h2 class="text-2xl font-bold text-slate-900 mt-12 mb-4">Yhteenveto</h2>

            <div class="rounded-xl border border-coral-200 bg-coral-50 p-6 my-6 not-prose">
                <h3 class="font-bold text-coral-900 mb-4 text-lg">Pörssisähkö vs kiinteä hinta – tiivistettynä:</h3>
                <div class="space-y-3">
                    <div class="flex items-start gap-3">
                        <span class="text-coral-700 font-bold text-lg leading-none mt-0.5">1.</span>
                        <span class="text-slate-700"><strong>Pörssisähkö kannattaa</strong>, jos voit ajoittaa kulutusta tai sinulla on suuri kulutus (omakotitalo, lämpöpumppu, sähköauto)</span>
                    </div>
                    <div class="flex items-start gap-3">
                        <span class="text-coral-700 font-bold text-lg leading-none mt-0.5">2.</span>
                        <span class="text-slate-700"><strong>Kiinteä hinta kannattaa</strong>, jos haluat ennustettavuutta tai kulutuksesi on pieni</span>
                    </div>
                    <div class="flex items-start gap-3">
                        <span class="text-coral-700 font-bold text-lg leading-none mt-0.5">3.</span>
                        <span class="text-slate-700"><strong>Kokeile laskuria</strong> omalla kulutuksellasi — todelliset säästöt riippuvat tilanteestasi</span>
                    </div>
                </div>
            </div>
        </article>

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
