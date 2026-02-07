<div>
    {{-- JSON-LD Structured Data --}}
    <script type="application/ld+json">
        {!! json_encode($jsonLdSchema, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) !!}
    </script>

    {{-- Hero Section --}}
    <section class="bg-gradient-to-br from-slate-900 via-slate-900 to-slate-950 -mx-4 sm:-mx-6 lg:-mx-8 mb-8 relative overflow-hidden">
        <div class="absolute inset-0 pointer-events-none">
            <div class="absolute top-0 right-1/4 w-96 h-96 bg-coral-500 rounded-full blur-3xl opacity-20"></div>
            <div class="absolute bottom-0 left-0 w-72 h-72 bg-coral-400 rounded-full blur-3xl opacity-10 -translate-x-1/2"></div>
        </div>

        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 relative">
            <div class="py-12 lg:py-20">
                <div class="max-w-3xl mx-auto text-center">
                    <div class="inline-flex items-center gap-2 bg-coral-500/20 backdrop-blur-sm px-4 py-2 rounded-full text-sm font-medium text-coral-300 mb-6 border border-coral-500/20">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z"/>
                        </svg>
                        Sähkösopimusvertailu
                    </div>
                    <h1 class="text-4xl font-extrabold text-white tracking-tight leading-tight md:text-5xl xl:text-6xl mb-6">
                        Kannattaako pörssisähkö?
                    </h1>
                    <p class="text-slate-300 text-lg md:text-xl max-w-2xl mx-auto">
                        Pörssisähkö on Suomen suosituin sähkösopimustyyppi. Mutta kannattaako se juuri sinulle? Selvitä asia oikeilla hinnoilla ja omalla kulutuksellasi.
                    </p>
                </div>
            </div>
        </div>
    </section>

    <div class="max-w-4xl mx-auto px-4 sm:px-6 lg:px-8 py-8">

        {{-- Breadcrumb --}}
        <nav class="mb-8" aria-label="Breadcrumb">
            <ol class="flex items-center space-x-2 text-sm text-slate-500">
                <li><a href="/" class="hover:text-coral-600">Etusivu</a></li>
                <li><svg class="w-4 h-4 mx-1" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"></path></svg></li>
                <li><a href="/sahkosopimus" class="hover:text-coral-600">Sähkösopimukset</a></li>
                <li><svg class="w-4 h-4 mx-1" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"></path></svg></li>
                <li class="font-medium text-slate-900" aria-current="page">Kannattaako pörssisähkö</li>
            </ol>
        </nav>

        {{-- Article Content --}}
        <article class="prose prose-slate prose-lg max-w-none">

            <p class="lead text-xl text-slate-600 mb-8">
                Pörssisähkö on noussut Suomen suosituimmaksi sähkösopimustyypiksi. Hinta vaihtelee tunneittain Nord Pool -sähköpörssin mukaan, mikä tarkoittaa sekä säästömahdollisuuksia että riskejä. Tässä artikkelissa vertailemme pörssisähköä kiinteähintaiseen sopimukseen oikeiden hintojen perusteella.
            </p>

            <h2 class="text-2xl font-bold text-slate-900 mt-12 mb-4">Mikä on pörssisähkö?</h2>

            <p>
                Pörssisähkösopimuksessa sähkön energiahinta määräytyy tunneittain Nord Pool -sähköpörssissä. Hinta muodostuu kolmesta osasta:
            </p>

            <ul class="list-disc pl-6 space-y-2">
                <li><strong>Pörssihinta</strong> – vaihtelee kysynnän ja tarjonnan mukaan tunneittain</li>
                <li><strong>Marginaali</strong> – sähköyhtiön kiinteä lisä (tyypillisesti 0,2–0,5 c/kWh)</li>
                <li><strong>Kuukausimaksu</strong> – kiinteä perusmaksu (tyypillisesti 2–5 €/kk)</li>
            </ul>

            <p>
                Pörssisähkön hinta voi vaihdella merkittävästi vuorokauden ja vuodenajan mukaan. Yöllä ja viikonloppuisin hinta on usein matalampi, kun taas kylminä talvipäivinä kysyntäpiikit nostavat hintaa.
            </p>

            <h2 class="text-2xl font-bold text-slate-900 mt-12 mb-4">Vertaile pörssisähköä ja kiinteähintaista</h2>

            <p>
                Alla oleva laskuri vertailee pörssisähkön ja kiinteähintaisen sopimuksen hintaa omalla kulutuksellasi. Pörssisähkön hinta-arvio perustuu viime vuoden saman kuukauden toteutuneisiin spot-hintoihin.
            </p>

        </article>

        {{-- Contract Type Comparison Widget --}}
        <div class="my-12">
            <livewire:contract-type-comparison comparison-mode="pricing_model" />
        </div>

        {{-- Continue Article --}}
        <article class="prose prose-slate prose-lg max-w-none">

            <h2 class="text-2xl font-bold text-slate-900 mt-12 mb-4">Kenelle pörssisähkö sopii?</h2>

            <p>
                Pörssisähkö on parhaimmillaan kuluttajille, jotka voivat hyödyntää hinnan vaihtelua. Erityisesti näille ryhmille pörssisähkö voi tuoda merkittäviä säästöjä:
            </p>

            <div class="bg-green-50 border border-green-200 rounded-xl p-6 my-6 not-prose">
                <h3 class="font-bold text-green-800 mb-4">Pörssisähkö sopii sinulle, jos:</h3>
                <ul class="space-y-3">
                    <li class="flex items-start gap-3">
                        <svg class="w-5 h-5 text-green-600 mt-0.5 flex-shrink-0" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clip-rule="evenodd"/></svg>
                        <span class="text-slate-700"><strong>Voit ajoittaa kulutusta</strong> – esim. pyykinpesu, astianpesukone ja sähköauton lataus yöllä tai viikonloppuisin</span>
                    </li>
                    <li class="flex items-start gap-3">
                        <svg class="w-5 h-5 text-green-600 mt-0.5 flex-shrink-0" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clip-rule="evenodd"/></svg>
                        <span class="text-slate-700"><strong>Sinulla on lämpöpumppu</strong> – voit lämmittää taloa edullisimpien tuntien aikana ja hyödyntää talon lämpövarastoa</span>
                    </li>
                    <li class="flex items-start gap-3">
                        <svg class="w-5 h-5 text-green-600 mt-0.5 flex-shrink-0" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clip-rule="evenodd"/></svg>
                        <span class="text-slate-700"><strong>Lataat sähköautoa kotona</strong> – yölataus on usein merkittävästi edullisempaa</span>
                    </li>
                    <li class="flex items-start gap-3">
                        <svg class="w-5 h-5 text-green-600 mt-0.5 flex-shrink-0" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clip-rule="evenodd"/></svg>
                        <span class="text-slate-700"><strong>Kulutuksesi on suuri</strong> – omakotitalossa ja suuressa kulutuksessa euromääräiset säästöt ovat suuremmat</span>
                    </li>
                </ul>
            </div>

            <h2 class="text-2xl font-bold text-slate-900 mt-12 mb-4">Milloin kiinteä hinta on parempi?</h2>

            <p>
                Kiinteähintainen sähkösopimus ei ole automaattisesti kalliimpi. Tietyissä tilanteissa se voi olla parempi valinta:
            </p>

            <div class="bg-blue-50 border border-blue-200 rounded-xl p-6 my-6 not-prose">
                <h3 class="font-bold text-blue-800 mb-4">Kiinteä hinta sopii sinulle, jos:</h3>
                <ul class="space-y-3">
                    <li class="flex items-start gap-3">
                        <svg class="w-5 h-5 text-blue-600 mt-0.5 flex-shrink-0" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clip-rule="evenodd"/></svg>
                        <span class="text-slate-700"><strong>Haluat ennustettavan sähkölaskun</strong> – tiedät tarkalleen, mitä maksat kuukausittain</span>
                    </li>
                    <li class="flex items-start gap-3">
                        <svg class="w-5 h-5 text-blue-600 mt-0.5 flex-shrink-0" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clip-rule="evenodd"/></svg>
                        <span class="text-slate-700"><strong>Et voi siirtää kulutusta</strong> – jos sähköä kuluu tasaisesti ympäri vuorokauden</span>
                    </li>
                    <li class="flex items-start gap-3">
                        <svg class="w-5 h-5 text-blue-600 mt-0.5 flex-shrink-0" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clip-rule="evenodd"/></svg>
                        <span class="text-slate-700"><strong>Kulutuksesi on pieni</strong> – kerrostaloasunnossa euromääräiset säästöt jäävät pieniksi</span>
                    </li>
                    <li class="flex items-start gap-3">
                        <svg class="w-5 h-5 text-blue-600 mt-0.5 flex-shrink-0" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clip-rule="evenodd"/></svg>
                        <span class="text-slate-700"><strong>Haluat suojautua hintapiikeiltä</strong> – kiinteä hinta suojaa markkinaheilahteluilta</span>
                    </li>
                </ul>
            </div>

            <h2 class="text-2xl font-bold text-slate-900 mt-12 mb-4">Kulutuksen vaikutus säästöihin</h2>

            <p>
                Sähkönkulutus vaikuttaa merkittävästi siihen, kumpi sopimustyyppi kannattaa. Suuremmalla kulutuksella euromääräiset erot kasvavat, mutta prosentuaalinen ero pysyy samana.
            </p>

            <div class="bg-slate-50 rounded-xl p-6 my-6 not-prose">
                <h3 class="font-bold text-slate-800 mb-4">Tyypilliset kulutustasot:</h3>
                <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                    <div class="bg-white rounded-lg p-4 border border-slate-200">
                        <div class="text-sm text-slate-500 mb-1">Kerrostalo</div>
                        <div class="text-2xl font-bold text-slate-900">~5 000 kWh/v</div>
                        <div class="text-sm text-slate-600 mt-2">Kodinkoneet, valaistus, viihde-elektroniikka</div>
                    </div>
                    <div class="bg-white rounded-lg p-4 border border-slate-200">
                        <div class="text-sm text-slate-500 mb-1">Rivitalo</div>
                        <div class="text-2xl font-bold text-slate-900">~10 000 kWh/v</div>
                        <div class="text-sm text-slate-600 mt-2">Lisäksi osittainen sähkölämmitys</div>
                    </div>
                    <div class="bg-white rounded-lg p-4 border border-slate-200">
                        <div class="text-sm text-slate-500 mb-1">Omakotitalo</div>
                        <div class="text-2xl font-bold text-slate-900">~18 000 kWh/v</div>
                        <div class="text-sm text-slate-600 mt-2">Sähkölämmitys tai lämpöpumppu</div>
                    </div>
                </div>
                <p class="text-sm text-slate-600 mt-4">
                    Kokeile yllä olevassa laskurissa eri kulutustasoja nähdäksesi, miten ero muuttuu.
                </p>
            </div>

            <h2 class="text-2xl font-bold text-slate-900 mt-12 mb-4">Vuodenajan vaikutus pörssisähkön hintaan</h2>

            <p>
                Pörssisähkön hinta vaihtelee voimakkaasti vuodenajan mukaan. Talvikuukausina (marras-maaliskuu) hinnat ovat tyypillisesti korkeammat kylmän sään aiheuttaman kysynnän vuoksi. Kesällä hinnat ovat usein matalat runsaan vesivoiman ja aurinkosähkön ansiosta.
            </p>

            <div class="bg-amber-50 border border-amber-200 rounded-xl p-6 my-6 not-prose">
                <div class="flex items-start gap-3">
                    <svg class="w-6 h-6 text-amber-600 mt-0.5 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/>
                    </svg>
                    <div>
                        <h3 class="font-bold text-amber-800 mb-2">Talvikuukaudet voivat olla kalliimpia</h3>
                        <p class="text-slate-700">
                            Kuukausivertailussa voit nähdä, että pörssisähkö on joissakin talvikuukausissa <strong>kalliimpaa</strong> kuin kiinteähintainen sopimus. Tämä johtuu sähkön korkeammasta pörssihinnasta ja suuremmasta kulutuksesta lämmityskaudella. Vuositasolla pörssisähkö on kuitenkin tyypillisesti kokonaisuutena edullisempi, koska kesän matalat hinnat kompensoivat talven hintapiikkejä.
                        </p>
                    </div>
                </div>
            </div>

            <p>
                Yllä oleva laskuri huomioi nämä kausivaihtelut käyttämällä viime vuoden toteutuneita kuukausihintoja ennusteena tuleville kuukausille. Sähkölämmitteiselle talolle laskuri jakaa kulutuksen kausivaihtelun mukaan – enemmän talvella, vähemmän kesällä. Näin saat realistisen arvion vuosikustannuksista.
            </p>

            <h2 class="text-2xl font-bold text-slate-900 mt-12 mb-4">Yhteenveto</h2>

            <div class="bg-coral-50 border border-coral-200 rounded-xl p-6 my-6 not-prose">
                <h3 class="font-bold text-coral-800 mb-4">Pörssisähkö vs kiinteä hinta – tiivistettynä:</h3>
                <div class="space-y-4">
                    <div class="flex items-start gap-3">
                        <span class="text-coral-600 font-bold text-lg">1.</span>
                        <span class="text-slate-700"><strong>Pörssisähkö kannattaa</strong>, jos voit ajoittaa kulutusta tai sinulla on suuri kulutus (omakotitalo, lämpöpumppu, sähköauto)</span>
                    </div>
                    <div class="flex items-start gap-3">
                        <span class="text-coral-600 font-bold text-lg">2.</span>
                        <span class="text-slate-700"><strong>Kiinteä hinta kannattaa</strong>, jos haluat ennustettavuutta tai kulutuksesi on pieni</span>
                    </div>
                    <div class="flex items-start gap-3">
                        <span class="text-coral-600 font-bold text-lg">3.</span>
                        <span class="text-slate-700"><strong>Kokeile laskuria</strong> omalla kulutuksellasi – todelliset säästöt riippuvat tilanteestasi</span>
                    </div>
                </div>
            </div>

        </article>

        {{-- Internal Links Section --}}
        <section class="mt-16 pt-8 border-t border-slate-200">
            <h2 class="text-xl font-bold text-slate-900 mb-6">Lue lisää</h2>
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <a href="/sahkosopimus/porssisahko" class="group bg-white border border-slate-200 rounded-xl p-5 hover:border-coral-400 hover:shadow-md transition-all">
                    <div class="flex items-center gap-4">
                        <div class="p-3 bg-coral-50 rounded-lg group-hover:bg-coral-100 transition-colors">
                            <svg class="w-6 h-6 text-coral-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z"/>
                            </svg>
                        </div>
                        <div>
                            <h3 class="font-semibold text-slate-900 group-hover:text-coral-600 transition-colors">Pörssisähkösopimukset</h3>
                            <p class="text-sm text-slate-500">Vertaile kaikkia pörssisähkösopimuksia</p>
                        </div>
                    </div>
                </a>
                <a href="/sahkosopimus/yleissahko" class="group bg-white border border-slate-200 rounded-xl p-5 hover:border-coral-400 hover:shadow-md transition-all">
                    <div class="flex items-center gap-4">
                        <div class="p-3 bg-blue-50 rounded-lg group-hover:bg-blue-100 transition-colors">
                            <svg class="w-6 h-6 text-blue-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/>
                            </svg>
                        </div>
                        <div>
                            <h3 class="font-semibold text-slate-900 group-hover:text-coral-600 transition-colors">Yleissähkösopimukset</h3>
                            <p class="text-sm text-slate-500">Vertaile kiinteähintaisia yleissähkösopimuksia</p>
                        </div>
                    </div>
                </a>
                <a href="/sahkosopimus/kannattaako-maaraaikainen" class="group bg-white border border-slate-200 rounded-xl p-5 hover:border-coral-400 hover:shadow-md transition-all">
                    <div class="flex items-center gap-4">
                        <div class="p-3 bg-green-50 rounded-lg group-hover:bg-green-100 transition-colors">
                            <svg class="w-6 h-6 text-green-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"/>
                            </svg>
                        </div>
                        <div>
                            <h3 class="font-semibold text-slate-900 group-hover:text-coral-600 transition-colors">Määräaikainen vs toistaiseksi</h3>
                            <p class="text-sm text-slate-500">Kannattaako sitoutua määräajaksi?</p>
                        </div>
                    </div>
                </a>
                <a href="/spot-price" class="group bg-white border border-slate-200 rounded-xl p-5 hover:border-coral-400 hover:shadow-md transition-all">
                    <div class="flex items-center gap-4">
                        <div class="p-3 bg-amber-50 rounded-lg group-hover:bg-amber-100 transition-colors">
                            <svg class="w-6 h-6 text-amber-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 12l3-3 3 3 4-4M8 21l4-4 4 4M3 4h18M4 4h16v12a1 1 0 01-1 1H5a1 1 0 01-1-1V4z"/>
                            </svg>
                        </div>
                        <div>
                            <h3 class="font-semibold text-slate-900 group-hover:text-coral-600 transition-colors">Spot-hinta nyt</h3>
                            <p class="text-sm text-slate-500">Seuraa sähkön pörssihintaa reaaliajassa</p>
                        </div>
                    </div>
                </a>
            </div>
        </section>

    </div>
</div>
