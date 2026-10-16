@php
    $guideVariant = match ($fixedTimeRange) {
        'Fixed6' => [
            'heading' => '6 kuukauden määräaikainen sähkösopimus käytännössä',
            'intro' => 'Puolen vuoden sopimus pitää sitoutumisen lyhyenä. Tarkista silti hinnoittelutapa: määräaikainen sopimus ei ole aina täysin kiinteähintainen.',
            'duration' => 6,
            'note' => 'Sopimuskortin 12 kuukauden vertailussa 6 kuukauden hinta jatkuu laskennallisesti koko vuoden. Itse sopimus päättyy 6 kuukauden kuluttua, joten tarvitset sen jälkeen uuden sopimuksen. Vertaa 12 kuukauden vaihtoehtoa, jos haluat yhden sopimuksen koko vuodeksi.',
            'note_link' => ['/sahkosopimus/maaraaikainen-12-kk', 'Vertaa 12 kuukauden sopimuksia'],
            'faqs' => [
                ['Onko 6 kuukauden määräaikainen aina kiinteähintainen?', 'Kuusi kuukautta kertoo sopimuksen keston. Valitse täysin kiinteä sopimus, jos et halua kulutusvaikutusta tai markkinan mukaan muuttuvaa hintaa.'],
                ['Milloin 6 kuukauden sopimus kannattaa valita?', 'Valitse se, kun haluat lyhyen sitoumuksen ja olet valmis kilpailuttamaan seuraavan sopimuksen jo muutaman kuukauden kuluttua.'],
                ['Mitä tapahtuu kuuden kuukauden jälkeen?', 'Tarkista tilausvahvistuksesta, päättyykö sopimus vai jatkuuko se toisena tuotteena. Kilpailuta jatko ennen määräajan loppua.'],
            ],
        ],
        'Fixed12' => [
            'heading' => '12 kuukauden määräaikainen sähkösopimus käytännössä',
            'intro' => 'Vuoden sopimus kattaa yhden talven ja kesän ilman kahden vuoden sitoumusta. Tarkista hinnoittelutapa erikseen.',
            'duration' => 12,
            'note' => '12 kuukauden sopimus sopii sinulle, jos haluat yhden sopimuksen kaikkien vuodenaikojen yli mutta et halua sitoutua kahdeksi vuodeksi.',
            'note_link' => ['/sahkosopimus/tilastot', 'Katso sähkösopimusten hintatilastot'],
            'faqs' => [
                ['Pysyykö sähkölasku samana koko vuoden?', 'Kiinteä energian yksikköhinta ei tee laskusta samansuuruista. Maksat enemmän niinä kuukausina, jolloin käytät enemmän sähköä.'],
                ['Onko 12 kuukauden sopimus aina täysin kiinteä?', 'Valitse täysin kiinteä hinnoittelutapa, jos et halua kulutusvaikutusta tai markkinan mukaan muuttuvaa hintaa.'],
                ['Milloin seuraavaa sopimusta kannattaa alkaa verrata?', 'Aloita vertailu 1–2 kuukautta ennen sopimuskauden loppua. Silloin ehdit tarkistaa hinnat ja jatkoehdot rauhassa.'],
            ],
        ],
        'Fixed24' => [
            'heading' => '24 kuukauden määräaikainen sähkösopimus käytännössä',
            'intro' => 'Kahden vuoden sopimus sopii parhaiten sinulle, jos arvostat hinnan ennakoitavuutta enemmän kuin mahdollisuutta hyötyä myöhemmin laskevista hinnoista.',
            'duration' => 24,
            'note' => 'Sopimuskortin kustannusarvio kattaa 12 kuukautta, mutta sitoumus kestää 24 kuukautta. Tarkista siksi myös toisen vuoden hinnat, maksut ja alennusten päättymispäivät.',
            'note_link' => ['/sahkosopimus/maaraaikainen-12-kk', 'Vertaa 12 kuukauden sopimuksia'],
            'faqs' => [
                ['Kattaako Voltikan vuosiarvio koko 24 kuukauden kauden?', 'Sopimuskortin arvio kattaa 12 kuukautta. Sopimus sitoo silti 24 kuukaudeksi.'],
                ['Milloin 24 kuukauden sopimus kannattaa valita?', 'Valitse se, kun pitkä hintavarmuus on sinulle tärkeämpi kuin mahdollisuus kilpailuttaa sopimus jo vuoden kuluttua.'],
                ['Miten vertaan pitkää sopimuskautta?', 'Vertaa 24 kuukauden hintaa 6 ja 12 kuukauden vaihtoehtoihin. Tarkista myös, pysyvätkö hinta ja kuukausimaksu samoina toisena vuonna.'],
            ],
        ],
        default => [
            'heading' => 'Määräaikaisen sähkösopimuksen valintaopas',
            'intro' => 'Valitse sopimuskausi sen mukaan, kuinka pitkäksi aikaa haluat sitoutua. Valitse hinnoittelutapa erikseen: määräaikainen sopimus voi olla täysin kiinteä, kulutusvaikutuksellinen tai markkinahintainen.',
            'duration' => null,
            'note' => 'Valitse 6 kuukautta lyhyeen sitoumukseen, 12 kuukautta yhden vuoden tarpeeseen ja 24 kuukautta pitkään hintavarmuuteen. Sopimuskortin kustannusarvio kattaa aina 12 kuukautta, vaikka sopimuskausi olisi lyhyempi tai pidempi.',
            'note_link' => ['/sahkosopimus/maaraaikainen-12-kk', 'Vertaa 12 kuukauden sopimuksia'],
            'faqs' => [
                ['Onko määräaikainen sopimus aina kiinteähintainen?', 'Valitse täysin kiinteä hinnoittelutapa, jos haluat lukita energian hinnan. Kulutusvaikutuksellisen ja markkinahintaisen sopimuksen hinta voi muuttua.'],
                ['Pysyykö lasku samana, jos energiahinta on kiinteä?', 'Lasku muuttuu kulutuksen mukaan. Kiinteä c/kWh-hinta lukitsee energian yksikköhinnan, ei kuukausittaista sähkön käyttöä.'],
                ['Voiko määräaikaisen sopimuksen päättää kesken kauden?', 'Määräaikainen sopimus sitoo tavallisesti koko sopimuskauden. Myyjän ehdoissa kerrotaan, miten muutto ja poikkeuksellinen päättäminen käsitellään.'],
            ],
        ],
    };
    $marketComparison = $fixedTermComparison ?? [];
    $mechanismSummary = $fixedTermMechanismSummary ?? null;
    $marketDirection = $fixedTermMarketDirection ?? [];
    $durationLinks = [
        6 => '/sahkosopimus/maaraaikainen-6-kk',
        12 => '/sahkosopimus/maaraaikainen-12-kk',
        24 => '/sahkosopimus/maaraaikainen-24-kk',
    ];
    $categoryLinks = [
        'fixed' => '/sahkosopimus/kiintea-hinta',
        'consumption_effect' => '/sahkosopimus/kulutusvaikutus',
        'market' => null,
    ];
    $cheapestStatements = [
        'fixed' => 'Listan halvin sopimus on täysin kiinteähintainen.',
        'consumption_effect' => 'Listan halvin sopimus on kulutusvaikutuksellinen.',
        'market' => 'Listan halvin sopimus on markkinahintainen.',
    ];
    $sixMonthEndDate = $guideVariant['duration'] === 6
        ? \Carbon\CarbonImmutable::now('Europe/Helsinki')->addMonthsNoOverflow(6)
        : null;
    $sixMonthEndsInWinter = $sixMonthEndDate !== null
        && in_array($sixMonthEndDate->month, [11, 12, 1, 2, 3], true);
    $lowestMedianRow = $marketComparison !== []
        ? collect($marketComparison['rows'])->sortBy('median')->first()
        : null;
    $forecastRecommendation = $marketDirection['forecast']['detail'] ?? null;
    $formatSigned = static function (float $value, int $decimals = 2): string {
        if (abs($value) < 0.00001) {
            return number_format(0, $decimals, ',', ' ');
        }

        return ($value > 0 ? '+' : '−').number_format(abs($value), $decimals, ',', ' ');
    };
