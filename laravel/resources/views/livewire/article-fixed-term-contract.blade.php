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
    $confidenceLabels = ['high' => 'hyvä', 'medium' => 'kohtalainen', 'low' => 'heikko'];
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

<div class="bg-white">
    <x-schema-markup :schemas="[$jsonLdSchema]" />

    <main class="mx-auto max-w-5xl px-4 pb-24 sm:px-6 lg:px-8">
        <nav class="pb-6 pt-8" aria-label="Breadcrumb">
            <ol class="flex flex-wrap items-center gap-2 text-sm text-slate-500">
                <li><a href="/" class="hover:text-slate-900">Etusivu</a></li>
                <li aria-hidden="true" class="text-slate-300">/</li>
                <li><a href="/sahkosopimus" class="hover:text-slate-900">Sähkösopimukset</a></li>
                <li aria-hidden="true" class="text-slate-300">/</li>
                <li class="font-medium text-slate-900" aria-current="page">Määräaikainen sähkösopimus</li>
            </ol>
        </nav>

        <header class="border-b border-slate-200 pb-10">
            <h1 class="max-w-[24ch] text-3xl font-extrabold leading-[1.08] tracking-tight text-slate-900 md:text-5xl">
                Kannattaako määräaikainen sähkösopimus?
            </h1>
            <p class="mt-5 max-w-[68ch] text-lg leading-relaxed text-slate-600">
                Vertaamme täysin kiinteähintaista 12 kuukauden sopimusta pörssisähköön, toistaiseksi voimassa olevaan sopimukseen, jaksoittain vaihtuvaan hintaan, kvartaalisähköön ja kulutusvaikutukseen.
            </p>
            <dl class="mt-8 grid gap-5 text-sm sm:grid-cols-2">
                <div>
                    <dt class="font-semibold text-slate-600">Markkina- ja ennustetiedot päivitetty</dt>
                    <dd class="mt-1 font-bold tabular-nums text-slate-900">{{ $fiDate($dataDate) }}</dd>
                </div>
                <div>
                    <dt class="font-semibold text-slate-600">Artikkeli tarkistettu</dt>
                    <dd class="mt-1 font-bold tabular-nums text-slate-900">{{ $fiDate($editorialReviewDate) }}</dd>
                </div>
            </dl>
        </header>

        <x-page-action-strip class="mb-12" />

        <article>
            <section class="mb-20 border-y border-slate-200 bg-slate-50 px-5 py-10 sm:px-8" aria-labelledby="conclusion-heading">
                <h2 id="conclusion-heading" class="max-w-[34ch] text-2xl font-extrabold leading-tight tracking-tight text-slate-900 md:text-3xl">
                    @if ($fixedTwelveAnnual)
                        Määräaikainen ei ole aina halvin, mutta sen energiahinta ei muutu kesken vuoden.
                    @else
                        Tuore vuosivertailu puuttuu, joten hintakysymykseen ei voi vastata nyt.
                    @endif
                </h2>
                @if ($fixedTwelveAnnual)
                    <div class="mt-5 max-w-[70ch] space-y-4 text-lg leading-relaxed text-slate-700">
                        @if ($largestSummaryMonthlyDifference !== null)
                            <p><strong class="font-bold text-slate-900">Hintaerot jäävät tässä vertailussa enintään {{ $fmt($largestSummaryMonthlyDifference, 2) }} euroon kuukaudessa.</strong></p>
                        @endif
                        @if (($spotComparison['state'] ?? null) === 'complete')
                            <p><strong class="font-bold text-slate-900">{{ $comparisonVerdictCopy($spotComparison, 'Pörssisähkö', 'pörssisähkön') }}</strong> {{ $differenceCopy($spotComparison, 'pörssisähkön') }}</p>
                        @endif
                        @if (($openEndedComparison['state'] ?? null) === 'complete')
                            <p><strong class="font-bold text-slate-900">{{ $comparisonVerdictCopy($openEndedComparison, 'Toistaiseksi voimassa oleva sopimus', 'toistaiseksi voimassa olevan sopimuksen') }}</strong> {{ $differenceCopy($openEndedComparison, 'toistaiseksi voimassa olevan sopimuksen') }}</p>
                        @endif
                        <p>Täysin kiinteähintainen sopimus helpottaa sähkölaskun ennakointia. Muut sopimustyypit voivat tulla halvemmiksi, mutta niiden tulevaa kustannusta ei tiedetä yhtä tarkasti.</p>
                    </div>
                    <p class="mt-4 text-sm text-slate-600">Vertailun hinnat <time datetime="{{ $annual['date'] }}">{{ $fiDate($annual['date']) }}</time>.</p>
                @else
                    <p class="mt-5 max-w-[70ch] text-lg leading-relaxed text-slate-700">Vertaa sopimuksia myöhemmin uudelleen. Älä päättele yhden tämänhetkisen energiahinnan perusteella koko vuoden kustannusta.</p>
                @endif
            </section>

            <section class="mb-20" aria-labelledby="benchmark-heading">
                <h2 id="benchmark-heading" class="text-2xl font-bold tracking-tight text-slate-900">Mitä luvuissa verrataan?</h2>
                <div class="mt-4 max-w-[72ch] space-y-4 text-base leading-relaxed text-slate-700">
                    <p>
                        Määräaikaisuus kertoo sopimusajan. Se ei yksin kerro, miten hinta määräytyy. Tässä kiinteä 12 kuukauden sopimus toimii vertailukohtana, ja sen energiahinta pysyy samana koko vuoden.
                    </p>
                    <p>
                        Mukana ovat myös pörssisähkö, toistaiseksi voimassa oleva sopimus, jaksoittain vaihtuva hinta, kvartaalisähkö ja kulutusvaikutus. Kiinteät 6 ja 24 kuukauden sopimukset auttavat vertaamaan sopimusaikoja.
                    </p>
                    <p>
                        Vuosikustannus lasketaan seuraaville 12 kuukaudelle 5 000 kWh:n kulutuksella. Se sisältää sähköenergian ja sopimuksen kuukausimaksut. Sähkönsiirto ei kuulu vertailuun. Mediaanikustannus on ryhmän keskimmäinen vuosikustannus.
                    </p>
                </div>
            </section>

            <section class="mb-20" aria-labelledby="annual-heading">
                <div class="border-b border-slate-300 pb-4">
                    <h2 id="annual-heading" class="text-2xl font-bold tracking-tight text-slate-900">Mitä eri sopimustyypit maksavat vuodessa?</h2>
                </div>

                @if (! $fixedTwelveAnnual || empty($annual['chart']['labels']))
                    <p class="py-10 text-slate-700">Vuosikustannusten vertailu ei ole juuri nyt saatavilla.</p>
                @else
                    <p id="annual-comparison-takeaway" class="mt-6 max-w-[72ch] text-lg font-bold leading-relaxed text-slate-900">
                        Mediaanin perusteella halvin on {{ lcfirst($lowestAnnual['label']) }}. Vuosikustannus on {{ $fmt($lowestAnnual['median'], 0) }} €.
                        @if ($lowestAnnual['consumption_effect_ignored'] ?? false)
                            @if ($lowestAnnual['base_only_count'] === $lowestAnnual['contract_count'])
                                Kulutusvaikutus oletetaan nollaksi.
                            @else
                                {{ number_format($lowestAnnual['base_only_count'], 0, ',', ' ') }} sopimuksen kulutusvaikutus oletetaan nollaksi.
                            @endif
                        @endif
                    </p>
                    <p class="mt-3 max-w-[72ch] text-base leading-relaxed text-slate-700">Kiinteä 12 kuukauden sopimus on vertailun lähtökohta, mutta vertailu ei oleta sen olevan halvin. Kaaviossa näkyvät vain sopimustyypit, joille koko seuraavan 12 kuukauden kustannus voidaan arvioida.</p>
                    @if (($resetComparison['state'] ?? null) === 'complete' && ($resetComparison['low_sample'] ?? false))
                        <p class="mt-4 max-w-[72ch] text-base leading-relaxed text-slate-700">Jaksoittain vaihtuvia sopimuksia on mukana alle 10. Tulos voi muuttua paljon jo muutaman sopimuksen myötä, joten sitä pitää tulkita varovasti.</p>
                    @endif

                    <div class="mt-6 flex flex-wrap gap-x-6 gap-y-3 text-sm text-slate-700" aria-label="Vuosikustannuskaavion selite">
                        <span class="flex items-center gap-2"><span class="h-3 w-5 rounded-sm bg-coral-600" aria-hidden="true"></span><span>Kiinteä 12 kk, vertailukohta</span></span>
                        <span class="flex items-center gap-2"><span class="h-3 w-5 rounded-sm bg-slate-500" aria-hidden="true"></span><span>Muut vuosikustannukset</span></span>
                    </div>
                    <div class="relative mt-4" style="height: 360px;">
                        <canvas
                            id="fixedAnnualComparisonChart"
                            data-chart="{{ json_encode($annual['chart']) }}"
                            role="img"
                            aria-label="Sopimustyyppien arvioitujen vuosikustannusten mediaanit 5 000 kilowattitunnin kulutuksella."
                            aria-describedby="annual-comparison-takeaway"
                        ></canvas>
                    </div>

                    <details class="mt-6 border-t border-slate-200 pt-4">
                        <summary class="cursor-pointer font-semibold text-slate-900 underline decoration-slate-300 underline-offset-4 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-coral-500">Näytä tarkemmat vuosikustannukset</summary>
                        <p class="mt-4 max-w-[72ch] text-sm leading-relaxed text-slate-600">p20–p80 on listattujen hintojen keskimmäinen 60 %. Sen ulkopuolelle jää edullisin ja kallein viidennes.</p>
                        <div class="mt-4 overflow-x-auto">
                            <table class="w-full min-w-[48rem] border-collapse text-sm tabular-nums">
                                <caption class="sr-only">Arvioidut vuosikustannukset ja niiden rajaukset.</caption>
                                <thead>
                                    <tr class="border-b border-slate-300 text-left text-slate-600">
                                        <th scope="col" class="py-2 pr-3 font-semibold">Sopimustyyppi</th>
                                        <th scope="col" class="px-3 py-2 text-right font-semibold">p20</th>
                                        <th scope="col" class="px-3 py-2 text-right font-semibold">Mediaani</th>
                                        <th scope="col" class="px-3 py-2 text-right font-semibold">p80</th>
                                        <th scope="col" class="px-3 py-2 text-right font-semibold">Sopimuksia</th>
                                        <th scope="col" class="py-2 pl-3 font-semibold">Huomio</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-slate-100">
                                    @foreach ($annualRows as $row)
                                        <tr>
                                            <th scope="row" class="py-3 pr-3 text-left font-semibold text-slate-900">{{ $row['label'] }}</th>
                                            <td class="px-3 py-3 text-right text-slate-700">{{ $fmt($row['p20'], 0) }} €</td>
                                            <td class="px-3 py-3 text-right font-semibold text-slate-900">{{ $fmt($row['median'], 0) }} €</td>
                                            <td class="px-3 py-3 text-right text-slate-700">{{ $fmt($row['p80'], 0) }} €</td>
                                            <td class="px-3 py-3 text-right text-slate-700">{{ number_format($row['contract_count'], 0, ',', ' ') }}</td>
                                            <td class="py-3 pl-3 text-slate-700">{{ $annualCaveats[$row['caveat']] }} @if (($row['consumption_effect_ignored'] ?? false) && $row['caveat'] !== 'consumption_effect_ignored') {{ number_format($row['base_only_count'], 0, ',', ' ') }} sopimuksen kulutusvaikutus oletetaan nollaksi. @endif @if ($row['low_sample']) Aineisto on pieni, alle 10 sopimusta. @endif</td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    </details>
                @endif
            </section>

            <section class="mb-10 border-b border-slate-300 pb-5" aria-labelledby="type-comparisons-heading">
                <h2 id="type-comparisons-heading" class="text-2xl font-bold tracking-tight text-slate-900">Miten määräaikainen vertautuu muihin sopimustyyppeihin?</h2>
                <p class="mt-3 max-w-[72ch] text-lg font-bold leading-relaxed text-slate-900">Valinta on tasapaino hinnan ja hintariskin välillä.</p>
                <p class="mt-2 max-w-[72ch] text-base leading-relaxed text-slate-700">Suurempi hintariski voi tuoda säästöä, jos markkinahinta laskee tai sähkönkäyttö osuu edullisiin aikoihin. Se ei kuitenkaan takaa halvempaa sopimusta.</p>
            </section>

            <section class="mb-16" aria-labelledby="risk-tradeoff-heading">
                <h3 id="risk-tradeoff-heading" class="text-xl font-bold tracking-tight text-slate-900">Hinta ja hintariski rinnakkain</h3>
                <p class="mt-3 max-w-[72ch] text-base leading-relaxed text-slate-700">Mitä heikommin hinta on ennakoitavissa, sitä enemmän lopullinen kustannus voi poiketa arviosta. Vuosikustannus perustuu 5 000 kWh:n kulutukseen.</p>
                <div class="mt-6 border-y border-slate-200" role="list" aria-label="Sopimustyyppien hinnan ennakoitavuus ja vuosikustannus">
                    <div class="hidden grid-cols-[12rem_10rem_9rem_minmax(0,1fr)] gap-3 border-b border-slate-300 py-3 text-xs font-semibold uppercase tracking-wide text-slate-500 md:grid" aria-hidden="true">
                        <span>Sopimustyyppi</span>
                        <span>Ennakoitavuus</span>
                        <span>Vuosikustannus</span>
                        <span>Mikä hinnassa voi muuttua?</span>
                    </div>
                    @foreach ($riskTradeoffRows as $riskRow)
                        <div class="grid grid-cols-2 gap-3 border-b border-slate-200 py-5 last:border-b-0 md:grid-cols-[12rem_10rem_9rem_minmax(0,1fr)] md:items-center" role="listitem">
                            <p class="col-span-2 font-bold text-slate-900 md:col-span-1">{{ $riskRow['label'] }}</p>
                            <div>
                                <p class="text-sm font-semibold text-slate-900">Ennakoitavuus: {{ mb_strtolower($riskRow['predictability']) }}</p>
                                <div class="mt-2 flex w-24 gap-1" aria-label="Hinnan ennakoitavuus: {{ mb_strtolower($riskRow['predictability']) }}">
                                    @for ($level = 1; $level <= 3; $level++)
                                        <span class="h-1.5 flex-1 rounded-full {{ $level <= $riskRow['level'] ? 'bg-slate-800' : 'bg-slate-200' }}" aria-hidden="true"></span>
                                    @endfor
                                </div>
                            </div>
                            <p class="tabular-nums">
                                @if ($riskRow['annual'])
                                    <strong class="font-bold text-slate-900">{{ $fmt($riskRow['annual']['median'], 0) }} € vuodessa</strong>
                                    @if ($riskRow['annual']['consumption_effect_ignored'] ?? false)
                                        <span class="mt-1 block text-xs text-slate-500">Kulutusvaikutus ei sisälly</span>
                                    @elseif ($riskRow['annual']['low_sample'])
                                        <span class="mt-1 block text-xs text-slate-500">Pieni aineisto</span>
                                    @endif
                                @else
                                    <span class="text-sm text-slate-500">Ei luotettavaa vuosiarviota</span>
                                @endif
                            </p>
                            <p class="col-span-2 text-sm leading-relaxed text-slate-600 md:col-span-1">{{ $riskRow['explanation'] }}</p>
                        </div>
                    @endforeach
                </div>
                <p class="mt-3 max-w-[72ch] text-sm leading-relaxed text-slate-600">Parempi ennakoitavuus ei tarkoita automaattisesti korkeampaa tai matalampaa hintaa. Se kertoo vain, kuinka paljon kustannus voi muuttua sopimuksen aikana.</p>
            </section>

            <section class="mb-14" aria-labelledby="spot-comparison-heading">
                <h3 id="spot-comparison-heading" class="text-xl font-bold tracking-tight text-slate-900">Määräaikainen vai pörssisähkö?</h3>
                <div class="mt-4 max-w-[72ch] space-y-3 text-base leading-relaxed text-slate-700">
                    @if (($spotComparison['state'] ?? null) === 'complete')
                        <p class="text-lg font-bold text-slate-900">{{ $comparisonVerdictCopy($spotComparison, 'Pörssisähkö', 'pörssisähkön') }}</p>
                        <p>{{ $differenceCopy($spotComparison, 'pörssisähkön') }}</p>
                    @else
                        <p class="text-lg font-bold text-slate-900">Luotettavaa hintavertailua ei voi tehdä.</p>
                        <p>Tuore vuosiarvio puuttuu. Yhden päivän pörssihinta ei kerro seuraavan vuoden kustannusta.</p>
                    @endif
                    <p>Pörssisähkön hinta seuraa markkinaa ja muuttuu päivän aikana. Vuosikustannus on arvio seuraavien 12 kuukauden markkinahinnoista. Toteutunut kustannus riippuu myös siitä, milloin sähköä käytetään.</p>
                    <p>Kiinteän sopimuksen energiahinta on helpompi ennakoida.</p>
                </div>
            </section>

            <section class="mb-14" aria-labelledby="open-ended-comparison-heading">
                <h3 id="open-ended-comparison-heading" class="text-xl font-bold tracking-tight text-slate-900">Määräaikainen vai toistaiseksi voimassa oleva sopimus?</h3>
                <div class="mt-4 max-w-[72ch] space-y-3 text-base leading-relaxed text-slate-700">
                    @if (($openEndedComparison['state'] ?? null) === 'complete')
                        <p class="text-lg font-bold text-slate-900">{{ $comparisonVerdictCopy($openEndedComparison, 'Toistaiseksi voimassa oleva sopimus', 'toistaiseksi voimassa olevan sopimuksen') }}</p>
                        <p>{{ $differenceCopy($openEndedComparison, 'toistaiseksi voimassa olevan sopimuksen') }}</p>
                    @else
                        <p class="text-lg font-bold text-slate-900">Luotettavaa hintavertailua ei voi tehdä.</p>
                        <p>Tuore vuosiarvio puuttuu.</p>
                    @endif
                    <p>Toistaiseksi voimassa olevan sopimuksen nykyinen hinta tunnetaan. Myyjä voi kuitenkin muuttaa sitä sopimusehtojen mukaisesti, joten vuosikustannus on arvio.</p>
                    <p>Kiinteä 12 kuukauden sopimus suojaa paremmin hinnanmuutoksilta, jos kotitalous haluaa tietää energian hinnan vuodeksi eteenpäin.</p>
                </div>
            </section>

            <section class="mb-14" aria-labelledby="reset-comparison-heading">
                <h3 id="reset-comparison-heading" class="text-xl font-bold tracking-tight text-slate-900">Määräaikainen vai jaksoittain vaihtuva hinta?</h3>
                <div class="mt-4 max-w-[72ch] space-y-3 text-base leading-relaxed text-slate-700">
                    @if (($resetComparison['state'] ?? null) === 'complete')
                        <p class="text-lg font-bold text-slate-900">{{ $comparisonVerdictCopy($resetComparison, 'Jaksoittain vaihtuva sopimus', 'jaksoittain vaihtuvan sopimuksen') }}</p>
                        <p>{{ $differenceCopy($resetComparison, 'jaksoittain vaihtuvan sopimuksen') }}</p>
                        @if ($resetComparison['consumption_effect_ignored'] ?? false)
                            <p>{{ number_format($resetComparison['base_only_count'], 0, ',', ' ') }} sopimuksen kulutusvaikutus oletetaan nollaksi. Todellinen vuosikustannus voi siksi olla suurempi tai pienempi.</p>
                        @endif
                        @if ($resetComparison['low_sample'] ?? false)
                            <p>Mukana on alle 10 sopimusta. Tulos on siksi vain suuntaa antava.</p>
                        @endif
                    @else
                        <p class="text-lg font-bold text-slate-900">Luotettavaa hintavertailua ei voi tehdä.</p>
                        <p>Tuore vuosiarvio puuttuu.</p>
                    @endif
                    <p>Jaksoittain vaihtuvassa sopimuksessa hinta vahvistetaan aina seuraavalle jaksolle. Nykyinen hinta tunnetaan, mutta myöhempien jaksojen hinnat ovat arvioita.</p>
                    @if (($resetComparison['state'] ?? null) === 'complete')
                        <p>Kiinteässä sopimuksessa energiahinta pysyy samana koko vuoden. Jaksoittain vaihtuvan sopimuksen myöhempien hintajaksojen arviot voivat vielä muuttua.</p>
                    @endif
                </div>
            </section>

            <section class="mb-14" aria-labelledby="quarterly-comparison-heading">
                <h3 id="quarterly-comparison-heading" class="text-xl font-bold tracking-tight text-slate-900">Määräaikainen vai kvartaalisähkö?</h3>
                <div class="mt-4 max-w-[72ch] space-y-3 text-base leading-relaxed text-slate-700">
                    @if (($quarterlyComparison['state'] ?? null) === 'complete')
                        <p class="text-lg font-bold text-slate-900">{{ $comparisonVerdictCopy($quarterlyComparison, 'Kvartaalisähkö', 'kvartaalisähkön') }}</p>
                        <p>{{ $differenceCopy($quarterlyComparison, 'kvartaalisähkön') }}</p>
                        @if ($quarterlyComparison['consumption_effect_ignored'] ?? false)
                            <p>{{ number_format($quarterlyComparison['base_only_count'], 0, ',', ' ') }} sopimuksen kulutusvaikutus oletetaan nollaksi. Todellinen vuosikustannus voi siksi olla suurempi tai pienempi.</p>
                        @endif
                        <p>Seuraavien vuosineljännesten todelliset hinnat voivat poiketa arviosta.</p>
                    @else
                        <p class="text-lg font-bold text-slate-900">Luotettavaa hintavertailua ei voi tehdä.</p>
                        <p>Tuore vuosiarvio puuttuu.</p>
                    @endif
                    <p>Kvartaalisähkön hinta vaihtuu yleensä kolmen kuukauden välein. Kiinteässä sopimuksessa energiahinta pysyy samana.</p>
                </div>
            </section>

            <section class="mb-20" aria-labelledby="hybrid-comparison-heading">
                <h3 id="hybrid-comparison-heading" class="text-xl font-bold tracking-tight text-slate-900">Määräaikainen vai kulutusvaikutussopimus?</h3>
                <div class="mt-4 max-w-[72ch] space-y-3 text-base leading-relaxed text-slate-700">
                    @if (($hybridComparison['state'] ?? null) === 'complete')
                        <p class="text-lg font-bold text-slate-900">{{ $comparisonVerdictCopy($hybridComparison, 'Kulutusvaikutussopimus', 'kulutusvaikutussopimuksen') }}</p>
                        <p>{{ $differenceCopy($hybridComparison, 'kulutusvaikutussopimuksen') }}</p>
                        <p>Vuosikustannus perustuu perushintaan, ja kulutusvaikutus oletetaan nollaksi. Todellinen vuosikustannus voi olla suurempi tai pienempi.</p>
                    @else
                        <p class="text-lg font-bold text-slate-900">Perushintaan perustuvaa vuosivertailua ei ole saatavilla.</p>
                    @endif
                    <p>Kulutusvaikutus lisätään perushintaan tai vähennetään siitä. Vaikutus riippuu siitä, milloin sähköä käytetään.</p>
                    <p>Ennakoitavuus jää pörssisähkön ja täysin kiinteän sopimuksen väliin: perushinta tunnetaan, mutta kulutusvaikutuksen määrää ei tiedetä etukäteen.</p>
                </div>
            </section>

            <section class="mb-20" aria-labelledby="current-heading">
                <div class="border-b border-slate-300 pb-4">
                    <h2 id="current-heading" class="text-2xl font-bold tracking-tight text-slate-900">Mikä on kiinteän sähkön hinta nyt?</h2>
                </div>
                @if ($currentRows->isEmpty())
                    <p class="py-10 text-slate-700">Saman päivän energiahintoja ei ole juuri nyt saatavilla.</p>
                @else
                    <p class="mt-6 max-w-[72ch] text-lg font-bold leading-relaxed text-slate-900">Matalin mediaanihinta on {{ $lowestFixedCurrent['duration_months'] }} kuukauden sopimuksilla: {{ $fmt($lowestFixedCurrent['median']) }} c/kWh.</p>
                    <p class="mt-3 max-w-[72ch] text-base leading-relaxed text-slate-700">Alla ovat 6, 12 ja 24 kuukauden kiinteähintaisten sopimusten mediaanihinnat. Kuukausimaksut eivät ole mukana.</p>
                    <dl class="mt-6 divide-y divide-slate-200 border-y border-slate-200">
                        @foreach ($currentRows->whereNotNull('duration_months') as $row)
                            <div class="grid gap-2 py-4 sm:grid-cols-[minmax(0,1fr)_auto] sm:items-baseline">
                                <dt class="font-semibold text-slate-900">{{ $segmentLabels[$row['segment_key']] }}</dt>
                                <dd class="text-xl font-extrabold tabular-nums text-slate-900">{{ $fmt($row['median']) }} c/kWh</dd>
                            </div>
                        @endforeach
                    </dl>
                    <p class="mt-3 text-sm text-slate-600">Hinnat <time datetime="{{ $current['date'] }}">{{ $fiDate($current['date']) }}</time>.</p>
                    <details class="mt-6 border-t border-slate-200 pt-4">
                        <summary class="cursor-pointer font-semibold text-slate-900 underline decoration-slate-300 underline-offset-4 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-coral-500">Näytä hintahaarukka ja sopimusten määrä</summary>
                        <div class="mt-4 overflow-x-auto">
                            <table class="w-full min-w-[40rem] border-collapse text-sm tabular-nums">
                                <caption class="sr-only">Täysin kiinteiden energiahintojen vaihteluväli ja sopimusten määrä.</caption>
                                <thead><tr class="border-b border-slate-300 text-left text-slate-600"><th scope="col" class="py-2 pr-3 font-semibold">Sopimustyyppi</th><th scope="col" class="px-3 py-2 text-right font-semibold">p20</th><th scope="col" class="px-3 py-2 text-right font-semibold">Mediaani</th><th scope="col" class="px-3 py-2 text-right font-semibold">p80</th><th scope="col" class="py-2 pl-3 text-right font-semibold">Sopimuksia</th></tr></thead>
                                <tbody class="divide-y divide-slate-100">
                                    @foreach ($currentRows->whereNotNull('duration_months') as $row)
                                        <tr><th scope="row" class="py-3 pr-3 text-left font-semibold text-slate-900">{{ $segmentLabels[$row['segment_key']] }}</th><td class="px-3 py-3 text-right text-slate-700">{{ $fmt($row['p20']) }}</td><td class="px-3 py-3 text-right font-semibold text-slate-900">{{ $fmt($row['median']) }}</td><td class="px-3 py-3 text-right text-slate-700">{{ $fmt($row['p80']) }}</td><td class="py-3 pl-3 text-right text-slate-700">{{ number_format($row['contract_count'], 0, ',', ' ') }}</td></tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    </details>
                @endif
            </section>

            <section class="mb-20" aria-labelledby="duration-heading">
                <div class="border-b border-slate-300 pb-4">
                    <h2 id="duration-heading" class="text-2xl font-bold tracking-tight text-slate-900">Mitä sopimusajan pituus merkitsee?</h2>
                    <p class="mt-2 max-w-[72ch] text-base leading-relaxed text-slate-600">6 kuukauden sopimus sitoo hinnan lyhimmäksi ja 24 kuukauden sopimus pisimmäksi ajaksi. 12 kuukauden sopimus vastaa tämän sivun vuosivertailua.</p>
                </div>
                @if ($currentRows->isEmpty())
                    <p class="py-10 text-slate-700">Sopimuspituuksia ei voi asettaa hintajärjestykseen ilman saman päivän tietoja.</p>
                @else
                    <div class="mt-6 divide-y divide-slate-200 border-y border-slate-200">
                        <div class="grid gap-2 py-5 md:grid-cols-[10rem_minmax(0,1fr)]"><h3 class="font-bold text-slate-900">6 kuukautta</h3><p class="leading-relaxed text-slate-700">Sopimus kattaa puoli vuotta. Vuosivertailussa sen hinta on muutettu 12 kuukauden vertailuluvuksi.</p></div>
                        <div class="grid gap-2 py-5 md:grid-cols-[10rem_minmax(0,1fr)]"><h3 class="font-bold text-slate-900">12 kuukautta</h3><p class="leading-relaxed text-slate-700">Sopimuskausi ja vuosivertailu kattavat saman 12 kuukauden jakson.</p></div>
                        <div class="grid gap-2 py-5 md:grid-cols-[10rem_minmax(0,1fr)]"><h3 class="font-bold text-slate-900">24 kuukautta</h3><p class="leading-relaxed text-slate-700">Sopimus kestää kaksi vuotta, mutta vuosikustannus koskee vain seuraavia 12 kuukautta.</p></div>
                    </div>
                @endif
            </section>

            <section class="mb-20" aria-labelledby="history-heading">
                <div class="border-b border-slate-300 pb-4">
                    <h2 id="history-heading" class="text-2xl font-bold tracking-tight text-slate-900">Miten määräaikaisten sopimusten hinnat ovat muuttuneet?</h2>
                </div>
                @if ($historySeries->isEmpty() || $historySeries->every(fn ($series) => empty($series['points'])))
                    <p class="py-10 text-slate-700">Hintahistoriaa ei ole vielä riittävästi.</p>
                @else
                    <p id="fixed-history-takeaway" class="mt-6 max-w-[72ch] text-lg font-bold leading-relaxed text-slate-900">{{ $historyLead }}</p>
                    <p class="mt-3 max-w-[72ch] text-base leading-relaxed text-slate-700">Kaavio näyttää täysin kiinteiden 6, 12 ja 24 kuukauden sopimusten viikoittaiset mediaanihinnat enintään viimeisten 12 kuukauden ajalta.</p>
                    <p class="mt-4 max-w-[72ch] text-base leading-relaxed text-slate-700">
                        <strong class="font-bold text-slate-900">Tarkat muutokset:</strong>
                        @foreach ($historySeries->filter(fn ($series) => !empty($series['summary'])) as $series)
                            {{ $series['duration_months'] }} kk:n mediaani
                            @if ($series['summary']['direction'] === 'rose') nousi @elseif ($series['summary']['direction'] === 'fell') laski @else pysyi lähes ennallaan @endif
                            — alussa {{ $fmt($series['summary']['start_median']) }} c/kWh, lopussa {{ $fmt($series['summary']['end_median']) }} c/kWh{{ $loop->last ? '.' : ';' }}
                        @endforeach
                    </p>
                    <div class="mt-6 flex flex-wrap gap-x-6 gap-y-3 text-sm text-slate-700" aria-label="Hintahistoriakaavion selite">
                        <span class="flex items-center gap-2"><span class="block h-0 w-8 border-t-2 border-dashed border-slate-700" aria-hidden="true"></span><span>6 kk, kolmio</span></span>
                        <span class="flex items-center gap-2"><span class="block h-0 w-8 border-t-[3px] border-coral-600" aria-hidden="true"></span><span>12 kk, ympyrä</span></span>
                        <span class="flex items-center gap-2"><span class="block h-0 w-8 border-t-2 border-dotted border-slate-400" aria-hidden="true"></span><span>24 kk, vinoneliö</span></span>
                    </div>
                    <div class="relative mt-4" style="height: 340px;">
                        <canvas
                            id="fixedPriceHistoryChart"
                            data-chart="{{ json_encode($historyChart) }}"
                            role="img"
                            aria-label="Täysin kiinteiden 6, 12 ja 24 kuukauden sopimusten viikoittainen mediaanihinta."
                            aria-describedby="fixed-history-takeaway"
                        ></canvas>
                    </div>
                    <details class="mt-6 border-t border-slate-200 pt-4">
                        <summary class="cursor-pointer font-semibold text-slate-900 underline decoration-slate-300 underline-offset-4 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-coral-500">Näytä viikoittaiset hinnat</summary>
                        <div class="mt-4 max-h-96 overflow-auto">
                            <table class="w-full min-w-[44rem] border-collapse text-sm tabular-nums">
                                <caption class="sr-only">Täysin kiinteiden sopimusten viikoittaiset energiahinnat ja aineiston koko.</caption>
                                <thead class="sticky top-0 bg-white"><tr class="border-b border-slate-300 text-left text-slate-600"><th scope="col" class="py-2 pr-3 font-semibold">Viikko alkoi</th><th scope="col" class="px-3 py-2 font-semibold">Sopimusaika</th><th scope="col" class="px-3 py-2 text-right font-semibold">p20</th><th scope="col" class="px-3 py-2 text-right font-semibold">Mediaani</th><th scope="col" class="px-3 py-2 text-right font-semibold">p80</th><th scope="col" class="py-2 pl-3 text-right font-semibold">Sopimuksia</th></tr></thead>
                                <tbody class="divide-y divide-slate-100">
                                    @foreach ($historySeries as $series)
                                        @foreach ($series['points'] as $point)
                                            <tr><th scope="row" class="py-2 pr-3 text-left font-medium text-slate-900">{{ $fiDate($point['date']) }}</th><td class="px-3 py-2 text-slate-700">{{ $series['duration_months'] }} kk</td><td class="px-3 py-2 text-right text-slate-700">{{ $fmt($point['p20']) }}</td><td class="px-3 py-2 text-right font-semibold text-slate-900">{{ $fmt($point['median']) }}</td><td class="px-3 py-2 text-right text-slate-700">{{ $fmt($point['p80']) }}</td><td class="py-2 pl-3 text-right text-slate-700">{{ number_format($point['contract_count'], 0, ',', ' ') }}</td></tr>
                                        @endforeach
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    </details>
                @endif
            </section>

            <section class="mb-20" aria-labelledby="forecast-heading">
                <div class="border-b border-slate-300 pb-4">
                    <h2 id="forecast-heading" class="text-2xl font-bold tracking-tight text-slate-900">Mitä 30 päivän ennuste kertoo hinnoista?</h2>
                </div>
                @if (empty($forecast['date']))
                    <p class="py-10 text-slate-700">30 päivän ennustetta ei ole juuri nyt saatavilla.</p>
                @else
                    <p class="mt-5 max-w-[72ch] text-lg font-bold leading-relaxed text-slate-900">
                        @if (($forecast['direction_summary'] ?? 'none') === 'down') Mediaanihinta laskee hieman kaikissa saatavilla olevissa ennusteissa.
                        @elseif (($forecast['direction_summary'] ?? 'none') === 'up') Mediaanihinta nousee hieman kaikissa saatavilla olevissa ennusteissa.
                        @elseif (($forecast['direction_summary'] ?? 'none') === 'stable') Mediaanihinta pysyy lähes ennallaan kaikissa saatavilla olevissa ennusteissa.
                        @elseif (($forecast['direction_summary'] ?? 'none') === 'mixed') Mediaanihinnan suunta vaihtelee sopimusajan mukaan.
                        @else Yhdellekään sopimusajalle ei ole kattavaa 30 päivän ennustetta. @endif
                    </p>
                    <p class="mt-3 max-w-[72ch] text-base leading-relaxed text-slate-700">Ennuste kertoo mahdollisesta suunnasta. Se ei lupaa tulevaa hintaa.</p>
                    <p class="mt-2 text-sm text-slate-600">Ennuste laadittu <time datetime="{{ $forecast['date'] }}">{{ $fiDate($forecast['date']) }}</time>.</p>
                    <div class="mt-6 divide-y divide-slate-200 border-y border-slate-200">
                        @foreach ([6, 12, 24] as $duration)
                            @php
                                $durationForecast = $forecastDurations->get($duration);
                                $available = $durationForecast && $durationForecast['available'];
                                $change = $available ? (float) $durationForecast['median_change'] : null;
                            @endphp
                            <section class="py-6" aria-labelledby="forecast-{{ $duration }}-heading">
                                <div class="grid gap-4 md:grid-cols-[9rem_minmax(0,1fr)_auto] md:items-center">
                                    <div><h3 id="forecast-{{ $duration }}-heading" class="font-bold text-slate-900">{{ $duration }} kuukautta</h3>@if ($available)<p class="mt-1 text-sm text-slate-600">Ennusteen luotettavuus: <strong class="font-semibold text-slate-900">{{ $confidenceLabels[$durationForecast['confidence']] ?? 'ei ilmoitettu' }}</strong></p>@endif</div>
                                    @if (! $available)
                                        <p class="text-slate-700">Ennustetta ei ole saatavilla.</p>
                                    @else
                                        <div class="flex flex-wrap gap-x-6 gap-y-2 tabular-nums"><p><span class="text-slate-600">Nyt</span> <strong class="font-bold text-slate-900">{{ $fmt($durationForecast['current']['median']) }} c/kWh</strong></p><p><span class="text-slate-600">Ennuste</span> <strong class="font-bold text-slate-900">{{ $fmt($durationForecast['forecast']['median']) }} c/kWh</strong></p></div>
                                        <p class="font-bold tabular-nums text-slate-900">{{ $change < -0.005 ? 'Laskua '.$fmt(abs($change)).' c/kWh' : ($change > 0.005 ? 'Nousua '.$fmt(abs($change)).' c/kWh' : 'Lähes ennallaan') }}</p>
                                    @endif
                                </div>
                                @if ($available)
                                    <details class="mt-4"><summary class="cursor-pointer text-sm font-semibold text-slate-900 underline decoration-slate-300 underline-offset-4 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-coral-500">Näytä hintahaarukka ja ennusteen taustatiedot</summary><div class="mt-4 overflow-x-auto"><table class="w-full min-w-[34rem] border-collapse text-sm tabular-nums"><caption class="sr-only">{{ $duration }} kuukauden sopimusten nykyinen ja ennustettu hintaväli.</caption><thead><tr class="border-b border-slate-200 text-left text-slate-600"><th scope="col" class="py-2 pr-3 font-semibold">Hintataso</th><th scope="col" class="px-3 py-2 text-right font-semibold">Nyt</th><th scope="col" class="py-2 pl-3 text-right font-semibold">30 päivän ennuste</th></tr></thead><tbody class="divide-y divide-slate-100">@foreach (['p20' => 'p20', 'median' => 'Mediaani', 'p80' => 'p80'] as $quantile => $label)<tr><th scope="row" class="py-3 pr-3 text-left font-semibold text-slate-900">{{ $label }}</th><td class="px-3 py-3 text-right text-slate-700">{{ $fmt($durationForecast['current'][$quantile]) }} c/kWh</td><td class="py-3 pl-3 text-right font-semibold text-slate-900">{{ $fmt($durationForecast['forecast'][$quantile]) }} c/kWh</td></tr>@endforeach</tbody></table></div><p class="mt-3 text-sm leading-relaxed text-slate-600">Ennusteen kohdepäivä on {{ $fiDate($durationForecast['target_date']) }}. Mukana on {{ number_format($durationForecast['contract_count'], 0, ',', ' ') }} sopimusta. Ennusteen luotettavuus: {{ $confidenceLabels[$durationForecast['confidence']] ?? 'ei ilmoitettu' }}.</p></details>
                                @endif
                            </section>
                        @endforeach
                    </div>
                    <p class="mt-5 max-w-[72ch] text-base leading-relaxed text-slate-700">Varsinkin heikko ennuste voi muuttua nopeasti. Älä tee sopimuspäätöstä pelkän 30 päivän ennusteen perusteella.</p>
                @endif
            </section>

            <section class="mb-16 border-y border-slate-200 py-12" aria-labelledby="checklist-heading">
                <h2 id="checklist-heading" class="text-2xl font-bold tracking-tight text-slate-900">Tarkista nämä ennen tilaamista</h2>
                <ul class="mt-6 divide-y divide-slate-200 border-y border-slate-200 text-base leading-relaxed text-slate-700">
                    <li class="grid gap-2 py-5 md:grid-cols-[12rem_minmax(0,1fr)]"><strong class="text-slate-900">Miten hinta määräytyy</strong><span>Tarkista, pysyykö energiahinta samana vai muuttuuko se markkinan, uuden hintajakson tai kulutuksen ajoituksen mukaan.</span></li>
                    <li class="grid gap-2 py-5 md:grid-cols-[12rem_minmax(0,1fr)]"><strong class="text-slate-900">Koko kustannus</strong><span>Laske energian ja kuukausimaksujen vuosikustannus omalla kulutuksellasi.</span></li>
                    <li class="grid gap-2 py-5 md:grid-cols-[12rem_minmax(0,1fr)]"><strong class="text-slate-900">Sopimuksen kesto</strong><span>Valitse sopimusaika, joka sopii omaan tilanteeseesi. Lue myyjän ehdot ennen tilaamista.</span></li>
                    <li class="grid gap-2 py-5 md:grid-cols-[12rem_minmax(0,1fr)]"><strong class="text-slate-900">Mikä on arvio</strong><span>Erota tunnettu energiahinta tulevien markkinahintojen ja hintajaksojen arvioista.</span></li>
                </ul>
            </section>

            <section aria-labelledby="offers-heading">
                <h2 id="offers-heading" class="text-2xl font-bold tracking-tight text-slate-900">Vertaa määräaikaisia tarjouksia</h2>
                <p class="mt-3 max-w-[70ch] text-base leading-relaxed text-slate-700">Mediaanikustannus antaa vertailukohdan. Tarkista tarjouksen vuosikustannus, hinnoittelutapa ja ehdot ennen tilaamista.</p>
                <div class="mt-6 flex flex-wrap gap-3">
                    <a href="/sahkosopimus/maaraaikainen" class="rounded-xl bg-coral-600 px-5 py-3 font-bold text-white hover:bg-coral-500 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-coral-500">Vertaa kaikkia määräaikaisia</a>
                    <a href="/sahkosopimus/maaraaikainen-6-kk" class="rounded-xl border border-slate-300 px-5 py-3 font-bold text-slate-900 hover:border-slate-500 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-coral-500">Vertaa 6 kk:n sopimuksia</a>
                    <a href="/sahkosopimus/maaraaikainen-12-kk" class="rounded-xl border border-slate-300 px-5 py-3 font-bold text-slate-900 hover:border-slate-500 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-coral-500">Vertaa 12 kk:n sopimuksia</a>
                    <a href="/sahkosopimus/maaraaikainen-24-kk" class="rounded-xl border border-slate-300 px-5 py-3 font-bold text-slate-900 hover:border-slate-500 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-coral-500">Vertaa 24 kk:n sopimuksia</a>
                </div>
            </section>
        </article>

        <x-methodology-byline updated="31.8.2026" class="mt-16 border-t border-slate-200 pt-6" />
    </main>

    @push('scripts')
        <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
        <script>
            function initFixedTermArticleCharts() {
                const formatNumber = (value, decimals = 0) => Number(value).toLocaleString('fi-FI', {
                    minimumFractionDigits: decimals,
                    maximumFractionDigits: decimals,
                });
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
                                        backgroundColor: payload.segment_keys.map((key) => key === payload.benchmark_segment_key ? '#ea580c' : '#64748b'),
                                        borderRadius: 4,
                                        borderSkipped: false,
                                    }],
                                },
                                options: {
                                    indexAxis: 'y',
                                    responsive: true,
                                    maintainAspectRatio: false,
                                    animation: false,
                                    plugins: {
                                        legend: { display: false },
                                        tooltip: { callbacks: { label: (context) => ` ${formatNumber(context.parsed.x, 0)} €/vuosi` } },
                                    },
                                    scales: {
                                        x: {
                                            beginAtZero: true,
                                            title: { display: true, text: 'Arvioitu vuosikustannus' },
                                            ticks: { callback: (value) => `${formatNumber(value, 0)} €` },
                                            grid: { color: '#e2e8f0' },
                                        },
                                        y: { grid: { display: false } },
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
                            6: { color: '#334155', dash: [7, 4], pointStyle: 'triangle' },
                            12: { color: '#ea580c', dash: [], pointStyle: 'circle' },
                            24: { color: '#94a3b8', dash: [2, 4], pointStyle: 'rectRot' },
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
                                        tooltip: { callbacks: { label: (context) => context.parsed.y === null ? null : ` ${context.dataset.label}: ${formatNumber(context.parsed.y, 2)} c/kWh` } },
                                    },
                                    scales: {
                                        x: { grid: { display: false }, ticks: { autoSkip: true, maxRotation: 0, maxTicksLimit: 7 } },
                                        y: {
                                            title: { display: true, text: 'Energiahinta' },
                                            ticks: { callback: (value) => `${formatNumber(value, 1)} c` },
                                            grid: { color: '#e2e8f0' },
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
