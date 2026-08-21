<div>
    <x-schema-markup :schemas="[$jsonLdSchema]" />

    {{-- Hero --}}
    <section class="relative -mx-4 overflow-hidden bg-slate-950 sm:-mx-6 lg:-mx-8">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 relative">
            <div class="py-12 lg:py-16">
                <div class="max-w-3xl mx-auto text-center">
                    <div class="inline-flex items-center gap-2 rounded-full border border-coral-500/20 bg-coral-500/10 px-4 py-2 text-sm font-medium text-coral-300 mb-6">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z"/>
                        </svg>
                        Riippumaton vertailu
                    </div>
                    <h1 class="text-4xl font-extrabold text-white tracking-tight leading-tight md:text-5xl xl:text-6xl mb-6">
                        Tietoa Voltikasta
                    </h1>
                    <p class="text-slate-300 text-lg md:text-xl max-w-2xl mx-auto">
                        Kuka Voltikkaa ylläpitää, miten palvelu rahoitetaan ja miten sähkösopimusten
                        vuosikustannukset lasketaan. Avoimesti, ilman provisioita ja mainosrahaa.
                    </p>
                </div>
            </div>
        </div>
    </section>

    <x-page-action-strip />

    <div class="max-w-4xl mx-auto px-4 sm:px-6 lg:px-8 py-8">

        {{-- Breadcrumb --}}
        <nav class="mb-6" aria-label="Breadcrumb">
            <ol class="flex items-center space-x-2 text-sm text-slate-500">
                <li><a href="/" class="hover:text-coral-600 transition-colors">Etusivu</a></li>
                <li><svg class="w-4 h-4 mx-1" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"></path></svg></li>
                <li class="font-medium text-slate-900" aria-current="page">Tietoa</li>
            </ol>
        </nav>

        {{-- In-page TOC --}}
        <nav class="mb-12 border-l-2 border-slate-200 pl-5" aria-label="Sivun sisältö">
            <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-slate-500 mb-3">Tällä sivulla</p>
            <ol class="space-y-1.5 text-[15px] text-slate-700">
                <li><a href="#mika-on" class="hover:text-coral-600 hover:underline underline-offset-4 decoration-coral-300">Mikä Voltikka on</a></li>
                <li><a href="#yllapito" class="hover:text-coral-600 hover:underline underline-offset-4 decoration-coral-300">Kuka Voltikkaa ylläpitää</a></li>
                <li><a href="#rahoitus" class="hover:text-coral-600 hover:underline underline-offset-4 decoration-coral-300">Miten Voltikka rahoitetaan</a></li>
                <li><a href="#menetelma" class="hover:text-coral-600 hover:underline underline-offset-4 decoration-coral-300">Näin laskemme kustannukset</a></li>
                <li><a href="#yhteys" class="hover:text-coral-600 hover:underline underline-offset-4 decoration-coral-300">Yhteystiedot</a></li>
            </ol>
        </nav>

        <div class="space-y-12 text-slate-700 leading-relaxed">

            {{-- Mikä Voltikka on --}}
            <section id="mika-on" class="scroll-mt-24">
                <h2 class="text-2xl font-extrabold text-slate-900 tracking-tight mb-4">Mikä Voltikka on</h2>
                <p class="mb-4">
                    Voltikka on riippumaton sähkösopimusten ja energian vertailupalvelu. Sivustolla voit
                    vertailla sähkösopimuksia oman kulutuksesi mukaan, seurata pörssisähkön hintaa ja
                    hintakehitystä sekä arvioida aurinkopaneelien tuottoa ja lämpöpumpun säästöjä.
                </p>
                <p>
                    Vertailussa on tällä hetkellä <span class="font-semibold text-slate-900">{{ number_format($contractCount, 0, ',', ' ') }} sähkösopimusta</span>
                    <span class="text-slate-600">{{ number_format($companyCount, 0, ',', ' ') }} sähköyhtiöltä</span> — yksi Suomen
                    kattavimmista sähkösopimusvertailuista. Kaikki sopimukset järjestetään samalla logiikalla,
                    eikä yksittäisiä yhtiöitä nosteta esiin maksua vastaan.
                </p>
            </section>

            {{-- Kuka ylläpitää --}}
            <section id="yllapito" class="scroll-mt-24">
                <h2 class="text-2xl font-extrabold text-slate-900 tracking-tight mb-4">Kuka Voltikkaa ylläpitää</h2>
                <p class="mb-4">
                    Voltikka on yksityishenkilön ylläpitämä harrasteprojekti, joka käynnistyi vuonna 2026.
                    Se ei ole yritys eikä sen takana ole sähköyhtiötä tai mainostajaa. Sivusto syntyi
                    kiinnostuksesta energiamarkkinoihin ja hintadataan.
                </p>
                <p>
                    Koska kyseessä on yhden ihmisen vapaa-ajan projekti, emme julkaise henkilön nimeä, mutta
                    palvelusta vastataan kysymyksiin ja korjauspyyntöihin sähköpostitse — ks.
                    <a href="#yhteys" class="text-coral-600 font-medium hover:text-coral-700 underline underline-offset-2">yhteystiedot</a>.
                </p>
            </section>

            {{-- Rahoitus --}}
            <section id="rahoitus" class="scroll-mt-24">
                <h2 class="text-2xl font-extrabold text-slate-900 tracking-tight mb-4">Miten Voltikka rahoitetaan</h2>
                <p class="mb-4">
                    Suoraan sanottuna: Voltikka ei tienaa rahaa. Palvelu on itse rahoitettu harrasteprojekti,
                    ja sen ainoat kulut — noin 20 € kuukaudessa palvelinkuluja — maksaa ylläpitäjä omasta
                    taskustaan.
                </p>
                <p class="mb-4">
                    Voltikka <span class="font-semibold text-slate-900">ei ota provisiota sähköyhtiöiltä</span>,
                    ei myy mainostilaa eikä veloita yhtiöitä listauksesta tai sijoituksesta vertailussa. Sivustolla
                    ei myöskään ole sponsoroituja sopimuksia. Tämän takia mikään yhtiö ei voi maksaa päästäkseen
                    vertailussa ylemmäs.
                </p>
                <p>
                    Käytännössä tämä tarkoittaa, että vertailun järjestys perustuu vain laskettuihin kustannuksiin
                    ja sopimusten ominaisuuksiin — ei siihen, kuka maksaa eniten.
                </p>
            </section>

            {{-- Menetelmä --}}
            <section id="menetelma" class="scroll-mt-24">
                <h2 class="text-2xl font-extrabold text-slate-900 tracking-tight mb-4">Näin laskemme kustannukset</h2>
                <p class="mb-6">
                    Vertailun vuosikustannukset lasketaan kaikille sopimuksille samalla kaavalla, jotta ne ovat
                    keskenään vertailukelpoisia. Alla on kerrottu lähteet, laskentakaava ja oletukset.
                </p>

                <div class="space-y-5">
                    <div class="rounded-xl border border-slate-200 bg-slate-50 p-5">
                        <h3 class="font-bold text-slate-900 mb-2">Lähde ja päivitys</h3>
                        <p>
                            Sopimushinnat kerätään sähköyhtiöiden julkisesti saatavilla olevista hinnastoista ja
                            päivitetään päivittäin. Pörssisähkön toteutuneet hinnat päivittyvät tunneittain.
                        </p>
                    </div>

                    <div class="rounded-xl border border-slate-200 bg-slate-50 p-5">
                        <h3 class="font-bold text-slate-900 mb-2">Vuosikustannuksen kaava</h3>
                        <p class="mb-3">
                            <span class="font-semibold text-slate-900">Vuosikustannus = energia (snt/kWh × kulutus) + perusmaksu × 12</span>,
                            sisältäen voimassa olevat tarjoukset.
                        </p>
                        <ul class="list-disc pl-5 space-y-1.5">
                            <li>Hinnat sisältävät arvonlisäveron (alv 25,5 %).</li>
                            <li>Sähkön <span class="font-medium">siirtomaksu ei sisälly</span> — se maksetaan paikalliselle
                                verkkoyhtiölle erikseen eikä riipu sähkösopimuksesta.</li>
                            <li>Kulutus jaetaan kuukausille realistisesti: talvikuukausina kulutus on suurempi kuin kesällä.</li>
                            <li>Kaikkien sopimusten hinta ei pysy samana koko vuotta. Pörssisähkön, kuukausi- ja
                                kvartaalisähkön sekä hinnaltaan muutettavien toistaiseksi voimassa olevien sopimusten
                                kohdalla laskemme kuukaudet erikseen. Katso omat kohtansa alempana.</li>
                        </ul>
                    </div>

                    <div class="rounded-xl border border-slate-200 bg-slate-50 p-5">
                        <h3 class="font-bold text-slate-900 mb-2">Sopimusten tarkistus ja harhaanjohtavat tarjoukset</h3>
                        <p class="mb-3">
                            Tarkistamme jokaisen sopimuksen hinnoittelun sekä sähköyhtiön ilmoittamista hinnoista
                            että sopimuksen kuvauksesta. Osa yhtiöistä ilmoittaa halvan tarjoushinnan, mutta
                            kertoo vasta kuvaustekstissä, että hinta nousee tietyn jakson jälkeen.
                        </p>
                        <p>
                            Tunnistamme tällaiset harhaanjohtavat tarjoukset ja korjaamme niiden vaikutuksen: laskemme
                            sopimuksen todellisen 12 kuukauden kustannuksen kaikkien hintavaiheiden yli, jotta
                            tarjoushinta ei nosta sopimusta vertailussa ansaitsematta korkeammalle. Merkitsemme tällaiset
                            sopimukset selkeästi (esimerkiksi <span class="font-medium">"Hinta nousee"</span> -merkinnällä)
                            ja kerromme hinnan muutoksesta sopimuksen tiedoissa.
                        </p>
                    </div>

                    <div class="rounded-xl border border-slate-200 bg-slate-50 p-5">
                        <h3 class="font-bold text-slate-900 mb-2">Pörssisähkön arviot (· arvio)</h3>
                        <p>
                            Pörssisopimuksilla todellinen hinta vaihtelee tunneittain, joten vuosikustannus on aina arvio.
                            Nykyinen arvio käyttää ensisijaisesti seuraavan 12 kuukauden Suomen tukkumarkkinan ennakkohintoja eli sähköfutuureja kuukausi kerrallaan. Viimeisen 365 päivän toteutuneista hinnoista säilytetään päivä- ja yöhintojen ero. Sen jälkeen lisätään sopimuksen tarkka marginaali ja perusmaksu; hinnat sisältävät arvonlisäveron.
                        </p>
                        <p class="mt-3">
                            Jos koko futuurijaksoa ei ole saatavilla tai tiedot ovat vanhentuneet, käytämme erikseen merkittynä varamenetelmänä edeltävän 12 kuukauden toteutunutta pörssihintaa
                            @if($spotAvg !== null)
                                (tällä hetkellä <span class="font-semibold text-slate-900 tabular-nums">{{ number_format($spotAvg, 2, ',', ' ') }} c/kWh</span>, sis. alv)
                            @endif.
                            Toteutunut hinta voi kummassakin tapauksessa olla arviota korkeampi tai matalampi.
                        </p>
                    </div>

                    <div class="rounded-xl border border-slate-200 bg-slate-50 p-5">
                        <h3 class="font-bold text-slate-900 mb-2">Kuukausi- ja kvartaalisähkön arviot (· arvio)</h3>
                        <p class="mb-3">
                            Osa sopimuksista lukitsee energian hinnan kuukaudeksi tai vuosineljännekseksi kerrallaan
                            ja päivittää sen sitten markkinahinnan mukaan. Voimassa oleva hinta on siis aina yhden
                            jakson hinta, eikä koko vuoden hinta: kesällä sähkö on halpaa ja talvella kallista.
                        </p>
                        <p class="mb-3">
                            Jos tällaisen sopimuksen nykyinen hinta kerrottaisiin suoraan kahdellatoista, sopimus
                            näyttäisi kesällä liian halvalta ja talvella liian kalliilta. Siksi laskemme
                            vuosikustannuksen näin: nykyinen jakso lasketaan sen omalla, jo tiedossa olevalla
                            hinnalla, ja loput kuukaudet sähkön futuurimarkkinan hinnalla kullekin kuukaudelle
                            erikseen. Sähköyhtiön oma kate pidetään tässä ennallaan.
                        </p>
                        <p>
                            Emme siis ennusta sähkön hintaa itse, vaan käytämme markkinan omaa hintaa tuleville
                            kuukausille. Lopputulos on arvio. Näytämme sopimuskortilla sekä
                            <span class="font-medium">voimassa olevan hinnan</span> että
                            <span class="font-medium">12 kuukauden arvion</span>, jotta molemmat ovat näkyvissä.
                            Toteutunut hinta voi olla arviota korkeampi tai matalampi, koska sopimuksen hinta
                            seuraa markkinaa.
                        </p>
                    </div>

                    <div class="rounded-xl border border-slate-200 bg-slate-50 p-5">
                        <h3 class="font-bold text-slate-900 mb-2">Muutettavan toistaiseksi voimassa olevan hinnan arvio (· arvio)</h3>
                        <p class="mb-3">
                            Toistaiseksi voimassa olevan sopimuksen nykyinen energianhinta on myyjän julkaisema hinta,
                            mutta myyjä voi muuttaa sitä ilmoittamalla muutoksesta etukäteen. Tulevia hintoja tai
                            muutosaikataulua ei tiedetä, joten nykyistä hintaa ei käsitellä koko vuoden hintalupauksena.
                        </p>
                        <p>
                            Nykyinen kalenterikuukausi lasketaan julkaistulla hinnalla. Myöhemmät kuukaudet arvioidaan
                            ensisijaisesti tukkumarkkinan ennakkohintojen eli sähköfutuurien avulla. Jos niitä ei voi
                            käyttää, arvio perustuu pörssisähkön usean vuoden kausivaihteluun tai viimeisenä vaihtoehtona
                            nykyisen hinnan jatkumiseen. Näytämme nykyisen hinnan erillään 12 kuukauden keskihinta-arviosta.
                            Toteutunut hinta voi olla korkeampi tai matalampi, eikä arvio ole hintalupaus.
                        </p>
                    </div>

                    <div class="rounded-xl border border-slate-200 bg-slate-50 p-5">
                        <h3 class="font-bold text-slate-900 mb-2">"Säästö €/v" -merkinnät</h3>
                        <p>
                            Kun sopimuksessa on tarjous, näytämme arvioidun ensimmäisen vuoden säästön. Säästö tarkoittaa
                            tarjouksen tuomaa alennusta verrattuna saman sopimuksen normaalihintaan — ei vertailua muiden
                            yhtiöiden hintoihin tai markkinakeskiarvoon.
                        </p>
                    </div>

                    <div class="rounded-xl border border-slate-200 bg-slate-50 p-5">
                        <h3 class="font-bold text-slate-900 mb-2">Pörssihintojen ja ennusteiden lähteet</h3>
                        <p class="mb-3">
                            Toteutuneet pörssisähkön hinnat ovat peräisin ENTSO-E:n julkisesta datasta. Mahdolliset
                            tuntikohtaiset hintaennusteet ovat kolmannen osapuolen ennusteita avoimesta lähteestä
                            <a href="https://github.com/vividfog/nordpool-predict-fi" rel="nofollow noopener" target="_blank" class="text-coral-600 font-medium hover:text-coral-700 underline underline-offset-2">vividfog/nordpool-predict-fi</a>,
                            ja ne erotetaan sivustolla selkeästi toteutuneista hinnoista.
                        </p>
                        <p>
                            Tulevien kuukausien markkinahinnat, joita käytämme kuukausi- ja kvartaalisähkön sekä
                            muutettavien toistaiseksi voimassa olevien hintojen arvioissa, ovat Suomen hinta-alueen
                            julkisia futuurien päätöshintoja
                            <a href="https://www.eex.com/en/market-data" rel="nofollow noopener" target="_blank" class="text-coral-600 font-medium hover:text-coral-700 underline underline-offset-2">EEX-sähköpörssistä</a>.
                            Ne päivittyvät jokaisena pörssipäivänä.
                        </p>
                    </div>
                </div>

                <p class="text-sm text-slate-500 mt-5">
                    Laskelmat ovat suuntaa antavia arvioita oman ilmoittamasi kulutuksen perusteella. Ne eivät ole
                    tarjous eivätkä takaa lopullista hintaa — tarkista aina sopimusehdot sähköyhtiöltä ennen
                    sopimuksen tekemistä.
                </p>
            </section>

            {{-- Yhteystiedot --}}
            <section id="yhteys" class="scroll-mt-24">
                <h2 class="text-2xl font-extrabold text-slate-900 tracking-tight mb-4">Yhteystiedot</h2>
                <p>
                    Kysymykset, palaute ja korjauspyynnöt:
                    <x-obfuscated-email
                        :email="\App\Livewire\AboutPage::CONTACT_EMAIL"
                        label="Näytä sähköpostiosoite"
                        link-class="text-coral-600 font-semibold hover:text-coral-700 underline underline-offset-2"
                    />.
                    Jos huomaat virheen jonkin sopimuksen tiedoissa tai hinnassa, otathan yhteyttä — korjaamme tiedot mielellämme.
                </p>
            </section>

        </div>
    </div>
</div>
