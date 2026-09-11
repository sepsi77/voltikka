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
                <p class="mb-4">
                    Sähkösopimuksissa yhdistyy yhä useammin määräaikaisia tarjoushintoja, muuttuvia hintoja ja
                    kulutuksen ajoituksen vaikutuksia. Siksi tulevan vuoden tarkkaa hintaa ei aina voi tietää.
                    Vertailemme sopimuksia samalla valitsemallasi vuosikulutuksella (kWh) 12 kuukauden ajalta
                    alla kerrotuilla oletuksilla. Sama kulutus ei tarkoita, että jokaisen sopimuksen hinta olisi yhtä varma.
                    Koko vuodeksi sovituista kiinteistä hinnoista kustannuksen voi laskea valitulle kulutukselle.
                </p>
                <p class="mb-6">
                    <strong class="font-semibold text-slate-900">Arvio ei ole lupaus tulevasta sähkölaskusta.</strong>
                    Todellinen kulutuksesi, sen ajoitus ja myyjän tulevat hinnat voivat poiketa oletuksista.
                    Pieni ero vuosiarvioissa ei yksin kerro, mikä sopimus tulee lopulta halvimmaksi.
                </p>

                <div class="space-y-5">
                    <div class="rounded-xl border border-slate-200 bg-slate-50 p-5">
                        <h3 class="font-bold text-slate-900 mb-2">Lähde ja päivitys</h3>
                        <p>
                            Haemme sopimushinnat päivittäin sähköyhtiöiden julkisesti saatavilla olevista hinnastoista.
                            Toteutuneita pörssihintoja haetaan tunneittain.
                            Jos tietojen haku ei onnistu, sivulla voi olla vanhempia tietoja.
                        </p>
                    </div>

                    <div class="rounded-xl border border-slate-200 bg-slate-50 p-5">
                        <h3 class="font-bold text-slate-900 mb-2">Vuosikustannuksen kaava</h3>
                        <p class="mb-3">
                            <span class="font-semibold text-slate-900">Vuosikustannus (€) = energiahinta (snt/kWh) × vuosikulutus (kWh) / 100 + perusmaksu (€/kk) × 12</span>.
                            Tämä peruskaava koskee koko vuoden samana pysyvää hintaa. Eri jaksojen hinnat, tarjoukset ja kertamaksut lasketaan erikseen.
                        </p>
                        <ul class="list-disc pl-5 space-y-1.5">
                            <li>Kotitalouksille tarkoitetut hinnat sisältävät arvonlisäveron (alv 25,5 %), myös kun sama sopimus on tarjolla yrityksille. Vain yrityksille tarkoitetut sopimukset näytetään ilman arvonlisäveroa. Jos kohderyhmää ei ole ilmoitettu, näytämme hinnat veroineen. Lisäämme tai poistamme veron tarvittaessa myyjän ilmoittamasta hinnasta. Jos verosta ei ole tietoa, oletamme kotitaloushinnan sisältävän veron ja vain yrityksille tarkoitetun hinnan olevan veroton.</li>
                            <li>Sähkön <span class="font-medium">siirtomaksu ei sisälly</span> — se maksetaan paikalliselle
                                verkkoyhtiölle erikseen eikä riipu sähkösopimuksesta.</li>
                            <li>Oletuksena vuosikulutus jaetaan tasan 12 kalenterikuukaudelle sopimuksen hintatyypistä riippumatta. Emme tiedä, milloin juuri sinä käytät sähköä. Tarkemmat lämmitys- ja jäähdytystiedot voivat muuttaa kulutuksen jakautumista kuukausille.</li>
                            <li>Päivä- ja yösähkön erikseen hinnoittelevassa aikasähkössä perusoletus on 85 % kulutuksesta päivällä ja 15 % yöllä. Tarkemmat kulutustiedot voivat muuttaa tätä jakoa. Tämä ei ole kaikkien asiakkaiden tai pörssisopimusten kulutusoletus.</li>
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
                            Tarkistus perustuu julkaistuihin hintoihin ja kuvauksiin, eikä se takaa kaikkien virheiden löytymistä.
                            Otamme tiedossa olevat hinnankorotukset huomioon, jotta pelkkä alun tarjoushinta ei ratkaise vertailua.
                            Sopimuksen tiedoissa kerromme havaitusta muutoksesta, esimerkiksi
                            <span class="font-medium">"Hinta nousee"</span> -merkinnällä.
                        </p>
                        <p class="mt-3">
                            Jos tavallisen sopimuksen myöhempää hintaa ei ole ilmoitettu, jatkamme arviossa viimeisimmällä
                            kyseiseen aikaan soveltuvalla hinnalla tai myyjän ilmoittamalla normaalihinnalla.
                            Myyjän ilmoittama myöhempi hinta menee aina oletuksen edelle.
                            Emme jätä puuttuvan hinnan aikaa maksuttomaksi emmekä laske sille ylimääräistä tarjoussäästöä.
                            Emme myöskään keksi tuntematonta hinnankorotusta. Kerromme arvion oletuksen.
                            Jos hinnoittelutapaa ei voida tunnistaa tai tiedot ovat ristiriidassa, emme näytä vertailuhintaa.
                        </p>
                    </div>

                    <div class="rounded-xl border border-slate-200 bg-slate-50 p-5">
                        <h3 class="font-bold text-slate-900 mb-2">Pörssisähkön arviot (· arvio)</h3>
                        <p>
                            Pörssisähkön hinta vaihtelee, joten vuosikustannus on aina arvio.
                            Käytämme ensisijaisesti seuraavan 12 kuukauden sähköfutuureja kuukausi kerrallaan.
                            Futuurit ovat tukkumarkkinoilla nyt sovittuja hintoja myöhemmin toimitettavalle sähkölle.
                            Kun viimeisen vuoden hintatietoja on riittävästi, käytämme toteutuneita päivä- ja yöhintojen eroja
                            myös arviossa. Lisäämme sopimuksen marginaalin eli myyjän oman hintalisän sekä muut maksut
                            niille jaksoille, joilla ne ovat voimassa.
                        </p>
                        <p class="mt-3">
                            Jos futuurit kattavat koko vertailujakson mutta aiempia päivä- ja yöhintoja ei ole riittävästi,
                            käytämme päivälle ja yölle samaa markkinahinta-arviota. Tällöin arvio on tavallista epävarmempi.
                            Tämä on oletus hinnoista, ei siitä, että käyttäisit sähköä tasaisesti kaikkina tunteina.
                        </p>
                        <p class="mt-3">
                            Jos koko futuurijaksoa ei ole saatavilla tai tiedot ovat vanhentuneet, käytämme varalla
                            edeltävän 12 kuukauden toteutunutta pörssihintaa.
                            @if($spotAvg !== null)
                                Tämä aiempi keskihinta on nyt <span class="font-semibold text-slate-900 tabular-nums">{{ number_format($spotAvg, 2, ',', ' ') }} c/kWh</span> (sis. alv).
                            @endif
                            Kerromme, kun arvio perustuu aiempiin hintoihin. Toteutuva hinta voi kummallakin menetelmällä
                            olla arviota korkeampi tai matalampi.
                        </p>
                    </div>

                    <div class="rounded-xl border border-slate-200 bg-slate-50 p-5">
                        <h3 class="font-bold text-slate-900 mb-2">Kuukausi- ja kvartaalisähkön arviot (· arvio)</h3>
                        <p class="mb-3">
                            Osa sopimuksista lukitsee energian hinnan kuukaudeksi tai vuosineljännekseksi kerrallaan
                            ja päivittää sen sitten markkinahinnan mukaan. Voimassa oleva hinta on siis aina yhden
                            jakson hinta, eikä koko vuoden hinta. Sähkön hinta voi vaihdella vuodenajan mukaan.
                        </p>
                        <p class="mb-3">
                            Laskemme nykyisen jakson ja muut tiedossa olevat jaksot myyjän ilmoittamilla hinnoilla.
                            Tulevien jaksojen arviossa lähdemme nykyisestä sopimushinnasta. Vertaamme tulevien kuukausien
                            tukkuhintoja siihen markkinatilanteeseen, jossa nykyinen hintajakso hinnoiteltiin.
                            Oletamme sopimushinnan muuttuvan suunnilleen saman verran kuin tukkuhinta.
                            Myyjä voi kuitenkin hinnoitella sähkön toisin.
                            Jos futuuritietoja ei voi käyttää, arvioimme muutosta aiempien vuosien pörssihintojen
                            vuodenaikavaihtelun avulla. Jos sekään ei onnistu, oletamme nykyisen hinnan jatkuvan.
                        </p>
                        <p>
                            Näytämme sopimuskortilla erikseen <span class="font-medium">voimassa olevan hinnan</span> ja
                            <span class="font-medium">12 kuukauden arvion</span>. Markkinahinnat auttavat arvioimaan muutosta,
                            mutta ne eivät kerro varmasti myyjän tulevia sopimushintoja.
                        </p>
                    </div>

                    <div class="rounded-xl border border-slate-200 bg-slate-50 p-5">
                        <h3 class="font-bold text-slate-900 mb-2">Toistaiseksi voimassa olevat sopimukset</h3>
                        <p class="mb-3">
                            Toistaiseksi voimassa olevan sopimuksen nykyinen energianhinta on myyjän julkaisema hinta,
                            mutta myyjä voi muuttaa sitä ilmoittamalla muutoksesta etukäteen. Tulevia hintoja tai
                            muutosaikataulua ei tiedetä, joten nykyistä hintaa ei käsitellä koko vuoden hintalupauksena.
                        </p>
                        <p>
                            Kun sopimukselle näytetään tällainen markkinamuutoksiin perustuva arvio, nykyinen kalenterikuukausi
                            lasketaan julkaistulla hinnalla. Myöhempien kuukausien arviossa muutamme nykyistä sopimushintaa
                            futuurien osoittaman markkinamuutoksen verran. Vertailukohtana on markkinahinta nykyisen
                            sopimushinnan alkaessa. Jos futuureja ei voi käyttää, arvioimme muutosta aiempien vuosien
                            vuodenaikavaihtelun avulla tai viimeisenä vaihtoehtona oletamme nykyisen hinnan jatkuvan.
                            Näytämme nykyisen hinnan erillään 12 kuukauden keskihinta-arviosta.
                            Kuukausittainen laskenta ei tarkoita, että tietäisimme myyjän tulevat hinnanmuutospäivät.
                        </p>
                    </div>

                    <div class="rounded-xl border border-slate-200 bg-slate-50 p-5">
                        <h3 class="font-bold text-slate-900 mb-2">Lyhyet sopimukset, kulutusvaikutus ja paketit</h3>
                        <p class="mb-3">
                            <strong class="font-semibold text-slate-900">Lyhyet määräaikaiset sopimukset.</strong>
                            Lyhyen sopimuksen vuosivertailu voi perustua vain sen omaan sopimusaikaan.
                            Tällöin laskemme ensin kustannuksen tältä sopimusajalta.
                            Sopimusajan tuntemattomat hinnat arvioidaan erikseen. Muunnamme summan vuosivertailuun
                            kertomalla sen luvulla 12 / sopimuskuukaudet. Esimerkki: jos kuuden kuukauden kustannus on
                            300 €, vuosivertailun luku on 600 €. Tämä on laskuesimerkki, ei tarjous jatkosopimuksesta.
                            Emme tiedä, millaisen sopimuksen saat määräajan jälkeen.
                        </p>
                        <p class="mb-3">
                            <strong class="font-semibold text-slate-900">Kulutusvaikutussopimukset eli hybridit.</strong>
                            Vertailuhinta sisältää eri jaksojen perushinnat ja maksut, mutta ei kulutusvaikutusta.
                            Tuntemattomat perushinnat arvioidaan. Kulutusvaikutus voi nostaa tai laskea omaa hintaasi
                            sen mukaan, milloin käytät sähköä. Sen pois jättäminen ei tarkoita, että vaikutus olisi sinulle nolla.
                        </p>
                        <p>
                            <strong class="font-semibold text-slate-900">Sähköpaketit.</strong>
                            Paketin kuukausimaksuun kuuluu tietty määrä sähköä kalenterikuukaudessa.
                            Käyttämätön osuus ei siirry seuraavaan kuukauteen. Rajan ylittävästä kulutuksesta maksetaan ylityshinta.
                            Siksi oman kotisi kulutuksen jakautuminen kuukausille vaikuttaa paketin kustannukseen.
                            Jos sopimus on voimassa vain osan kuukaudesta, pienennämme sekä maksua että kulutusrajaa
                            samassa suhteessa päivien määrään. Pakettiin sisältyvä sähkö ei ole tarjoussäästöä.
                        </p>
                    </div>

                    <div class="rounded-xl border border-slate-200 bg-slate-50 p-5">
                        <h3 class="font-bold text-slate-900 mb-2">"Säästö €/v" -merkinnät</h3>
                        <p>
                            Tarjoussäästö tarkoittaa alennusta saman sopimuksen normaalihintaan verrattuna.
                            Yleensä näytämme säästön 12 kuukauden vertailuajalta. Alle vuoden määräaikaisessa sopimuksessa
                            säästö koskee vain todellista sopimusaikaa, eikä sitä kerrota vuositasolle.
                            Kyse ei ole säästöstä omaan vanhaan sopimukseesi, muiden yhtiöiden hintoihin tai markkinakeskiarvoon verrattuna.
                        </p>
                    </div>

                    <div class="rounded-xl border border-slate-200 bg-slate-50 p-5">
                        <h3 class="font-bold text-slate-900 mb-2">Pörssihintojen ja ennusteiden lähteet</h3>
                        <p class="mb-3">
                            Toteutuneet pörssisähkön hinnat ovat peräisin ENTSO-E:n julkisesta datasta. Mahdolliset
                            tuntikohtaiset hintaennusteet ovat kolmannen osapuolen ennusteita avoimesta lähteestä
                            <a href="https://github.com/vividfog/nordpool-predict-fi" rel="nofollow noopener" target="_blank" class="text-coral-600 font-medium hover:text-coral-700 underline underline-offset-2">vividfog/nordpool-predict-fi</a>,
                            ja ne erotetaan sivustolla toteutuneista hinnoista. Nämä lyhyen aikavälin tuntiennusteet
                            ovat eri asia kuin sopimusvertailun vuosiarvio.
                        </p>
                        <p>
                            Pörssisähkön vuosiarvioissa sekä kuukausi- ja kvartaalisähkön ja muutettavien
                            toistaiseksi voimassa olevien hintojen arvioissa käytetyt markkinahinnat ovat Suomen hinta-alueen
                            julkisia futuurien päätöshintoja
                            <a href="https://www.eex.com/en/market-data" rel="nofollow noopener" target="_blank" class="text-coral-600 font-medium hover:text-coral-700 underline underline-offset-2">EEX-sähköpörssistä</a>.
                            Päätöshintoja julkaistaan pörssipäivinä, ja haemme niitä päivittäin.
                        </p>
                    </div>
                </div>

                <p class="text-sm text-slate-500 mt-5">
                    Tarkista ennen sopimuksen tekemistä myyjältä hinta, sopimuksen kesto ja ehdot hinnan muuttamiselle.
                    Kun vertailuhinnan vieressä on Arvio-painike, avaa siitä juuri sen laskelman oletukset.
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
