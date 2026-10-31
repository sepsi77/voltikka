<div>
    {{-- Hero (Paper 38P-0 "Quiet hero"): the night sky and the fox asleep under a lamp. --}}
    <x-site.longread-hero variant="legal" :tone="\App\Support\Season::heroTone('legal-hero')" plate="night-sky-plate" figure="legal-fox-asleep" figure-width="480" figure-height="184">
        <h1 class="vk-hero-h1">Käyttöehdot</h1>
        <p class="vk-hero-lead !mt-0">
            Näitä käyttöehtoja sovelletaan Voltikka.fi-sivuston käyttöön.
        </p>
    </x-site.longread-hero>

    <x-page-action-strip />

    <div class="vk-wrap vk-lr-page">

        <nav class="vk-crumbs" aria-label="Breadcrumb">
            <ol>
                <li><a href="/" class="vk-focus">Etusivu</a></li>
                <li aria-hidden="true">/</li>
                <li aria-current="page">Käyttöehdot</li>
            </ol>
        </nav>

        <div class="vk-lr-layout">
            <x-site.longread-toc :items="[
                ['tarkoitus', 'Palvelun tarkoitus'],
                ['tiedot', 'Tiedot ja laskelmat'],
                ['vastuu', 'Käyttäjän vastuu'],
                ['ulkopuoliset', 'Ulkopuoliset sivustot'],
                ['virheet', 'Virheet ja puutteet'],
                ['saatavuus', 'Palvelun saatavuus ja muutokset'],
                ['vastuunrajoitus', 'Vastuunrajoitus'],
                ['immateriaalioikeudet', 'Immateriaalioikeudet'],
                ['laki', 'Sovellettava laki'],
                ['yhteys', 'Yhteystiedot'],
            ]" />

            <div class="vk-lr-article">

                <p class="vk-lr-intro">
                    Käyttämällä sivustoa hyväksyt nämä käyttöehdot. Jos et hyväksy ehtoja, älä käytä sivustoa.
                </p>

                {{-- Palvelun tarkoitus --}}
                <section id="tarkoitus" class="vk-lr-section">
                    <x-site.section-heading size="compact" wave="150">Palvelun tarkoitus</x-site.section-heading>
                    <p class="vk-lr-text">
                        Voltikka on sähkösopimusten vertailupalvelu. Palvelun tarkoituksena on auttaa käyttäjiä vertailemaan
                        sähkösopimuksia, arvioituja kustannuksia, hintarakenteita ja sopimusten ominaisuuksia.
                    </p>
                    <p class="vk-lr-text">
                        Voltikka ei ole sähköyhtiö, sähkönmyyjä tai sähkösopimuksen osapuoli. Sähkösopimus tehdään aina
                        käyttäjän ja valitun sähköyhtiön välillä.
                    </p>
                </section>

                {{-- Tiedot ja laskelmat --}}
                <section id="tiedot" class="vk-lr-section">
                    <x-site.section-heading size="compact" wave="150">Tiedot ja laskelmat</x-site.section-heading>
                    <p class="vk-lr-text">
                        Voltikka pyrkii pitämään sivustolla esitetyt tiedot mahdollisimman ajantasaisina ja oikeina.
                        Sähkösopimusten hinnat, kampanjat, ehdot, saatavuus ja muut tiedot voivat kuitenkin muuttua nopeasti.
                    </p>
                    <p class="vk-lr-text">
                        Sivustolla esitetyt hinnat, vuosikustannukset ja muut laskelmat ovat suuntaa antavia arvioita. Ne
                        perustuvat sivustolla käytössä oleviin tietoihin, käyttäjän valitsemiin oletuksiin ja
                        laskentamalleihin. Lopullinen hinta, sopimusehdot ja saatavuus voivat poiketa Voltikassa näytetyistä
                        tiedoista.
                    </p>
                    <p class="vk-lr-text">
                        Voltikka ei anna sitovia tarjouksia sähkösopimuksista. Ennen sopimuksen tekemistä käyttäjän tulee aina
                        tarkistaa lopullinen hinta, sopimuksen ehdot, kesto, irtisanomisehdot, kampanjaehdot ja muut olennaiset
                        tiedot sähköyhtiön omalta sivustolta tai sähköyhtiöltä suoraan.
                    </p>
                </section>

                {{-- Käyttäjän vastuu --}}
                <section id="vastuu" class="vk-lr-section">
                    <x-site.section-heading size="compact" wave="150">Käyttäjän vastuu</x-site.section-heading>
                    <p class="vk-lr-text">
                        Käyttäjä vastaa itse siitä, minkä sähkösopimuksen hän valitsee ja millä perusteilla päätös tehdään.
                    </p>
                    <p class="vk-lr-text">
                        Voltikka tarjoaa vertailutietoa päätöksenteon tueksi, mutta ei anna henkilökohtaista neuvontaa,
                        taloudellista neuvontaa tai suositusta yksittäisen käyttäjän tilanteeseen. Käyttäjän tulee arvioida
                        itse, sopiiko tietty sähkösopimus hänen kulutukseensa, asumistilanteeseensa, riskinsietoonsa ja muihin
                        tarpeisiinsa.
                    </p>
                </section>

                {{-- Ulkopuoliset sivustot --}}
                <section id="ulkopuoliset" class="vk-lr-section">
                    <x-site.section-heading size="compact" wave="150">Ulkopuoliset sivustot</x-site.section-heading>
                    <p class="vk-lr-text">
                        Voltikka voi sisältää linkkejä sähköyhtiöiden tai muiden kolmansien osapuolten sivustoille.
                    </p>
                    <p class="vk-lr-text">
                        Voltikka ei vastaa ulkopuolisten sivustojen sisällöstä, hinnoista, ehdoista, saatavuudesta,
                        tietosuojakäytännöistä tai muista palveluista. Kun käyttäjä siirtyy ulkopuoliselle sivustolle, kyseisen
                        sivuston omat ehdot ja käytännöt tulevat sovellettaviksi.
                    </p>
                </section>

                {{-- Virheet ja puutteet --}}
                <section id="virheet" class="vk-lr-section">
                    <x-site.section-heading size="compact" wave="150">Virheet ja puutteet</x-site.section-heading>
                    <p class="vk-lr-text">
                        Voltikka pyrkii korjaamaan havaitut virheet mahdollisimman nopeasti. Jos huomaat sivustolla
                        virheellisen hinnan, vanhentuneen sopimustiedon tai muun puutteen, voit ilmoittaa siitä meille.
                    </p>
                    <p class="vk-lr-text">
                        Virheilmoitukset ja korjauspyynnöt voi lähettää osoitteeseen:
                        <x-obfuscated-email
                            :email="\App\Livewire\AboutPage::CONTACT_EMAIL"
                            label="Näytä sähköpostiosoite"
                            link-class="vk-lr-email"
                            class="mt-2.5 block"
                        />
                    </p>
                </section>

                {{-- Palvelun saatavuus ja muutokset --}}
                <section id="saatavuus" class="vk-lr-section">
                    <x-site.section-heading size="compact" wave="150">Palvelun saatavuus ja muutokset</x-site.section-heading>
                    <p class="vk-lr-text">
                        Voltikka pyrkii pitämään palvelun käytettävissä, mutta ei takaa sivuston keskeytyksetöntä tai
                        virheetöntä toimintaa.
                    </p>
                    <p class="vk-lr-text">
                        Sivustoa, sen sisältöä, laskentatapoja, ominaisuuksia ja käyttöehtoja voidaan muuttaa milloin tahansa.
                        Muutokset tulevat voimaan, kun ne julkaistaan sivustolla.
                    </p>
                </section>

                {{-- Vastuunrajoitus --}}
                <section id="vastuunrajoitus" class="vk-lr-section">
                    <x-site.section-heading size="compact" wave="150">Vastuunrajoitus</x-site.section-heading>
                    <p class="vk-lr-text">
                        Voltikka ei vastaa vahingoista, jotka aiheutuvat sivustolla esitettyjen tietojen käyttämisestä,
                        tietojen virheellisyydestä, tietojen vanhentumisesta, palvelun keskeytymisestä tai ulkopuolisten
                        sivustojen toiminnasta.
                    </p>
                    <p class="vk-lr-text">
                        Tämä vastuunrajoitus ei rajoita kuluttajalle lain mukaan kuuluvia oikeuksia eikä muuta vastuuta, jota
                        ei voida sovellettavan lain mukaan rajoittaa.
                    </p>
                </section>

                {{-- Immateriaalioikeudet --}}
                <section id="immateriaalioikeudet" class="vk-lr-section">
                    <x-site.section-heading size="compact" wave="150">Immateriaalioikeudet</x-site.section-heading>
                    <p class="vk-lr-text">
                        Voltikka-sivuston sisältö, rakenne, laskentatavat, tekstit, ulkoasu ja muu aineisto kuuluvat Voltikalle
                        tai sen oikeudenhaltijoille, ellei toisin mainita.
                    </p>
                    <p class="vk-lr-text">
                        Sivustoa saa käyttää henkilökohtaiseen ja tavanomaiseen tiedonhakuun. Sivuston sisällön laajamittainen
                        kopiointi, automatisoitu kerääminen, uudelleenjulkaisu tai kaupallinen hyödyntäminen ilman lupaa on
                        kielletty.
                    </p>
                </section>

                {{-- Sovellettava laki --}}
                <section id="laki" class="vk-lr-section">
                    <x-site.section-heading size="compact" wave="150">Sovellettava laki</x-site.section-heading>
                    <p class="vk-lr-text">
                        Näihin käyttöehtoihin sovelletaan Suomen lakia, ellei pakottavasta lainsäädännöstä muuta johdu.
                    </p>
                </section>

                {{-- Yhteystiedot --}}
                <section id="yhteys" class="vk-lr-section">
                    <x-site.section-heading size="compact" wave="150">Yhteystiedot</x-site.section-heading>
                    <p class="vk-lr-text">Palvelun tarjoaja:</p>
                    <x-site.company-details />
                    <p class="vk-lr-text">
                        Käyttöehtoihin liittyvissä kysymyksissä voit ottaa yhteyttä:
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
                    <p class="vk-lr-updated">Päivitetty viimeksi: {{ $lastUpdated }}</p>
                </section>

            </div>
        </div>
    </div>
</div>
