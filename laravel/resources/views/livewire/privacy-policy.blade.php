<div>
    {{-- Hero (Paper 38P-0 "Quiet hero"): the night sky and the fox asleep under a lamp. --}}
    <x-site.longread-hero variant="legal" :tone="\App\Support\Season::heroTone('legal-hero')" plate="night-sky-plate" figure="legal-fox-asleep" figure-width="480" figure-height="184">
        <h1 class="vk-hero-h1">Tietosuoja ja evästeet</h1>
        <p class="vk-hero-lead !mt-0">
            Tällä sivulla kerromme, miten Voltikka käsittelee kävijöiden tietoja ja käyttää evästeitä.
        </p>
    </x-site.longread-hero>

    <x-page-action-strip />

    <div class="vk-wrap vk-lr-page">

        <nav class="vk-crumbs" aria-label="Breadcrumb">
            <ol>
                <li><a href="/" class="vk-focus">Etusivu</a></li>
                <li aria-hidden="true">/</li>
                <li aria-current="page">Tietosuoja ja evästeet</li>
            </ol>
        </nav>

        <div class="vk-lr-layout">
            <x-site.longread-toc :items="[
                ['tiedot', 'Mitä tietoja keräämme'],
                ['analytiikka', 'Analytiikka'],
                ['evasteet', 'Evästeet'],
                ['kaytto', 'Mihin tietoja käytetään'],
                ['sailytys', 'Tietojen säilytys'],
                ['luovuttaminen', 'Tietojen luovuttaminen'],
                ['oikeudet', 'Käyttäjän oikeudet'],
                ['yhteys', 'Yhteydenotot'],
            ]" />

            <div class="vk-lr-article">

                <p class="vk-lr-intro">
                    Voltikka on sähkösopimusten vertailupalvelu. Palvelua voi käyttää ilman rekisteröitymistä,
            emmekä pyydä käyttäjiltä nimeä, sähköpostiosoitetta, puhelinnumeroa tai muita yhteystietoja.
                    </p>

                {{-- Mitä tietoja keräämme --}}
                <section id="tiedot" class="vk-lr-section">
                    <x-site.section-heading size="compact" wave="150">Mitä tietoja keräämme?</x-site.section-heading>
                    <p class="vk-lr-text">
                        Voltikka ei kerää käyttäjiltä henkilötietoja palvelun normaalia käyttöä varten. Sivustolla ei ole
                        käyttäjätilejä, kirjautumista, yhteydenottolomakkeita tai ostotapahtumia.
                    </p>
                    <p class="vk-lr-text">
                        Käytämme sivuston käytön mittaamiseen Plausible Analytics -palvelua. Lisäksi tallennamme tiedon siitä,
                        kun kävijä siirtyy sopimussivulta sähköyhtiön tilaussivulle. Mittaus ei vahvista, että tilaussivu avautui
                        tai että kävijä teki sopimuksen.
                    </p>
                    <p class="vk-lr-text">
                        Tilaussivulle siirtymisestä tallennamme sopimuksen ja yhtiön, sivulla näytetyn vuosihinnan ja kulutuksen,
                        sopimuksen sijoituksen vertailussa, painikkeen sijainnin sekä liikenteen lähteen, median, kampanjan ja
                        ensimmäisen laskeutumissivun polun. Emme tallenna tähän analytiikkatietoon IP-osoitetta, selaimen
                        käyttäjätunnistetta, koko viittaavaa osoitetta, sivun kyselyä, kävijätunnistetta tai istuntotunnistetta.
                    </p>
                    <p class="vk-lr-text">
                        Sivuilla voi vastata kysymykseen ”Löysitkö etsimäsi?”. Vastauksesta tallennamme sivun, vastauksen,
                        valitun syyn ja tavoitteen, vapaaehtoisen tekstin, laitetyypin (puhelin tai tietokone) ja liikenteen
                        lähteen tiedot. Vertailusivulla tallennamme lisäksi valitun kulutuksen, hinnoittelurajauksen, tulosten
                        määrän sekä tiedon siitä, oliko postinumero tai laskuvertailu käytössä. Itse postinumeroa emme tallenna.
                        Emme tallenna vastaukseen IP-osoitetta, kävijätunnistetta tai istuntotunnistetta. Älä kirjoita
                        vapaaseen tekstiin nimeäsi, puhelinnumeroasi, sähköpostiosoitettasi tai muita henkilötietoja.
                    </p>
                    <p class="vk-lr-text">
                        Sivuston teknisestä toiminnasta voi lisäksi syntyä tavanomaisia palvelin- ja lokitietoja. Näitä voivat
                        olla esimerkiksi IP-osoite, ajankohta, pyydetty sivu, selaimen teknisiä tietoja ja virhetilanteisiin
                        liittyviä tietoja. Näitä tietoja käytetään sivuston toiminnan, tietoturvan, virheiden selvittämisen ja
                        väärinkäytösten estämisen vuoksi.
                    </p>
                </section>

                {{-- Analytics --}}
                <section id="analytiikka" class="vk-lr-section">
                    <x-site.section-heading size="compact" wave="150">Analytiikka</x-site.section-heading>
                    <p class="vk-lr-text">
                        Voltikka käyttää evästeetöntä Plausible Analyticsia sivuston yleisen käytön mittaamiseen. Plausible ei
                        luo yksittäisistä kävijöistä profiileja.
                    </p>
                    <p class="vk-lr-text">
                        Voltikkan oma analytiikka mittaa sopimussivujen tilaussivulinkkien käyttöä. Selain säilyttää liikenteen
                        ensimmäisen lähteen paikallisesti 30 minuutin aktiivisuusjakson ajan. Uusi ulkoinen UTM-kampanja aloittaa
                        uuden jakson. Tieto ei sisällä kävijä- tai istuntotunnistetta. Kun kävijä käyttää tilaussivulinkkiä,
                        lähdetiedot kopioidaan tapahtumariville yhdessä sivulla näytettyjen sopimustietojen kanssa.
                    </p>
                    <p class="vk-lr-text">Analytiikan avulla näemme esimerkiksi:</p>
                    <ul class="vk-lr-list vk-lr-text">
                        <li>kuinka paljon sivustolla on käyntejä</li>
                        <li>mitkä sivut ovat suosituimpia</li>
                        <li>mistä lähteistä kävijöitä tulee</li>
                        <li>miltä sopimussivuilta kävijät siirtyvät sähköyhtiöiden tilaussivuille</li>
                        <li>kuinka hyvin sivut vastaavat sitä, mitä kävijät etsivät (”Löysitkö etsimäsi?” -kysymys)</li>
                        <li>millaisilla laitteilla ja selaimilla sivustoa käytetään yleisellä tasolla</li>
                    </ul>
                    <p class="vk-lr-text">
                        Näitä tietoja käytetään Voltikkan kehittämiseen. Emme käytä analytiikkaa yksittäisten käyttäjien
                        tunnistamiseen, profilointiin tai mainonnan kohdentamiseen.
                    </p>
                </section>

                {{-- Evästeet --}}
                <section id="evasteet" class="vk-lr-section">
                    <x-site.section-heading size="compact" wave="150">Evästeet</x-site.section-heading>
                    <p class="vk-lr-text">
                        Voltikka ei käytä analytiikkaevästeitä, mainosevästeitä, uudelleenmarkkinointipikseleitä tai
                        kumppanuusmarkkinoinnin seurantatunnisteita. Ensimmäisen liikenteen lähteen tiedot tallennetaan selaimen
                        paikalliseen tallennustilaan avaimella <code>voltikka_attribution_v1</code>. Tieto vanhenee 30 minuutin
                        käyttämättömyyden jälkeen, vaikka vanha tieto voi säilyä selaimessa seuraavaan Voltikka-sivun avaukseen.
                    </p>
                    <p class="vk-lr-text">
                        Kun vastaat kysymykseen ”Löysitkö etsimäsi?” tai suljet sen, selain tallentaa avaimella
                        <code>voltikka_feedback_v1</code> vain päivän, johon asti kysymystä ei näytetä uudelleen.
                    </p>
                    <p class="vk-lr-text">
                        Paikallinen tieto ei sisällä kävijä- tai istuntotunnistetta. Sivustolla ei näytetä erillistä
                        evästesuostumusbanneria.
                    </p>
                    <p class="vk-lr-text">
                        Jos sivustolle lisätään myöhemmin evästeitä tai muuta sellaista seurantaa, joka edellyttää käyttäjän
                        suostumusta, tämä sivu päivitetään ja suostumus pyydetään lain edellyttämällä tavalla.
                    </p>
                </section>

                {{-- Mihin tietoja käytetään --}}
                <section id="kaytto" class="vk-lr-section">
                    <x-site.section-heading size="compact" wave="150">Mihin tietoja käytetään?</x-site.section-heading>
                    <p class="vk-lr-text">
                        Mahdollisia teknisiä tietoja ja anonyymiä analytiikkaa käytetään vain seuraaviin tarkoituksiin:
                    </p>
                    <ul class="vk-lr-list vk-lr-text">
                        <li>sivuston toiminnan varmistamiseen</li>
                        <li>tietoturvan ylläpitämiseen</li>
                        <li>virheiden ja teknisten ongelmien selvittämiseen</li>
                        <li>palvelun käytön yleiseen mittaamiseen</li>
                        <li>Voltikkan sisällön ja käytettävyyden parantamiseen</li>
                    </ul>
                    <p class="vk-lr-text">
                        Tietoja ei myydä ulkopuolisille. Tietoja ei käytetä henkilökohtaiseen mainontaan, käyttäjien
                        profilointiin tai yksittäisten kävijöiden seurantaan.
                    </p>
                </section>

                {{-- Tietojen säilytys --}}
                <section id="sailytys" class="vk-lr-section">
                    <x-site.section-heading size="compact" wave="150">Tietojen säilytys</x-site.section-heading>
                    <p class="vk-lr-text">
                        Plausible Analyticsin kautta saatavia sivuston käyttötilastoja säilytetään palvelun kehittämistä ja
                        pidemmän aikavälin seurantaa varten.
                    </p>
                    <p class="vk-lr-text">
                        Sähköyhtiön tilaussivulle siirtymisen tapahtumariveillä on aluksi toistaiseksi voimassa oleva säilytys.
                        Automaattista poistopäivää ei ole. Säilytystarve arvioidaan uudelleen, jos analytiikan käyttötarkoitus tai
                        kerättävät tiedot muuttuvat.
                    </p>
                    <p class="vk-lr-text">
                        ”Löysitkö etsimäsi?” -vastausten vapaaehtoiset tekstit poistetaan automaattisesti 12 kuukauden
                        kuluttua. Vastausvalinnat säilytetään palvelun kehittämistä varten.
                    </p>
                    <p class="vk-lr-text">
                        Teknisiä palvelin- ja lokitietoja säilytetään vain niin kauan kuin se on tarpeen sivuston toiminnan,
                        tietoturvan ja virheiden selvittämisen kannalta.
                    </p>
                </section>

                {{-- Tietojen luovuttaminen --}}
                <section id="luovuttaminen" class="vk-lr-section">
                    <x-site.section-heading size="compact" wave="150">Tietojen luovuttaminen</x-site.section-heading>
                    <p class="vk-lr-text">
                        Voltikka ei luovuta käyttäjien tietoja sähköyhtiöille, mainostajille tai muille kaupallisille osapuolille.
                    </p>
                    <p class="vk-lr-text">
                        Sivuston teknisessä ylläpidossa voidaan käyttää palveluntarjoajia, kuten analytiikka-, hosting- tai muita
                        teknisiä palveluita. Näitä palveluita käytetään vain sivuston toiminnan ja kehittämisen kannalta
                        tarpeellisiin tarkoituksiin.
                    </p>
                </section>

                {{-- Käyttäjän oikeudet --}}
                <section id="oikeudet" class="vk-lr-section">
                    <x-site.section-heading size="compact" wave="150">Käyttäjän oikeudet</x-site.section-heading>
                    <p class="vk-lr-text">
                        Koska Voltikka ei normaalissa käytössä pyydä tai tallenna käyttäjän antamia henkilötietoja, meillä ei
                        yleensä ole tietoja, joiden perusteella yksittäinen kävijä voitaisiin tunnistaa.
                    </p>
                    <p class="vk-lr-text">
                        Jos kuitenkin epäilet, että olemme käsitelleet sinua koskevia henkilötietoja, voit ottaa yhteyttä ja
                        pyytää lisätietoja. Sinulla voi tilanteesta riippuen olla oikeus saada pääsy tietoihin, pyytää tietojen
                        korjaamista tai poistamista sekä vastustaa tietojen käsittelyä.
                    </p>
                </section>

                {{-- Yhteydenotot --}}
                <section id="yhteys" class="vk-lr-section">
                    <x-site.section-heading size="compact" wave="150">Yhteydenotot</x-site.section-heading>
                    <p class="vk-lr-text">
                        Tietosuojaa koskevissa kysymyksissä voit ottaa yhteyttä:
                        <x-obfuscated-email
                            :email="\App\Livewire\AboutPage::CONTACT_EMAIL"
                            label="Näytä sähköpostiosoite"
                            link-class="vk-lr-email"
                            class="mt-2.5 block"
                        />
                    </p>
                </section>

                {{-- Sivun päivitykset --}}
                <section class="vk-lr-section">
                    <x-site.section-heading size="compact" wave="170">Sivun päivitykset</x-site.section-heading>
                    <p class="vk-lr-text">
                        Tätä sivua päivitetään, jos Voltikkan tietojen käsittely, analytiikka tai evästeiden käyttö muuttuu.
                    </p>
                    <p class="vk-lr-updated">Päivitetty viimeksi: {{ $lastUpdated }}</p>
                </section>

            </div>
        </div>
    </div>
</div>
