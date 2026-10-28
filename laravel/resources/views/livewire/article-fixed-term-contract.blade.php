@php
    use Illuminate\Support\Carbon;

    $current = $articleData['current'] ?? [];
    $annual = $articleData['annual_comparison'] ?? [];
    $history = $articleData['history'] ?? [];
    $forecast = $articleData['forecast'] ?? [];
    $currentRows = collect($current['rows'] ?? []);
    $currentRowsBySegment = $currentRows->keyBy('segment_key');
    $annualRows = collect($annual['rows'] ?? []);
    $annualRowsBySegment = $annualRows->keyBy('segment_key');
    $comparisons = collect($annual['comparisons'] ?? []);
    $spotComparison = $comparisons->get('spot', []);
    $openEndedComparison = $comparisons->get('open_ended', []);
    $resetComparison = $comparisons->get('market_reset', ['state' => 'unavailable']);
    $quarterlyComparison = $comparisons->get('quarterly', ['state' => 'unavailable']);
    $hybridComparison = $comparisons->get('hybrid', ['state' => 'base_only']);
    $fixedTwelveAnnual = $annualRowsBySegment->get('fixed_term_12');
    $summaryMonthlyDifferences = collect([$spotComparison, $openEndedComparison])
        ->filter(fn ($comparison) => ($comparison['state'] ?? null) === 'complete')
        ->map(fn ($comparison) => abs((float) $comparison['median_difference_monthly_eur']));
    $largestSummaryMonthlyDifference = $summaryMonthlyDifferences->isEmpty()
        ? null
        : $summaryMonthlyDifferences->max();
    $historySeries = collect($history['series'] ?? []);
    $historyChart = $history['chart'] ?? ['labels' => [], 'datasets' => []];
    $historyDirections = $historySeries->pluck('summary.direction')->filter()->values();
    $historyLead = match (true) {
        $historyDirections->isNotEmpty() && $historyDirections->every(fn ($direction) => $direction === 'rose') => 'Kaikkien vertailtujen sopimusaikojen mediaanihinta nousi vertailujakson aikana.',
        $historyDirections->isNotEmpty() && $historyDirections->every(fn ($direction) => $direction === 'fell') => 'Kaikkien vertailtujen sopimusaikojen mediaanihinta laski vertailujakson aikana.',
        $historyDirections->isNotEmpty() && $historyDirections->every(fn ($direction) => $direction === 'stable') => 'Vertailtujen sopimusaikojen mediaanihinnat pysyivät lähes ennallaan.',
        default => 'Hintakehitys vaihteli sopimusajan mukaan.',
    };
    $forecastDurations = collect($forecast['durations'] ?? [])->keyBy('duration_months');
    $dataDate = $articleData['data_date'] ?? null;
    $fiDate = fn ($date) => $date ? Carbon::parse($date)->translatedFormat('j.n.Y') : 'Ei saatavilla';
    $fmt = fn ($value, $decimals = 2) => $value === null ? 'Ei saatavilla' : number_format((float) $value, $decimals, ',', ' ');
    $confidenceLabels = ['high' => 'pitkä hintahistoria', 'medium' => 'kohtalainen hintahistoria', 'low' => 'rajallinen hintahistoria'];
    $segmentLabels = [
        'open_ended' => 'Toistaiseksi voimassa oleva, nykyinen kiinteä hinta',
        'fixed_term_6' => '6 kk, täysin kiinteä hinta',
        'fixed_term_12' => '12 kk, täysin kiinteä hinta',
        'fixed_term_24' => '24 kk, täysin kiinteä hinta',
    ];
    $annualCaveats = [
        'clean_benchmark' => 'Energiahinta pysyy samana 12 kuukautta.',
        'spot_forward_estimate' => 'Pörssisähkön vuosikustannus perustuu markkina-arvioon.',
        'seller_can_change_price' => 'Myyjä voi muuttaa hintaa myöhemmin ehtojen mukaisesti.',
        'future_periods_estimated' => 'Myöhempien hintajaksojen hinnat ovat arvioita.',
        'annualized_equivalent' => '6 kuukauden hinta on muutettu 12 kuukauden vertailuarvoksi.',
        'next_twelve_months_only' => '24 kuukauden sopimuksesta verrataan vain seuraavia 12 kuukautta.',
        'consumption_effect_ignored' => 'Vuosikustannus perustuu perushintaan. Kulutusvaikutus oletetaan nollaksi.',
    ];
    $riskTradeoffRows = collect([
        ['segment_key' => 'fixed_term_12', 'label' => 'Kiinteä 12 kk', 'predictability' => 'Korkea', 'level' => 3, 'explanation' => 'Energiahinta pysyy samana 12 kuukautta.'],
        ['segment_key' => 'open_ended', 'label' => 'Toistaiseksi voimassa oleva', 'predictability' => 'Kohtalainen', 'level' => 2, 'explanation' => 'Nykyinen hinta tunnetaan, mutta myyjä voi muuttaa sitä.'],
        ['segment_key' => 'market_reset', 'label' => 'Jaksoittain vaihtuva', 'predictability' => 'Kohtalainen', 'level' => 2, 'explanation' => 'Hinta vahvistetaan jakso kerrallaan.'],
        ['segment_key' => 'quarterly', 'label' => 'Kvartaalisähkö', 'predictability' => 'Kohtalainen', 'level' => 2, 'explanation' => 'Hinta vaihtuu yleensä kolmen kuukauden välein.'],
        ['segment_key' => 'spot', 'label' => 'Pörssisähkö', 'predictability' => 'Matala', 'level' => 1, 'explanation' => 'Hinta seuraa markkinaa ja kulutuksen ajoitusta.'],
        ['segment_key' => 'hybrid', 'label' => 'Kulutusvaikutus', 'predictability' => 'Kohtalainen', 'level' => 2, 'explanation' => 'Perushinta tunnetaan, mutta kulutusvaikutus jää avoimeksi.'],
    ])->map(fn ($row) => [
        ...$row,
        'annual' => $annualRowsBySegment->get($row['segment_key']),
    ]);
    $lowestAnnual = $annualRows->sortBy('median')->first();
    $lowestFixedCurrent = $currentRows
        ->whereNotNull('duration_months')
        ->sortBy('median')
        ->first();
    $comparisonVerdictCopy = function (array $comparison, string $alternativeSentenceStart, string $alternativeGenitive) {
        if (($comparison['state'] ?? null) !== 'complete') return null;
        $alternativeName = lcfirst($alternativeSentenceStart);
        if (($comparison['cheaper_direction'] ?? null) === 'equal') {
            return "Kiinteän 12 kuukauden sopimuksen ja {$alternativeGenitive} vuosikustannukset ovat samat.";
        }
        if ($comparison['difference_is_small'] ?? false) {
            return "Kiinteän 12 kuukauden sopimuksen ja {$alternativeGenitive} hintaero on pieni.";
        }

        return ($comparison['cheaper_direction'] ?? null) === 'fixed_12_cheaper'
            ? "Kiinteä 12 kuukauden sopimus on halvempi kuin {$alternativeName}."
            : "{$alternativeSentenceStart} on halvempi kuin kiinteä 12 kuukauden sopimus.";
    };
    $differenceCopy = function (array $comparison, string $alternativeGenitive) use ($fmt) {
        if (($comparison['state'] ?? null) !== 'complete') return null;
        $yearly = $fmt(abs($comparison['median_difference_eur']), 0);
        $monthly = $fmt(abs($comparison['median_difference_monthly_eur']), 2);

        return match ($comparison['cheaper_direction']) {
            'fixed_12_cheaper' => "Mediaanien ero on {$yearly} € vuodessa eli {$monthly} € kuukaudessa kiinteän 12 kuukauden sopimuksen hyväksi.",
            'alternative_cheaper' => "Mediaanien ero on {$yearly} € vuodessa eli {$monthly} € kuukaudessa {$alternativeGenitive} hyväksi.",
            default => 'Vuosikustannusten mediaanit ovat samat.',
        };
    };
