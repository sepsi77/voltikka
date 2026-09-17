<?php

namespace Tests\Feature;

use App\Models\SpotPriceAverage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AboutPageMethodologyTest extends TestCase
{
    use RefreshDatabase;

    public function test_methodology_explains_comparable_premium_and_estimated_savings(): void
    {
        $text = $this->methodologyText();
        $this->assertStringContainsString('vertailukelpoisista saman yhtiön tai markkinan sopimuksista arvioidun vähittäishinnan lisän', $text);
        $this->assertStringContainsString('Jos normaalihinta voi muuttua, myös vertailu siihen on arvio.', $text);
        $this->assertStringContainsString('Arvioitu säästö ei ole taattu säästö.', $text);
    }

    public function test_visible_estimate_note_precedes_calculation_details(): void
    {
        $response = $this->get('/tietoa')->assertOk();
        $response->assertSee('href="#menetelma"', false);
        $document = new \DOMDocument;
        @$document->loadHTML('<?xml encoding="UTF-8">'.$response->getContent());
        $xpath = new \DOMXPath($document);
        $intro = $xpath->query('//section[@id="menetelma"]/h2/following-sibling::p[1]')->item(0);
        $note = $xpath->query('//section[@id="menetelma"]/h2/following-sibling::p[2]')->item(0);

        $this->assertNotNull($intro);
        $this->assertNotNull($note);
        $this->assertStringContainsString('Sähkösopimuksissa yhdistyy yhä useammin', $intro->textContent);
        $this->assertStringContainsString('Arvio ei ole lupaus tulevasta sähkölaskusta.', $note->textContent);
        $this->assertSame('mb-6', $note->getAttribute('class'));
        $this->assertSame('div', $xpath->query('following-sibling::*[1]', $note)->item(0)->nodeName);
        $this->assertStringNotContainsString('hidden', $note->getAttribute('class'));

        $text = $this->methodologyText();
        $this->assertStringContainsString('Sama kulutus ei tarkoita, että jokaisen sopimuksen hinta olisi yhtä varma.', $text);
        $this->assertStringContainsString('Koko vuodeksi sovituista kiinteistä hinnoista kustannuksen voi laskea valitulle kulutukselle.', $text);
        $this->assertStringContainsString('Pieni ero vuosiarvioissa ei yksin kerro', $text);
    }

    public function test_methodology_explains_units_audience_vat_and_common_consumption(): void
    {
        $text = $this->methodologyText();
        foreach ([
            'energiahinta (snt/kWh) × vuosikulutus (kWh) / 100',
            'Kotitalouksille tarkoitetut hinnat sisältävät arvonlisäveron (alv 25,5 %), myös kun sama sopimus on tarjolla yrityksille.',
            'Vain yrityksille tarkoitetut sopimukset näytetään ilman arvonlisäveroa.',
            'Jos kohderyhmää ei ole ilmoitettu, näytämme hinnat veroineen.',
            'Jos verosta ei ole tietoa, oletamme kotitaloushinnan sisältävän veron',
            'Oletuksena vuosikulutus jaetaan tasan 12 kalenterikuukaudelle',
            'Tarkemmat lämmitys- ja jäähdytystiedot voivat muuttaa kulutuksen jakautumista kuukausille.',
            'aikasähkössä perusoletus on 85 % kulutuksesta päivällä ja 15 % yöllä.',
            'Tämä ei ole kaikkien asiakkaiden tai pörssisopimusten kulutusoletus.',
        ] as $meaning) {
            $this->assertStringContainsString($meaning, $text);
        }
        $this->assertStringNotContainsString('talvikuukausina kulutus on suurempi kuin kesällä', $text);
    }

    public function test_methodology_distinguishes_unknown_prices_terms_hybrid_and_packages(): void
    {
        $text = $this->methodologyText();
        foreach ([
            'viimeisimmällä kyseiseen aikaan soveltuvalla hinnalla tai myyjän ilmoittamalla normaalihinnalla.',
            'Myyjän ilmoittama myöhempi hinta menee aina oletuksen edelle.',
            'Emme jätä puuttuvan hinnan aikaa maksuttomaksi emmekä laske sille ylimääräistä tarjoussäästöä.',
            'Emme myöskään keksi tuntematonta hinnankorotusta.',
            'Lyhyen sopimuksen vuosivertailu voi perustua vain sen omaan sopimusaikaan. Tällöin laskemme ensin kustannuksen tältä sopimusajalta.',
            '12 / sopimuskuukaudet',
            'Sopimusajan tuntemattomat hinnat arvioidaan erikseen.',
            '300 €, vuosivertailun luku on 600 €. Tämä on laskuesimerkki, ei tarjous jatkosopimuksesta.',
            'perushinnat ja maksut, mutta ei kulutusvaikutusta.',
            'Kulutusvaikutus voi nostaa tai laskea omaa hintaasi',
            'ei tarkoita, että vaikutus olisi sinulle nolla.',
            'Käyttämätön osuus ei siirry seuraavaan kuukauteen.',
            'Rajan ylittävästä kulutuksesta maksetaan ylityshinta.',
            'pienennämme sekä maksua että kulutusrajaa samassa suhteessa päivien määrään.',
            'Tarjoussäästö tarkoittaa alennusta saman sopimuksen normaalihintaan verrattuna.',
            'säästö koskee vain todellista sopimusaikaa, eikä sitä kerrota vuositasolle.',
            'Kyse ei ole säästöstä omaan vanhaan sopimukseesi',
        ] as $meaning) {
            $this->assertStringContainsString($meaning, $text);
        }
        $this->assertStringNotContainsString('arvioidun ensimmäisen vuoden säästön', $text);
        $this->assertStringNotContainsString('Laskemme ensin alle vuoden sopimuksen kustannuksen sen omalta sopimusajalta.', $text);
    }

    public function test_spot_fallback_is_explained_without_historical_values(): void
    {
        $text = $this->methodologyText();
        foreach ([
            'Futuurit ovat tukkumarkkinoilla nyt sovittuja hintoja myöhemmin toimitettavalle sähkölle.',
            'marginaalin eli myyjän oman hintalisän',
            'käytämme päivälle ja yölle samaa markkinahinta-arviota.',
            'Tällöin arvio on tavallista epävarmempi.',
            'Tämä on oletus hinnoista, ei siitä, että käyttäisit sähköä tasaisesti kaikkina tunteina.',
            'Jos koko futuurijaksoa ei ole saatavilla tai tiedot ovat vanhentuneet',
            'edeltävän 12 kuukauden toteutunutta pörssihintaa. Kerromme, kun arvio perustuu aiempiin hintoihin.',
        ] as $meaning) {
            $this->assertStringContainsString($meaning, $text);
        }
        $this->assertStringNotContainsString('Tämä aiempi keskihinta on nyt', $text);
        $this->assertStringNotContainsString('peruskuormahinta', $text);
        $this->assertStringNotContainsString('luottamustaso', $text);
    }

    public function test_reset_assumptions_sources_and_seller_check_are_clear(): void
    {
        $text = $this->methodologyText();
        foreach ([
            'nykyisen jakson ja muut tiedossa olevat jaksot myyjän ilmoittamilla hinnoilla.',
            'siihen markkinatilanteeseen, jossa nykyinen hintajakso hinnoiteltiin.',
            'Oletamme sopimushinnan muuttuvan suunnilleen saman verran kuin tukkuhinta.',
            'Myyjä voi kuitenkin hinnoitella sähkön toisin.',
            'aiempien vuosien pörssihintojen vuodenaikavaihtelun avulla.',
            'Jos sekään ei onnistu, oletamme nykyisen hinnan jatkuvan.',
            'Toistaiseksi voimassa olevat sopimukset',
            'Kun sopimukselle näytetään tällainen markkinamuutoksiin perustuva arvio',
            'nykyinen kalenterikuukausi lasketaan julkaistulla hinnalla.',
            'Kuukausittainen laskenta ei tarkoita, että tietäisimme myyjän tulevat hinnanmuutospäivät.',
            'Pörssisähkön vuosiarvioissa sekä kuukausi- ja kvartaalisähkön',
            'EEX-sähköpörssistä',
            'lyhyen aikavälin tuntiennusteet ovat eri asia kuin sopimusvertailun vuosiarvio.',
            'eikä se takaa kaikkien virheiden löytymistä.',
            'Haemme sopimushinnat päivittäin sähköyhtiöiden julkisesti saatavilla olevista hinnastoista.',
            'Jos tietojen haku ei onnistu, sivulla voi olla vanhempia tietoja.',
            'Tarkista ennen sopimuksen tekemistä myyjältä hinta, sopimuksen kesto ja ehdot hinnan muuttamiselle.',
            'Kun vertailuhinnan vieressä on Arvio-painike',
        ] as $meaning) {
            $this->assertStringContainsString($meaning, $text);
        }
        $this->assertStringNotContainsString('Sähköyhtiön oma kate', $text);
    }

    public function test_historical_spot_value_remains_a_tax_inclusive_fallback_reference(): void
    {
        SpotPriceAverage::create([
            'region' => 'FI',
            'period_type' => SpotPriceAverage::PERIOD_ROLLING_365D_LOCAL,
            'period_start' => '2026-08-01',
            'period_end' => '2026-08-01',
            'avg_price_without_tax' => 4,
            'avg_price_with_tax' => 5.02,
            'hours_count' => 8760,
        ]);

        $this->assertStringContainsString(
            'käytämme varalla edeltävän 12 kuukauden toteutunutta pörssihintaa. Tämä aiempi keskihinta on nyt 5,02 c/kWh (sis. alv).',
            $this->methodologyText(),
        );
    }

    private function methodologyText(): string
    {
        $html = $this->get('/tietoa')->assertOk()->getContent();

        return preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    }
}
