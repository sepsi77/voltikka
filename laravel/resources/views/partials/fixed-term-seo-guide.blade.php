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
    $forecastRecommendation = match ($marketDirection['forecast']['tone'] ?? null) {
        'up' => 'Nousuennuste puoltaa hinnan lukitsemista nyt, kun haluat varman hinnan.',
        'down' => 'Laskuennusteen aikana lyhyt sopimus antaa mahdollisuuden kilpailuttaa hinta pian uudelleen.',
        'neutral' => 'Kun ennuste on vakaa, valitse kausi sen mukaan, kuinka pitkäksi aikaa haluat lukita hinnan.',
        default => null,
    };
    $formatSigned = static function (float $value, int $decimals = 2): string {
        if (abs($value) < 0.00001) {
            return number_format(0, $decimals, ',', ' ');
        }

        return ($value > 0 ? '+' : '−').number_format(abs($value), $decimals, ',', ' ');
    };
@endphp

<section class="mx-auto mt-12 max-w-5xl border-t border-slate-200 pt-10 text-base leading-7 text-slate-700">
    <h2 class="text-2xl font-bold tracking-tight text-slate-950 sm:text-3xl">{{ $guideVariant['heading'] }}</h2>
    <p class="mt-4 max-w-3xl">{{ $guideVariant['intro'] }}</p>

    @if ($sixMonthEndDate)
        <section class="mt-8 border-y border-slate-200 py-6" aria-labelledby="six-month-end-date-title">
            <h3 id="six-month-end-date-title" class="text-xl font-bold text-slate-950">Milloin nyt alkava 6 kuukauden sopimus päättyy?</h3>
            <p class="mt-2 max-w-3xl">
                Jos sopimus alkaa tänään, se päättyy noin <time datetime="{{ $sixMonthEndDate->toDateString() }}" class="font-bold text-slate-950">{{ $sixMonthEndDate->format('j.n.Y') }}</time>. Tarkka päivä näkyy tilausvahvistuksessa, koska sopimuskausi alkaa sovittuna päivänä.
            </p>
            @if ($sixMonthEndsInWinter)
                <p class="mt-3 max-w-3xl font-semibold text-slate-950">Uusiminen osuu talveen, jolloin uusi sopimus voi olla kalliimpi. Vertaa seuraava sopimus 1–2 kuukautta ennen nykyisen sopimuksen päättymistä.</p>
            @else
                <p class="mt-3 max-w-3xl font-semibold text-slate-950">Vertaa seuraava sopimus 1–2 kuukautta ennen nykyisen sopimuksen päättymistä.</p>
            @endif
        </section>
    @endif

    @if ($marketComparison !== [])
        <section class="mt-10" aria-labelledby="duration-cost-title">
            <h3 id="duration-cost-title" class="text-xl font-bold text-slate-950 sm:text-2xl">Miten sopimuskausi vaikuttaa hintaan?</h3>
            <p class="mt-2 max-w-3xl font-semibold text-slate-900">
                Täysin kiinteiden sopimusten mediaanihinta on matalin {{ $lowestMedianRow['duration_months'] }} kuukauden sopimuksissa: {{ number_format($lowestMedianRow['median'], 2, ',', ' ') }} c/kWh.
            </p>
            <p class="mt-1 max-w-3xl text-slate-600">
                Hinnat on poimittu samalta päivältä. Euroero ei sisällä kuukausimaksuja.
            </p>

            <div class="mt-5 border-y border-slate-200">
                @foreach ($marketComparison['rows'] as $row)
                    @php
                        $isExactPageRow = $guideVariant['duration'] === $row['duration_months'];
                        $isGeneralBaseline = $guideVariant['duration'] === null && $row['duration_months'] === 12;
                        $rangeWidth = $row['p80_percent'] - $row['p20_percent'];
                    @endphp
                    <div class="border-b border-slate-200 py-5 last:border-b-0 {{ $isExactPageRow || $isGeneralBaseline ? 'bg-slate-50' : '' }}">
                        <div class="grid gap-3 px-3 sm:grid-cols-[1.1fr_1fr_1fr_1fr] sm:items-start">
                            <div>
                                <a href="{{ $durationLinks[$row['duration_months']] }}" class="font-bold text-slate-950 underline decoration-slate-300 underline-offset-4 hover:decoration-slate-700">{{ $row['duration_months'] }} kuukautta</a>
                                @if ($isExactPageRow)
                                    <span class="ml-2 text-sm font-semibold text-coral-700">Tällä sivulla</span>
                                @elseif ($isGeneralBaseline)
                                    <span class="ml-2 text-sm font-semibold text-slate-700">Vertailukohta</span>
                                @endif
                                <p class="mt-1 text-sm text-slate-600">Sitoumus {{ $row['duration_months'] }} kk</p>
                            </div>
                            <div>
                                <p class="text-sm font-semibold text-slate-600">Täysin kiinteä mediaani</p>
                                <p class="font-bold tabular-nums text-slate-950">{{ number_format($row['median'], 2, ',', ' ') }} c/kWh</p>
                            </div>
                            <div>
                                <p class="text-sm font-semibold text-slate-600">Ero vertailukohtaan</p>
                                <p class="font-bold tabular-nums text-slate-950">{{ $formatSigned($row['difference_cents_per_kwh']) }} c/kWh</p>
                            </div>
                            <div>
                                <p class="text-sm font-semibold text-slate-600">Vaikutus vuodessa</p>
                                <p class="font-bold tabular-nums text-slate-950">{{ $formatSigned($row['annual_energy_cost_difference_eur'], 0) }} €</p>
                                <p class="text-sm text-slate-600">ennen kuukausimaksuja</p>
                            </div>
                        </div>
                        <div class="mt-4 px-3">
                            <div class="flex flex-wrap justify-between gap-2 text-sm text-slate-600">
                                <span>Hintaväli keskimmäiselle 60 prosentille tarjouksista</span>
                                <span class="tabular-nums">{{ number_format($row['p20'], 2, ',', ' ') }}–{{ number_format($row['p80'], 2, ',', ' ') }} c/kWh · {{ $row['contract_count'] }} sopimusta</span>
                            </div>
                            <div class="relative mt-2 h-2 bg-slate-100" aria-hidden="true">
                                <span class="absolute top-0 h-2 bg-slate-400" style="left: {{ number_format($row['p20_percent'], 4, '.', '') }}%; width: {{ number_format($rangeWidth, 4, '.', '') }}%"></span>
                                <span class="absolute -top-1 h-4 w-1 -translate-x-1/2 bg-slate-950" style="left: {{ number_format($row['median_percent'], 4, '.', '') }}%"></span>
                            </div>
                        </div>
                    </div>
                @endforeach
            </div>
            <p class="mt-3 text-sm text-slate-600">
                Laskettu kulutuksella <span class="font-semibold tabular-nums">{{ number_format($marketComparison['selected_consumption_kwh'], 0, ',', ' ') }} kWh/v</span>. Hinnat ovat päivältä <time datetime="{{ $marketComparison['date'] }}">{{ \Carbon\CarbonImmutable::parse($marketComparison['date'])->format('j.n.Y') }}</time>. Taulukossa ovat vain täysin kiinteähintaiset sopimukset.
            </p>
        </section>
    @endif

    @if ($mechanismSummary)
        <section class="mt-10" aria-labelledby="mechanism-title">
            <h3 id="mechanism-title" class="text-xl font-bold text-slate-950 sm:text-2xl">Onko listan halvin sopimus kiinteähintainen?</h3>
            <p class="mt-2 font-semibold text-slate-900">{{ $cheapestStatements[$mechanismSummary['cheapest_category']] }}</p>
            <p class="mt-1 max-w-3xl text-slate-600">Pelkkä sopimuskausi ei kerro, onko hinta kiinteä. Taulukossa ovat kaikki hakua vastaavat sopimukset, eivät vain tällä sivulla näkyvät.</p>

            <div class="mt-5 overflow-x-auto border-y border-slate-200">
                <table class="w-full min-w-[42rem] text-left">
                    <thead class="border-b border-slate-200 bg-slate-50 text-sm text-slate-600">
                        <tr>
                            <th scope="col" class="px-3 py-3 font-semibold">Hinnoittelutapa</th>
                            <th scope="col" class="px-3 py-3 text-right font-semibold">Sopimuksia</th>
                            <th scope="col" class="px-3 py-3 text-right font-semibold">Matalin 12 kk vertailu</th>
                            <th scope="col" class="px-3 py-3 text-right font-semibold">Keskimäärin kuukaudessa</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-200">
                        @foreach ($mechanismSummary['groups'] as $group)
                            <tr>
                                <th scope="row" class="px-3 py-4 font-semibold text-slate-950">
                                    @if ($categoryLinks[$group['category']])
                                        <a href="{{ $categoryLinks[$group['category']] }}" class="underline decoration-slate-300 underline-offset-4 hover:decoration-slate-700">{{ $group['label'] }}</a>
                                    @else
                                        {{ $group['label'] }}
                                    @endif
                                    @if ($group['category'] === 'consumption_effect')
                                        <span class="mt-1 block text-sm font-normal text-slate-600">Hinta ennen kulutusvaikutusta</span>
                                    @endif
                                </th>
                                <td class="px-3 py-4 text-right tabular-nums">{{ $group['count'] }}</td>
                                <td class="px-3 py-4 text-right font-semibold tabular-nums text-slate-950">
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
            <p class="mt-3 text-sm text-slate-600">Vertailu käyttää valittua kulutusta {{ number_format($mechanismSummary['selected_consumption_kwh'], 0, ',', ' ') }} kWh/v. Markkinahintaisen tuotteen 12 kuukauden luku on arvio, ei lukittu energiahinta.</p>
        </section>
    @endif

    @if ($marketDirection !== [])
        <section class="mt-10" aria-labelledby="market-direction-title">
            <h3 id="market-direction-title" class="text-xl font-bold text-slate-950 sm:text-2xl">Ovatko hinnat nousussa vai laskussa?</h3>
            <p class="mt-2 max-w-3xl text-slate-600">Katso toteutunut 30 päivän muutos ja {{ $marketDirection['duration_months'] }} kuukauden sopimusten ennuste.</p>

            <div class="mt-5 border-y border-slate-200 divide-y divide-slate-200">
                @if ($marketDirection['trend'])
                    <div class="grid gap-2 px-3 py-5 sm:grid-cols-[1fr_auto] sm:items-start">
                        <div>
                            <p class="font-bold text-slate-950">30 päivän toteutunut hintakehitys</p>
                            <p class="mt-1 text-slate-600">{{ $marketDirection['trend']['headline'] }}.</p>
                        </div>
                        <div class="sm:text-right">
                            <p class="font-bold tabular-nums text-slate-950">{{ $marketDirection['trend']['change_label'] }}</p>
                            <p class="text-sm text-slate-600">{{ number_format($marketDirection['trend']['latest_value'], 2, ',', ' ') }} c/kWh · <time datetime="{{ $marketDirection['trend']['as_of'] }}">{{ \Carbon\CarbonImmutable::parse($marketDirection['trend']['as_of'])->format('j.n.Y') }}</time></p>
                        </div>
                    </div>
                @endif

                @if ($marketDirection['forecast'])
                    <div class="grid gap-3 px-3 py-5 sm:grid-cols-[1fr_auto] sm:items-start">
                        <div>
                            <p class="font-bold text-slate-950">Ennuste {{ $marketDirection['forecast']['horizon_days'] }} päivän päähän: {{ mb_strtolower($marketDirection['forecast']['direction_label']) }}</p>
                            <p class="mt-1 text-slate-600">
                                Mediaani {{ number_format($marketDirection['forecast']['current_price_cents_per_kwh'], 2, ',', ' ') }} → {{ number_format($marketDirection['forecast']['forecast_price_cents_per_kwh'], 2, ',', ' ') }} c/kWh. Ennuste perustuu {{ $marketDirection['forecast']['contract_count'] }} sopimukseen.
                            </p>
                        </div>
                        <div class="sm:text-right">
                            <p class="font-bold tabular-nums text-slate-950">{{ $formatSigned($marketDirection['forecast']['expected_change_cents_per_kwh']) }} c/kWh</p>
                            <p class="font-semibold tabular-nums text-slate-900">{{ $formatSigned($marketDirection['forecast']['annual_energy_rate_change_eur'], 0) }} €/vuosi</p>
                            <p class="text-sm text-slate-600"><time datetime="{{ $marketDirection['forecast']['forecast_date'] }}">{{ \Carbon\CarbonImmutable::parse($marketDirection['forecast']['forecast_date'])->format('j.n.Y') }}</time></p>
                        </div>
                    </div>
                @endif
            </div>
            @if ($forecastRecommendation)
                <p class="mt-3 max-w-3xl font-semibold text-slate-950">{{ $forecastRecommendation }}</p>
            @endif
            <p class="mt-2 text-sm text-slate-600">
                Ennuste koskee energiahintaa. Euroarvo näyttää ennustetun muutoksen kulutuksella {{ number_format($marketDirection['selected_consumption_kwh'], 0, ',', ' ') }} kWh/v. Kuukausimaksu ja muut sähkölaskun erät eivät sisälly. <a href="/sahkosopimus/sahkon-hintaennuste" class="font-semibold text-coral-600 underline underline-offset-2 hover:text-coral-700">Katso ennusteen perusteet</a>.
            </p>
        </section>
    @endif

    <section class="mt-10 border-y border-slate-200 py-6" aria-labelledby="commitment-note-title">
        <h3 id="commitment-note-title" class="text-xl font-bold text-slate-950">Mitä sopimuskausi tarkoittaa?</h3>
        <p class="mt-2 max-w-3xl">{{ $guideVariant['note'] }} <a href="{{ $guideVariant['note_link'][0] }}" class="font-semibold text-coral-600 underline underline-offset-2 hover:text-coral-700">{{ $guideVariant['note_link'][1] }}</a>.</p>
    </section>

    <section class="mt-10" aria-labelledby="before-order-title">
        <h3 id="before-order-title" class="text-xl font-bold text-slate-950">Tarkista ennen tilausta</h3>
        <ul class="mt-3 list-disc space-y-2 pl-6">
            <li>sopimuskauden alku, loppu ja jatko kauden jälkeen</li>
            <li>energian hinnoittelutapa ja muuttuvat hinnanosat</li>
            <li>kuukausimaksu, alennusten kesto, kulutusrajat ja verot</li>
        </ul>
        <p class="mt-3 max-w-3xl">Määräaikainen sopimus sitoo tavallisesti koko sopimuskauden. Myyjän ehdoissa kerrotaan, miten muutto ja poikkeuksellinen päättäminen käsitellään.</p>
    </section>

    <section class="mt-10" aria-labelledby="fixed-term-faq-title">
        <h3 id="fixed-term-faq-title" class="text-xl font-bold text-slate-950">Usein kysyttyä</h3>
        <div class="mt-4 divide-y divide-slate-200 border-y border-slate-200">
            @foreach ($guideVariant['faqs'] as [$question, $answer])
                <div class="py-5" data-fixed-term-faq-item>
                    <p class="font-bold text-slate-950">{{ $question }}</p>
                    <p class="mt-1">{{ $answer }}</p>
                </div>
            @endforeach
        </div>
    </section>
</section>