@endphp

<div>
    <x-schema-markup :schemas="[$jsonLdSchema]" />

    {{-- Editorial header (Paper 6HX-0, "also kannattaako-maaraaikainen"): no painted plate, so
         the page passes no heroTone and the top bar sits on its night band above it. --}}
    <x-site.longread-hero variant="article" tone="light" figure="article-fox-fireside" figure-width="560" figure-height="420" :drift="false">
        <div>
            <h1 class="vk-lr-title vk-lr-title--long">Kannattaako määräaikainen sähkösopimus?</h1>
            <x-ink.wave width="520" stroke="5" class="vk-lr-title-wave" />
        </div>
        <p class="vk-lr-hero-lead">
            Vertaamme täysin kiinteähintaista 12 kuukauden sopimusta pörssisähköön, toistaiseksi voimassa olevaan sopimukseen, jaksoittain vaihtuvaan hintaan, kvartaalisähköön ja kulutusvaikutukseen.
        </p>
        <dl class="vk-lr-dates">
            <div>
                <dt>Markkina- ja ennustetiedot päivitetty</dt>
                <dd>{{ $fiDate($dataDate) }}</dd>
            </div>
            <div>
                <dt>Artikkeli tarkistettu</dt>
                <dd>{{ $fiDate($editorialReviewDate) }}</dd>
            </div>
        </dl>
    </x-site.longread-hero>

    <x-page-action-strip />

    <div class="vk-wrap vk-lr-page">
        <nav class="vk-crumbs" aria-label="Breadcrumb">
            <ol>
                <li><a href="/" class="vk-focus">Etusivu</a></li>
                <li aria-hidden="true">/</li>
                <li><a href="/sahkosopimus" class="vk-focus">Sähkösopimukset</a></li>
                <li aria-hidden="true">/</li>
                <li aria-current="page">Määräaikainen sähkösopimus</li>
            </ol>
        </nav>

        <div class="vk-lr-layout vk-lr-layout--article">
            <x-site.longread-toc title="Tässä artikkelissa" label="Artikkelin sisältö" :items="[
                ['benchmark-heading', 'Mitä luvuissa verrataan?'],
                ['annual-heading', 'Mitä eri sopimustyypit maksavat vuodessa?'],
                ['type-comparisons-heading', 'Miten määräaikainen vertautuu muihin sopimustyyppeihin?'],
                ['current-heading', 'Mikä on kiinteän sähkön hinta nyt?'],
                ['duration-heading', 'Mitä sopimusajan pituus merkitsee?'],
                ['history-heading', 'Miten määräaikaisten sopimusten hinnat ovat muuttuneet?'],
                ['forecast-heading', 'Mitä ennuste kertoo hinnoista?'],
                ['checklist-heading', 'Tarkista nämä ennen tilaamista'],
                ['offers-heading', 'Vertaa määräaikaisia tarjouksia'],
            ]" />

            <article class="vk-lr-article vk-lr-article--wide">
                {{-- Verdict (replaces the grey summary box): an ink rule and a display verdict. --}}
                <section class="vk-lr-verdict" aria-labelledby="conclusion-heading">
                    <h2 id="conclusion-heading" class="vk-lr-verdict-title">
                        @if ($fixedTwelveAnnual)
                            Määräaikainen ei ole aina halvin, mutta sen energiahinta ei muutu kesken vuoden.
                        @else
                            Tuore vuosivertailu puuttuu, joten hintakysymykseen ei voi vastata nyt.
                        @endif
                    </h2>
                    @if ($fixedTwelveAnnual)
                        <div class="flex flex-col gap-4">
                            @if ($largestSummaryMonthlyDifference !== null)
                                <p class="vk-lr-text"><strong>Hintaerot jäävät tässä vertailussa enintään {{ $fmt($largestSummaryMonthlyDifference, 2) }} euroon kuukaudessa.</strong></p>
                            @endif
                            @if (($spotComparison['state'] ?? null) === 'complete')
                                <p class="vk-lr-text"><strong>{{ $comparisonVerdictCopy($spotComparison, 'Pörssisähkö', 'pörssisähkön') }}</strong> {{ $differenceCopy($spotComparison, 'pörssisähkön') }}</p>
                            @endif
                            @if (($openEndedComparison['state'] ?? null) === 'complete')
                                <p class="vk-lr-text"><strong>{{ $comparisonVerdictCopy($openEndedComparison, 'Toistaiseksi voimassa oleva sopimus', 'toistaiseksi voimassa olevan sopimuksen') }}</strong> {{ $differenceCopy($openEndedComparison, 'toistaiseksi voimassa olevan sopimuksen') }}</p>
                            @endif
                            <p class="vk-lr-text">Täysin kiinteähintainen sopimus helpottaa sähkölaskun ennakointia. Muut sopimustyypit voivat tulla halvemmiksi, mutta niiden tulevaa kustannusta ei tiedetä yhtä tarkasti.</p>
                        </div>
                        <p class="vk-lr-note">Vertailun hinnat <time datetime="{{ $annual['date'] }}">{{ $fiDate($annual['date']) }}</time>.</p>
                    @else
                        <p class="vk-lr-text">Vertaa sopimuksia myöhemmin uudelleen. Älä päättele yhden tämänhetkisen energiahinnan perusteella koko vuoden kustannusta.</p>
                    @endif
                </section>

                <section class="vk-lr-section" aria-labelledby="benchmark-heading">
                    <x-site.section-heading id="benchmark-heading" wave="240">Mitä luvuissa verrataan?</x-site.section-heading>
                    <p class="vk-lr-text">
                        Määräaikaisuus kertoo sopimusajan. Se ei yksin kerro, miten hinta määräytyy. Tässä kiinteä 12 kuukauden sopimus toimii vertailukohtana, ja sen energiahinta pysyy samana koko vuoden.
                    </p>
                    <p class="vk-lr-text">
                        Mukana ovat myös pörssisähkö, toistaiseksi voimassa oleva sopimus, jaksoittain vaihtuva hinta, kvartaalisähkö ja kulutusvaikutus. Kiinteät 6 ja 24 kuukauden sopimukset auttavat vertaamaan sopimusaikoja.
                    </p>
                    <p class="vk-lr-text">
                        Vuosikustannus lasketaan seuraaville 12 kuukaudelle 5 000 kWh:n kulutuksella. Se sisältää sähköenergian ja sopimuksen kuukausimaksut. Sähkönsiirto ei kuulu vertailuun. Mediaanikustannus on ryhmän keskimmäinen vuosikustannus.
                    </p>
                </section>

                <section class="vk-lr-figure" aria-labelledby="annual-heading">
                    <x-site.section-heading id="annual-heading" wave="300">Mitä eri sopimustyypit maksavat vuodessa?</x-site.section-heading>

                    @if (! $fixedTwelveAnnual || empty($annual['chart']['labels']))
                        <p class="vk-lr-empty">Vuosikustannusten vertailu ei ole juuri nyt saatavilla.</p>
                    @else
                        <p id="annual-comparison-takeaway" class="vk-lr-text">
                            <strong>Mediaanin perusteella halvin on {{ lcfirst($lowestAnnual['label']) }}. Vuosikustannus on {{ $fmt($lowestAnnual['median'], 0) }} €.
                            @if ($lowestAnnual['consumption_effect_ignored'] ?? false)
                                @if ($lowestAnnual['base_only_count'] === $lowestAnnual['contract_count'])
                                    Kulutusvaikutus oletetaan nollaksi.
                                @else
                                    {{ number_format($lowestAnnual['base_only_count'], 0, ',', ' ') }} sopimuksen kulutusvaikutus oletetaan nollaksi.
                                @endif
                            @endif</strong>
                        </p>
                        <p class="vk-lr-text">Kiinteä 12 kuukauden sopimus on vertailun lähtökohta, mutta vertailu ei oleta sen olevan halvin. Kaaviossa näkyvät vain sopimustyypit, joille koko seuraavan 12 kuukauden kustannus voidaan arvioida.</p>
                        @if (($resetComparison['state'] ?? null) === 'complete' && ($resetComparison['low_sample'] ?? false))
                            <p class="vk-lr-text">Jaksoittain vaihtuvia sopimuksia on mukana alle 10. Tulos voi muuttua paljon jo muutaman sopimuksen myötä, joten sitä pitää tulkita varovasti.</p>
                        @endif

                        <div class="flex flex-col gap-3">
                            <div class="vk-lr-legend" aria-label="Vuosikustannuskaavion selite">
                                <span class="vk-lr-legend-item"><span class="vk-lr-swatch" style="--lr-swatch: var(--color-fox)" aria-hidden="true"></span><span>Kiinteä 12 kk, vertailukohta</span></span>
                                <span class="vk-lr-legend-item"><span class="vk-lr-swatch" style="--lr-swatch: var(--color-ink-muted)" aria-hidden="true"></span><span>Muut vuosikustannukset</span></span>
                            </div>
                            <div class="vk-lr-chart" style="height: 360px;">
                                <canvas
                                    id="fixedAnnualComparisonChart"
                                    data-chart="{{ json_encode($annual['chart']) }}"
                                    role="img"
                                    aria-label="Sopimustyyppien arvioitujen vuosikustannusten mediaanit 5 000 kilowattitunnin kulutuksella."
                                    aria-describedby="annual-comparison-takeaway"
                                ></canvas>
                            </div>
                        </div>

                        <details class="vk-lr-details">
                            <summary>Näytä tarkemmat vuosikustannukset</summary>
                            <p class="vk-lr-note mt-3">p20–p80 on listattujen hintojen keskimmäinen 60 %. Sen ulkopuolelle jää edullisin ja kallein viidennes.</p>
                            <div class="vk-lr-table-wrap">
                                <table class="vk-lr-table min-w-[48rem]">
                                    <caption class="sr-only">Arvioidut vuosikustannukset ja niiden rajaukset.</caption>
                                    <thead>
                                        <tr>
                                            <th scope="col">Sopimustyyppi</th>
                                            <th scope="col" class="is-num">p20</th>
                                            <th scope="col" class="is-num">Mediaani</th>
                                            <th scope="col" class="is-num">p80</th>
                                            <th scope="col" class="is-num">Sopimuksia</th>
                                            <th scope="col">Huomio</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        @foreach ($annualRows as $row)
                                            <tr>
                                                <th scope="row">{{ $row['label'] }}</th>
                                                <td class="is-num">{{ $fmt($row['p20'], 0) }} €</td>
                                                <td class="is-num is-strong">{{ $fmt($row['median'], 0) }} €</td>
                                                <td class="is-num">{{ $fmt($row['p80'], 0) }} €</td>
                                                <td class="is-num">{{ number_format($row['contract_count'], 0, ',', ' ') }}</td>
                                                <td class="!whitespace-normal">{{ $annualCaveats[$row['caveat']] }} @if (($row['consumption_effect_ignored'] ?? false) && $row['caveat'] !== 'consumption_effect_ignored') {{ number_format($row['base_only_count'], 0, ',', ' ') }} sopimuksen kulutusvaikutus oletetaan nollaksi. @endif @if ($row['low_sample']) Aineisto on pieni, alle 10 sopimusta. @endif</td>
                                            </tr>
                                        @endforeach
                                    </tbody>
                                </table>
                            </div>
                        </details>
                    @endif
                </section>

                <section class="vk-lr-section" aria-labelledby="type-comparisons-heading">
                    <x-site.section-heading id="type-comparisons-heading" wave="320">Miten määräaikainen vertautuu muihin sopimustyyppeihin?</x-site.section-heading>
                    <p class="vk-lr-text"><strong>Valinta on tasapaino hinnan ja hintariskin välillä.</strong></p>
                    <p class="vk-lr-text">Suurempi hintariski voi tuoda säästöä, jos markkinahinta laskee tai sähkönkäyttö osuu edullisiin aikoihin. Se ei kuitenkaan takaa halvempaa sopimusta.</p>
                </section>

                <section class="vk-lr-section" aria-labelledby="risk-tradeoff-heading">
                    <h3 id="risk-tradeoff-heading" class="vk-lr-h3 !pt-0">Hinta ja hintariski rinnakkain</h3>
                    <p class="vk-lr-text">Mitä heikommin hinta on ennakoitavissa, sitä enemmän lopullinen kustannus voi poiketa arviosta. Vuosikustannus perustuu 5 000 kWh:n kulutukseen.</p>
                    <div class="vk-lr-rows" role="list" aria-label="Sopimustyyppien hinnan ennakoitavuus ja vuosikustannus">
                        <div class="hidden grid-cols-[12rem_10rem_10rem_minmax(0,1fr)] gap-3 py-3 text-[13px] font-extrabold uppercase tracking-[0.1em] text-ink-muted md:grid" aria-hidden="true">
                            <span>Sopimustyyppi</span>
                            <span>Ennakoitavuus</span>
                            <span>Vuosikustannus</span>
                            <span>Mikä hinnassa voi muuttua?</span>
                        </div>
                        @foreach ($riskTradeoffRows as $riskRow)
                            <div class="grid grid-cols-2 gap-3 py-5 md:grid-cols-[12rem_10rem_10rem_minmax(0,1fr)] md:items-center" role="listitem">
                                <p class="vk-lr-row-title col-span-2 text-[17px] md:col-span-1">{{ $riskRow['label'] }}</p>
                                <div>
                                    <p class="text-[14px] font-bold text-ink">Ennakoitavuus: {{ mb_strtolower($riskRow['predictability']) }}</p>
                                    <div class="vk-lr-meter" aria-label="Hinnan ennakoitavuus: {{ mb_strtolower($riskRow['predictability']) }}">
                                        @for ($level = 1; $level <= 3; $level++)
                                            <span class="{{ $level <= $riskRow['level'] ? 'is-on' : '' }}" aria-hidden="true"></span>
                                        @endfor
                                    </div>
                                </div>
                                <p class="tabular-nums">
                                    @if ($riskRow['annual'])
                                        <strong class="font-display text-[20px] font-extrabold leading-6 text-ink">{{ $fmt($riskRow['annual']['median'], 0) }} € vuodessa</strong>
                                        @if ($riskRow['annual']['consumption_effect_ignored'] ?? false)
                                            <span class="mt-1 block text-[13px] text-ink-muted">Kulutusvaikutus ei sisälly</span>
                                        @elseif ($riskRow['annual']['low_sample'])
                                            <span class="mt-1 block text-[13px] text-ink-muted">Pieni aineisto</span>
                                        @endif
                                    @else
                                        <span class="text-[14px] text-ink-muted">Ei luotettavaa vuosiarviota</span>
                                    @endif
                                </p>
                                <p class="col-span-2 text-[15px] leading-6 text-ink-body md:col-span-1">{{ $riskRow['explanation'] }}</p>
                            </div>
                        @endforeach
                    </div>
                    <p class="vk-lr-note">Parempi ennakoitavuus ei tarkoita automaattisesti korkeampaa tai matalampaa hintaa. Se kertoo vain, kuinka paljon kustannus voi muuttua sopimuksen aikana.</p>
                </section>

                <section class="vk-lr-section" aria-labelledby="spot-comparison-heading">
                    <div class="vk-lr-glyph-heading !pt-0">
                        <x-ink.glyph bucket="porssisahko" size="40" />
                        <h3 id="spot-comparison-heading" class="vk-lr-h3">Määräaikainen vai pörssisähkö?</h3>
                    </div>
                    @if (($spotComparison['state'] ?? null) === 'complete')
                        <p class="vk-lr-text"><strong>{{ $comparisonVerdictCopy($spotComparison, 'Pörssisähkö', 'pörssisähkön') }}</strong></p>
                        <p class="vk-lr-text">{{ $differenceCopy($spotComparison, 'pörssisähkön') }}</p>
                    @else
                        <p class="vk-lr-text"><strong>Luotettavaa hintavertailua ei voi tehdä.</strong></p>
                        <p class="vk-lr-text">Tuore vuosiarvio puuttuu. Yhden päivän pörssihinta ei kerro seuraavan vuoden kustannusta.</p>
                    @endif
                    <p class="vk-lr-text">Pörssisähkön hinta seuraa markkinaa ja muuttuu päivän aikana. Vuosikustannus on arvio seuraavien 12 kuukauden markkinahinnoista. Toteutunut kustannus riippuu myös siitä, milloin sähköä käytetään.</p>
                    <p class="vk-lr-text">Kiinteän sopimuksen energiahinta on helpompi ennakoida.</p>
                </section>

                <section class="vk-lr-section" aria-labelledby="open-ended-comparison-heading">
                    <div class="vk-lr-glyph-heading !pt-0">
                        <x-ink.glyph bucket="toistaiseksi" size="40" />
                        <h3 id="open-ended-comparison-heading" class="vk-lr-h3">Määräaikainen vai toistaiseksi voimassa oleva sopimus?</h3>
                    </div>
                    @if (($openEndedComparison['state'] ?? null) === 'complete')
                        <p class="vk-lr-text"><strong>{{ $comparisonVerdictCopy($openEndedComparison, 'Toistaiseksi voimassa oleva sopimus', 'toistaiseksi voimassa olevan sopimuksen') }}</strong></p>
                        <p class="vk-lr-text">{{ $differenceCopy($openEndedComparison, 'toistaiseksi voimassa olevan sopimuksen') }}</p>
                    @else
                        <p class="vk-lr-text"><strong>Luotettavaa hintavertailua ei voi tehdä.</strong></p>
                        <p class="vk-lr-text">Tuore vuosiarvio puuttuu.</p>
                    @endif
                    <p class="vk-lr-text">Toistaiseksi voimassa olevan sopimuksen nykyinen hinta tunnetaan. Myyjä voi kuitenkin muuttaa sitä sopimusehtojen mukaisesti, joten vuosikustannus on arvio.</p>
                    <p class="vk-lr-text">Kiinteä 12 kuukauden sopimus suojaa paremmin hinnanmuutoksilta, jos kotitalous haluaa tietää energian hinnan vuodeksi eteenpäin.</p>
                </section>

                <section class="vk-lr-section" aria-labelledby="reset-comparison-heading">
                    <div class="vk-lr-glyph-heading !pt-0">
                        <x-ink.glyph bucket="paivittyva" size="40" />
                        <h3 id="reset-comparison-heading" class="vk-lr-h3">Määräaikainen vai jaksoittain vaihtuva hinta?</h3>
                    </div>
                    @if (($resetComparison['state'] ?? null) === 'complete')
                        <p class="vk-lr-text"><strong>{{ $comparisonVerdictCopy($resetComparison, 'Jaksoittain vaihtuva sopimus', 'jaksoittain vaihtuvan sopimuksen') }}</strong></p>
                        <p class="vk-lr-text">{{ $differenceCopy($resetComparison, 'jaksoittain vaihtuvan sopimuksen') }}</p>
                        @if ($resetComparison['consumption_effect_ignored'] ?? false)
                            <p class="vk-lr-text">{{ number_format($resetComparison['base_only_count'], 0, ',', ' ') }} sopimuksen kulutusvaikutus oletetaan nollaksi. Todellinen vuosikustannus voi siksi olla suurempi tai pienempi.</p>
                        @endif
                        @if ($resetComparison['low_sample'] ?? false)
                            <p class="vk-lr-text">Mukana on alle 10 sopimusta. Tulos on siksi vain suuntaa antava.</p>
                        @endif
                    @else
                        <p class="vk-lr-text"><strong>Luotettavaa hintavertailua ei voi tehdä.</strong></p>
                        <p class="vk-lr-text">Tuore vuosiarvio puuttuu.</p>
                    @endif
                    <p class="vk-lr-text">Jaksoittain vaihtuvassa sopimuksessa hinta vahvistetaan aina seuraavalle jaksolle. Nykyinen hinta tunnetaan, mutta myöhempien jaksojen hinnat ovat arvioita.</p>
                    @if (($resetComparison['state'] ?? null) === 'complete')
                        <p class="vk-lr-text">Kiinteässä sopimuksessa energiahinta pysyy samana koko vuoden. Jaksoittain vaihtuvan sopimuksen myöhempien hintajaksojen arviot voivat vielä muuttua.</p>
                    @endif
                </section>

                <section class="vk-lr-section" aria-labelledby="quarterly-comparison-heading">
                    <h3 id="quarterly-comparison-heading" class="vk-lr-h3 !pt-0">Määräaikainen vai kvartaalisähkö?</h3>
                    @if (($quarterlyComparison['state'] ?? null) === 'complete')
                        <p class="vk-lr-text"><strong>{{ $comparisonVerdictCopy($quarterlyComparison, 'Kvartaalisähkö', 'kvartaalisähkön') }}</strong></p>
                        <p class="vk-lr-text">{{ $differenceCopy($quarterlyComparison, 'kvartaalisähkön') }}</p>
                        @if ($quarterlyComparison['consumption_effect_ignored'] ?? false)
                            <p class="vk-lr-text">{{ number_format($quarterlyComparison['base_only_count'], 0, ',', ' ') }} sopimuksen kulutusvaikutus oletetaan nollaksi. Todellinen vuosikustannus voi siksi olla suurempi tai pienempi.</p>
                        @endif
                    @else
                        <p class="vk-lr-text"><strong>Luotettavaa hintavertailua ei voi tehdä.</strong></p>
                        <p class="vk-lr-text">Tuore vuosiarvio puuttuu.</p>
                    @endif
                    <p class="vk-lr-text">Kvartaalisähkön hinta vaihtuu yleensä kolmen kuukauden välein. Kiinteässä sopimuksessa energiahinta pysyy samana.</p>
                </section>

                <section class="vk-lr-section" aria-labelledby="hybrid-comparison-heading">
                    <div class="vk-lr-glyph-heading !pt-0">
                        <x-ink.glyph bucket="kulutusvaikutus" size="40" />
                        <h3 id="hybrid-comparison-heading" class="vk-lr-h3">Määräaikainen vai kulutusvaikutussopimus?</h3>
                    </div>
                    @if (($hybridComparison['state'] ?? null) === 'complete')
                        <p class="vk-lr-text"><strong>{{ $comparisonVerdictCopy($hybridComparison, 'Kulutusvaikutussopimus', 'kulutusvaikutussopimuksen') }}</strong></p>
                        <p class="vk-lr-text">{{ $differenceCopy($hybridComparison, 'kulutusvaikutussopimuksen') }}</p>
                        <p class="vk-lr-text">Vuosikustannus perustuu perushintaan, ja kulutusvaikutus oletetaan nollaksi. Todellinen vuosikustannus voi olla suurempi tai pienempi.</p>
                    @else
                        <p class="vk-lr-text"><strong>Perushintaan perustuvaa vuosivertailua ei ole saatavilla.</strong></p>
                    @endif
                    <p class="vk-lr-text">Kulutusvaikutus lisätään perushintaan tai vähennetään siitä. Vaikutus riippuu siitä, milloin sähköä käytetään.</p>
                    <p class="vk-lr-text">Ennakoitavuus jää pörssisähkön ja täysin kiinteän sopimuksen väliin: perushinta tunnetaan, mutta kulutusvaikutuksen määrää ei tiedetä etukäteen.</p>
                </section>

                <section class="vk-lr-section" aria-labelledby="current-heading">
                    <x-site.section-heading id="current-heading" wave="280">Mikä on kiinteän sähkön hinta nyt?</x-site.section-heading>
                    @if ($currentRows->isEmpty())
                        <p class="vk-lr-empty">Saman päivän energiahintoja ei ole juuri nyt saatavilla.</p>
                    @else
                        <p class="vk-lr-text"><strong>Matalin mediaanihinta on {{ $lowestFixedCurrent['duration_months'] }} kuukauden sopimuksilla: {{ $fmt($lowestFixedCurrent['median']) }} c/kWh.</strong></p>
                        <p class="vk-lr-text">Alla ovat 6, 12 ja 24 kuukauden kiinteähintaisten sopimusten mediaanihinnat. Kuukausimaksut eivät ole mukana.</p>
                        <dl class="vk-lr-rows max-w-[760px]">
                            @foreach ($currentRows->whereNotNull('duration_months') as $row)
                                <div class="grid gap-1 py-4 sm:grid-cols-[minmax(0,1fr)_auto] sm:items-baseline">
                                    <dt class="vk-lr-row-title text-[16px]">{{ $segmentLabels[$row['segment_key']] }}</dt>
                                    <dd class="vk-lr-big-value">{{ $fmt($row['median']) }} c/kWh</dd>
                                </div>
                            @endforeach
                        </dl>
                        <p class="vk-lr-note">Hinnat <time datetime="{{ $current['date'] }}">{{ $fiDate($current['date']) }}</time>.</p>
                        <details class="vk-lr-details">
                            <summary>Näytä hintahaarukka ja sopimusten määrä</summary>
                            <div class="vk-lr-table-wrap">
                                <table class="vk-lr-table min-w-[40rem]">
                                    <caption class="sr-only">Täysin kiinteiden energiahintojen vaihteluväli ja sopimusten määrä.</caption>
                                    <thead><tr><th scope="col">Sopimustyyppi</th><th scope="col" class="is-num">p20</th><th scope="col" class="is-num">Mediaani</th><th scope="col" class="is-num">p80</th><th scope="col" class="is-num">Sopimuksia</th></tr></thead>
                                    <tbody>
                                        @foreach ($currentRows->whereNotNull('duration_months') as $row)
                                            <tr><th scope="row">{{ $segmentLabels[$row['segment_key']] }}</th><td class="is-num">{{ $fmt($row['p20']) }}</td><td class="is-num is-strong">{{ $fmt($row['median']) }}</td><td class="is-num">{{ $fmt($row['p80']) }}</td><td class="is-num">{{ number_format($row['contract_count'], 0, ',', ' ') }}</td></tr>
                                        @endforeach
                                    </tbody>
                                </table>
                            </div>
                        </details>
                    @endif
                </section>

                <section class="vk-lr-section" aria-labelledby="duration-heading">
                    <x-site.section-heading id="duration-heading" wave="280">Mitä sopimusajan pituus merkitsee?</x-site.section-heading>
                    <p class="vk-lr-text">6 kuukauden sopimus sitoo hinnan lyhimmäksi ja 24 kuukauden sopimus pisimmäksi ajaksi. 12 kuukauden sopimus vastaa tämän sivun vuosivertailua.</p>
                    @if ($currentRows->isEmpty())
                        <p class="vk-lr-empty">Sopimuspituuksia ei voi asettaa hintajärjestykseen ilman saman päivän tietoja.</p>
                    @else
                        <div class="vk-lr-rows max-w-[760px]">
                            <div class="grid gap-2 py-5 md:grid-cols-[10rem_minmax(0,1fr)]"><h3 class="vk-lr-h3 !pt-0 !text-[20px]">6 kuukautta</h3><p class="vk-lr-text vk-lr-text--small">Sopimus kattaa puoli vuotta. Vuosivertailussa sen hinta on muutettu 12 kuukauden vertailuluvuksi.</p></div>
                            <div class="grid gap-2 py-5 md:grid-cols-[10rem_minmax(0,1fr)]"><h3 class="vk-lr-h3 !pt-0 !text-[20px]">12 kuukautta</h3><p class="vk-lr-text vk-lr-text--small">Sopimuskausi ja vuosivertailu kattavat saman 12 kuukauden jakson.</p></div>
                            <div class="grid gap-2 py-5 md:grid-cols-[10rem_minmax(0,1fr)]"><h3 class="vk-lr-h3 !pt-0 !text-[20px]">24 kuukautta</h3><p class="vk-lr-text vk-lr-text--small">Sopimus kestää kaksi vuotta, mutta vuosikustannus koskee vain seuraavia 12 kuukautta.</p></div>
                        </div>
                    @endif
                </section>

                <section class="vk-lr-figure" aria-labelledby="history-heading">
                    <x-site.section-heading id="history-heading" wave="320">Miten määräaikaisten sopimusten hinnat ovat muuttuneet?</x-site.section-heading>
                    @if ($historySeries->isEmpty() || $historySeries->every(fn ($series) => empty($series['points'])))
                        <p class="vk-lr-empty">Hintahistoriaa ei ole vielä riittävästi.</p>
                    @else
                        <p id="fixed-history-takeaway" class="vk-lr-text"><strong>{{ $historyLead }}</strong></p>
                        <p class="vk-lr-text">Kaavio näyttää täysin kiinteiden 6, 12 ja 24 kuukauden sopimusten viikoittaiset mediaanihinnat enintään viimeisten 12 kuukauden ajalta.</p>
                        <p class="vk-lr-text">
                            <strong>Tarkat muutokset:</strong>
                            @foreach ($historySeries->filter(fn ($series) => !empty($series['summary'])) as $series)
                                {{ $series['duration_months'] }} kk:n mediaani
                                @if ($series['summary']['direction'] === 'rose') nousi @elseif ($series['summary']['direction'] === 'fell') laski @else pysyi lähes ennallaan @endif
                                — alussa {{ $fmt($series['summary']['start_median']) }} c/kWh, lopussa {{ $fmt($series['summary']['end_median']) }} c/kWh{{ $loop->last ? '.' : ';' }}
                            @endforeach
                        </p>
                        <div class="flex flex-col gap-3">
                            <div class="vk-lr-legend" aria-label="Hintahistoriakaavion selite">
                                <span class="vk-lr-legend-item"><span class="vk-lr-swatch vk-lr-swatch--dash" style="--lr-swatch: var(--color-ink)" aria-hidden="true"></span><span>6 kk, kolmio</span></span>
                                <span class="vk-lr-legend-item"><span class="vk-lr-swatch vk-lr-swatch--line" style="--lr-swatch: var(--color-fox)" aria-hidden="true"></span><span>12 kk, ympyrä</span></span>
                                <span class="vk-lr-legend-item"><span class="vk-lr-swatch vk-lr-swatch--dash" style="--lr-swatch: var(--color-ink-muted)" aria-hidden="true"></span><span>24 kk, vinoneliö</span></span>
                            </div>
                            <div class="vk-lr-chart" style="height: 340px;">
                                <canvas
                                    id="fixedPriceHistoryChart"
                                    data-chart="{{ json_encode($historyChart) }}"
                                    role="img"
                                    aria-label="Täysin kiinteiden 6, 12 ja 24 kuukauden sopimusten viikoittainen mediaanihinta."
                                    aria-describedby="fixed-history-takeaway"
                                ></canvas>
                            </div>
                        </div>
                        <details class="vk-lr-details">
                            <summary>Näytä viikoittaiset hinnat</summary>
                            <div class="vk-lr-table-wrap max-h-96 overflow-auto">
                                <table class="vk-lr-table min-w-[44rem]">
                                    <caption class="sr-only">Täysin kiinteiden sopimusten viikoittaiset energiahinnat ja aineiston koko.</caption>
                                    <thead class="sticky top-0 bg-ground"><tr><th scope="col">Viikko alkoi</th><th scope="col">Sopimusaika</th><th scope="col" class="is-num">p20</th><th scope="col" class="is-num">Mediaani</th><th scope="col" class="is-num">p80</th><th scope="col" class="is-num">Sopimuksia</th></tr></thead>
                                    <tbody>
                                        @foreach ($historySeries as $series)
                                            @foreach ($series['points'] as $point)
                                                <tr><th scope="row">{{ $fiDate($point['date']) }}</th><td>{{ $series['duration_months'] }} kk</td><td class="is-num">{{ $fmt($point['p20']) }}</td><td class="is-num is-strong">{{ $fmt($point['median']) }}</td><td class="is-num">{{ $fmt($point['p80']) }}</td><td class="is-num">{{ number_format($point['contract_count'], 0, ',', ' ') }}</td></tr>
                                            @endforeach
                                        @endforeach
                                    </tbody>
                                </table>
                            </div>
                        </details>
                    @endif
                </section>

                <section class="vk-lr-section" aria-labelledby="forecast-heading">
                    <x-site.section-heading id="forecast-heading" wave="300">Mitä {{ $forecast['horizon_days'] }} päivän ennuste kertoo hinnoista?</x-site.section-heading>
                    @if (empty($forecast['date']))
                        <p class="vk-lr-empty">Ennustetta ei ole juuri nyt saatavilla.</p>
                    @else
                        <p class="vk-lr-text"><strong>
                            @if (($forecast['direction_summary'] ?? 'none') === 'down') Hintojen odotetaan laskevan kaikissa kolmessa sopimuspituudessa.
                            @elseif (($forecast['direction_summary'] ?? 'none') === 'up') Hintojen odotetaan nousevan kaikissa kolmessa sopimuspituudessa.
                            @elseif (($forecast['direction_summary'] ?? 'none') === 'stable') Hintatason odotetaan pysyvän suunnilleen ennallaan kaikissa kolmessa sopimuspituudessa.
                            @elseif (($forecast['direction_summary'] ?? 'none') === 'mixed') Hintojen suunnat eroavat sopimuspituuksittain.
                            @elseif (($forecast['direction_summary'] ?? 'none') === 'incomplete') Hintanäkymä on saatavilla vain osalle sopimuspituuksista.
                            @else Yhdellekään sopimusajalle ei ole kattavaa ennustetta. @endif
                        </strong></p>
                        <p class="vk-lr-text">Suuntaa antava arvio, ei varma hintakehitys.</p>
                        <p class="vk-lr-note">Ennuste laadittu <time datetime="{{ $forecast['date'] }}">{{ $fiDate($forecast['date']) }}</time>.</p>
                        <div class="vk-lr-rows">
                            @foreach ([6, 12, 24] as $duration)
                                @php
                                    $durationForecast = $forecastDurations->get($duration);
                                    $available = $durationForecast && $durationForecast['available'];
                                    $change = $available ? (float) $durationForecast['median_change'] : null;
                                @endphp
                                <section class="py-6" aria-labelledby="forecast-{{ $duration }}-heading">
                                    <div class="grid gap-4 md:grid-cols-[9rem_minmax(0,1fr)_auto] md:items-center">
                                        <div><h3 id="forecast-{{ $duration }}-heading" class="vk-lr-h3 !pt-0 !text-[20px]">{{ $duration }} kuukautta</h3>@if ($available)<p class="mt-1 text-[14px] text-ink-muted">Ennusteen tietopohja: <strong class="font-bold text-ink">{{ $confidenceLabels[$durationForecast['confidence']] ?? 'ei ilmoitettu' }}</strong></p>@endif</div>
                                        @if (! $available)
                                            <p class="text-ink-body">Ennustetta ei ole saatavilla.</p>
                                        @else
                                            <div class="flex flex-wrap gap-x-6 gap-y-2 tabular-nums"><p><span class="text-ink-muted">Nyt</span> <strong class="font-display text-[20px] font-extrabold text-ink">{{ $fmt($durationForecast['current']['median']) }} c/kWh</strong></p><p><span class="text-ink-muted">Ennuste</span> <strong class="font-display text-[20px] font-extrabold text-ink">{{ $fmt($durationForecast['forecast']['median']) }} c/kWh</strong></p></div>
                                            <p class="font-display text-[18px] font-extrabold tabular-nums text-ink">{{ $durationForecast['outlook']['label'] }}</p>
                                        @endif
                                    </div>
                                    @if ($available)
                                        <p class="vk-lr-note mt-3">{{ $durationForecast['horizon_days'] }} päivän arvio ajalle {{ $fiDate($durationForecast['forecast_date']) }}–{{ $fiDate($durationForecast['target_date']) }}. Suuntaa antava arvio, ei varma hintakehitys.</p>
                                        <details class="vk-lr-details mt-4"><summary>Näytä hintahaarukka ja ennusteen taustatiedot</summary><div class="vk-lr-table-wrap"><table class="vk-lr-table min-w-[34rem]"><caption class="sr-only">{{ $duration }} kuukauden sopimusten nykyinen ja ennustettu hintaväli.</caption><thead><tr><th scope="col">Hintataso</th><th scope="col" class="is-num">Nyt</th><th scope="col" class="is-num">{{ $durationForecast['horizon_days'] }} päivän ennuste</th></tr></thead><tbody>@foreach (['p20' => 'p20', 'median' => 'Mediaani', 'p80' => 'p80'] as $quantile => $label)<tr><th scope="row">{{ $label }}</th><td class="is-num">{{ $fmt($durationForecast['current'][$quantile]) }} c/kWh</td><td class="is-num is-strong">{{ $fmt($durationForecast['forecast'][$quantile]) }} c/kWh</td></tr>@endforeach</tbody></table></div><p class="vk-lr-note mt-3">Ennusteen kohdepäivä on {{ $fiDate($durationForecast['target_date']) }}. Mukana on {{ number_format($durationForecast['contract_count'], 0, ',', ' ') }} sopimusta. Ennusteen tietopohja: {{ $confidenceLabels[$durationForecast['confidence']] ?? 'ei ilmoitettu' }}.</p></details>
                                    @endif
                                </section>
                            @endforeach
                        </div>
                        <p class="vk-lr-text vk-lr-text--small">Tietopohja kuvaa vertailukelpoisen hintahistorian määrää, ei mitattua osumatarkkuutta tai toteutumisen todennäköisyyttä. Rajallinen historia ei tarkoita varmasti väärää arviota. p20 ja p80 kuvaavat sopimusten hintajakaumaa, eivät ennusteen epävarmuusväliä.</p>
                    @endif
                </section>

                <section class="vk-lr-section" aria-labelledby="checklist-heading">
                    <x-site.section-heading id="checklist-heading" wave="280">Tarkista nämä ennen tilaamista</x-site.section-heading>
                    <ul class="vk-lr-rows max-w-[760px]">
                        <li class="grid gap-2 py-5 md:grid-cols-[12rem_minmax(0,1fr)]"><strong class="vk-lr-row-title">Miten hinta määräytyy</strong><span class="vk-lr-text vk-lr-text--small">Tarkista, pysyykö energiahinta samana vai muuttuuko se markkinan, uuden hintajakson tai kulutuksen ajoituksen mukaan.</span></li>
                        <li class="grid gap-2 py-5 md:grid-cols-[12rem_minmax(0,1fr)]"><strong class="vk-lr-row-title">Koko kustannus</strong><span class="vk-lr-text vk-lr-text--small">Laske energian ja kuukausimaksujen vuosikustannus omalla kulutuksellasi.</span></li>
                        <li class="grid gap-2 py-5 md:grid-cols-[12rem_minmax(0,1fr)]"><strong class="vk-lr-row-title">Sopimuksen kesto</strong><span class="vk-lr-text vk-lr-text--small">Valitse sopimusaika, joka sopii omaan tilanteeseesi. Lue myyjän ehdot ennen tilaamista.</span></li>
                        <li class="grid gap-2 py-5 md:grid-cols-[12rem_minmax(0,1fr)]"><strong class="vk-lr-row-title">Mikä on arvio</strong><span class="vk-lr-text vk-lr-text--small">Erota tunnettu energiahinta tulevien markkinahintojen ja hintajaksojen arvioista.</span></li>
                    </ul>
                </section>

                <section class="vk-lr-section" aria-labelledby="offers-heading">
                    <x-site.section-heading id="offers-heading" wave="280">Vertaa määräaikaisia tarjouksia</x-site.section-heading>
                    <p class="vk-lr-text">Mediaanikustannus antaa vertailukohdan. Tarkista tarjouksen vuosikustannus, hinnoittelutapa ja ehdot ennen tilaamista.</p>
                    <div class="vk-lr-actions pt-2">
                        <a href="/sahkosopimus/maaraaikainen" class="vk-action vk-focus"><span>Vertaa kaikkia määräaikaisia →</span><x-ink.wave width="full" stroke="2.6" /></a>
                        <a href="/sahkosopimus/maaraaikainen-6-kk" class="vk-action vk-focus"><span>Vertaa 6 kk:n sopimuksia →</span><x-ink.wave width="full" stroke="2.6" /></a>
                        <a href="/sahkosopimus/maaraaikainen-12-kk" class="vk-action vk-focus"><span>Vertaa 12 kk:n sopimuksia →</span><x-ink.wave width="full" stroke="2.6" /></a>
                        <a href="/sahkosopimus/maaraaikainen-24-kk" class="vk-action vk-focus"><span>Vertaa 24 kk:n sopimuksia →</span><x-ink.wave width="full" stroke="2.6" /></a>
                    </div>
                </section>

                {{-- "Löysitkö etsimäsi?" after the last section, before the byline. --}}
                <x-page-feedback page-type="article" />

                <x-methodology-byline updated="31.8.2026" :inked="true" class="border-t-[1.5px] border-edge pt-6" />
            </article>
        </div>
    </div>

    @push('scripts')
        <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
        <script>
            function initFixedTermArticleCharts() {
                const formatNumber = (value, decimals = 0) => Number(value).toLocaleString('fi-FI', {
                    minimumFractionDigits: decimals,
                    maximumFractionDigits: decimals,
                });
                // Design tokens (resources/css/tokens.css): the canvas cannot read CSS variables itself.
                const token = (name) => getComputedStyle(document.documentElement).getPropertyValue(name).trim();
                const ink = token('--color-ink');
                const tickFont = { family: 'Figtree, system-ui, sans-serif', size: 12, weight: '700' };
                const readPayload = (canvas) => {
                    const raw = canvas?.getAttribute('data-chart');
                    if (!raw) return null;
                    const payload = JSON.parse(raw);
                    return payload && typeof payload === 'object' ? payload : null;
                };

                const annualCanvas = document.getElementById('fixedAnnualComparisonChart');
                if (annualCanvas && window.Chart) {
                    try {
                        const payload = readPayload(annualCanvas);
                        const valid = payload
                            && Array.isArray(payload.labels)
                            && Array.isArray(payload.medians)
                            && Array.isArray(payload.segment_keys)
                            && payload.labels.length > 0
                            && payload.labels.length === payload.medians.length
                            && payload.labels.length === payload.segment_keys.length
                            && payload.medians.every((value) => Number.isFinite(Number(value)));
                        if (valid) {
                            if (annualCanvas.chartInstance) annualCanvas.chartInstance.destroy();
                            annualCanvas.chartInstance = new Chart(annualCanvas, {
                                type: 'bar',
                                data: {
                                    labels: payload.labels,
                                    datasets: [{
                                        label: 'Vuosikustannuksen mediaani',
                                        data: payload.medians,
                                        backgroundColor: payload.segment_keys.map((key) => key === payload.benchmark_segment_key ? token('--color-fox') : token('--color-ink-muted')),
                                        borderRadius: { topRight: 2, bottomRight: 2 },
                                        borderSkipped: 'left',
                                    }],
                                },
                                options: {
                                    indexAxis: 'y',
                                    responsive: true,
                                    maintainAspectRatio: false,
                                    animation: false,
                                    plugins: {
                                        legend: { display: false },
                                        tooltip: { backgroundColor: ink, cornerRadius: 4, callbacks: { label: (context) => ` ${formatNumber(context.parsed.x, 0)} €/vuosi` } },
                                    },
                                    scales: {
                                        x: {
                                            beginAtZero: true,
                                            title: { display: true, text: 'Arvioitu vuosikustannus', color: token('--color-ink-muted'), font: tickFont },
                                            ticks: { callback: (value) => `${formatNumber(value, 0)} €`, color: token('--color-ink-muted'), font: tickFont },
                                            border: { display: false, dash: [3, 4] },
                                            grid: { color: token('--color-edge') },
                                        },
                                        y: { grid: { display: false }, border: { color: ink, width: 1.8 }, ticks: { color: ink, font: { family: 'Figtree, system-ui, sans-serif', size: 13, weight: '700' } } },
                                    },
                                },
                            });
                        }
                    } catch (error) {
                        annualCanvas.setAttribute('data-chart-error', 'true');
                    }
                }

                const historyCanvas = document.getElementById('fixedPriceHistoryChart');
                if (historyCanvas && window.Chart) {
                    try {
                        const payload = readPayload(historyCanvas);
                        const styles = {
                            6: { color: ink, dash: [7, 4], pointStyle: 'triangle' },
                            12: { color: token('--color-fox'), dash: [], pointStyle: 'circle' },
                            24: { color: token('--color-ink-muted'), dash: [2, 4], pointStyle: 'rectRot' },
                        };
                        const valid = payload
                            && Array.isArray(payload.labels)
                            && Array.isArray(payload.datasets)
                            && payload.labels.length > 0
                            && payload.datasets.every((dataset) => Array.isArray(dataset.values)
                                && dataset.values.length === payload.labels.length
                                && dataset.values.every((value) => value === null || Number.isFinite(Number(value))));
                        if (valid) {
                            if (historyCanvas.chartInstance) historyCanvas.chartInstance.destroy();
                            historyCanvas.chartInstance = new Chart(historyCanvas, {
                                type: 'line',
                                data: {
                                    labels: payload.labels,
                                    datasets: payload.datasets.map((dataset) => {
                                        const style = styles[dataset.duration_months] || styles[24];
                                        return {
                                            label: dataset.label,
                                            data: dataset.values,
                                            borderColor: style.color,
                                            backgroundColor: style.color,
                                            borderDash: style.dash,
                                            borderWidth: dataset.duration_months === 12 ? 3 : 2,
                                            pointStyle: style.pointStyle,
                                            pointRadius: 2,
                                            pointHoverRadius: 5,
                                            tension: 0.25,
                                            spanGaps: false,
                                        };
                                    }),
                                },
                                options: {
                                    responsive: true,
                                    maintainAspectRatio: false,
                                    animation: false,
                                    interaction: { intersect: false, mode: 'index' },
                                    plugins: {
                                        legend: { display: false },
                                        tooltip: { backgroundColor: ink, cornerRadius: 4, callbacks: { label: (context) => context.parsed.y === null ? null : ` ${context.dataset.label}: ${formatNumber(context.parsed.y, 2)} c/kWh` } },
                                    },
                                    scales: {
                                        x: { grid: { display: false }, border: { color: ink, width: 1.8 }, ticks: { autoSkip: true, maxRotation: 0, maxTicksLimit: 7, color: token('--color-ink-muted'), font: tickFont } },
                                        y: {
                                            title: { display: true, text: 'Energiahinta', color: token('--color-ink-muted'), font: tickFont },
                                            ticks: { callback: (value) => `${formatNumber(value, 1)} c`, color: token('--color-ink-muted'), font: tickFont },
                                            border: { display: false, dash: [3, 4] },
                                            grid: { color: token('--color-edge') },
                                        },
                                    },
                                },
                            });
                        }
                    } catch (error) {
                        historyCanvas.setAttribute('data-chart-error', 'true');
                    }
                }
            }

            document.addEventListener('DOMContentLoaded', initFixedTermArticleCharts);
            document.addEventListener('livewire:navigated', initFixedTermArticleCharts);
        </script>
    @endpush
</div>
