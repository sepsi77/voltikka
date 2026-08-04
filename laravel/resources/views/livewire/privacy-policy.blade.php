<div>
    {{-- Hero --}}
    <section class="relative -mx-4 overflow-hidden bg-slate-950 sm:-mx-6 lg:-mx-8 mb-8">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 relative">
            <div class="py-12 lg:py-16">
                <div class="max-w-3xl mx-auto text-center">
                    <div class="inline-flex items-center gap-2 rounded-full border border-coral-500/20 bg-coral-500/10 px-4 py-2 text-sm font-medium text-coral-300 mb-6">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/>
                        </svg>
                        Tietosuoja
                    </div>
                    <h1 class="text-4xl font-extrabold text-white tracking-tight leading-tight md:text-5xl xl:text-6xl mb-6">
                        Tietosuoja ja evästeet
                    </h1>
                    <p class="text-slate-300 text-lg md:text-xl max-w-2xl mx-auto">
                        Tällä sivulla kerromme, miten Voltikka käsittelee kävijöiden tietoja ja käyttää evästeitä.
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
                <li class="font-medium text-slate-900" aria-current="page">Tietosuoja ja evästeet</li>
            </ol>
        </nav>

        <p class="mb-8 text-slate-700 leading-relaxed">
            Voltikka on sähkösopimusten vertailupalvelu. Palvelua voi käyttää ilman rekisteröitymistä,
            emmekä pyydä käyttäjiltä nimeä, sähköpostiosoitetta, puhelinnumeroa tai muita yhteystietoja.
        </p>

        {{-- In-page TOC --}}
        <nav class="mb-12 border-l-2 border-slate-200 pl-5" aria-label="Sivun sisältö">
            <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-slate-500 mb-3">Tällä sivulla</p>
            <ol class="space-y-1.5 text-[15px] text-slate-700">
                <li><a href="#tiedot" class="hover:text-coral-600 hover:underline underline-offset-4 decoration-coral-300">Mitä tietoja keräämme</a></li>
                <li><a href="#analytiikka" class="hover:text-coral-600 hover:underline underline-offset-4 decoration-coral-300">Analytiikka</a></li>
                <li><a href="#evasteet" class="hover:text-coral-600 hover:underline underline-offset-4 decoration-coral-300">Evästeet</a></li>
                <li><a href="#kaytto" class="hover:text-coral-600 hover:underline underline-offset-4 decoration-coral-300">Mihin tietoja käytetään</a></li>
                <li><a href="#sailytys" class="hover:text-coral-600 hover:underline underline-offset-4 decoration-coral-300">Tietojen säilytys</a></li>
                <li><a href="#luovuttaminen" class="hover:text-coral-600 hover:underline underline-offset-4 decoration-coral-300">Tietojen luovuttaminen</a></li>
                <li><a href="#oikeudet" class="hover:text-coral-600 hover:underline underline-offset-4 decoration-coral-300">Käyttäjän oikeudet</a></li>
                <li><a href="#yhteys" class="hover:text-coral-600 hover:underline underline-offset-4 decoration-coral-300">Yhteydenotot</a></li>
            </ol>
        </nav>

        <div class="space-y-12 text-slate-700 leading-relaxed">

            {{-- Mitä tietoja keräämme --}}
            <section id="tiedot" class="scroll-mt-24">
                <h2 class="text-2xl font-extrabold text-slate-900 tracking-tight mb-4">Mitä tietoja keräämme?</h2>
                <p class="mb-4">
                    Voltikka ei kerää käyttäjiltä henkilötietoja palvelun normaalia käyttöä varten. Sivustolla ei ole
                    käyttäjätilejä, kirjautumista, yhteydenottolomakkeita tai ostotapahtumia.
                </p>
                <p class="mb-4">
                    Käytämme sivuston käytön mittaamiseen Plausible Analytics -palvelua. Lisäksi tallennamme tiedon siitä,
                    kun kävijä siirtyy sopimussivulta sähköyhtiön tilaussivulle. Mittaus ei vahvista, että tilaussivu avautui
                    tai että kävijä teki sopimuksen.
                </p>
                <p class="mb-4">
                    Tilaussivulle siirtymisestä tallennamme sopimuksen ja yhtiön, sivulla näytetyn vuosihinnan ja kulutuksen,
                    sopimuksen sijoituksen vertailussa, painikkeen sijainnin sekä liikenteen lähteen, median, kampanjan ja
                    ensimmäisen laskeutumissivun polun. Emme tallenna tähän analytiikkatietoon IP-osoitetta, selaimen
                    käyttäjätunnistetta, koko viittaavaa osoitetta, sivun kyselyä, kävijätunnistetta tai istuntotunnistetta.
                </p>
                <p>
                    Sivuston teknisestä toiminnasta voi lisäksi syntyä tavanomaisia palvelin- ja lokitietoja. Näitä voivat
                    olla esimerkiksi IP-osoite, ajankohta, pyydetty sivu, selaimen teknisiä tietoja ja virhetilanteisiin
                    liittyviä tietoja. Näitä tietoja käytetään sivuston toiminnan, tietoturvan, virheiden selvittämisen ja
                    väärinkäytösten estämisen vuoksi.
                </p>
            </section>

            {{-- Analytics --}}
            <section id="analytiikka" class="scroll-mt-24">
                <h2 class="text-2xl font-extrabold text-slate-900 tracking-tight mb-4">Analytiikka</h2>
                <p class="mb-4">
                    Voltikka käyttää evästeetöntä Plausible Analyticsia sivuston yleisen käytön mittaamiseen. Plausible ei
                    luo yksittäisistä kävijöistä profiileja.
                </p>
                <p class="mb-4">
                    Voltikkan oma analytiikka mittaa sopimussivujen tilaussivulinkkien käyttöä. Selain säilyttää liikenteen
                    ensimmäisen lähteen paikallisesti 30 minuutin aktiivisuusjakson ajan. Uusi ulkoinen UTM-kampanja aloittaa
                    uuden jakson. Tieto ei sisällä kävijä- tai istuntotunnistetta. Kun kävijä käyttää tilaussivulinkkiä,
                    lähdetiedot kopioidaan tapahtumariville yhdessä sivulla näytettyjen sopimustietojen kanssa.
                </p>
                <p class="mb-3">Analytiikan avulla näemme esimerkiksi:</p>
                <ul class="list-disc pl-5 space-y-1.5 mb-4">
                    <li>kuinka paljon sivustolla on käyntejä</li>
                    <li>mitkä sivut ovat suosituimpia</li>
                    <li>mistä lähteistä kävijöitä tulee</li>
                    <li>miltä sopimussivuilta kävijät siirtyvät sähköyhtiöiden tilaussivuille</li>
                    <li>millaisilla laitteilla ja selaimilla sivustoa käytetään yleisellä tasolla</li>
                </ul>
                <p>
                    Näitä tietoja käytetään Voltikkan kehittämiseen. Emme käytä analytiikkaa yksittäisten käyttäjien
                    tunnistamiseen, profilointiin tai mainonnan kohdentamiseen.
                </p>
            </section>

            {{-- Evästeet --}}
            <section id="evasteet" class="scroll-mt-24">
                <h2 class="text-2xl font-extrabold text-slate-900 tracking-tight mb-4">Evästeet</h2>
                <p class="mb-4">
                    Voltikka ei käytä analytiikkaevästeitä, mainosevästeitä, uudelleenmarkkinointipikseleitä tai
                    kumppanuusmarkkinoinnin seurantatunnisteita. Ensimmäisen liikenteen lähteen tiedot tallennetaan selaimen
                    paikalliseen tallennustilaan avaimella <code>voltikka_attribution_v1</code>. Tieto vanhenee 30 minuutin
                    käyttämättömyyden jälkeen, vaikka vanha tieto voi säilyä selaimessa seuraavaan Voltikka-sivun avaukseen.
                </p>
                <p class="mb-4">
                    Paikallinen tieto ei sisällä kävijä- tai istuntotunnistetta. Sivustolla ei näytetä erillistä
                    evästesuostumusbanneria.
                </p>
                <p>
                    Jos sivustolle lisätään myöhemmin evästeitä tai muuta sellaista seurantaa, joka edellyttää käyttäjän
                    suostumusta, tämä sivu päivitetään ja suostumus pyydetään lain edellyttämällä tavalla.
                </p>
            </section>

            {{-- Mihin tietoja käytetään --}}
            <section id="kaytto" class="scroll-mt-24">
                <h2 class="text-2xl font-extrabold text-slate-900 tracking-tight mb-4">Mihin tietoja käytetään?</h2>
                <p class="mb-3">
                    Mahdollisia teknisiä tietoja ja anonyymiä analytiikkaa käytetään vain seuraaviin tarkoituksiin:
                </p>
                <ul class="list-disc pl-5 space-y-1.5 mb-4">
                    <li>sivuston toiminnan varmistamiseen</li>
                    <li>tietoturvan ylläpitämiseen</li>
                    <li>virheiden ja teknisten ongelmien selvittämiseen</li>
                    <li>palvelun käytön yleiseen mittaamiseen</li>
                    <li>Voltikkan sisällön ja käytettävyyden parantamiseen</li>
                </ul>
                <p>
                    Tietoja ei myydä ulkopuolisille. Tietoja ei käytetä henkilökohtaiseen mainontaan, käyttäjien
                    profilointiin tai yksittäisten kävijöiden seurantaan.
                </p>
            </section>

            {{-- Tietojen säilytys --}}
            <section id="sailytys" class="scroll-mt-24">
                <h2 class="text-2xl font-extrabold text-slate-900 tracking-tight mb-4">Tietojen säilytys</h2>
                <p class="mb-4">
                    Plausible Analyticsin kautta saatavia sivuston käyttötilastoja säilytetään palvelun kehittämistä ja
                    pidemmän aikavälin seurantaa varten.
                </p>
                <p class="mb-4">
                    Sähköyhtiön tilaussivulle siirtymisen tapahtumariveillä on aluksi toistaiseksi voimassa oleva säilytys.
                    Automaattista poistopäivää ei ole. Säilytystarve arvioidaan uudelleen, jos analytiikan käyttötarkoitus tai
                    kerättävät tiedot muuttuvat.
                </p>
                <p>
                    Teknisiä palvelin- ja lokitietoja säilytetään vain niin kauan kuin se on tarpeen sivuston toiminnan,
                    tietoturvan ja virheiden selvittämisen kannalta.
                </p>
            </section>

            {{-- Tietojen luovuttaminen --}}
            <section id="luovuttaminen" class="scroll-mt-24">
                <h2 class="text-2xl font-extrabold text-slate-900 tracking-tight mb-4">Tietojen luovuttaminen</h2>
                <p class="mb-4">
                    Voltikka ei luovuta käyttäjien tietoja sähköyhtiöille, mainostajille tai muille kaupallisille osapuolille.
                </p>
                <p>
                    Sivuston teknisessä ylläpidossa voidaan käyttää palveluntarjoajia, kuten analytiikka-, hosting- tai muita
                    teknisiä palveluita. Näitä palveluita käytetään vain sivuston toiminnan ja kehittämisen kannalta
                    tarpeellisiin tarkoituksiin.
                </p>
            </section>

            {{-- Käyttäjän oikeudet --}}
            <section id="oikeudet" class="scroll-mt-24">
                <h2 class="text-2xl font-extrabold text-slate-900 tracking-tight mb-4">Käyttäjän oikeudet</h2>
                <p class="mb-4">
                    Koska Voltikka ei normaalissa käytössä pyydä tai tallenna käyttäjän antamia henkilötietoja, meillä ei
                    yleensä ole tietoja, joiden perusteella yksittäinen kävijä voitaisiin tunnistaa.
                </p>
                <p>
                    Jos kuitenkin epäilet, että olemme käsitelleet sinua koskevia henkilötietoja, voit ottaa yhteyttä ja
                    pyytää lisätietoja. Sinulla voi tilanteesta riippuen olla oikeus saada pääsy tietoihin, pyytää tietojen
                    korjaamista tai poistamista sekä vastustaa tietojen käsittelyä.
                </p>
            </section>

            {{-- Yhteydenotot --}}
            <section id="yhteys" class="scroll-mt-24">
                <h2 class="text-2xl font-extrabold text-slate-900 tracking-tight mb-4">Yhteydenotot</h2>
                <p>
                    Tietosuojaa koskevissa kysymyksissä voit ottaa yhteyttä:
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
                <p class="text-sm text-slate-500">
                    Tätä sivua päivitetään, jos Voltikkan tietojen käsittely, analytiikka tai evästeiden käyttö muuttuu.
                </p>
                <p class="text-sm text-slate-500 mt-2">Päivitetty viimeksi: {{ $lastUpdated }}</p>
            </section>

        </div>
    </div>
</div>
