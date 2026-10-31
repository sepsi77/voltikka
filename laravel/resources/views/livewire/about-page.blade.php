<div>
    <x-schema-markup :schemas="[$jsonLdSchema]" />

    {{-- Hero (Paper 49Y-0 "Cabin hero"): the fox at its desk on the night band. --}}
    <x-site.longread-hero variant="tietoa" :tone="\App\Support\Season::heroTone('tietoa-hero')" figure="tietoa-fox-desk" figure-width="540" figure-height="405">
        <p class="vk-lr-kicker">Riippumaton vertailu</p>
        <h1 class="vk-lr-h1">Tietoa Voltikasta</h1>
        <p class="vk-lr-hero-lead">
            Kuka Voltikkaa ylläpitää, miten palvelu rahoitetaan ja miten sähkösopimusten
            vuosikustannukset lasketaan. Avoimesti, ilman provisioita ja mainosrahaa.
        </p>
    </x-site.longread-hero>

    <x-page-action-strip />

    <div class="vk-wrap vk-lr-page">

        <nav class="vk-crumbs" aria-label="Breadcrumb">
            <ol>
                <li><a href="/" class="vk-focus">Etusivu</a></li>
                <li aria-hidden="true">/</li>
                <li aria-current="page">Tietoa</li>
            </ol>
        </nav>

        {{-- Fact numerals (Paper "Fact numerals"): the live facts of the page as big numbers. --}}
        <div class="vk-lr-facts">
            <div class="vk-lr-fact">
                <p class="vk-lr-fact-value">{{ number_format($contractCount, 0, ',', ' ') }}</p>
                <p class="vk-lr-fact-label">sähkösopimusta vertailussa</p>
            </div>
            <div class="vk-lr-fact">
                <p class="vk-lr-fact-value">{{ number_format($companyCount, 0, ',', ' ') }}</p>
                <p class="vk-lr-fact-label">sähköyhtiötä</p>
            </div>
            <div class="vk-lr-fact">
                <p class="vk-lr-fact-value vk-lr-fact-value--fox">0 €</p>
                <p class="vk-lr-fact-label">provisioita ja mainosrahaa</p>
                <svg class="vk-lr-fact-loop" viewBox="0 0 150 88" preserveAspectRatio="none" aria-hidden="true" focusable="false">
                    <path d="M128 14 C 104 2, 40 2, 16 16 C 1 28, 5 66, 38 76 C 74 88, 126 80, 140 60 C 152 42, 146 22, 116 10" fill="none" style="stroke: var(--color-fox)" stroke-width="2.4" stroke-linecap="round" vector-effect="non-scaling-stroke" />
                </svg>
            </div>
            <div class="vk-lr-fact">
                <p class="vk-lr-fact-value"><span class="vk-lr-fact-prefix">n.</span>20 €</p>
                <p class="vk-lr-fact-label">palvelinkulut kuukaudessa, itse maksettu</p>
            </div>
            <div class="vk-lr-fact">
                <p class="vk-lr-fact-value">2026</p>
                <p class="vk-lr-fact-label">harrasteprojekti alkoi</p>
            </div>
        </div>

        <div class="vk-lr-layout">
            <x-site.longread-toc :items="[
                ['mika-on', 'Mikä Voltikka on'],
                ['yllapito', 'Kuka Voltikkaa ylläpitää'],
                ['rahoitus', 'Miten Voltikka rahoitetaan'],
                ['menetelma', 'Näin laskemme kustannukset'],
                ['yhteys', 'Yhteystiedot'],
            ]" />

            <div class="vk-lr-article">

                {{-- Mikä Voltikka on --}}
                <section id="mika-on" class="vk-lr-section">
                    <x-site.section-heading>Mikä Voltikka on</x-site.section-heading>
                    <p class="vk-lr-text">
                        Voltikka on riippumaton sähkösopimusten ja energian vertailupalvelu. Sivustolla voit
                        vertailla sähkösopimuksia oman kulutuksesi mukaan, seurata pörssisähkön hintaa ja
                        hintakehitystä sekä arvioida aurinkopaneelien tuottoa ja lämpöpumpun säästöjä.
                    </p>
                    <p class="vk-lr-text">
                        Vertailussa on tällä hetkellä <strong>{{ number_format($contractCount, 0, ',', ' ') }} sähkösopimusta</strong>
                        {{ number_format($companyCount, 0, ',', ' ') }} sähköyhtiöltä — yksi Suomen
                        kattavimmista sähkösopimusvertailuista. Kaikki sopimukset järjestetään samalla logiikalla,
                        eikä yksittäisiä yhtiöitä nosteta esiin maksua vastaan.
                    </p>
                </section>

                {{-- Kuka ylläpitää --}}
                <section id="yllapito" class="vk-lr-section">
                    <x-site.section-heading>Kuka Voltikkaa ylläpitää</x-site.section-heading>
                    <p class="vk-lr-text">
                        Voltikan on tehnyt <strong>{{ \App\Livewire\AboutPage::MAINTAINER_NAME }}</strong>. Se on
                        harrasteprojekti, joka käynnistyi vuonna 2026 kiinnostuksesta dataan perustuviin
                        verkkopalveluihin, energiamarkkinoihin ja hintadataan.
                    </p>
                    <p class="vk-lr-text">
                        Sivuston ylläpitäjä on {{ \App\Livewire\AboutPage::COMPANY_NAME }}, Seppo Puusan oma yritys.
                        Se ei ole sähköyhtiö eikä sähkön myyjä, eikä Voltikan takana ole sähköyhtiötä tai mainostajaa.
                        Yrityksen tiedot ovat kohdassa <a href="#yhteys" class="vk-text-link vk-focus">yhteystiedot</a>.
                    </p>
                    <p class="vk-lr-text">
                        Kysymyksiin ja korjauspyyntöihin vastataan sähköpostitse. Seppo Puusan löydät myös
                        <a href="{{ \App\Livewire\AboutPage::MAINTAINER_LINKEDIN }}" class="vk-text-link vk-focus" rel="me noopener" target="_blank">LinkedInistä</a>.
                    </p>
                </section>

                {{-- Rahoitus --}}
                <section id="rahoitus" class="vk-lr-section">
                    <x-site.section-heading wave="230">Miten Voltikka rahoitetaan</x-site.section-heading>
                    {{-- Pull quote: repeats the first sentence below, so screen readers skip it. --}}
                    <div class="vk-lr-quote" aria-hidden="true">
                        <span class="vk-lr-quote-mark">“</span>
                        <div class="vk-lr-quote-body">
                            <p class="vk-lr-quote-text vk-lr-quote-text--lg">Suoraan sanottuna: Voltikka ei tienaa rahaa.</p>
                            <x-ink.wave width="300" stroke="3.5" class="max-w-full" />
                        </div>
                    </div>
                    <p class="vk-lr-text">
                        Suoraan sanottuna: Voltikka ei tienaa rahaa. Palvelu on itse rahoitettu harrasteprojekti,
                        ja sen ainoat kulut — noin 20 € kuukaudessa palvelinkuluja — maksaa ylläpitäjä
                        {{ \App\Livewire\AboutPage::COMPANY_NAME }}.
                    </p>
                    <p class="vk-lr-text">
                        Voltikka <strong>ei ota provisiota sähköyhtiöiltä</strong>,
                        ei myy mainostilaa eikä veloita yhtiöitä listauksesta tai sijoituksesta vertailussa. Sivustolla
                        ei myöskään ole sponsoroituja sopimuksia. Tämän takia mikään yhtiö ei voi maksaa päästäkseen
                        vertailussa ylemmäs.
                    </p>
                    <p class="vk-lr-text">
                        Käytännössä tämä tarkoittaa, että vertailun järjestys perustuu vain laskettuihin kustannuksiin
                        ja sopimusten ominaisuuksiin — ei siihen, kuka maksaa eniten.
                    </p>
                </section>

                {{-- Menetelmä (cards link here: /tietoa#menetelma) --}}
                <section id="menetelma" class="vk-lr-section">
                    <x-site.section-heading wave="250">Näin laskemme kustannukset</x-site.section-heading>
                    <p class="vk-lr-text">
                        Sähkösopimuksissa yhdistyy yhä useammin määräaikaisia tarjoushintoja, muuttuvia hintoja ja
                        kulutuksen ajoituksen vaikutuksia. Siksi tulevan vuoden tarkkaa hintaa ei aina voi tietää.
                        Vertailemme sopimuksia samalla valitsemallasi vuosikulutuksella (kWh) 12 kuukauden ajalta
                        alla kerrotuilla oletuksilla. Sama kulutus ei tarkoita, että jokaisen sopimuksen hinta olisi yhtä varma.
                        Koko vuodeksi sovituista kiinteistä hinnoista kustannuksen voi laskea valitulle kulutukselle.
                    </p>
                    <p class="vk-lr-text">
                        <strong>Arvio ei ole lupaus tulevasta sähkölaskusta.</strong>
                        Todellinen kulutuksesi, sen ajoitus ja myyjän tulevat hinnat voivat poiketa oletuksista.
                        Pieni ero vuosiarvioissa ei yksin kerro, mikä sopimus tulee lopulta halvimmaksi.
                    </p>

                    <div class="flex flex-col gap-4">
                        <div class="vk-lr-subsection">
                            <h3 class="vk-lr-h3">Lähde ja päivitys</h3>
                            <p class="vk-lr-text">
                                Haemme sopimushinnat päivittäin sähköyhtiöiden julkisesti saatavilla olevista hinnastoista.
                                Toteutuneita pörssihintoja haetaan tunneittain.
                                Jos tietojen haku ei onnistu, sivulla voi olla vanhempia tietoja.
                            </p>
                        </div>

                        <div class="vk-lr-subsection">
                            <h3 class="vk-lr-h3">Vuosikustannuksen kaava</h3>
                            {{-- The formula drawn in ink; the sentence below says the same in text. --}}
                            <div class="vk-lr-formula" aria-hidden="true">
                                <div class="vk-lr-formula-row">
                                    <span class="vk-lr-formula-head">Vuosikustannus</span>
                                    <span class="vk-lr-formula-unit">(€)</span>
                                    <span>=</span>
                                </div>
                                <div class="vk-lr-formula-row">
                                    <span>energiahinta</span>
                                    <span class="vk-lr-formula-unit">(snt/kWh)</span>
                                    <span class="vk-lr-formula-op">×</span>
                                    <span>vuosikulutus</span>
                                    <span class="vk-lr-formula-unit">(kWh)</span>
                                    <span class="vk-lr-formula-op">/ 100</span>
                                </div>
                                <div class="vk-lr-formula-row">
                                    <span class="vk-lr-formula-op">+</span>
                                    <span>perusmaksu</span>
                                    <span class="vk-lr-formula-unit">(€/kk)</span>
                                    <span class="vk-lr-formula-op">× 12</span>
                                </div>
                            </div>
                            <p class="vk-lr-text">
                                <strong>Vuosikustannus (€) = energiahinta (snt/kWh) × vuosikulutus (kWh) / 100 + perusmaksu (€/kk) × 12</strong>.
                                Tämä peruskaava koskee koko vuoden samana pysyvää hintaa. Eri jaksojen hinnat, tarjoukset ja kertamaksut lasketaan erikseen.
                            </p>
                            <ul class="vk-lr-list vk-lr-text">
                                <li>Kotitalouksille tarkoitetut hinnat sisältävät arvonlisäveron (alv 25,5 %), myös kun sama sopimus on tarjolla yrityksille. Vain yrityksille tarkoitetut sopimukset näytetään ilman arvonlisäveroa. Jos kohderyhmää ei ole ilmoitettu, näytämme hinnat veroineen. Lisäämme tai poistamme veron tarvittaessa myyjän ilmoittamasta hinnasta. Jos verosta ei ole tietoa, oletamme kotitaloushinnan sisältävän veron ja vain yrityksille tarkoitetun hinnan olevan veroton.</li>
                                <li>Sähkön <strong>siirtomaksu ei sisälly</strong> — se maksetaan paikalliselle
                                    verkkoyhtiölle erikseen eikä riipu sähkösopimuksesta.</li>
                                <li>Oletuksena vuosikulutus jaetaan tasan 12 kalenterikuukaudelle sopimuksen hintatyypistä riippumatta. Emme tiedä, milloin juuri sinä käytät sähköä. Tarkemmat lämmitys- ja jäähdytystiedot voivat muuttaa kulutuksen jakautumista kuukausille.</li>
                                <li>Päivä- ja yösähkön erikseen hinnoittelevassa aikasähkössä perusoletus on 85 % kulutuksesta päivällä ja 15 % yöllä. Tarkemmat kulutustiedot voivat muuttaa tätä jakoa. Tämä ei ole kaikkien asiakkaiden tai pörssisopimusten kulutusoletus.</li>
                                <li>Kaikkien sopimusten hinta ei pysy samana koko vuotta. Pörssisähkön, kuukausi- ja
                                    kvartaalisähkön sekä hinnaltaan muutettavien toistaiseksi voimassa olevien sopimusten
                                    kohdalla laskemme kuukaudet erikseen. Katso omat kohtansa alempana.</li>
                            </ul>
                        </div>

                        <div class="vk-lr-subsection">
                            <h3 class="vk-lr-h3">Sopimusten tarkistus ja harhaanjohtavat tarjoukset</h3>
                            <p class="vk-lr-text">
                                Tarkistamme jokaisen sopimuksen hinnoittelun sekä sähköyhtiön ilmoittamista hinnoista
                                että sopimuksen kuvauksesta. Osa yhtiöistä ilmoittaa halvan tarjoushinnan, mutta
                                kertoo vasta kuvaustekstissä, että hinta nousee tietyn jakson jälkeen.
                            </p>
                            <p class="vk-lr-text">
                                Tarkistus perustuu julkaistuihin hintoihin ja kuvauksiin, eikä se takaa kaikkien virheiden löytymistä.
                                Otamme tiedossa olevat hinnankorotukset huomioon, jotta pelkkä alun tarjoushinta ei ratkaise vertailua.
                                Sopimuksen tiedoissa kerromme havaitusta muutoksesta, esimerkiksi
                                <strong>"Hinta nousee"</strong> -merkinnällä.
                            </p>
                            <p class="vk-lr-text">
                                Jos tavallisen sopimuksen myöhempää hintaa ei ole ilmoitettu, jatkamme arviossa viimeisimmällä
                                kyseiseen aikaan soveltuvalla hinnalla tai myyjän ilmoittamalla normaalihinnalla.
                                Myyjän ilmoittama myöhempi hinta menee aina oletuksen edelle.
                                Emme jätä puuttuvan hinnan aikaa maksuttomaksi emmekä laske sille ylimääräistä tarjoussäästöä.
                                Emme myöskään keksi tuntematonta hinnankorotusta. Kerromme arvion oletuksen.
                                Jos hinnoittelutapaa ei voida tunnistaa tai tiedot ovat ristiriidassa, emme näytä vertailuhintaa.
                            </p>
                        </div>

                        <div class="vk-lr-subsection">
                            <div class="vk-lr-glyph-heading">
                                <x-ink.glyph bucket="porssisahko" size="40" />
                                <h3 class="vk-lr-h3">Pörssisähkön arviot (· arvio)</h3>
                            </div>
                            <p class="vk-lr-text">
                                Pörssisähkön hinta vaihtelee, joten vuosikustannus on aina arvio.
                                Käytämme ensisijaisesti seuraavan 12 kuukauden sähköfutuureja kuukausi kerrallaan.
                                Futuurit ovat tukkumarkkinoilla nyt sovittuja hintoja myöhemmin toimitettavalle sähkölle.
                                Kun viimeisen vuoden hintatietoja on riittävästi, käytämme toteutuneita päivä- ja yöhintojen eroja
                                myös arviossa. Lisäämme sopimuksen marginaalin eli myyjän oman hintalisän sekä muut maksut
                                niille jaksoille, joilla ne ovat voimassa.
                            </p>
                            <p class="vk-lr-text">
                                Jos futuurit kattavat koko vertailujakson mutta aiempia päivä- ja yöhintoja ei ole riittävästi,
                                käytämme päivälle ja yölle samaa markkinahinta-arviota. Tällöin arvio on tavallista epävarmempi.
                                Tämä on oletus hinnoista, ei siitä, että käyttäisit sähköä tasaisesti kaikkina tunteina.
                            </p>
                            <p class="vk-lr-text">
                                Jos koko futuurijaksoa ei ole saatavilla tai tiedot ovat vanhentuneet, käytämme varalla
                                edeltävän 12 kuukauden toteutunutta pörssihintaa.
                                @if($spotAvg !== null)
                                    Tämä aiempi keskihinta on nyt <strong class="tabular-nums">{{ number_format($spotAvg, 2, ',', ' ') }} c/kWh</strong> (sis. alv).
                                @endif
                                Kerromme, kun arvio perustuu aiempiin hintoihin. Toteutuva hinta voi kummallakin menetelmällä
                                olla arviota korkeampi tai matalampi.
                            </p>
                        </div>

                        <div class="vk-lr-subsection">
                            <div class="vk-lr-glyph-heading">
                                <x-ink.glyph bucket="paivittyva" size="40" />
                                <h3 class="vk-lr-h3">Kuukausi- ja kvartaalisähkön arviot (· arvio)</h3>
                            </div>
                            <p class="vk-lr-text">
                                Osa sopimuksista lukitsee energian hinnan kuukaudeksi tai vuosineljännekseksi kerrallaan
                                ja päivittää sen sitten markkinahinnan mukaan. Voimassa oleva hinta on siis aina yhden
                                jakson hinta, eikä koko vuoden hinta. Sähkön hinta voi vaihdella vuodenajan mukaan.
                            </p>
                            <p class="vk-lr-text">
                                Laskemme nykyisen jakson ja muut tiedossa olevat jaksot myyjän ilmoittamilla hinnoilla.
                                Tulevien jaksojen arviossa lähdemme nykyisestä sopimushinnasta. Vertaamme tulevien kuukausien
                                tukkuhintoja siihen markkinatilanteeseen, jossa nykyinen hintajakso hinnoiteltiin.
                                Oletamme sopimushinnan muuttuvan suunnilleen saman verran kuin tukkuhinta.
                                Myyjä voi kuitenkin hinnoitella sähkön toisin.
                                Jos omaa vertailukohtaa ei voi käyttää, yhdistämme futuureihin vertailukelpoisista saman yhtiön
                                tai markkinan sopimuksista arvioidun vähittäishinnan lisän.
                                Jos riittäviä futuuri- ja vertailuhintatietoja ei ole, arvioimme muutosta aiempien vuosien pörssihintojen
                                vuodenaikavaihtelun avulla. Jos sekään ei onnistu, oletamme nykyisen hinnan jatkuvan.
                            </p>
                            <p class="vk-lr-text">
                                Näytämme sopimuskortilla erikseen <strong>voimassa olevan hinnan</strong> ja
                                <strong>12 kuukauden arvion</strong>. Markkinahinnat auttavat arvioimaan muutosta,
                                mutta ne eivät kerro varmasti myyjän tulevia sopimushintoja.
                            </p>
                        </div>

                        <div class="vk-lr-subsection">
                            <h3 class="vk-lr-h3">Toistaiseksi voimassa olevat sopimukset</h3>
                            <p class="vk-lr-text">
                                Toistaiseksi voimassa olevan sopimuksen nykyinen energianhinta on myyjän julkaisema hinta,
                                mutta myyjä voi muuttaa sitä ilmoittamalla muutoksesta etukäteen. Tulevia hintoja tai
                                muutosaikataulua ei tiedetä, joten nykyistä hintaa ei käsitellä koko vuoden hintalupauksena.
                            </p>
                            <p class="vk-lr-text">
                                Kun sopimukselle näytetään tällainen markkinamuutoksiin perustuva arvio, nykyinen kalenterikuukausi
                                lasketaan julkaistulla hinnalla. Myöhempien kuukausien arviossa muutamme nykyistä sopimushintaa
                                futuurien osoittaman markkinamuutoksen verran. Vertailukohtana on markkinahinta nykyisen
                                sopimushinnan alkaessa. Jos omaa vertailukohtaa ei voi käyttää, yhdistämme futuureihin
                                vertailukelpoisista saman yhtiön tai markkinan sopimuksista arvioidun vähittäishinnan lisän.
                                Jos riittäviä futuuri- ja vertailuhintatietoja ei ole, arvioimme muutosta aiempien vuosien
                                vuodenaikavaihtelun avulla tai viimeisenä vaihtoehtona oletamme nykyisen hinnan jatkuvan.
                                Näytämme nykyisen hinnan erillään 12 kuukauden keskihinta-arviosta.
                                Kuukausittainen laskenta ei tarkoita, että tietäisimme myyjän tulevat hinnanmuutospäivät.
                            </p>
                        </div>

                        <div class="vk-lr-subsection">
                            <div class="vk-lr-glyph-heading">
                                <x-ink.glyph bucket="kulutusvaikutus" size="40" />
                                <h3 class="vk-lr-h3">Lyhyet sopimukset, kulutusvaikutus ja paketit</h3>
                            </div>
                            <p class="vk-lr-text">
                                <strong>Lyhyet määräaikaiset sopimukset.</strong>
                                Lyhyen sopimuksen vuosivertailu voi perustua vain sen omaan sopimusaikaan.
                                Tällöin laskemme ensin kustannuksen tältä sopimusajalta.
                                Sopimusajan tuntemattomat hinnat arvioidaan erikseen. Muunnamme summan vuosivertailuun
                                kertomalla sen luvulla 12 / sopimuskuukaudet. Esimerkki: jos kuuden kuukauden kustannus on
                                300 €, vuosivertailun luku on 600 €. Tämä on laskuesimerkki, ei tarjous jatkosopimuksesta.
                                Emme tiedä, millaisen sopimuksen saat määräajan jälkeen.
                            </p>
                            <p class="vk-lr-text">
                                <strong>Kulutusvaikutussopimukset eli hybridit.</strong>
                                Vertailuhinta sisältää eri jaksojen perushinnat ja maksut, mutta ei kulutusvaikutusta.
                                Tuntemattomat perushinnat arvioidaan. Kulutusvaikutus voi nostaa tai laskea omaa hintaasi
                                sen mukaan, milloin käytät sähköä. Sen pois jättäminen ei tarkoita, että vaikutus olisi sinulle nolla.
                            </p>
                            <p class="vk-lr-text">
                                <strong>Sähköpaketit.</strong>
                                Paketin kuukausimaksuun kuuluu tietty määrä sähköä kalenterikuukaudessa.
                                Käyttämätön osuus ei siirry seuraavaan kuukauteen. Rajan ylittävästä kulutuksesta maksetaan ylityshinta.
                                Siksi oman kotisi kulutuksen jakautuminen kuukausille vaikuttaa paketin kustannukseen.
                                Jos sopimus on voimassa vain osan kuukaudesta, pienennämme sekä maksua että kulutusrajaa
                                samassa suhteessa päivien määrään. Pakettiin sisältyvä sähkö ei ole tarjoussäästöä.
                            </p>
                        </div>

                        <div class="vk-lr-subsection">
                            <h3 class="vk-lr-h3">"Säästö €/v" -merkinnät</h3>
                            <p class="vk-lr-text">
                                Tarjoussäästö tarkoittaa alennusta saman sopimuksen normaalihintaan verrattuna.
                                Jos normaalihinta voi muuttua, myös vertailu siihen on arvio. Arvioitu säästö ei ole taattu säästö.
                                Yleensä näytämme säästön 12 kuukauden vertailuajalta. Alle vuoden määräaikaisessa sopimuksessa
                                säästö koskee vain todellista sopimusaikaa, eikä sitä kerrota vuositasolle.
                                Kyse ei ole säästöstä omaan vanhaan sopimukseesi, muiden yhtiöiden hintoihin tai markkinakeskiarvoon verrattuna.
                            </p>
                        </div>

                        <div class="vk-lr-subsection">
                            <h3 class="vk-lr-h3">Pörssihintojen ja ennusteiden lähteet</h3>
                            <p class="vk-lr-text">
                                Toteutuneet pörssisähkön hinnat ovat peräisin ENTSO-E:n julkisesta datasta. Mahdolliset
                                tuntikohtaiset hintaennusteet ovat kolmannen osapuolen ennusteita avoimesta lähteestä
                                <a href="https://github.com/vividfog/nordpool-predict-fi" rel="nofollow noopener" target="_blank" class="vk-text-link vk-focus">vividfog/nordpool-predict-fi</a>,
                                ja ne erotetaan sivustolla toteutuneista hinnoista. Nämä lyhyen aikavälin tuntiennusteet
                                ovat eri asia kuin sopimusvertailun vuosiarvio.
                            </p>
                            <p class="vk-lr-text">
                                Pörssisähkön vuosiarvioissa sekä kuukausi- ja kvartaalisähkön ja muutettavien
                                toistaiseksi voimassa olevien hintojen arvioissa käytetyt markkinahinnat ovat Suomen hinta-alueen
                                julkisia futuurien päätöshintoja
                                <a href="https://www.eex.com/en/market-data" rel="nofollow noopener" target="_blank" class="vk-text-link vk-focus">EEX-sähköpörssistä</a>.
                                Päätöshintoja julkaistaan pörssipäivinä, ja haemme niitä päivittäin.
                            </p>
                        </div>
                    </div>

                    <p class="vk-lr-text">
                        Tarkista ennen sopimuksen tekemistä myyjältä hinta, sopimuksen kesto ja ehdot hinnan muuttamiselle.
                        Kun vertailuhinnan vieressä on Arvio-painike, avaa siitä juuri sen laskelman oletukset.
                    </p>
                </section>

                {{-- Yhteystiedot --}}
                <section id="yhteys" class="vk-lr-section">
                    <x-site.section-heading wave="160">Yhteystiedot</x-site.section-heading>
                    <p class="vk-lr-text">Kysymykset, palaute ja korjauspyynnöt:</p>
                    <x-obfuscated-email
                        :email="\App\Livewire\AboutPage::CONTACT_EMAIL"
                        label="Näytä sähköpostiosoite"
                        link-class="vk-lr-email"
                        class="block"
                    />
                    <p class="vk-lr-text">
                        Jos huomaat virheen jonkin sopimuksen tiedoissa tai hinnassa, otathan yhteyttä — korjaamme tiedot mielellämme.
                    </p>
                    <p class="vk-lr-text">Sivuston ylläpitäjä:</p>
                    <x-site.company-details />
                </section>

                {{-- "Löysitkö etsimäsi?" at the end of the page text. --}}
                <x-page-feedback page-type="about" />

            </div>
        </div>
    </div>
</div>
