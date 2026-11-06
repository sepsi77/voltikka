{{--
    Market review, October 2026 (edition 5.11.2026). Frozen copy and figures: do not change a
    figure after publication without a dated correction note. Source draft and calculations:
    market-review/editions/2026-10/2026-10-katsaus.md. Paper: page "Markkinakatsaus · sivu".
    Charts: frozen data in resources/market-reviews/2026-10/kuva-*.json (written by
    market-review/tools/site_payloads.py); CSV: luvut.csv in the same folder.
--}}
@php
    use App\Support\MarketReviews;

    $month = $review['month'];
    $bills = MarketReviews::chartData($month, 'kuva-1');
    $daily = MarketReviews::chartData($month, 'kuva-2');
    $index = MarketReviews::chartData($month, 'kuva-3');
    $fmt = fn ($value, int $decimals = 0) => $value === null ? '–' : number_format((float) $value, $decimals, ',', "\u{00A0}");
    $signed = fn ($value) => $value === null ? '–' : ($value > 0 ? '+' : ($value < 0 ? '−' : '')).number_format(abs((float) $value), 1, ',', '');
@endphp

<section class="mr-score" aria-labelledby="mr-score-title">
    <h2 id="mr-score-title" class="mr-caps mr-caps--fox">Pörssisähkön hinta, alv 25,5 %</h2>
    <div class="mr-score-row">
        <div class="mr-score-item">
            <svg class="mr-score-glyph" viewBox="0 0 44 44" aria-hidden="true" focusable="false">
                <g fill="var(--color-aurora)" opacity="0.6">
                    <rect x="6.2" y="27" width="5" height="10" /><rect x="12.2" y="19" width="5" height="18" /><rect x="18.2" y="31" width="5" height="6" />
                    <rect x="24.2" y="11" width="5" height="26" /><rect x="30.2" y="22" width="5" height="15" /><rect x="36.2" y="26" width="4.6" height="11" />
                </g>
                <path d="M5 37.5 L5.2 25.8 L10.3 26.1 L10.1 37.5 M11.1 37.5 L11.2 17.9 L16.2 18.1 L16.1 37.5 M17.2 37.5 L17.1 30.2 L22.1 29.9 L22.3 37.5 M23.1 37.5 L23.3 9.8 L28.2 10.1 L28.1 37.5 M29.2 37.5 L29.1 21 L34.2 21.2 L34.1 37.5 M35.2 37.5 L35.1 25.1 L39.9 24.9 L40 37.5" fill="none" stroke="var(--color-ink)" stroke-width="1.25" stroke-linecap="round" stroke-linejoin="round" />
                <path d="M3 38.6 C 14 37.6, 28 39.3, 41.5 38.2" fill="none" stroke="var(--color-ink)" stroke-width="1.6" stroke-linecap="round" />
            </svg>
            <div>
                <p class="mr-score-label">Lokakuu 2026, toteutunut</p>
                <p class="mr-score-value mr-score-value--spot">4,77&nbsp;c</p>
                <p class="mr-score-sub">−33&nbsp;% syyskuusta</p>
            </div>
        </div>
        <span class="mr-score-arrow" aria-hidden="true">→</span>
        <div class="mr-score-item">
            <div>
                <p class="mr-score-label mr-score-label--high">Marras–maaliskuu, odotus</p>
                <p class="mr-score-value mr-score-value--high">11–16&nbsp;c</p>
                <p class="mr-score-sub">Futuurit 30.10.2026</p>
            </div>
        </div>
        <div class="mr-score-item mr-score-item--summer">
            <div>
                <p class="mr-score-label">Huhti–lokakuu 2027, odotus</p>
                <p class="mr-score-value">4–10&nbsp;c</p>
                <p class="mr-score-sub mr-score-sub--saving">Kesä pysyy halpana</p>
            </div>
        </div>
    </div>
    <p class="mr-note">Hinnat senttiä kilowattitunnilta (c/kWh). Odotus on kuukausien futuurien vaihteluväli; laskelman perusteet ovat sivun lopussa.</p>
</section>

