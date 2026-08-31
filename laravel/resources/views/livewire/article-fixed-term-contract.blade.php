@php
    use Illuminate\Support\Carbon;

    $current = $articleData['current'] ?? [];
    $priceOfCertainty = $articleData['price_of_certainty'] ?? [];
    $history = $articleData['history'] ?? [];
    $forecast = $articleData['forecast'] ?? [];
    $currentRows = collect($current['rows'] ?? []);
    $currentRowsBySegment = $currentRows->keyBy('segment_key');
    $priceOfCertaintyRows = collect($priceOfCertainty['rows'] ?? [])->keyBy('segment_key');
    $openEndedAnnual = $priceOfCertaintyRows->get('open_ended');
    $fixedTwelveAnnual = $priceOfCertaintyRows->get('fixed_term_12');
    $annualMedianDifference = $priceOfCertainty['median_difference_eur'] ?? null;
    $monthlyMedianDifference = $priceOfCertainty['median_difference_monthly_eur'] ?? null;
    $differenceDirection = $priceOfCertainty['difference_direction'] ?? null;
    $differenceIsSmall = (bool) ($priceOfCertainty['difference_is_small'] ?? false);
    $historySeries = collect($history['series'] ?? []);
    $historyTicks = collect($history['ticks'] ?? []);
    $forecastDurations = collect($forecast['durations'] ?? [])->keyBy('duration_months');
    $dataDate = $articleData['data_date'] ?? null;
    $fiDate = fn ($date) => $date ? Carbon::parse($date)->translatedFormat('j.n.Y') : 'Ei saatavilla';
    $fmt = fn ($value, $decimals = 2) => $value === null ? 'Ei saatavilla' : number_format((float) $value, $decimals, ',', ' ');
    $fmtSigned = function ($value, $decimals = 2) {
        if ($value === null) return 'Ei saatavilla';
        if (round((float) $value, $decimals) == 0.0) return '0'.($decimals > 0 ? ','.str_repeat('0', $decimals) : '');
        return ((float) $value > 0 ? '+' : '−').number_format(abs((float) $value), $decimals, ',', ' ');
    };
    $confidenceLabels = [
        'high' => 'korkea',
        'medium' => 'keskitaso',
        'low' => 'matala',
    ];
    $segmentLabels = [
        'open_ended' => 'Toistaiseksi voimassa oleva, nykyinen kiinteä hinta',
        'fixed_term_6' => '6 kk, täysin kiinteä hinta',
        'fixed_term_12' => '12 kk, täysin kiinteä hinta',
        'fixed_term_24' => '24 kk, täysin kiinteä hinta',
    ];
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
                <li class="font-medium text-slate-900" aria-current="page">Kannattaako määräaikainen</li>
            </ol>
        </nav>

        <header class="border-b border-slate-200 pb-10">
            <h1 class="max-w-[24ch] text-3xl font-extrabold leading-[1.08] tracking-tight text-slate-900 md:text-5xl">
                Kannattaako määräaikainen sähkösopimus?
            </h1>
            <p class="mt-5 max-w-[68ch] text-lg leading-relaxed text-slate-600">
                Katso, onko 12 kuukauden kiinteä sopimus nyt hyvä valinta ja mitä pidempi tai lyhyempi sopimusaika maksaa.
            </p>
            <dl class="mt-8 grid gap-5 text-sm sm:grid-cols-2">
                <div>
                    <dt class="font-semibold text-slate-600">Uusimmat markkina- ja ennustetiedot</dt>
                    <dd class="mt-1 font-bold tabular-nums text-slate-900">{{ $fiDate($dataDate) }}</dd>
                </div>
                <div>
                    <dt class="font-semibold text-slate-600">Teksti tarkistettu</dt>
                    <dd class="mt-1 font-bold tabular-nums text-slate-900">{{ $fiDate($editorialReviewDate) }}</dd>
                </div>
            </dl>
        </header>

        <x-page-action-strip class="mb-12" />

        <article>
            <section class="mb-20 border-y border-slate-200 bg-slate-50 px-5 py-10 sm:px-8" aria-labelledby="conclusion-heading">
                <h2 id="conclusion-heading" class="max-w-[34ch] text-2xl font-extrabold leading-tight tracking-tight text-slate-900 md:text-3xl">
                    @if ($annualMedianDifference !== null && $fixedTwelveAnnual && $openEndedAnnual)
                        @if ($differenceIsSmall)
                            Hinnat ovat nyt lähellä toisiaan. Täysin kiinteä 12 kuukauden sopimus on järkevä valinta, jos haluat varmistaa energian hinnan vuodeksi.
                        @elseif ($differenceDirection === 'fixed_12_cheaper')
                            Täysin kiinteä 12 kuukauden sopimus on nyt vertailun edullisempi vaihtoehto ja varmistaa energian hinnan vuodeksi.
                        @elseif ($differenceDirection === 'open_ended_cheaper')
                            Toistaiseksi voimassa olevan sopimuksen arvio on nyt edullisempi, mutta sen hinta voi muuttua myöhemmin.
                        @else
                            Molempien vaihtoehtojen keskimmäinen vuosihinta on nyt sama. Valinta riippuu siitä, haluatko varmistaa hinnan vuodeksi.
                        @endif
                    @else
                        Vuosihintojen vertailu ei ole juuri nyt saatavilla. Valitse vasta, kun voit verrata hintaa ja sopimusaikaa yhdessä.
                    @endif
                </h2>

                @if ($annualMedianDifference !== null && $fixedTwelveAnnual && $openEndedAnnual)
                    <p class="mt-5 max-w-[70ch] text-lg leading-relaxed text-slate-700">
                        @if ($differenceDirection === 'fixed_12_cheaper')
                            12 kuukauden sopimuksen arvioitu vuosihinta on <strong class="font-extrabold tabular-nums text-slate-900">{{ $fmt($fixedTwelveAnnual['median'], 0) }} €</strong> ja toistaiseksi voimassa olevan <strong class="font-extrabold tabular-nums text-slate-900">{{ $fmt($openEndedAnnual['median'], 0) }} €</strong>. Ero on 12 kuukauden sopimuksen hyväksi <strong class="font-extrabold tabular-nums text-slate-900">{{ $fmt(abs($annualMedianDifference), 0) }} € vuodessa</strong> eli <strong class="font-extrabold tabular-nums text-slate-900">{{ $fmt(abs($monthlyMedianDifference), 2) }} € kuukaudessa</strong>.
                        @elseif ($differenceDirection === 'open_ended_cheaper')
                            12 kuukauden sopimuksen arvioitu vuosihinta on <strong class="font-extrabold tabular-nums text-slate-900">{{ $fmt($fixedTwelveAnnual['median'], 0) }} €</strong> ja toistaiseksi voimassa olevan <strong class="font-extrabold tabular-nums text-slate-900">{{ $fmt($openEndedAnnual['median'], 0) }} €</strong>. Ero on toistaiseksi voimassa olevan sopimuksen hyväksi <strong class="font-extrabold tabular-nums text-slate-900">{{ $fmt(abs($annualMedianDifference), 0) }} € vuodessa</strong> eli <strong class="font-extrabold tabular-nums text-slate-900">{{ $fmt(abs($monthlyMedianDifference), 2) }} € kuukaudessa</strong>.
                        @else
                            Molempien arvioitu vuosihinta on sama.
                        @endif
                        @if ($differenceIsSmall)
                            Ero on pieni, joten valinnassa painaa hintaeron lisäksi se, haluatko hinnan pysyvän samana koko vuoden.
                        @endif
                    </p>
                    <p class="mt-4 text-sm text-slate-600">Vertailun päivä: <time datetime="{{ $priceOfCertainty['date'] }}">{{ $fiDate($priceOfCertainty['date']) }}</time>.</p>
                @endif
            </section>

            <section class="mb-20" aria-labelledby="concept-heading">
                <h2 id="concept-heading" class="text-2xl font-bold tracking-tight text-slate-900">Mitä tässä verrataan?</h2>
                <div class="mt-4 max-w-[72ch] space-y-4 text-base leading-relaxed text-slate-700">
                    <p>
                        Sopimuksen pituus ja hinnan toiminta ovat eri asioita. Tämän sivun 6, 12 ja 24 kuukauden luvut koskevat sopimuksia, joissa energian hinta pysyy samana koko sopimusajan. Kaikki määräaikaiset sopimukset eivät ole tällaisia.
                    </p>
                    <p>
                        Vertailukohta on toistaiseksi voimassa oleva kiinteähintainen sopimus. Sen tämänhetkinen hinta on tiedossa, mutta myyjä voi muuttaa sitä ehtojen mukaisesti. Siksi sen tulevan vuoden kustannus on arvio. Mediaani tarkoittaa listattujen hintojen keskimmäistä hintaa.
                    </p>
                </div>
            </section>

            <section class="mb-20" aria-labelledby="annual-heading">
                <div class="border-b border-slate-300 pb-4">
                    <h2 id="annual-heading" class="text-2xl font-bold tracking-tight text-slate-900">Mitä sopimus maksaisi vuodessa?</h2>
                    <p class="mt-2 max-w-[72ch] text-base leading-relaxed text-slate-600">
                        Arvio perustuu 5 000 kWh vuosikulutukseen. Se sisältää energian ja kuukausimaksut, mutta ei sähkönsiirtoa.
                    </p>
                </div>

                @if (! $openEndedAnnual || ! $fixedTwelveAnnual || $annualMedianDifference === null)
                    <p class="py-10 text-slate-700">Vuosihintojen vertailua ei ole juuri nyt saatavilla.</p>
                @else
                    <dl class="mt-6 divide-y divide-slate-200 border-y border-slate-200">
                        <div class="grid gap-2 py-5 sm:grid-cols-[minmax(0,1fr)_auto] sm:items-baseline">
                            <dt class="font-bold text-slate-900">Toistaiseksi voimassa oleva, arvio seuraavalle 12 kuukaudelle</dt>
                            <dd class="text-2xl font-extrabold tabular-nums text-slate-900">{{ $fmt($openEndedAnnual['median'], 0) }} €/vuosi</dd>
                        </div>
                        <div class="grid gap-2 py-5 sm:grid-cols-[minmax(0,1fr)_auto] sm:items-baseline">
                            <dt class="font-bold text-slate-900">12 kk, täysin kiinteä hinta</dt>
                            <dd class="text-2xl font-extrabold tabular-nums text-slate-900">{{ $fmt($fixedTwelveAnnual['median'], 0) }} €/vuosi</dd>
                        </div>
                    </dl>
                    <p class="mt-5 max-w-[72ch] text-base leading-relaxed text-slate-700">
                        @if ($differenceDirection === 'fixed_12_cheaper')
                            12 kuukauden sopimus on noin <strong class="font-bold tabular-nums text-slate-900">{{ $fmt(abs($annualMedianDifference), 0) }} €/vuosi</strong> eli <strong class="font-bold tabular-nums text-slate-900">{{ $fmt(abs($monthlyMedianDifference), 2) }} €/kuukausi</strong> halvempi.
                        @elseif ($differenceDirection === 'open_ended_cheaper')
                            12 kuukauden sopimus on noin <strong class="font-bold tabular-nums text-slate-900">{{ $fmt(abs($annualMedianDifference), 0) }} €/vuosi</strong> eli <strong class="font-bold tabular-nums text-slate-900">{{ $fmt(abs($monthlyMedianDifference), 2) }} €/kuukausi</strong> kalliimpi.
                        @else
                            Arvioidut vuosihinnat ovat samat.
                        @endif
                    </p>
                    <p class="mt-2 text-sm text-slate-600">Hinnat <time datetime="{{ $priceOfCertainty['date'] }}">{{ $fiDate($priceOfCertainty['date']) }}</time>.</p>

                    <details class="mt-6 border-t border-slate-200 pt-4">
                        <summary class="cursor-pointer font-semibold text-slate-900 underline decoration-slate-300 underline-offset-4 hover:decoration-coral-500">
                            Katso hintojen vaihteluväli ja aineiston koko
                        </summary>
                        <div class="mt-4 max-w-[72ch] text-sm leading-relaxed text-slate-600">
                            <p>p20–p80 on listattujen hintojen keskimmäinen 60 %. Sen ulkopuolelle jää edullisin ja kallein viidennes.</p>
                        </div>
                        <div class="mt-4 overflow-x-auto">
                            <table class="w-full min-w-[38rem] border-collapse text-sm">
                                <caption class="sr-only">Vuosihintojen vaihteluväli ja sopimusten määrä</caption>
                                <thead>
                                    <tr class="border-b border-slate-300 text-left text-slate-600">
                                        <th class="py-2 pr-3 font-semibold">Sopimustyyppi</th>
                                        <th class="px-3 py-2 text-right font-semibold">p20</th>
                                        <th class="px-3 py-2 text-right font-semibold">Keskimmäinen hinta</th>
                                        <th class="px-3 py-2 text-right font-semibold">p80</th>
                                        <th class="py-2 pl-3 text-right font-semibold">Sopimuksia</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-slate-100">
                                    @foreach ([$openEndedAnnual, $fixedTwelveAnnual] as $row)
                                        <tr>
                                            <th scope="row" class="py-3 pr-3 text-left font-semibold text-slate-900">{{ $row['segment_key'] === 'open_ended' ? 'Toistaiseksi voimassa oleva' : '12 kk, täysin kiinteä' }}</th>
                                            <td class="px-3 py-3 text-right tabular-nums text-slate-700">{{ $fmt($row['p20'], 0) }} €</td>
                                            <td class="px-3 py-3 text-right font-semibold tabular-nums text-slate-900">{{ $fmt($row['median'], 0) }} €</td>
                                            <td class="px-3 py-3 text-right tabular-nums text-slate-700">{{ $fmt($row['p80'], 0) }} €</td>
                                            <td class="py-3 pl-3 text-right tabular-nums text-slate-700">{{ number_format($row['contract_count'], 0, ',', ' ') }}</td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                        <p class="mt-4 max-w-[72ch] text-sm leading-relaxed text-slate-600">
                            Luvut ovat valmiiksi laskettuja markkinatilastoja samalta päivältä. Yksittäinen tarjous voi olla keskimmäistä hintaa halvempi tai kalliimpi.
                        </p>
                    </details>
                @endif
            </section>

            <section class="mb-20" aria-labelledby="current-heading">
                <div class="border-b border-slate-300 pb-4">
                    <h2 id="current-heading" class="text-2xl font-bold tracking-tight text-slate-900">Mitä energiahinta on nyt?</h2>
                    <p class="mt-2 max-w-[72ch] text-base leading-relaxed text-slate-600">
                        Nämä ovat neljän vaihtoehdon keskimmäiset energiahinnat. Kuukausimaksut eivät ole tässä mukana.
                    </p>
                </div>

                @if ($currentRows->isEmpty())
                    <p class="py-10 text-slate-700">Saman päivän energiahintoja ei ole juuri nyt saatavilla.</p>
                @else
                    <dl class="mt-6 divide-y divide-slate-200 border-y border-slate-200">
                        @foreach ($currentRows as $row)
                            <div class="grid gap-2 py-4 sm:grid-cols-[minmax(0,1fr)_auto] sm:items-baseline">
                                <dt class="font-semibold text-slate-900">{{ $segmentLabels[$row['segment_key']] }}</dt>
                                <dd class="text-xl font-extrabold tabular-nums text-slate-900">{{ $fmt($row['median']) }} c/kWh</dd>
                            </div>
                        @endforeach
                    </dl>
                    <p class="mt-3 text-sm text-slate-600">Hinnat <time datetime="{{ $current['date'] }}">{{ $fiDate($current['date']) }}</time>.</p>

                    <details class="mt-6 border-t border-slate-200 pt-4">
                        <summary class="cursor-pointer font-semibold text-slate-900 underline decoration-slate-300 underline-offset-4 hover:decoration-coral-500">
                            Katso hintojen vaihteluväli ja sopimusten määrä
                        </summary>
                        <div class="mt-4 overflow-x-auto">
                            <table class="w-full min-w-[40rem] border-collapse text-sm">
                                <caption class="sr-only">Energiahintojen vaihteluväli ja sopimusten määrä</caption>
                                <thead>
                                    <tr class="border-b border-slate-300 text-left text-slate-600">
                                        <th class="py-2 pr-3 font-semibold">Sopimustyyppi</th>
                                        <th class="px-3 py-2 text-right font-semibold">p20</th>
                                        <th class="px-3 py-2 text-right font-semibold">Keskimmäinen hinta</th>
                                        <th class="px-3 py-2 text-right font-semibold">p80</th>
                                        <th class="py-2 pl-3 text-right font-semibold">Sopimuksia</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-slate-100">
                                    @foreach ($currentRows as $row)
                                        <tr>
                                            <th scope="row" class="py-3 pr-3 text-left font-semibold text-slate-900">{{ $segmentLabels[$row['segment_key']] }}</th>
                                            <td class="px-3 py-3 text-right tabular-nums text-slate-700">{{ $fmt($row['p20']) }}</td>
                                            <td class="px-3 py-3 text-right font-semibold tabular-nums text-slate-900">{{ $fmt($row['median']) }}</td>
                                            <td class="px-3 py-3 text-right tabular-nums text-slate-700">{{ $fmt($row['p80']) }}</td>
                                            <td class="py-3 pl-3 text-right tabular-nums text-slate-700">{{ number_format($row['contract_count'], 0, ',', ' ') }}</td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    </details>
                @endif
            </section>

            <section class="mb-20" aria-labelledby="duration-heading">
                <div class="border-b border-slate-300 pb-4">
                    <h2 id="duration-heading" class="text-2xl font-bold tracking-tight text-slate-900">Miten sopimuspituutta kannattaa ajatella?</h2>
                    <p class="mt-2 max-w-[72ch] text-base leading-relaxed text-slate-600">Määräaikaisen sopimuksen pituus kertoo, kuinka kauan nykyinen energiahinta sitoo sinua ja myyjää. Näin vaihtoehdot eroavat nykyisillä hinnoilla.</p>
                </div>

                @if ($currentRows->isEmpty())
                    <p class="py-10 text-slate-700">Sopimuspituuksia ei voi asettaa hintajärjestykseen ilman saman päivän tietoja.</p>
                @else
                    <div class="mt-6 divide-y divide-slate-200 border-y border-slate-200">
                        @if ($currentRowsBySegment->has('fixed_term_12'))
                            <div class="grid gap-2 py-5 md:grid-cols-[10rem_minmax(0,1fr)]">
                                <h3 class="font-bold text-slate-900">12 kuukautta</h3>
                                <p class="leading-relaxed text-slate-700">
                                    @if ($differenceIsSmall)
                                        Tasapainoinen yhden vuoden valinta: hinta on lähellä toistaiseksi voimassa olevan arviota, mutta energiahinta pysyy samana koko vuoden.
                                    @else
                                        Yhden vuoden vaihtoehto. Nykyinen keskimmäinen energiahinta on {{ $fmt($currentRowsBySegment['fixed_term_12']['median']) }} c/kWh.
                                    @endif
                                </p>
                            </div>
                        @endif
                        @if ($currentRowsBySegment->has('fixed_term_24'))
                            <div class="grid gap-2 py-5 md:grid-cols-[10rem_minmax(0,1fr)]">
                                <h3 class="font-bold text-slate-900">24 kuukautta</h3>
                                <p class="leading-relaxed text-slate-700">
                                    @if (($current['lowest_fixed_duration_months'] ?? null) === 24)
                                        Matalin nykyinen keskimmäinen energiahinta, {{ $fmt($currentRowsBySegment['fixed_term_24']['median']) }} c/kWh, mutta myös pisin sitoutuminen.
                                    @else
                                        Nykyinen keskimmäinen energiahinta on {{ $fmt($currentRowsBySegment['fixed_term_24']['median']) }} c/kWh. Tämä on pisin vertailtu sitoutuminen.
                                    @endif
                                </p>
                            </div>
                        @endif
                        @if ($currentRowsBySegment->has('fixed_term_6'))
                            <div class="grid gap-2 py-5 md:grid-cols-[10rem_minmax(0,1fr)]">
                                <h3 class="font-bold text-slate-900">6 kuukautta</h3>
                                <p class="leading-relaxed text-slate-700">
                                    @if (($current['highest_fixed_duration_months'] ?? null) === 6)
                                        Korkein nykyinen keskimmäinen energiahinta, {{ $fmt($currentRowsBySegment['fixed_term_6']['median']) }} c/kWh, mutta lyhin vertailtu sitoutuminen.
                                    @else
                                        Nykyinen keskimmäinen energiahinta on {{ $fmt($currentRowsBySegment['fixed_term_6']['median']) }} c/kWh. Tämä on lyhin vertailtu sitoutuminen.
                                    @endif
                                </p>
                            </div>
                        @endif
                        @if ($currentRowsBySegment->has('open_ended'))
                            <div class="grid gap-2 py-5 md:grid-cols-[10rem_minmax(0,1fr)]">
                                <h3 class="font-bold text-slate-900">Toistaiseksi</h3>
                                <p class="leading-relaxed text-slate-700">Nykyinen julkaistu hinta on {{ $fmt($currentRowsBySegment['open_ended']['median']) }} c/kWh. Hinta voi muuttua myöhemmin sopimusehtojen mukaisesti.</p>
                            </div>
                        @endif
                    </div>
                @endif
            </section>

            <section class="mb-20" aria-labelledby="history-heading">
                <div class="border-b border-slate-300 pb-4">
                    <h2 id="history-heading" class="text-2xl font-bold tracking-tight text-slate-900">Miten määräaikaisten hinnat ovat muuttuneet?</h2>
                    <p class="mt-2 max-w-[72ch] text-base leading-relaxed text-slate-600">
                        Viikkotiedot näyttävät hintojen suunnan enintään viimeisen 12 kuukauden ajalta. Kaikissa kuvissa on sama asteikko.
                    </p>
                </div>

                @if ($historySeries->isEmpty() || $historySeries->every(fn ($series) => empty($series['points'])))
                    <p class="py-10 text-slate-700">Hintahistoriaa ei ole vielä riittävästi.</p>
                @else
                    <div class="mt-6 flex flex-wrap gap-x-6 gap-y-3 text-sm text-slate-700" aria-label="Kaavioiden selite">
                        <div class="flex items-center gap-2">
                            <span class="block h-0 w-8 border-t-[3px] border-coral-600" aria-hidden="true"></span>
                            <span><strong class="font-semibold text-slate-900">Koralliviiva:</strong> keskimmäinen hinta</span>
                        </div>
                        <div class="flex items-center gap-2">
                            <span class="block h-4 w-8 border border-slate-300 bg-slate-100" aria-hidden="true"></span>
                            <span><strong class="font-semibold text-slate-900">Vaalea alue:</strong> hintojen keskimmäinen 60 % (p20–p80)</span>
                        </div>
                    </div>

                    <div class="mt-10 grid gap-14">
                        @foreach ($historySeries as $series)
                            @php
                                $points = collect($series['points'] ?? []);
                                $summary = $series['summary'] ?? null;
                                $scaleMin = (float) ($history['scale_min'] ?? 0);
                                $scaleMax = (float) ($history['scale_max'] ?? 0);
                                $scaleSpan = max(0.0001, $scaleMax - $scaleMin);
                                $plotWidth = 720;
                                $plotHeight = 208;
                                $pointCount = $points->count();
                                $x = fn ($index) => $pointCount <= 1 ? $plotWidth / 2 : ($index / ($pointCount - 1)) * $plotWidth;
                                $y = fn ($value) => (($scaleMax - (float) $value) / $scaleSpan) * $plotHeight;
                                $upper = $points->values()->map(fn ($point, $index) => round($x($index), 2).','.round($y($point['p80']), 2))->implode(' ');
                                $lower = $points->values()->reverse()->values()->map(fn ($point, $reverseIndex) => round($x($pointCount - 1 - $reverseIndex), 2).','.round($y($point['p20']), 2))->implode(' ');
                                $medianLine = $points->values()->map(fn ($point, $index) => round($x($index), 2).','.round($y($point['median']), 2))->implode(' ');
                                $chartId = 'fixed-history-'.$series['duration_months'];
                            @endphp
                            <figure aria-labelledby="{{ $chartId }}-heading" @if ($summary) aria-describedby="{{ $chartId }}-summary" @endif>
                                <h3 id="{{ $chartId }}-heading" class="text-lg font-bold text-slate-900">{{ $series['duration_months'] }} kuukauden sopimukset</h3>
                                @if ($points->isEmpty())
                                    <p class="mt-3 border-y border-slate-200 py-8 text-slate-700">Tälle sopimusajalle ei ole hintahistoriaa.</p>
                                @else
                                    @if ($summary)
                                        <p id="{{ $chartId }}-summary" class="mt-2 max-w-[72ch] leading-relaxed text-slate-700">
                                            Keskimmäinen hinta
                                            @if ($summary['direction'] === 'rose')
                                                nousi
                                            @elseif ($summary['direction'] === 'fell')
                                                laski
                                            @else
                                                pysyi lähes ennallaan
                                            @endif
                                            tasolta <strong class="font-semibold tabular-nums text-slate-900">{{ $fmt($summary['start_median']) }} c/kWh</strong> tasolle <strong class="font-semibold tabular-nums text-slate-900">{{ $fmt($summary['end_median']) }} c/kWh</strong> aikavälillä {{ $fiDate($summary['start_date']) }}–{{ $fiDate($summary['end_date']) }}.
                                        </p>
                                    @endif

                                    <p class="mt-5 text-sm font-semibold text-slate-700">Hinta, c/kWh</p>
                                    <div class="mt-3 grid grid-cols-[76px_minmax(0,1fr)] gap-x-2">
                                        <div class="relative h-44 overflow-visible md:h-52" aria-hidden="true">
                                            @foreach ($historyTicks as $tick)
                                                <span class="absolute right-1 -translate-y-1/2 whitespace-nowrap text-sm font-medium tabular-nums text-slate-600" style="top: {{ $tick['percent'] }}%">
                                                    {{ $fmt($tick['value'], abs($tick['value'] - round($tick['value'])) < 0.001 ? 0 : 1) }} c/kWh
                                                </span>
                                            @endforeach
                                        </div>
                                        <svg viewBox="0 0 720 208" preserveAspectRatio="none" class="h-44 w-full overflow-visible md:h-52" role="img" aria-labelledby="{{ $chartId }}-title {{ $chartId }}-desc">
                                            <title id="{{ $chartId }}-title">{{ $series['duration_months'] }} kuukauden sopimusten hintakehitys</title>
                                            <desc id="{{ $chartId }}-desc">Koralliviiva näyttää keskimmäisen hinnan. Vaalea alue näyttää hintojen keskimmäisen 60 prosentin vaihteluvälin. Tarkat viikkoarvot ovat kuvan jälkeen avattavassa taulukossa.</desc>
                                            @foreach ($historyTicks as $tick)
                                                @php $tickY = ($tick['percent'] / 100) * $plotHeight; @endphp
                                                <line x1="0" y1="{{ $tickY }}" x2="720" y2="{{ $tickY }}" stroke="#cbd5e1" stroke-width="1" vector-effect="non-scaling-stroke" />
                                            @endforeach
                                            <polygon points="{{ $upper }} {{ $lower }}" fill="#f1f5f9" stroke="#cbd5e1" stroke-width="1" vector-effect="non-scaling-stroke" />
                                            <polyline points="{{ $medianLine }}" fill="none" stroke="#ea580c" stroke-width="3" stroke-linejoin="round" stroke-linecap="round" vector-effect="non-scaling-stroke" />
                                        </svg>
                                        <div aria-hidden="true"></div>
                                        <div class="mt-3 flex justify-between gap-3 text-sm tabular-nums text-slate-600">
                                            <span>{{ $fiDate($points->first()['date']) }}</span>
                                            <span>{{ $fiDate($points->last()['date']) }}</span>
                                        </div>
                                    </div>

                                    <details class="mt-5 border-t border-slate-200 pt-4">
                                        <summary class="cursor-pointer font-semibold text-slate-900 underline decoration-slate-300 underline-offset-4 hover:decoration-coral-500">
                                            Katso kaikki viikkoarvot
                                        </summary>
                                        <div class="mt-4 max-h-96 overflow-auto">
                                            <table class="w-full min-w-[38rem] border-collapse text-sm">
                                                <thead class="sticky top-0 bg-white">
                                                    <tr class="border-b border-slate-300 text-left text-slate-600">
                                                        <th class="py-2 pr-3 font-semibold">Viikko alkoi</th>
                                                        <th class="px-3 py-2 text-right font-semibold">p20</th>
                                                        <th class="px-3 py-2 text-right font-semibold">Keskimmäinen hinta</th>
                                                        <th class="px-3 py-2 text-right font-semibold">p80</th>
                                                        <th class="py-2 pl-3 text-right font-semibold">Sopimuksia</th>
                                                    </tr>
                                                </thead>
                                                <tbody class="divide-y divide-slate-100">
                                                    @foreach ($points as $point)
                                                        <tr>
                                                            <th scope="row" class="py-2 pr-3 text-left font-medium tabular-nums text-slate-900">{{ $fiDate($point['date']) }}</th>
                                                            <td class="px-3 py-2 text-right tabular-nums text-slate-700">{{ $fmt($point['p20']) }}</td>
                                                            <td class="px-3 py-2 text-right font-semibold tabular-nums text-slate-900">{{ $fmt($point['median']) }}</td>
                                                            <td class="px-3 py-2 text-right tabular-nums text-slate-700">{{ $fmt($point['p80']) }}</td>
                                                            <td class="py-2 pl-3 text-right tabular-nums text-slate-700">{{ number_format($point['contract_count'], 0, ',', ' ') }}</td>
                                                        </tr>
                                                    @endforeach
                                                </tbody>
                                            </table>
                                        </div>
                                    </details>
                                @endif
                            </figure>
                        @endforeach
                    </div>
                @endif
            </section>

            <section class="mb-20" aria-labelledby="forecast-heading">
                <div class="border-b border-slate-300 pb-4">
                    <h2 id="forecast-heading" class="text-2xl font-bold tracking-tight text-slate-900">Mitä hinnalle voi tapahtua seuraavan 30 päivän aikana?</h2>
                    <p class="mt-2 max-w-[72ch] text-base leading-relaxed text-slate-600">
                        Ennuste näyttää mahdollisen suunnan, ei lupausta tulevasta hinnasta.
                    </p>
                </div>

                @if (empty($forecast['date']))
                    <p class="py-10 text-slate-700">30 päivän ennustetta ei ole juuri nyt saatavilla.</p>
                @else
                    <p class="mt-5 max-w-[72ch] text-lg font-bold leading-relaxed text-slate-900">
                        @if (($forecast['direction_summary'] ?? 'none') === 'down')
                            Kaikki saatavilla olevat ennusteet viittaavat hienoiseen laskuun.
                        @elseif (($forecast['direction_summary'] ?? 'none') === 'up')
                            Kaikki saatavilla olevat ennusteet viittaavat hienoiseen nousuun.
                        @elseif (($forecast['direction_summary'] ?? 'none') === 'stable')
                            Saatavilla olevat ennusteet viittaavat lähes ennallaan pysyvään hintaan.
                        @elseif (($forecast['direction_summary'] ?? 'none') === 'mixed')
                            Ennusteiden suunta vaihtelee sopimuspituuden mukaan.
                        @else
                            Yhtään täydellistä 30 päivän ennustetta ei ole saatavilla.
                        @endif
                    </p>
                    <p class="mt-2 text-sm text-slate-600">Ennuste tehty <time datetime="{{ $forecast['date'] }}">{{ $fiDate($forecast['date']) }}</time>.</p>

                    <div class="mt-6 divide-y divide-slate-200 border-y border-slate-200">
                        @foreach ([6, 12, 24] as $duration)
                            @php
                                $durationForecast = $forecastDurations->get($duration);
                                $available = $durationForecast && $durationForecast['available'];
                                $change = $available ? (float) $durationForecast['median_change'] : null;
                            @endphp
                            <section class="py-6" aria-labelledby="forecast-{{ $duration }}-heading">
                                <div class="grid gap-4 md:grid-cols-[9rem_minmax(0,1fr)_auto] md:items-center">
                                    <div>
                                        <h3 id="forecast-{{ $duration }}-heading" class="font-bold text-slate-900">{{ $duration }} kuukautta</h3>
                                        @if ($available)
                                            <p class="mt-1 text-sm text-slate-600">Luottamus: <strong class="font-semibold text-slate-900">{{ $confidenceLabels[$durationForecast['confidence']] ?? 'ei ilmoitettu' }}</strong></p>
                                        @endif
                                    </div>
                                    @if (! $available)
                                        <p class="text-slate-700">Ennustetta ei ole saatavilla.</p>
                                    @else
                                        <div class="flex flex-wrap gap-x-6 gap-y-2 tabular-nums">
                                            <p><span class="text-slate-600">Nyt</span> <strong class="font-bold text-slate-900">{{ $fmt($durationForecast['current']['median']) }} c/kWh</strong></p>
                                            <p><span class="text-slate-600">30 päivän arvio</span> <strong class="font-bold text-slate-900">{{ $fmt($durationForecast['forecast']['median']) }} c/kWh</strong></p>
                                        </div>
                                        <p class="font-bold tabular-nums text-slate-900">
                                            {{ $change < -0.005 ? 'Laskua' : ($change > 0.005 ? 'Nousua' : 'Muutos') }} {{ $fmt(abs($change)) }} c/kWh
                                        </p>
                                    @endif
                                </div>

                                @if ($available)
                                    <details class="mt-4">
                                        <summary class="cursor-pointer text-sm font-semibold text-slate-900 underline decoration-slate-300 underline-offset-4 hover:decoration-coral-500">Katso vaihteluväli ja ennusteen tiedot</summary>
                                        <div class="mt-4 overflow-x-auto">
                                            <table class="w-full min-w-[34rem] border-collapse text-sm">
                                                <caption class="sr-only">{{ $duration }} kuukauden sopimusten nykyinen ja ennustettu hintaväli</caption>
                                                <thead>
                                                    <tr class="border-b border-slate-200 text-left text-slate-600">
                                                        <th class="py-2 pr-3 font-semibold">Hintataso</th>
                                                        <th class="px-3 py-2 text-right font-semibold">Nyt</th>
                                                        <th class="py-2 pl-3 text-right font-semibold">30 päivän ennuste</th>
                                                    </tr>
                                                </thead>
                                                <tbody class="divide-y divide-slate-100">
                                                    @foreach (['p20' => 'p20', 'median' => 'Keskimmäinen hinta', 'p80' => 'p80'] as $quantile => $label)
                                                        <tr>
                                                            <th scope="row" class="py-3 pr-3 text-left font-semibold text-slate-900">{{ $label }}</th>
                                                            <td class="px-3 py-3 text-right tabular-nums text-slate-700">{{ $fmt($durationForecast['current'][$quantile]) }} c/kWh</td>
                                                            <td class="py-3 pl-3 text-right font-semibold tabular-nums text-slate-900">{{ $fmt($durationForecast['forecast'][$quantile]) }} c/kWh</td>
                                                        </tr>
                                                    @endforeach
                                                </tbody>
                                            </table>
                                        </div>
                                        <p class="mt-3 text-sm leading-relaxed text-slate-600">Ennusteen päivä {{ $fiDate($durationForecast['target_date']) }}. Aineistossa {{ number_format($durationForecast['contract_count'], 0, ',', ' ') }} sopimusta. Luottamus {{ $confidenceLabels[$durationForecast['confidence']] ?? 'ei ilmoitettu' }}.</p>
                                    </details>
                                @endif
                            </section>
                        @endforeach
                    </div>
                    <p class="mt-5 max-w-[72ch] text-base leading-relaxed text-slate-700">Luottamus kertoo, kuinka vahva ennusteen tietopohja on. Matalan luottamuksen ennuste voi muuttua nopeasti, eikä sitä pidä käyttää yksin sopimuspäätökseen.</p>
                @endif
            </section>

            <section class="mb-16 border-y border-slate-200 py-12" aria-labelledby="checklist-heading">
                <h2 id="checklist-heading" class="text-2xl font-bold tracking-tight text-slate-900">Tarkista nämä ennen sopimusta</h2>
                <ul class="mt-6 divide-y divide-slate-200 border-y border-slate-200 text-base leading-relaxed text-slate-700">
                    <li class="grid gap-2 py-5 md:grid-cols-[12rem_minmax(0,1fr)]"><strong class="text-slate-900">Hinnan toiminta</strong><span>Varmista, pysyykö energiahinta samana koko sopimusajan vai sisältääkö sopimus kulutusvaikutuksen tai muun muuttuvan osan.</span></li>
                    <li class="grid gap-2 py-5 md:grid-cols-[12rem_minmax(0,1fr)]"><strong class="text-slate-900">Koko vuosihinta</strong><span>Laske energiahinnan lisäksi kuukausimaksut omalla vuosikulutuksellasi.</span></li>
                    <li class="grid gap-2 py-5 md:grid-cols-[12rem_minmax(0,1fr)]"><strong class="text-slate-900">Sopimusaika</strong><span>Valitse vain aika, johon olet valmis sitoutumaan, ja lue myyjän ehdot ennen tilausta.</span></li>
                    <li class="grid gap-2 py-5 md:grid-cols-[12rem_minmax(0,1fr)]"><strong class="text-slate-900">Ennusteen rajat</strong><span>Pidä ennustetta yhtenä tietona muiden joukossa. Se ei takaa tulevaa hintaa.</span></li>
                </ul>
            </section>

            <section aria-labelledby="offers-heading">
                <h2 id="offers-heading" class="text-2xl font-bold tracking-tight text-slate-900">Vertaa seuraavaksi tarjoukset</h2>
                <p class="mt-3 max-w-[70ch] text-base leading-relaxed text-slate-700">
                    Markkinan keskimmäinen hinta auttaa valitsemaan sopimusajan. Tarkista lopuksi yksittäisen tarjouksen vuosihinta ja ehdot.
                </p>
                <div class="mt-6 flex flex-wrap gap-3">
                    <a href="/sahkosopimus/maaraaikainen" class="rounded-xl bg-coral-600 px-5 py-3 font-bold text-white hover:bg-coral-500">Kaikki määräaikaiset</a>
                    <a href="/sahkosopimus/maaraaikainen-6-kk" class="rounded-xl border border-slate-300 px-5 py-3 font-bold text-slate-900 hover:border-slate-500">6 kk tarjoukset</a>
                    <a href="/sahkosopimus/maaraaikainen-12-kk" class="rounded-xl border border-slate-300 px-5 py-3 font-bold text-slate-900 hover:border-slate-500">12 kk tarjoukset</a>
                    <a href="/sahkosopimus/maaraaikainen-24-kk" class="rounded-xl border border-slate-300 px-5 py-3 font-bold text-slate-900 hover:border-slate-500">24 kk tarjoukset</a>
                </div>
            </section>
        </article>

        <x-methodology-byline updated="31.8.2026" class="mt-16 border-t border-slate-200 pt-6" />
    </main>
</div>