@endphp

{{-- Fixed-term guide (redesign): display headings with the wavy fox underline, inked
     hairlines between rows, big numbers on the page, plain range bars. No cards or tints. --}}
<section class="vk-guide max-w-[1000px] pt-10 text-base leading-7 text-ink-body" aria-labelledby="fixed-term-guide-title">
    <x-site.section-heading id="fixed-term-guide-title">{{ $guideVariant['heading'] }}</x-site.section-heading>
    <p class="mt-5 max-w-3xl">{{ $guideVariant['intro'] }}</p>

    @if ($sixMonthEndDate)
        <section class="vk-guide-note mt-8" aria-labelledby="six-month-end-date-title">
            <h3 id="six-month-end-date-title" class="vk-guide-h3">Milloin nyt alkava 6 kuukauden sopimus päättyy?</h3>
            <p class="mt-2 max-w-3xl">
                Jos sopimus alkaa tänään, se päättyy noin <time datetime="{{ $sixMonthEndDate->toDateString() }}" class="font-extrabold text-ink">{{ $sixMonthEndDate->format('j.n.Y') }}</time>. Tarkka päivä näkyy tilausvahvistuksessa, koska sopimuskausi alkaa sovittuna päivänä.
            </p>
            @if ($sixMonthEndsInWinter)
                <p class="mt-3 max-w-3xl font-bold text-ink">Uusiminen osuu talveen, jolloin uusi sopimus voi olla kalliimpi. Vertaa seuraava sopimus 1–2 kuukautta ennen nykyisen sopimuksen päättymistä.</p>
            @else
                <p class="mt-3 max-w-3xl font-bold text-ink">Vertaa seuraava sopimus 1–2 kuukautta ennen nykyisen sopimuksen päättymistä.</p>
            @endif
        </section>
    @endif

    @if ($marketComparison !== [])
        <section class="mt-12" aria-labelledby="duration-cost-title">
            <x-site.section-heading id="duration-cost-title" level="3" size="sub">Miten sopimuskausi vaikuttaa hintaan?</x-site.section-heading>
            <p class="mt-4 max-w-3xl font-bold text-ink">
                Täysin kiinteiden sopimusten mediaanihinta on matalin {{ $lowestMedianRow['duration_months'] }} kuukauden sopimuksissa: {{ number_format($lowestMedianRow['median'], 2, ',', ' ') }} c/kWh.
            </p>
            <p class="mt-1 max-w-3xl text-ink-body">
                Hinnat on poimittu samalta päivältä. Euroero ei sisällä kuukausimaksuja.
            </p>

            <div class="vk-rows mt-5">
                @foreach ($marketComparison['rows'] as $row)
                    @php
                        $isExactPageRow = $guideVariant['duration'] === $row['duration_months'];
                        $isGeneralBaseline = $guideVariant['duration'] === null && $row['duration_months'] === 12;
                        $rangeWidth = $row['p80_percent'] - $row['p20_percent'];
                    @endphp
                    <div class="py-5">
                        <div class="grid gap-3 px-3 sm:grid-cols-[1.1fr_1fr_1fr_1fr] sm:items-start">
                            <div>
                                <a href="{{ $durationLinks[$row['duration_months']] }}" class="vk-focus font-display text-[22px] font-extrabold leading-[26px] text-ink underline decoration-[color:var(--color-edge-strong)] decoration-1 underline-offset-4 hover:text-fox-deep">{{ $row['duration_months'] }} kuukautta</a>
                                @if ($isExactPageRow)
                                    <span class="vk-kicker ml-1 align-middle"><x-ink.sparkle size="11" color="var(--color-fox)" />Tällä sivulla</span>
                                @elseif ($isGeneralBaseline)
                                    <span class="vk-kicker ml-1 align-middle !text-ink-body"><x-ink.sparkle size="11" color="var(--color-ink-muted)" />Vertailukohta</span>
                                @endif
                                <p class="mt-1 text-sm text-ink-muted">Sitoumus {{ $row['duration_months'] }} kk</p>
                            </div>
                            <div>
                                <p class="text-sm font-bold text-ink-muted">Täysin kiinteä mediaani</p>
                                <p class="font-display text-[22px] font-extrabold leading-[28px] tabular-nums text-ink">{{ number_format($row['median'], 2, ',', ' ') }} c/kWh</p>
                            </div>
                            <div>
                                <p class="text-sm font-bold text-ink-muted">Ero vertailukohtaan</p>
                                <p class="font-display text-[22px] font-extrabold leading-[28px] tabular-nums text-ink">{{ $formatSigned($row['difference_cents_per_kwh']) }} c/kWh</p>
                            </div>
                            <div>
                                <p class="text-sm font-bold text-ink-muted">Vaikutus vuodessa</p>
                                <p class="font-display text-[22px] font-extrabold leading-[28px] tabular-nums text-ink">{{ $formatSigned($row['annual_energy_cost_difference_eur'], 0) }} €</p>
                                <p class="text-sm text-ink-muted">ennen kuukausimaksuja</p>
                            </div>
                        </div>
                        <div class="mt-4 px-3">
                            <div class="flex flex-wrap justify-between gap-2 text-sm text-ink-body">
                                <span>Hintaväli keskimmäiselle 60 prosentille tarjouksista</span>
                                <span class="tabular-nums">{{ number_format($row['p20'], 2, ',', ' ') }}–{{ number_format($row['p80'], 2, ',', ' ') }} c/kWh · {{ $row['contract_count'] }} sopimusta</span>
                            </div>
                            <div class="vk-range mt-2" aria-hidden="true">
                                <span class="vk-range-band" style="left: {{ number_format($row['p20_percent'], 4, '.', '') }}%; width: {{ number_format($rangeWidth, 4, '.', '') }}%"></span>
                                <span class="vk-range-median" style="left: {{ number_format($row['median_percent'], 4, '.', '') }}%"></span>
                            </div>
                        </div>
                    </div>
                @endforeach
            </div>
            <p class="mt-3 text-sm text-ink-muted">
                Laskettu kulutuksella <span class="font-bold tabular-nums text-ink-body">{{ number_format($marketComparison['selected_consumption_kwh'], 0, ',', ' ') }} kWh/v</span>. Hinnat ovat päivältä <time datetime="{{ $marketComparison['date'] }}">{{ \Carbon\CarbonImmutable::parse($marketComparison['date'])->format('j.n.Y') }}</time>. Taulukossa ovat vain täysin kiinteähintaiset sopimukset.
            </p>
        </section>
    @endif

    @if ($mechanismSummary)
        <section class="mt-12" aria-labelledby="mechanism-title">
            <x-site.section-heading id="mechanism-title" level="3" size="sub">Onko listan halvin sopimus kiinteähintainen?</x-site.section-heading>
            <p class="mt-4 font-bold text-ink">{{ $cheapestStatements[$mechanismSummary['cheapest_category']] }}</p>
            <p class="mt-1 max-w-3xl text-ink-body">Pelkkä sopimuskausi ei kerro, onko hinta kiinteä. Taulukossa ovat kaikki hakua vastaavat sopimukset, eivät vain tällä sivulla näkyvät.</p>

            <div class="mt-5 overflow-x-auto border-y-[1.5px] border-[color:var(--color-edge)]">
                <table class="w-full min-w-[42rem] text-left">
                    <thead class="border-b-[1.5px] border-[color:var(--color-edge)] text-sm text-ink">
                        <tr>
                            <th scope="col" class="px-3 py-3 font-extrabold">Hinnoittelutapa</th>
                            <th scope="col" class="px-3 py-3 text-right font-extrabold">Sopimuksia</th>
                            <th scope="col" class="px-3 py-3 text-right font-extrabold">Matalin 12 kk vertailu</th>
                            <th scope="col" class="px-3 py-3 text-right font-extrabold">Keskimäärin kuukaudessa</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-[color:var(--color-edge)]">
                        @foreach ($mechanismSummary['groups'] as $group)
                            <tr>
                                <th scope="row" class="px-3 py-4 font-extrabold text-ink">
                                    @if ($categoryLinks[$group['category']])
                                        <a href="{{ $categoryLinks[$group['category']] }}" class="vk-focus underline decoration-[color:var(--color-edge-strong)] underline-offset-4 hover:text-fox-deep">{{ $group['label'] }}</a>
                                    @else
                                        {{ $group['label'] }}
                                    @endif
                                    @if ($group['category'] === 'consumption_effect')
                                        <span class="mt-1 block text-sm font-normal text-ink-muted">Hinta ennen kulutusvaikutusta</span>
                                    @endif
                                </th>
                                <td class="px-3 py-4 text-right tabular-nums">{{ $group['count'] }}</td>
                                <td class="px-3 py-4 text-right font-display text-lg font-extrabold tabular-nums text-ink">
                                    {{ $group['lowest_annual_comparison_eur'] !== null ? number_format($group['lowest_annual_comparison_eur'], 0, ',', ' ').' €' : 'Ei vertailulukua' }}
                                </td>
                                <td class="px-3 py-4 text-right tabular-nums">
                                    {{ $group['monthly_equivalent_eur'] !== null ? number_format($group['monthly_equivalent_eur'], 1, ',', ' ').' €/kk' : 'Ei vertailulukua' }}
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            <p class="mt-3 text-sm text-ink-muted">Vertailu käyttää valittua kulutusta {{ number_format($mechanismSummary['selected_consumption_kwh'], 0, ',', ' ') }} kWh/v. Markkinahintaisen tuotteen 12 kuukauden luku on arvio, ei lukittu energiahinta.</p>
        </section>
    @endif

    @if ($marketDirection !== [])
        <section class="mt-12" aria-labelledby="market-direction-title">
            <x-site.section-heading id="market-direction-title" level="3" size="sub">Ovatko hinnat nousussa vai laskussa?</x-site.section-heading>
            <p class="mt-4 max-w-3xl text-ink-body">Katso toteutunut 30 päivän muutos ja {{ $marketDirection['duration_months'] }} kuukauden sopimusten ennuste.</p>

            <div class="vk-rows mt-5">
                @if ($marketDirection['trend'])
                    <div class="grid gap-2 px-3 py-5 sm:grid-cols-[1fr_auto] sm:items-start">
                        <div>
                            <p class="font-extrabold text-ink">30 päivän toteutunut hintakehitys</p>
                            <p class="mt-1 text-ink-body">{{ $marketDirection['trend']['headline'] }}.</p>
                        </div>
                        <div class="sm:text-right">
                            <p class="font-display text-[22px] font-extrabold leading-[28px] tabular-nums text-ink">{{ $marketDirection['trend']['change_label'] }}</p>
                            <p class="text-sm text-ink-muted">{{ number_format($marketDirection['trend']['latest_value'], 2, ',', ' ') }} c/kWh · <time datetime="{{ $marketDirection['trend']['as_of'] }}">{{ \Carbon\CarbonImmutable::parse($marketDirection['trend']['as_of'])->format('j.n.Y') }}</time></p>
                        </div>
                    </div>
                @endif

                @if ($marketDirection['forecast'])
                    <div class="grid gap-3 px-3 py-5 sm:grid-cols-[1fr_auto] sm:items-start">
                        <div>
                            <p class="font-extrabold text-ink">Ennuste {{ $marketDirection['forecast']['horizon_days'] }} päivän päähän: {{ mb_strtolower($marketDirection['forecast']['direction_label']) }}</p>
                            <p class="mt-1 text-ink-body">
                                Mediaani {{ number_format($marketDirection['forecast']['current_price_cents_per_kwh'], 2, ',', ' ') }} → {{ number_format($marketDirection['forecast']['forecast_price_cents_per_kwh'], 2, ',', ' ') }} c/kWh. Ennuste perustuu {{ $marketDirection['forecast']['contract_count'] }} sopimukseen.
                            </p>
                        </div>
                        <div class="sm:text-right">
                            <p class="font-display text-[22px] font-extrabold leading-[28px] tabular-nums text-ink">{{ $formatSigned($marketDirection['forecast']['expected_change_cents_per_kwh']) }} c/kWh</p>
                            <p class="font-bold tabular-nums text-ink">{{ $formatSigned($marketDirection['forecast']['annual_energy_rate_change_eur'], 0) }} €/vuosi</p>
                            <p class="text-sm text-ink-muted"><time datetime="{{ $marketDirection['forecast']['forecast_date'] }}">{{ \Carbon\CarbonImmutable::parse($marketDirection['forecast']['forecast_date'])->format('j.n.Y') }}</time></p>
                        </div>
                    </div>
                @endif
            </div>
            @if ($forecastRecommendation)
                <p class="mt-3 max-w-3xl font-bold text-ink">{{ $forecastRecommendation }}</p>
            @endif
            <p class="mt-2 max-w-3xl text-sm text-ink-muted">
                Ennuste koskee energiahintaa. Euroarvo on ennustetun energiahinnan muutoksen vuosivastaavuus, ei odottamisesta saatava säästö. Se on laskettu kulutuksella {{ number_format($marketDirection['selected_consumption_kwh'], 0, ',', ' ') }} kWh/v. Kuukausimaksu ja muut sähkölaskun erät eivät sisälly. <a href="/sahkosopimus/sahkon-hintaennuste" class="vk-text-link">Katso ennusteen perusteet</a>.
            </p>
        </section>
    @endif

    <section class="vk-guide-note mt-12" aria-labelledby="commitment-note-title">
        <h3 id="commitment-note-title" class="vk-guide-h3">Mitä sopimuskausi tarkoittaa?</h3>
        <p class="mt-2 max-w-3xl">{{ $guideVariant['note'] }} <a href="{{ $guideVariant['note_link'][0] }}" class="vk-text-link">{{ $guideVariant['note_link'][1] }}</a>.</p>
    </section>

    <section class="mt-12" aria-labelledby="before-order-title">
        <x-site.section-heading id="before-order-title" level="3" size="sub">Tarkista ennen tilausta</x-site.section-heading>
        <ul class="vk-guide-list mt-4 space-y-2">
            <li>sopimuskauden alku, loppu ja jatko kauden jälkeen</li>
            <li>energian hinnoittelutapa ja muuttuvat hinnanosat</li>
            <li>kuukausimaksu, alennusten kesto, kulutusrajat ja verot</li>
        </ul>
        <p class="mt-3 max-w-3xl">Määräaikainen sopimus sitoo tavallisesti koko sopimuskauden. Myyjän ehdoissa kerrotaan, miten muutto ja poikkeuksellinen päättäminen käsitellään.</p>
    </section>

    <section class="mt-12" aria-labelledby="fixed-term-faq-title">
        <x-site.section-heading id="fixed-term-faq-title" level="3" size="sub">Usein kysyttyä</x-site.section-heading>
        <div class="vk-rows mt-5">
            @foreach ($guideVariant['faqs'] as [$question, $answer])
                <div class="py-5" data-fixed-term-faq-item>
                    <p class="text-lg font-extrabold leading-6 text-ink">{{ $question }}</p>
                    <p class="mt-1 max-w-[680px]">{{ $answer }}</p>
                </div>
            @endforeach
        </div>
    </section>
</section>