<div class="vk-lr-layout vk-lr-layout--article">
    <x-market-review.contents :archive="$archive" :next-label="$review['next_label']" :items="[
        ['talvi', 'Miltä tuleva talvi nyt näyttää'],
        ['sahkolammitteinen-pientalo', 'Sähkölämmitteinen pientalo'],
        ['pientalo', 'Pientalo ilman sähkölämmitystä'],
        ['kerrostaloasunto', 'Kerrostaloasunto'],
        ['tarkemmat-tiedot', 'Tarkemmat tiedot: lokakuu 2026'],
        ['nain-laskimme', 'Näin laskimme'],
    ]" />

    <article class="vk-lr-article vk-lr-article--wide mr-article">
        {{-- 1 · Winter outlook --}}
        <section class="vk-lr-section mr-block" aria-labelledby="talvi">
            <x-site.section-heading id="talvi" wave="330">Miltä tuleva talvi nyt näyttää</x-site.section-heading>
            <p class="mr-stance">Talvesta tulee kallis, ja syitä on kolme.</p>
            <ol class="mr-reasons">
                <li>Norjan vesialtaat ovat 13 prosenttiyksikköä tavallista vajaammat.</li>
                <li>Euroopan kaasuvarastot ovat vajaammat kuin vuosi sitten.</li>
                <li>Olkiluoto 3 palaa vuosihuollosta vasta 11.11.</li>
            </ol>
            <p class="vk-lr-text">Marraskuu näytti jo, mitä tämä tarkoittaa. Maanantaina 2.11. tuulivoimaa oli vain kolmannes lokakuun keskitasosta, ja pörssisähkö maksoi keskimäärin 26&nbsp;c/kWh ja illalla 51&nbsp;c/kWh. Pahin riski on kylmä ja tyyni viikko, kuten tammikuussa 2024: silloin pörssisähkö maksaa viikon ajan noin 34&nbsp;c/kWh. Lämmin syksy painoi hinnat syys- ja lokakuussa selvästi futuurien alle, mutta sen varaan ei kannata laskea talvea.</p>
            <x-market-review.figure number="1" caption="Arvioitu energia ja sähkövero kuukaudessa, marraskuu 2026 – lokakuu 2027. Viiksi näyttää kolmen menneen talven säävaihtelun.">
                <div class="mr-chart-group" data-review-chart-data>
                    <ul class="mr-legend" aria-hidden="true">
                        <li><span class="mr-key" style="--key: var(--color-cat-spot)"></span>Pörssisähkö, tyypillinen sopimus</li>
                        <li><span class="mr-key" style="--key: var(--color-cat-fixed)"></span>12 kk kiinteä hinta, mediaani</li>
                        <li><span class="mr-key mr-key--whisker"></span>Kolmen talven säävaihtelu</li>
                    </ul>
                    @foreach ($bills['homes'] as $home)
                        <p class="mr-chart-title">{{ $home['title'] }}</p>
                        <div class="mr-chart-box mr-chart-box--bill">
                            <canvas data-review-chart="bill-outlook" data-home="{{ $home['key'] }}" role="img" aria-label="{{ $home['title'] }}: arvioitu energia ja sähkövero kuukaudessa. Talvella pörssisähkö on kalliimpi, kesällä halvempi kuin kiinteä hinta."></canvas>
                        </div>
                    @endforeach
                    <script type="application/json">{!! json_encode($bills, JSON_UNESCAPED_UNICODE) !!}</script>
                </div>
                <x-slot:table>
                    <table class="vk-lr-table">
                        <caption class="sr-only">Arvioitu energia ja sähkövero kuukaudessa, euroa</caption>
                        <thead>
                            <tr>
                                <th scope="col">Kuukausi</th>
                                @foreach ($bills['homes'] as $home)
                                    <th scope="col" class="is-num">{{ $home['key'] === 'BE02' ? 'Sähkölämmitteinen' : 'Ei sähkölämmitystä' }}: pörssi</th>
                                    <th scope="col" class="is-num">kiinteä</th>
                                @endforeach
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($bills['labels'] as $i => $label)
                                <tr>
                                    <th scope="row">{{ $label }}</th>
                                    @foreach ($bills['homes'] as $home)
                                        <td class="is-num">{{ $fmt($home['spot'][$i]) }}&nbsp;€ ({{ $fmt($home['spot_low'][$i]) }}–{{ $fmt($home['spot_high'][$i]) }})</td>
                                        <td class="is-num">{{ $fmt($home['fixed'][$i]) }}&nbsp;€</td>
                                    @endforeach
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </x-slot:table>
            </x-market-review.figure>
        </section>

        {{-- 2 · Electrically heated house --}}
        <section class="vk-lr-section mr-block" aria-labelledby="sahkolammitteinen-pientalo">
            <x-market-review.home art="home-house" kwh="11 100" id="sahkolammitteinen-pientalo">Sähkölämmitteinen pientalo</x-market-review.home>
            <x-market-review.advice stance="Pörssisähkö on pitkällä aikavälillä halvempi, mutta tuleva talvi on sille riski.">Kiinteä hinta antaa varmuuden talven laskusta, mutta siitä maksat kesällä.</x-market-review.advice>

            <div class="mr-table-wrap">
                <table class="mr-compare">
                    <caption class="sr-only">Sähkölämmitteisen pientalon energia ja sähkövero: pörssisähkö ja 12 kuukauden kiinteä hinta</caption>
                    <thead>
                        <tr>
                            <th scope="col" class="mr-caps">Energia + vero</th>
                            <th scope="col" class="mr-compare-head mr-compare-head--spot">Pörssisähkö</th>
                            <th scope="col" class="mr-compare-head mr-compare-head--fixed">Kiinteä 12&nbsp;kk</th>
                            <th scope="col" class="mr-caps">Ero</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr>
                            <th scope="row">Marras–maaliskuu <span>kylmänä talvena</span></th>
                            <td><span class="mr-num">1&nbsp;145&nbsp;€</span> <span>1&nbsp;232&nbsp;€</span></td>
                            <td><span class="mr-num">995&nbsp;€</span> <span>1&nbsp;046&nbsp;€</span></td>
                            <td class="mr-diff mr-diff--high">Pörssisähkö noin 150&nbsp;€ kalliimpi, kylmänä talvena noin 190&nbsp;€</td>
                        </tr>
                        <tr>
                            <th scope="row">Huhti–lokakuu</th>
                            <td><span class="mr-num">460&nbsp;€</span></td>
                            <td><span class="mr-num">686&nbsp;€</span></td>
                            <td class="mr-diff mr-diff--saving">Pörssisähkö noin 225&nbsp;€ halvempi</td>
                        </tr>
                        <tr class="mr-compare-total">
                            <th scope="row">Koko vuosi</th>
                            <td><span class="mr-num">1&nbsp;605&nbsp;€</span></td>
                            <td><span class="mr-num">1&nbsp;681&nbsp;€</span></td>
                            <td class="mr-diff mr-diff--saving">Pörssisähkö noin 75&nbsp;€ halvempi</td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <div class="mr-cases">
                <div>
                    <h3 class="mr-case-title">Jos haluat kiinteän hinnan, valitse 24 kuukautta.</h3>
                    <p class="mr-case-text">Se kattaa kaksi talvea, ja sen hinta on alempi kuin 12 kuukauden sopimuksen (mediaanit 10,90 ja 12,00&nbsp;c/kWh). Edullisin on Pohjois-Karjalan Sähkö Optimi takuu 24 kuukautta, joka on aikasähkö: ensimmäinen vuosi maksaa noin 1&nbsp;500&nbsp;€, kun edullisin pörssisähkö, Herrfors Spot+, maksaa 1&nbsp;564&nbsp;€. Toisena vuonna pörssisähkö on noin 175&nbsp;€ halvempi, joten koko 24 kuukauden aikana se on noin 115&nbsp;€ edullisempi. 36 kuukauden sopimus maksaa kolmessa vuodessa noin 290&nbsp;€ enemmän kuin pörssisähkö.</p>
                </div>
                <div>
                    <h3 class="mr-case-title">Jos siirrät kulutusta halvoille tunneille</h3>
                    <p class="mr-case-text">Lämminvesivaraaja tai sähköauton lataus, noin 900&nbsp;kWh vuodessa: säästät pörssisähköllä vielä noin 77&nbsp;€ vuodessa.</p>
                </div>
                <div>
                    <h3 class="mr-case-title mr-case-title--warn"><span aria-hidden="true">! </span>Jos sinulla on kvartaalisähkö</h3>
                    <p class="mr-case-text">Hintasi nousee 1.1. noin 3,8&nbsp;c/kWh, ja tammi–maaliskuu maksaa noin 155&nbsp;€ enemmän kuin nyt. Vaihda ennen vuodenvaihdetta pörssisähköön tai 24 kuukauden kiinteään hintaan.</p>
                </div>
            </div>
        </section>

        {{-- 3 · House without electric heating --}}
        <section class="vk-lr-section mr-block" aria-labelledby="pientalo">
            <x-market-review.home art="home-rowhouse" kwh="6 800" wave="360" id="pientalo">Pientalo ilman sähkölämmitystä</x-market-review.home>
            <x-market-review.advice stance="Pysy pörssisähkössä.">Talvella se maksaa noin 90&nbsp;€ enemmän kuin kiinteä hinta, mutta huhti–lokakuussa noin 155&nbsp;€ vähemmän, joten vuodessa se on noin 65&nbsp;€ halvempi. Edullisin 24 kuukauden kiinteä hinta on ensimmäisenä vuonna lähes yhtä halpa, mutta toisena vuonna noin 120&nbsp;€ pörssisähköä kalliimpi.</x-market-review.advice>
            <div class="mr-cases">
                <div>
                    <h3 class="mr-case-title mr-case-title--warn"><span aria-hidden="true">! </span>Jos sinulla on kvartaalisähkö</h3>
                    <p class="mr-case-text">Tammi–maaliskuu maksaa noin 90&nbsp;€ enemmän kuin nyt. Vaihda pörssisähköön ennen vuodenvaihdetta.</p>
                </div>
            </div>
        </section>

        {{-- 4 · Apartment --}}
        <section class="vk-lr-section mr-block" aria-labelledby="kerrostaloasunto">
            <x-market-review.home art="home-apartment" kwh="2 100" wave="220" id="kerrostaloasunto">Kerrostaloasunto</x-market-review.home>
            <x-market-review.advice stance="Pysy pörssisähkössä.">Se on vuoden aikana noin 40&nbsp;€ kiinteää halvempi, ja kalleinkin talviviikko maksaa vain noin 30&nbsp;€. Edullisin on Nordic Green Energy Pörssisähkö.</x-market-review.advice>
        </section>

        <div class="mr-cta">
            <div>
                <p class="mr-cta-title">Kulutatko enemmän tai vähemmän?</p>
                <p class="mr-cta-text">Laske sopimusten hinnat omalla kulutuksellasi. Laskelman perusteet ovat sivun lopussa.</p>
            </div>
            <a href="/sahkosopimus" class="vk-action vk-focus">
                <span>Vertaa sopimuksia →</span>
                <x-ink.wave width="full" />
            </a>
        </div>

        <x-site.interlude art="interlude-icefishing" class="vk-lr-interlude" />

        {{-- 5 · Details (mainly for journalists; the heading does not name an audience) --}}
        <section class="mr-details-intro" aria-labelledby="tarkemmat-tiedot">
            <p class="mr-caps mr-caps--fox">Luvut, syyt ja ennusteet</p>
            <div>
                <h2 id="tarkemmat-tiedot" class="mr-layer-title">Tarkemmat tiedot: lokakuu 2026</h2>
                <x-ink.wave width="520" stroke="4" class="vk-lr-title-wave" />
            </div>
            <p class="vk-lr-text">Kuukauden luvut lähteineen. Voit lainata lukuja ja kuvia, kun mainitset lähteeksi Voltikan.</p>
        </section>

        <section class="vk-lr-section mr-block" aria-labelledby="tarkeimmat-luvut">
            <div class="mr-sub-head">
                <h3 id="tarkeimmat-luvut" class="mr-sub-title">Tärkeimmät luvut</h3>
                <a href="/markkinakatsaus/{{ $month }}.csv" class="vk-action vk-focus mr-csv" download>
                    <span>Lataa CSV ↓</span>
                    <x-ink.wave width="full" stroke="2.2" />
                </a>
            </div>
            <dl class="mr-figures">
                <div>
                    <dt>Pörssisähkön keskihinta lokakuussa</dt>
                    <dd class="mr-figure-values"><span class="mr-big">4,77&nbsp;c/kWh</span></dd>
                    <dd class="mr-figure-note">−33&nbsp;% syyskuusta, −22&nbsp;% vuodentakaisesta. Alv 25,5&nbsp;%.</dd>
                </div>
                <div>
                    <dt>Kulutuksella painotettu hinta lokakuussa <span class="mr-figure-flag">Alustava 11.11. asti</span></dt>
                    <dd class="mr-figure-pairs">
                        <span class="mr-big">5,08&nbsp;c/kWh</span><span>Kerrostaloasunto, syyskuussa 7,46</span>
                        <span class="mr-big">4,81&nbsp;c/kWh</span><span>Sähkölämmitteinen pientalo, syyskuussa 7,11</span>
                    </dd>
                    <dd class="mr-figure-note mr-figure-note--after">Molemmat −32&nbsp;%. Kerrostalon kulutus painottuu kalliisiin iltatunteihin.</dd>
                </div>
                <div>
                    <dt>Sähkölämmitteisen pientalon lokakuun energialasku <span>Mediaanisopimukset, sähkövero mukana</span></dt>
                    <dd class="mr-figure-pairs">
                        <span class="mr-big">71&nbsp;€</span><span>Pörssisähköllä</span>
                        <span class="mr-big">121&nbsp;€</span><span>Määräaikaisella sopimuksella</span>
                    </dd>
                </div>
                <div>
                    <dt>Voltikan hintaindeksi <span>Myyjien asettamat energiahinnat</span></dt>
                    <dd class="mr-figure-values"><span class="mr-big">13,27&nbsp;c/kWh</span></dd>
                    <dd class="mr-figure-note mr-figure-note--high">+12,0&nbsp;% lokakuussa, +17,5&nbsp;% syyskuun alusta</dd>
                </div>
                <div>
                    <dt>12 kuukauden kiinteän hinnan mediaani 2.11.</dt>
                    <dd class="mr-figure-values"><span class="mr-big">12,00&nbsp;c/kWh</span></dd>
                    <dd class="mr-figure-note">Ennallaan lokakuussa.</dd>
                </div>
                <div>
                    <dt>Tuleva talvi, sähkölämmitteinen pientalo <span>Mediaanisopimukset, futuurit 30.10.</span></dt>
                    <dd class="mr-figure-pairs">
                        <span class="mr-big">150&nbsp;€</span><span>Kiinteä halvempi marras–maaliskuussa</span>
                        <span class="mr-big">75&nbsp;€</span><span>Pörssisähkö halvempi koko vuonna</span>
                    </dd>
                </div>
            </dl>
        </section>

        <section class="vk-lr-section mr-block" aria-labelledby="mika-vaikutti">
            <h3 id="mika-vaikutti" class="mr-sub-title">Mikä vaikutti sähkön hintaan</h3>
            <p class="vk-lr-text">Lämmin ja tuulinen sää painoi hinnan alas, vaikka lähes puolet ydinvoimasta oli huollossa.</p>
            <x-market-review.figure number="2">
                <div class="mr-chart-group" data-review-chart-data>
                    <ul class="mr-legend" aria-hidden="true">
                        <li><span class="mr-key" style="--key: var(--color-cat-spot)"></span>Pörssisähkön vuorokausikeskihinta, c/kWh</li>
                        <li><span class="mr-key mr-key--dash"></span>Kuukauden keskihinta {{ $fmt($daily['price_mean'], 2) }}</li>
                        <li><span class="mr-key" style="--key: var(--color-cat-fixed)"></span>Tuulivoima, GWh</li>
                        <li><span class="mr-key mr-key--split"></span>Lämpötila normaaliin verrattuna, °C: lämpimämpi / kylmempi</li>
                    </ul>
                    <div class="mr-chart-box mr-chart-box--daily">
                        <canvas data-review-chart="daily-drivers" role="img" aria-label="Pörssisähkön vuorokausikeskihinta lokakuussa 2026 sekä tuulivoiman tuotanto ja lämpötila normaaliin verrattuna samoina päivinä. Tuulisina ja lämpiminä päivinä hinta oli matala."></canvas>
                    </div>
                    <script type="application/json">{!! json_encode($daily, JSON_UNESCAPED_UNICODE) !!}</script>
                </div>
                <x-slot:table>
                    <table class="vk-lr-table">
                        <caption class="sr-only">Pörssisähkö, tuulivoima ja lämpötila päivittäin, lokakuu 2026</caption>
                        <thead>
                            <tr>
                                <th scope="col">Päivä</th>
                                <th scope="col" class="is-num">Pörssisähkö, c/kWh</th>
                                <th scope="col" class="is-num">Tuulivoima, GWh</th>
                                <th scope="col" class="is-num">Lämpötila normaaliin, °C</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($daily['labels'] as $i => $label)
                                <tr>
                                    <th scope="row">{{ $label }}</th>
                                    <td class="is-num">{{ $fmt($daily['price'][$i], 2) }}</td>
                                    <td class="is-num">{{ $fmt($daily['wind'][$i], 1) }}</td>
                                    <td class="is-num">{{ $signed($daily['temperature_anomaly'][$i]) }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </x-slot:table>
            </x-market-review.figure>
            <div class="mr-table-wrap">
                <table class="mr-factors">
                    <caption class="sr-only">Hintaan vaikuttaneet tekijät lokakuussa 2026</caption>
                    <thead>
                        <tr>
                            <th scope="col">Tekijä</th>
                            <th scope="col">Vaikutus</th>
                            <th scope="col">Selitys</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr>
                            <th scope="row">Tuulivoima</th>
                            <td class="mr-effect mr-effect--low">↓ Laski</td>
                            <td>Tuotanto oli 2,83&nbsp;TWh, 23&nbsp;% enemmän kuin vuotta aiemmin (kapasiteetti 9&nbsp;553&nbsp;MW).</td>
                        </tr>
                        <tr>
                            <th scope="row">Lämpötila</th>
                            <td class="mr-effect mr-effect--low">↓ Laski</td>
                            <td>Lokakuu oli 2,9 astetta normaalia (1991–2020) lämpimämpi, ja lämmitystarve oli 75&nbsp;% normaalista.</td>
                        </tr>
                        <tr>
                            <th scope="row">Ydinvoima</th>
                            <td class="mr-effect mr-effect--high">↑ Nosti</td>
                            <td>Käytettävyys oli 46,5&nbsp;%: Olkiluoto 3 oli vuosihuollossa koko kuun, Loviisa 1 ja 2 osan kuusta.</td>
                        </tr>
                        <tr>
                            <th scope="row">Tuonti ja vienti</th>
                            <td class="mr-effect">– Vähäinen</td>
                            <td>Nettotuonti oli pieni (0,39&nbsp;TWh). Suomen hinta (ilman veroa 3,80&nbsp;c/kWh) oli Pohjois-Ruotsin SE1:n (2,72) ja Etelä-Ruotsin SE3:n (7,35) välissä ja alempi kuin Virossa (8,21). Suomi vei sähköä Viroon 0,64&nbsp;TWh.</td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <p class="mr-dated"><span class="mr-dated-date">30.10.</span> <span>Kuun kallein päivä (18,6&nbsp;c/kWh): tuuli heikkeni, ja Olkiluoto 1 oli vuorokauden pois verkosta.</span></p>
        </section>

        <section class="vk-lr-section mr-block" aria-labelledby="sopimusmarkkina">
            <h3 id="sopimusmarkkina" class="mr-sub-title">Sopimusmarkkina</h3>
            <p class="mr-stance mr-stance--sub">Myyjät nostivat energiahintojaan, vaikka pörssisähkö halpeni lokakuussa kolmanneksen.</p>
            <p class="vk-lr-text">Voltikan hintaindeksi seuraa myyjien itse asettamia energiahintoja. Suurin osa lokakuun noususta tuli kuun vaihteessa, kun kvartaali- ja kuukausihintaisten sopimusten uudet hinnat alkoivat 1.10. Indeksi on nyt 36&nbsp;% korkeampi kuin toukokuun pohjalla (9,78&nbsp;c/kWh). Myyjät hinnoittelevat tulevaa talvea, eivät mennyttä kuukautta: tammi–maaliskuun futuuri nousi lokakuussa 6&nbsp;% ja vuoden 2027 futuuri 9&nbsp;%.</p>
            <x-market-review.figure number="3" caption="Myyjien asettamat energiahinnat päivittäin 30.9.–31.10.2026, c/kWh (alv 25,5 %). 1.10. puuttuu aineistosta.">
                <ul class="data-legend mr-index-legend" aria-hidden="true">
                    @foreach ($index['series'] as $i => $series)
                        <li><x-ink.series-line :label="$series['label']" :lead="$i === 0 ? 'var(--color-fox)' : null" /><span>{{ $series['label'] }}</span></li>
                    @endforeach
                </ul>
                <div
                    wire:ignore
                    data-line-chart="seller-set-index"
                    data-chart-skin="ink"
                    class="relative h-72 w-full select-none"
                    role="img"
                    aria-label="Viivakaavio: Voltikan hintaindeksi ja sen kolme sopimusryhmää päivittäin 30.9.–31.10.2026. Indeksi oli 30.9. 11,84 c/kWh ja 31.10. 13,27 c/kWh; kvartaali- ja kuukausihintaiset nousivat 1.10. 10,00:sta 13,78 c/kWh:iin."
                >
                    <script type="application/json">{!! json_encode($index, JSON_UNESCAPED_UNICODE) !!}</script>
                </div>
                <x-slot:table>
                    <table class="vk-lr-table">
                        <caption class="sr-only">Voltikan hintaindeksi päivittäin 30.9.–31.10.2026, c/kWh</caption>
                        <thead>
                            <tr>
                                <th scope="col">Päivä</th>
                                @foreach ($index['series'] as $series)
                                    <th scope="col" class="is-num">{{ $series['label'] }}</th>
                                @endforeach
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($index['x'] as $i => $timestamp)
                                <tr>
                                    <th scope="row">{{ \Illuminate\Support\Carbon::createFromTimestamp($timestamp, 'Europe/Helsinki')->format('j.n.') }}</th>
                                    @foreach ($index['series'] as $series)
                                        <td class="is-num">{{ $fmt($series['values'][$i], 2) }}</td>
                                    @endforeach
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </x-slot:table>
            </x-market-review.figure>
            <dl class="mr-families">
                <div class="mr-family-total">
                    <dt>Voltikan hintaindeksi</dt>
                    <dd class="mr-num mr-num--high">+12,0&nbsp;%</dd>
                    <dd>13,27&nbsp;c/kWh</dd>
                </div>
                <div>
                    <dt>Toistaiseksi voimassa</dt>
                    <dd class="mr-num mr-num--high">+10,5&nbsp;%</dd>
                    <dd>15,29&nbsp;c/kWh</dd>
                </div>
                <div>
                    <dt>Kvartaali- ja kuukausihintaiset</dt>
                    <dd class="mr-num mr-num--high">+45,4&nbsp;%</dd>
                    <dd>14,54&nbsp;c/kWh</dd>
                </div>
                <div>
                    <dt>Määräaikaiset</dt>
                    <dd class="mr-num">+1,2&nbsp;%</dd>
                    <dd>11,55&nbsp;c/kWh · nousivat jo syyskuussa</dd>
                </div>
            </dl>
            <p class="vk-lr-text">Pitkät sopimukset ovat lyhyitä halvempia. 24 kuukauden sopimusten mediaani nousi 10,70 sentistä 10,90 senttiin, ja 6 kuukauden mediaani on 14,90&nbsp;c/kWh. Syksyllä tehty 6 kuukauden sopimus kattaa lähes pelkkää talvea, ja 24 kuukauden sopimuksen hinnassa on mukana vuosi 2028, jonka futuuri (7,14&nbsp;c/kWh) on alempi kuin vuoden 2027 futuuri.</p>
        </section>

        <section class="vk-lr-section mr-block" aria-labelledby="ennusteet">
            <h3 id="ennusteet" class="mr-sub-title">Ennusteet</h3>
            <div class="mr-forecast">
                <p class="mr-caps mr-caps--spot">Pörssisähkö · futuurit 30.10.2026</p>
                <dl class="mr-forecast-values">
                    <div><dt>Marraskuu</dt><dd>11,33&nbsp;c</dd></div>
                    <div><dt>Tammi–maaliskuu</dt><dd class="mr-num--high">14,54&nbsp;c</dd></div>
                    <div><dt>Vuosi 2027</dt><dd>8,41&nbsp;c</dd></div>
                </dl>
                <p class="mr-case-text">Lokakuulle futuuri odotti 30.9. hintaa 10,29&nbsp;c/kWh, ja toteutunut hinta oli 54&nbsp;% halvempi; syyskuussa ero oli 27&nbsp;%. Marraskuun kuusi ensimmäistä päivää maksoivat keskimäärin 8,8&nbsp;c/kWh, ja kaksi ensimmäistä viikkoa ovat normaalia lämpimämpiä (ECMWF 1.11.), joten marraskuu jää futuuria halvemmaksi.</p>
            </div>
            <div class="mr-forecast">
                <p class="mr-caps mr-caps--fixed">Sopimushinnat</p>
                <dl class="mr-forecast-values">
                    <div class="mr-forecast-wide">
                        <dt>Määräaikaiset joulukuun alkuun</dt>
                        <dd>Ennallaan</dd>
                        <dd class="mr-forecast-note"><a href="/sahkosopimus/sahkon-hintaennuste" class="vk-focus">Voltikan hintaennuste</a></dd>
                    </div>
                    <div class="mr-forecast-wide">
                        <dt>Kvartaalisähkön mediaani 1.1.2027</dt>
                        <dd class="mr-num--high">13,12 → 16,9&nbsp;c</dd>
                        <dd class="mr-forecast-note">Tammi–maaliskuun futuuri on selvästi korkeampi kuin loka–joulukuun futuuri oli syyskuun lopussa.</dd>
                    </div>
                </dl>
            </div>
            <div class="mr-forecast">
                <p class="mr-caps">Tulossa</p>
                <ul class="mr-events">
                    <li><span class="mr-event-date">2.–7.11.</span> <span>Fenno-Skan 2 -yhteys Ruotsiin on rajoitettu.</span></li>
                    <li><span class="mr-event-date">11.11.</span> <span>Olkiluoto 3 palaa vuosihuollosta.</span></li>
                    <li><span class="mr-event-date">huhti 2027</span> <span>Olkiluoto 2:n teho on rajoitettu siihen asti.</span></li>
                </ul>
            </div>
        </section>

        {{-- 6 · Method and sources --}}
        <section class="mr-method" aria-labelledby="nain-laskimme">
            <h2 id="nain-laskimme" class="mr-sub-title">Näin laskimme</h2>
            <p class="mr-caps">Kotien sähkölaskut</p>
            <dl class="mr-method-rows">
                <div><dt>Mitä lasku sisältää</dt><dd>Sähköenergian ja sähköveron (2,918&nbsp;c/kWh alv mukaan lukien). Siirtomaksut eivät ole mukana.</dd></div>
                <div><dt>Kodit</dt><dd>Fingridin Datahub kertoo, kuinka paljon keskimääräinen kerrostaloasunto, pientalo ilman sähkölämmitystä ja sähkölämmitteinen pientalo kuluttavat sähköä kunakin tuntina: noin 2&nbsp;100, 6&nbsp;800 ja 11&nbsp;100&nbsp;kWh vuodessa. Jos kulutat enemmän, eurot kasvavat samassa suhteessa.</dd></div>
                <div><dt>Tulevat hinnat</dt><dd>Kunkin kuukauden hintataso on Suomen sähköfutuuri 30.10.2026. Tuntien vaihtelu tulee kolmesta menneestä talvesta: jokainen tuleva tunti saa saman päivän ja kellonajan hinnan ja kulutuksen menneeltä talvelta, siirrettynä futuurin tasolle. Kylmä talvi tarkoittaa talven 2023–24 säätä.</dd></div>
                <div><dt>Toinen ja kolmas vuosi</dt><dd>Futuurit kvartaaleittain syyskuuhun 2028 sekä vuosifutuurit 2028 ja 2029. Kulutus ja tuntien vaihtelu ovat samat kuin ensimmäisenä vuonna, ja kiinteä hinta pysyy samana koko sopimuskauden.</dd></div>
                <div><dt>Sopimukset</dt><dd>Tyypillinen pörssisähkö: marginaali 0,50&nbsp;c/kWh ja perusmaksu 4,12&nbsp;€/kk; tyypillinen 12 kuukauden kiinteä hinta 12,00&nbsp;c/kWh ja 4,89&nbsp;€/kk (mediaanit 2.11.). Edullisimmat: kaikki 2.11. koko maassa tarjolla olleet kotitaloussopimukset samalla laskentamoottorilla kuin Voltikan vertailu, normaalihinnoilla ilman kampanjaetuja.</dd></div>
                <div><dt>Kvartaalisähkö 1.1.</dt><dd>Uusi hinta on nykyinen hinta, johon lisätään tammi–maaliskuun futuurin ero siihen loka–joulukuun futuuriin, jolla nykyinen hinta asetettiin.</dd></div>
                <div><dt>Voltikan hintaindeksi</dt><dd>Koko maassa tarjolla olevien kiinteiden, toistaiseksi voimassa olevien sekä kvartaali- ja kuukausihintaisten sopimusten energiahintojen painotettu keskiarvo. Kukin myyjän sopimusryhmä on mukana yhdellä hinnalla, ja ryhmien painot ovat kiinteät. Pörssisähkö ei ole mukana.</dd></div>
            </dl>
            <p class="mr-caps mr-sources-title">Lähteet</p>
            <p class="mr-sources">Pörssisähkö: ENTSO-E Transparency Platform. Kulutus: Fingrid Datahub (aineisto 364) ja Fingridin avoin data (CC BY 4.0); lokakuun Datahub-luvut ovat alustavia noin 11.11. asti. Sää: <a href="https://open-meteo.com/" class="vk-focus" rel="noopener">Weather data by Open-Meteo.com</a> (ERA5, ECMWF). Vesi ja kaasu: NVE, GIE AGSI. Sopimukset: Voltikan päivittäin keräämät kotitaloussopimusten hinnat. Futuurit: EEX, Finnish Power Base -päätöskurssit, alv 25,5&nbsp;%. Sähkövero: Verohallinto. Julkaistut luvut on jäädytetty 6.11.2026.</p>
        </section>
    </article>
</div>

@push('scripts')
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    @vite(['resources/js/market-review-charts.js', 'resources/js/contract-price-statistics.js'])
@endpush
