<div>
    {{-- Hero --}}
    <section class="relative -mx-4 overflow-hidden bg-slate-950 sm:-mx-6 lg:-mx-8">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 relative">
            <div class="py-12 lg:py-16">
                <div class="max-w-3xl mx-auto text-center">
                    <div class="inline-flex items-center gap-2 rounded-full border border-coral-500/20 bg-coral-500/10 px-4 py-2 text-sm font-medium text-coral-300 mb-6">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/>
                        </svg>
                        Käyttöehdot
                    </div>
                    <h1 class="text-4xl font-extrabold text-white tracking-tight leading-tight md:text-5xl xl:text-6xl mb-6">
                        Käyttöehdot
                    </h1>
                    <p class="text-slate-300 text-lg md:text-xl max-w-2xl mx-auto">
                        Näitä käyttöehtoja sovelletaan Voltikka.fi-sivuston käyttöön.
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
                <li class="font-medium text-slate-900" aria-current="page">Käyttöehdot</li>
            </ol>
        </nav>

        <p class="mb-8 text-slate-700 leading-relaxed">
            Käyttämällä sivustoa hyväksyt nämä käyttöehdot. Jos et hyväksy ehtoja, älä käytä sivustoa.
        </p>

        {{-- In-page TOC --}}
        <nav class="mb-12 border-l-2 border-slate-200 pl-5" aria-label="Sivun sisältö">
            <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-slate-500 mb-3">Tällä sivulla</p>
            <ol class="space-y-1.5 text-[15px] text-slate-700">
                <li><a href="#tarkoitus" class="hover:text-coral-600 hover:underline underline-offset-4 decoration-coral-300">Palvelun tarkoitus</a></li>
                <li><a href="#tiedot" class="hover:text-coral-600 hover:underline underline-offset-4 decoration-coral-300">Tiedot ja laskelmat</a></li>
                <li><a href="#vastuu" class="hover:text-coral-600 hover:underline underline-offset-4 decoration-coral-300">Käyttäjän vastuu</a></li>
                <li><a href="#ulkopuoliset" class="hover:text-coral-600 hover:underline underline-offset-4 decoration-coral-300">Ulkopuoliset sivustot</a></li>
                <li><a href="#virheet" class="hover:text-coral-600 hover:underline underline-offset-4 decoration-coral-300">Virheet ja puutteet</a></li>
                <li><a href="#saatavuus" class="hover:text-coral-600 hover:underline underline-offset-4 decoration-coral-300">Palvelun saatavuus ja muutokset</a></li>
                <li><a href="#vastuunrajoitus" class="hover:text-coral-600 hover:underline underline-offset-4 decoration-coral-300">Vastuunrajoitus</a></li>
                <li><a href="#immateriaalioikeudet" class="hover:text-coral-600 hover:underline underline-offset-4 decoration-coral-300">Immateriaalioikeudet</a></li>
                <li><a href="#laki" class="hover:text-coral-600 hover:underline underline-offset-4 decoration-coral-300">Sovellettava laki</a></li>
                <li><a href="#yhteys" class="hover:text-coral-600 hover:underline underline-offset-4 decoration-coral-300">Yhteystiedot</a></li>
            </ol>
        </nav>

        <div class="space-y-12 text-slate-700 leading-relaxed">

            {{-- Palvelun tarkoitus --}}
            <section id="tarkoitus" class="scroll-mt-24">
                <h2 class="text-2xl font-extrabold text-slate-900 tracking-tight mb-4">Palvelun tarkoitus</h2>
                <p class="mb-4">
                    Voltikka on sähkösopimusten vertailupalvelu. Palvelun tarkoituksena on auttaa käyttäjiä vertailemaan
                    sähkösopimuksia, arvioituja kustannuksia, hintarakenteita ja sopimusten ominaisuuksia.
                </p>
                <p>
                    Voltikka ei ole sähköyhtiö, sähkönmyyjä tai sähkösopimuksen osapuoli. Sähkösopimus tehdään aina
                    käyttäjän ja valitun sähköyhtiön välillä.
                </p>
            </section>

            {{-- Tiedot ja laskelmat --}}
            <section id="tiedot" class="scroll-mt-24">
                <h2 class="text-2xl font-extrabold text-slate-900 tracking-tight mb-4">Tiedot ja laskelmat</h2>
                <p class="mb-4">
                    Voltikka pyrkii pitämään sivustolla esitetyt tiedot mahdollisimman ajantasaisina ja oikeina.
                    Sähkösopimusten hinnat, kampanjat, ehdot, saatavuus ja muut tiedot voivat kuitenkin muuttua nopeasti.
                </p>
                <p class="mb-4">
                    Sivustolla esitetyt hinnat, vuosikustannukset ja muut laskelmat ovat suuntaa antavia arvioita. Ne
                    perustuvat sivustolla käytössä oleviin tietoihin, käyttäjän valitsemiin oletuksiin ja
                    laskentamalleihin. Lopullinen hinta, sopimusehdot ja saatavuus voivat poiketa Voltikassa näytetyistä
                    tiedoista.
                </p>
                <p>
                    Voltikka ei anna sitovia tarjouksia sähkösopimuksista. Ennen sopimuksen tekemistä käyttäjän tulee aina
                    tarkistaa lopullinen hinta, sopimuksen ehdot, kesto, irtisanomisehdot, kampanjaehdot ja muut olennaiset
                    tiedot sähköyhtiön omalta sivustolta tai sähköyhtiöltä suoraan.
                </p>
            </section>

            {{-- Käyttäjän vastuu --}}
            <section id="vastuu" class="scroll-mt-24">
                <h2 class="text-2xl font-extrabold text-slate-900 tracking-tight mb-4">Käyttäjän vastuu</h2>
                <p class="mb-4">
                    Käyttäjä vastaa itse siitä, minkä sähkösopimuksen hän valitsee ja millä perusteilla päätös tehdään.
                </p>
                <p>
                    Voltikka tarjoaa vertailutietoa päätöksenteon tueksi, mutta ei anna henkilökohtaista neuvontaa,
                    taloudellista neuvontaa tai suositusta yksittäisen käyttäjän tilanteeseen. Käyttäjän tulee arvioida
                    itse, sopiiko tietty sähkösopimus hänen kulutukseensa, asumistilanteeseensa, riskinsietoonsa ja muihin
                    tarpeisiinsa.
                </p>
            </section>

            {{-- Ulkopuoliset sivustot --}}
            <section id="ulkopuoliset" class="scroll-mt-24">
                <h2 class="text-2xl font-extrabold text-slate-900 tracking-tight mb-4">Ulkopuoliset sivustot</h2>
                <p class="mb-4">
                    Voltikka voi sisältää linkkejä sähköyhtiöiden tai muiden kolmansien osapuolten sivustoille.
                </p>
                <p>
                    Voltikka ei vastaa ulkopuolisten sivustojen sisällöstä, hinnoista, ehdoista, saatavuudesta,
                    tietosuojakäytännöistä tai muista palveluista. Kun käyttäjä siirtyy ulkopuoliselle sivustolle, kyseisen
                    sivuston omat ehdot ja käytännöt tulevat sovellettaviksi.
                </p>
            </section>

            {{-- Virheet ja puutteet --}}
            <section id="virheet" class="scroll-mt-24">
                <h2 class="text-2xl font-extrabold text-slate-900 tracking-tight mb-4">Virheet ja puutteet</h2>
                <p class="mb-4">
                    Voltikka pyrkii korjaamaan havaitut virheet mahdollisimman nopeasti. Jos huomaat sivustolla
                    virheellisen hinnan, vanhentuneen sopimustiedon tai muun puutteen, voit ilmoittaa siitä meille.
                </p>
                <p>
                    Virheilmoitukset ja korjauspyynnöt voi lähettää osoitteeseen:
                    <x-obfuscated-email
                        :email="\App\Livewire\AboutPage::CONTACT_EMAIL"
                        label="Näytä sähköpostiosoite"
                        link-class="text-coral-600 font-semibold hover:text-coral-700 underline underline-offset-2"
                    />
                </p>
            </section>

            {{-- Palvelun saatavuus ja muutokset --}}
            <section id="saatavuus" class="scroll-mt-24">
                <h2 class="text-2xl font-extrabold text-slate-900 tracking-tight mb-4">Palvelun saatavuus ja muutokset</h2>
                <p class="mb-4">
                    Voltikka pyrkii pitämään palvelun käytettävissä, mutta ei takaa sivuston keskeytyksetöntä tai
                    virheetöntä toimintaa.
                </p>
                <p>
                    Sivustoa, sen sisältöä, laskentatapoja, ominaisuuksia ja käyttöehtoja voidaan muuttaa milloin tahansa.
                    Muutokset tulevat voimaan, kun ne julkaistaan sivustolla.
                </p>
            </section>

            {{-- Vastuunrajoitus --}}
            <section id="vastuunrajoitus" class="scroll-mt-24">
                <h2 class="text-2xl font-extrabold text-slate-900 tracking-tight mb-4">Vastuunrajoitus</h2>
                <p class="mb-4">
                    Voltikka ei vastaa vahingoista, jotka aiheutuvat sivustolla esitettyjen tietojen käyttämisestä,
                    tietojen virheellisyydestä, tietojen vanhentumisesta, palvelun keskeytymisestä tai ulkopuolisten
                    sivustojen toiminnasta.
                </p>
                <p>
                    Tämä vastuunrajoitus ei rajoita kuluttajalle lain mukaan kuuluvia oikeuksia eikä muuta vastuuta, jota
                    ei voida sovellettavan lain mukaan rajoittaa.
                </p>
            </section>

            {{-- Immateriaalioikeudet --}}
            <section id="immateriaalioikeudet" class="scroll-mt-24">
                <h2 class="text-2xl font-extrabold text-slate-900 tracking-tight mb-4">Immateriaalioikeudet</h2>
                <p class="mb-4">
                    Voltikka-sivuston sisältö, rakenne, laskentatavat, tekstit, ulkoasu ja muu aineisto kuuluvat Voltikalle
                    tai sen oikeudenhaltijoille, ellei toisin mainita.
                </p>
                <p>
                    Sivustoa saa käyttää henkilökohtaiseen ja tavanomaiseen tiedonhakuun. Sivuston sisällön laajamittainen
                    kopiointi, automatisoitu kerääminen, uudelleenjulkaisu tai kaupallinen hyödyntäminen ilman lupaa on
                    kielletty.
                </p>
            </section>

            {{-- Sovellettava laki --}}
            <section id="laki" class="scroll-mt-24">
                <h2 class="text-2xl font-extrabold text-slate-900 tracking-tight mb-4">Sovellettava laki</h2>
                <p>
                    Näihin käyttöehtoihin sovelletaan Suomen lakia, ellei pakottavasta lainsäädännöstä muuta johdu.
                </p>
            </section>

            {{-- Yhteystiedot --}}
            <section id="yhteys" class="scroll-mt-24">
                <h2 class="text-2xl font-extrabold text-slate-900 tracking-tight mb-4">Yhteystiedot</h2>
                <p>
                    Käyttöehtoihin liittyvissä kysymyksissä voit ottaa yhteyttä:
                    <x-obfuscated-email
                        :email="\App\Livewire\AboutPage::CONTACT_EMAIL"
                        label="Näytä sähköpostiosoite"
                        link-class="text-coral-600 font-semibold hover:text-coral-700 underline underline-offset-2"
                    />
                </p>
            </section>

            {{-- Sivun päivitykset --}}
            <section class="border-t border-slate-200 pt-6">
                <h2 class="text-base font-bold text-slate-900 mb-2">Sivun päivitykset</h2>
                <p class="text-sm text-slate-500">Päivitetty viimeksi: {{ $lastUpdated }}</p>
            </section>

        </div>
    </div>
</div>
