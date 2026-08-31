@php
    use Illuminate\Support\Carbon;

    $current = $articleData['current'] ?? [];
    $priceOfCertainty = $articleData['price_of_certainty'] ?? [];
    $history = $articleData['history'] ?? [];
    $forecast = $articleData['forecast'] ?? [];
    $currentRows = collect($current['rows'] ?? []);
    $priceOfCertaintyRows = collect($priceOfCertainty['rows'] ?? [])->keyBy('segment_key');
    $openEndedAnnual = $priceOfCertaintyRows->get('open_ended');
    $fixedTwelveAnnual = $priceOfCertaintyRows->get('fixed_term_12');
    $annualMedianDifference = $priceOfCertainty['median_difference_eur'] ?? null;
    $historySeries = collect($history['series'] ?? []);
    $forecastDurations = collect($forecast['durations'] ?? [])->keyBy('duration_months');
    $dataDate = $articleData['data_date'] ?? null;
    $cheapestFixed = $currentRows->whereNotNull('duration_months')->sortBy('median')->first();
    $fiDate = fn ($date) => $date ? Carbon::parse($date)->translatedFormat('j.n.Y') : 'Ei saatavilla';
    $fmt = fn ($value, $decimals = 2) => $value === null ? '–' : number_format((float) $value, $decimals, ',', ' ');
    $fmtSigned = function ($value) {
        if ($value === null) return '–';
        if (round((float) $value, 2) == 0.0) return '±0,00';
        return ((float) $value > 0 ? '+' : '−').number_format(abs((float) $value), 2, ',', ' ');
    };
    $confidenceLabels = [
        'high' => 'Korkea',
        'medium' => 'Keskitaso',
        'low' => 'Matala',
    ];
    $quantileLabels = [
        'p20' => 'p20',
        'median' => 'Mediaani',
        'p80' => 'p80',
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
            <p class="mb-4 text-sm font-semibold uppercase tracking-[0.14em] text-slate-600">Markkinakatsaus</p>
            <h1 class="max-w-[24ch] text-3xl font-extrabold leading-[1.08] tracking-tight text-slate-900 md:text-5xl">
                Kannattaako määräaikainen sähkösopimus?
            </h1>
            <p class="mt-5 max-w-[68ch] text-lg leading-relaxed text-slate-600">
                Vertaa 12 kuukauden sopimusta toistaiseksi voimassa olevan kiinteähintaisen sopimuksen nykyiseen tasoon. Katso myös täysin kiinteiden 6, 12 ja 24 kuukauden sopimusten historia ja 30 päivän ennuste.
            </p>
            <dl class="mt-8 grid gap-5 text-sm sm:grid-cols-2">
                <div>
                    <dt class="font-semibold text-slate-600">Markkina- ja ennustedata</dt>
                    <dd class="mt-1 font-bold tabular-nums text-slate-900">{{ $fiDate($dataDate) }}</dd>
                </div>
                <div>
                    <dt class="font-semibold text-slate-600">Toimituksellinen tarkistus</dt>
                    <dd class="mt-1 font-bold tabular-nums text-slate-900">{{ $fiDate($editorialReviewDate) }}</dd>
                </div>
            </dl>
        </header>

        <x-page-action-strip class="mb-12" />

        <article>
            <section class="mb-14 border-b border-slate-200 pb-14" aria-labelledby="separate-choices-heading">
                <h2 id="separate-choices-heading" class="text-2xl font-bold tracking-tight text-slate-900">
                    Kesto ja hinnoittelutapa ovat eri valintoja
                </h2>
                <div class="mt-4 max-w-[72ch] space-y-4 text-base leading-relaxed text-slate-700">
                    <p>
                        Määräaikaisuus kertoo, kuinka kauan sopimus sitoo. Se ei yksin kerro, miten energian hinta muodostuu. Määräaikainen sopimus ei aina ole kiinteähintainen, sillä hinta voi sisältää kulutusvaikutuksen tai seurata markkinaa sopimusehtojen mukaan.
                    </p>
                    <p>
                        Nykyinen vertailutaso sisältää myös toistaiseksi voimassa olevat kiinteähintaiset sopimukset. Niiden julkaistu nykyhinta on kiinteä, mutta myyjä voi muuttaa hintaa sopimusehtojen mukaan. Siksi niiden 12 kuukauden kustannus on arvio.
                    </p>
                    <p>
                        Historia ja ennuste koskevat täysin kiinteitä 6, 12 ja 24 kuukauden sopimuksia. Kulutusvaikutukselliset, pörssihintaiset ja jaksoittain markkinan mukaan muuttuvat tuotteet eivät ole mukana näissä luvuissa.
                    </p>
                </div>
            </section>

            <section class="mb-16" aria-labelledby="short-answer-heading">
                <p class="mb-3 text-sm font-semibold uppercase tracking-[0.14em] text-slate-600">
                    Lyhyt vastaus {{ ! empty($priceOfCertainty['date']) ? $fiDate($priceOfCertainty['date']) : '' }}
                </p>
                <h2 id="short-answer-heading" class="max-w-[34ch] text-2xl font-extrabold leading-tight tracking-tight text-slate-900 md:text-3xl">
                    @if ($annualMedianDifference !== null && $fixedTwelveAnnual && $openEndedAnnual)
                        @if ($annualMedianDifference > 0)
                            12 kuukauden täysin kiinteän sopimuksen mediaani maksaa {{ $fmt(abs($annualMedianDifference), 0) }} € vuodessa enemmän kuin toistaiseksi voimassa olevan kiinteähintaisen sopimuksen arvioitu mediaani.
                        @elseif ($annualMedianDifference < 0)
                            12 kuukauden täysin kiinteän sopimuksen mediaani maksaa {{ $fmt(abs($annualMedianDifference), 0) }} € vuodessa vähemmän kuin toistaiseksi voimassa olevan kiinteähintaisen sopimuksen arvioitu mediaani.
                        @else
                            12 kuukauden täysin kiinteän sopimuksen ja toistaiseksi voimassa olevan kiinteähintaisen sopimuksen arvioitu mediaanivuosikustannus on sama.
                        @endif
                    @else
                        12 kuukauden ja toistaiseksi voimassa olevan sopimuksen vertailukelpoista vuosikustannusta ei ole juuri nyt saatavilla.
                    @endif
                </h2>
                <p class="mt-4 max-w-[70ch] text-base leading-relaxed text-slate-700">
                    @if ($cheapestFixed)
                        Energiahinnan nykyvertailussa täysin kiinteiden määräaikaisten sopimusten matalin mediaani on {{ $cheapestFixed['duration_months'] }} kuukauden sopimuksissa, {{ $fmt($cheapestFixed['median']) }} c/kWh. Tämä on markkinan yhteenveto, ei suositus tai lupaus yksittäisen tarjouksen hinnasta.
                    @else
                        Päätöstä ei pidä tehdä puutteellisen jakauman perusteella. Tarkista myös sopimuskausi, hinnoittelutapa ja myyjän ehdot.
                    @endif
                </p>
            </section>

            <section class="mb-20" aria-labelledby="current-heading">
                <div class="border-b border-slate-300 pb-4">
                    <h2 id="current-heading" class="text-2xl font-bold tracking-tight text-slate-900">Nykyinen hintataso</h2>
                    <p class="mt-2 max-w-[72ch] text-base leading-relaxed text-slate-600">
                        Taulukko vertaa toistaiseksi voimassa olevien sopimusten nykyistä kiinteää hintaa täysin kiinteisiin 6, 12 ja 24 kuukauden hintoihin. p20 kuvaa edullisemman viidenneksen rajaa, mediaani keskimmäistä hintaa ja p80 kalliimman viidenneksen rajaa. Hinnat sisältävät arvonlisäveron ja koskevat energiahintaa.
                    </p>
                </div>

                @if ($currentRows->isEmpty())
                    <p class="py-10 text-slate-700">Vertailukelpoista yhteisen päivän aineistoa ei ole saatavilla.</p>
                @else
                    <div class="overflow-x-auto">
                        <table class="w-full min-w-[42rem] border-collapse text-sm">
                            <caption class="sr-only">Toistaiseksi voimassa olevan nykyisen kiinteän hinnan sekä täysin kiinteiden määräaikaisten sopimusten hintajakauma</caption>
                            <thead>
                                <tr class="border-b border-slate-200 text-left text-slate-600">
                                    <th class="py-3 pr-4 font-semibold">Sopimuskausi</th>
                                    <th class="px-4 py-3 text-right font-semibold">p20</th>
                                    <th class="px-4 py-3 text-right font-semibold">Mediaani</th>
                                    <th class="px-4 py-3 text-right font-semibold">p80</th>
                                    <th class="py-3 pl-4 text-right font-semibold">Sopimuksia</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-slate-100">
                                @foreach ($currentRows as $row)
                                    <tr>
                                        <th scope="row" class="py-4 pr-4 text-left font-bold text-slate-900">
                                            {{ $row['segment_key'] === 'open_ended' ? 'Toistaiseksi voimassa oleva, nykyinen kiinteä hinta' : $row['duration_months'].' kk, täysin kiinteä hinta' }}
                                        </th>
                                        <td class="px-4 py-4 text-right tabular-nums text-slate-700">{{ $fmt($row['p20']) }} c/kWh</td>
                                        <td class="px-4 py-4 text-right font-bold tabular-nums text-slate-900">{{ $fmt($row['median']) }} c/kWh</td>
                                        <td class="px-4 py-4 text-right tabular-nums text-slate-700">{{ $fmt($row['p80']) }} c/kWh</td>
                                        <td class="py-4 pl-4 text-right tabular-nums text-slate-700">{{ number_format($row['contract_count'], 0, ',', ' ') }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                    <p class="mt-3 text-sm text-slate-600">Yhteinen aineistopäivä: <time datetime="{{ $current['date'] }}">{{ $fiDate($current['date']) }}</time>.</p>
                @endif
            </section>

            <section class="mb-20" aria-labelledby="certainty-heading">
                <div class="border-b border-slate-300 pb-4">
                    <h2 id="certainty-heading" class="text-2xl font-bold tracking-tight text-slate-900">Varmuuden hinta 5 000 kWh vuosikulutuksella</h2>
                    <p class="mt-2 max-w-[72ch] text-base leading-relaxed text-slate-600">
                        Vuosikustannus sisältää sähkön energian ja sopimuksen kuukausimaksut. Se ei sisällä sähkönsiirtoa. Vertailu käyttää yhden päivän tietoja ja samaa vuosikustannuksen laskentamenetelmää. Toistaiseksi voimassa olevan sopimuksen 12 kuukauden kustannus on arvio, sillä myyjä voi muuttaa hintaa sopimusehtojen mukaan.
                    </p>
                </div>

                @if (! $openEndedAnnual || ! $fixedTwelveAnnual || $annualMedianDifference === null)
                    <p class="py-10 text-slate-700">Vertailukelpoista 5 000 kWh vuosikustannusta ei ole juuri nyt saatavilla.</p>
                @else
                    <div class="overflow-x-auto">
                        <table class="w-full min-w-[42rem] border-collapse text-sm">
                            <caption class="sr-only">Toistaiseksi voimassa olevan kiinteähintaisen sopimuksen arvioitu vuosikustannus ja täysin kiinteän 12 kuukauden sopimuksen vuosikustannus</caption>
                            <thead>
                                <tr class="border-b border-slate-200 text-left text-slate-600">
                                    <th class="py-3 pr-4 font-semibold">Sopimustyyppi</th>
                                    <th class="px-4 py-3 text-right font-semibold">p20</th>
                                    <th class="px-4 py-3 text-right font-semibold">Mediaani</th>
                                    <th class="px-4 py-3 text-right font-semibold">p80</th>
                                    <th class="py-3 pl-4 text-right font-semibold">Sopimuksia</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-slate-100">
                                @foreach ([$openEndedAnnual, $fixedTwelveAnnual] as $row)
                                    <tr>
                                        <th scope="row" class="py-4 pr-4 text-left font-bold text-slate-900">
                                            {{ $row['segment_key'] === 'open_ended' ? 'Toistaiseksi voimassa oleva kiinteähintainen sopimus' : '12 kk, täysin kiinteä hinta' }}
                                        </th>
                                        <td class="px-4 py-4 text-right tabular-nums text-slate-700">{{ $fmt($row['p20'], 0) }} €/v</td>
                                        <td class="px-4 py-4 text-right font-bold tabular-nums text-slate-900">{{ $fmt($row['median'], 0) }} €/v</td>
                                        <td class="px-4 py-4 text-right tabular-nums text-slate-700">{{ $fmt($row['p80'], 0) }} €/v</td>
                                        <td class="py-4 pl-4 text-right tabular-nums text-slate-700">{{ number_format($row['contract_count'], 0, ',', ' ') }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                    <p class="mt-5 max-w-[72ch] text-base leading-relaxed text-slate-700">
                        12 kuukauden sopimuksen mediaaniero toistaiseksi voimassa olevaan on <strong class="font-bold tabular-nums text-slate-900">{{ $fmtSigned($annualMedianDifference) }} €/vuosi</strong>. Ero kuvaa tämän aineistopäivän markkinatasoa, ei yksittäisen sopimusparin hintaa.
                    </p>
                    <p class="mt-3 text-sm text-slate-600">Yhteinen aineistopäivä: <time datetime="{{ $priceOfCertainty['date'] }}">{{ $fiDate($priceOfCertainty['date']) }}</time>.</p>
                @endif
            </section>

            <section class="mb-20" aria-labelledby="history-heading">
                <div class="border-b border-slate-300 pb-4">
                    <h2 id="history-heading" class="text-2xl font-bold tracking-tight text-slate-900">Hintahaarukka viimeisen 12 kuukauden aikana</h2>
                    <p class="mt-2 max-w-[72ch] text-base leading-relaxed text-slate-600">
                        Jokainen kuva näyttää yhden sopimuspituuden viikkokeskiarvot. Harmaa alue on p20–p80 ja koralliviiva on mediaani. Kaikissa kuvissa on sama hinta-asteikko.
                    </p>
                </div>

                @if ($historySeries->every(fn ($series) => empty($series['points'])))
                    <p class="py-10 text-slate-700">Historiallista vertailua ei ole vielä riittävästi.</p>
                @else
                    <div class="mt-8 grid gap-10">
                        @foreach ($historySeries as $series)
                            @php
                                $points = collect($series['points'] ?? []);
                                $scaleMin = (float) ($history['scale_min'] ?? 0);
                                $scaleMax = (float) ($history['scale_max'] ?? 0);
                                $scaleSpan = max(0.0001, $scaleMax - $scaleMin);
                                $plotLeft = 0;
                                $plotTop = 2;
                                $plotWidth = 720;
                                $plotHeight = 124;
                                $pointCount = $points->count();
                                $x = fn ($index) => $pointCount <= 1 ? $plotLeft + $plotWidth / 2 : $plotLeft + ($index / ($pointCount - 1)) * $plotWidth;
                                $y = fn ($value) => $plotTop + (($scaleMax - (float) $value) / $scaleSpan) * $plotHeight;
                                $upper = $points->values()->map(fn ($point, $index) => round($x($index), 2).','.round($y($point['p80']), 2))->implode(' ');
                                $lower = $points->values()->reverse()->values()->map(fn ($point, $reverseIndex) => round($x($pointCount - 1 - $reverseIndex), 2).','.round($y($point['p20']), 2))->implode(' ');
                                $medianLine = $points->values()->map(fn ($point, $index) => round($x($index), 2).','.round($y($point['median']), 2))->implode(' ');
                                $chartId = 'fixed-history-'.$series['duration_months'];
                            @endphp
                            <figure aria-labelledby="{{ $chartId }}-heading">
                                <div class="mb-3 flex flex-wrap items-baseline justify-between gap-3">
                                    <h3 id="{{ $chartId }}-heading" class="text-lg font-bold text-slate-900">{{ $series['duration_months'] }} kuukauden sopimukset</h3>
                                    <span class="text-sm tabular-nums text-slate-600">{{ $fmt($scaleMin) }}–{{ $fmt($scaleMax) }} c/kWh</span>
                                </div>
                                @if ($points->isEmpty())
                                    <p class="border-y border-slate-200 py-8 text-slate-700">Tälle sopimuskaudelle ei ole vertailukelpoista historiaa.</p>
                                @else
                                    <svg viewBox="0 0 720 132" class="h-auto w-full" role="img" aria-labelledby="{{ $chartId }}-title {{ $chartId }}-desc">
                                        <title id="{{ $chartId }}-title">{{ $series['duration_months'] }} kuukauden sopimusten viikoittainen hintahaarukka</title>
                                        <desc id="{{ $chartId }}-desc">p20–p80-hintahaarukka ja mediaani. Tarkat arvot ovat kuvan jälkeisessä taulukossa.</desc>
                                        <line x1="0" y1="2" x2="720" y2="2" stroke="#e2e8f0" />
                                        <line x1="0" y1="64" x2="720" y2="64" stroke="#e2e8f0" />
                                        <line x1="0" y1="126" x2="720" y2="126" stroke="#cbd5e1" />
                                        <polygon points="{{ $upper }} {{ $lower }}" fill="#f1f5f9" stroke="#cbd5e1" stroke-width="1" />
                                        <polyline points="{{ $medianLine }}" fill="none" stroke="#ea580c" stroke-width="2.5" stroke-linejoin="round" stroke-linecap="round" />
                                    </svg>
                                    <div class="mt-2 flex justify-between gap-4 text-sm tabular-nums text-slate-600" aria-hidden="true">
                                        <span>{{ $fiDate($points->first()['date']) }}</span>
                                        <span>{{ $fiDate($points->last()['date']) }}</span>
                                    </div>

                                    <details class="mt-4 border-t border-slate-200 pt-4">
                                        <summary class="cursor-pointer font-semibold text-slate-900 underline decoration-slate-300 underline-offset-4 hover:decoration-coral-500">
                                            Näytä kuvan arvot taulukkona
                                        </summary>
                                        <div class="mt-4 max-h-96 overflow-auto">
                                            <table class="w-full min-w-[38rem] border-collapse text-sm">
                                                <thead class="sticky top-0 bg-white">
                                                    <tr class="border-b border-slate-300 text-left text-slate-600">
                                                        <th class="py-2 pr-3 font-semibold">Viikko alkoi</th>
                                                        <th class="px-3 py-2 text-right font-semibold">p20</th>
                                                        <th class="px-3 py-2 text-right font-semibold">Mediaani</th>
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
                    <h2 id="forecast-heading" class="text-2xl font-bold tracking-tight text-slate-900">30 päivän hintaennuste</h2>
                    <p class="mt-2 max-w-[72ch] text-base leading-relaxed text-slate-600">
                        Ennuste arvioi markkinan p20-, mediaani- ja p80-tasoja. Se ei takaa tulevaa hintaa. Sopimuspituus näytetään vain, jos nykyiset ja ennustetut kolme hintatasoa ovat täydelliset ja oikeassa järjestyksessä.
                    </p>
                </div>

                @if (empty($forecast['date']))
                    <p class="py-10 text-slate-700">Kelvollista 30 päivän ennustetta ei ole juuri nyt saatavilla.</p>
                @else
                    <p class="mt-4 text-sm text-slate-600">
                        Ennuste on tehty <time datetime="{{ $forecast['date'] }}">{{ $fiDate($forecast['date']) }}</time>.
                    </p>
                    <div class="mt-6 divide-y divide-slate-200 border-y border-slate-200">
                        @foreach ([6, 12, 24] as $duration)
                            @php $durationForecast = $forecastDurations->get($duration); @endphp
                            <section class="py-8" aria-labelledby="forecast-{{ $duration }}-heading">
                                <div class="flex flex-wrap items-start justify-between gap-4">
                                    <div>
                                        <h3 id="forecast-{{ $duration }}-heading" class="text-lg font-bold text-slate-900">{{ $duration }} kuukauden sopimukset</h3>
                                        @if ($durationForecast && $durationForecast['available'])
                                            <p class="mt-1 text-sm text-slate-600">
                                                Tavoitepäivä {{ $fiDate($durationForecast['target_date']) }}, otos {{ number_format($durationForecast['contract_count'], 0, ',', ' ') }} sopimusta, luottamus {{ mb_strtolower($confidenceLabels[$durationForecast['confidence']] ?? 'ei ilmoitettu') }}.
                                            </p>
                                        @endif
                                    </div>
                                    @if ($durationForecast && $durationForecast['available'])
                                        <p class="text-sm font-semibold tabular-nums text-slate-900">Mediaanin muutos {{ $fmtSigned($durationForecast['median_change']) }} c/kWh</p>
                                    @endif
                                </div>

                                @if (! $durationForecast || ! $durationForecast['available'])
                                    <p class="mt-4 text-slate-700">Tälle sopimuskaudelle ei ole täydellistä kelvollista ennustetta.</p>
                                @else
                                    <div class="mt-5 overflow-x-auto">
                                        <table class="w-full min-w-[34rem] border-collapse text-sm">
                                            <caption class="sr-only">{{ $duration }} kuukauden sopimusten nykyinen ja ennustettu hintajakauma</caption>
                                            <thead>
                                                <tr class="border-b border-slate-200 text-left text-slate-600">
                                                    <th class="py-2 pr-3 font-semibold">Hintataso</th>
                                                    <th class="px-3 py-2 text-right font-semibold">Nykyinen</th>
                                                    <th class="py-2 pl-3 text-right font-semibold">Ennuste 30 pv</th>
                                                </tr>
                                            </thead>
                                            <tbody class="divide-y divide-slate-100">
                                                @foreach (['p20', 'median', 'p80'] as $quantile)
                                                    <tr>
                                                        <th scope="row" class="py-3 pr-3 text-left font-semibold text-slate-900">{{ $quantileLabels[$quantile] }}</th>
                                                        <td class="px-3 py-3 text-right tabular-nums text-slate-700">{{ $fmt($durationForecast['current'][$quantile]) }} c/kWh</td>
                                                        <td class="py-3 pl-3 text-right font-semibold tabular-nums text-slate-900">{{ $fmt($durationForecast['forecast'][$quantile]) }} c/kWh</td>
                                                    </tr>
                                                @endforeach
                                            </tbody>
                                        </table>
                                    </div>
                                @endif
                            </section>
                        @endforeach
                    </div>
                @endif
            </section>

            <section class="mb-16 border-y border-slate-200 py-12" aria-labelledby="decision-heading">
                <h2 id="decision-heading" class="text-2xl font-bold tracking-tight text-slate-900">Mitä ennen päätöstä pitää tarkistaa?</h2>
                <div class="mt-5 grid gap-8 text-base leading-relaxed text-slate-700 md:grid-cols-2">
                    <div>
                        <h3 class="font-bold text-slate-900">Hinnan toiminta</h3>
                        <p class="mt-2">Tarkista, pysyykö energiahinta samana koko sopimuskauden ajan. Katso erikseen perusmaksu, mahdollinen kulutusvaikutus ja muut hinnan muutosehdot.</p>
                    </div>
                    <div>
                        <h3 class="font-bold text-slate-900">Sitoutuminen ja muutto</h3>
                        <p class="mt-2">Myyjän sopimusehdot ratkaisevat, mitä muutossa tapahtuu ja milloin määräaikaisen sopimuksen voi päättää poikkeustilanteessa. Tarkista ehdot ennen sopimuksen tekemistä.</p>
                    </div>
                    <div>
                        <h3 class="font-bold text-slate-900">Sopimuskauden päättyminen</h3>
                        <p class="mt-2">Sopimuksen päättymisen jälkeinen menettely ja mahdollinen uusi hinta käyvät ilmi myyjän ehdoista ja ilmoituksista. Älä oleta, että sopimus jatkuu aina samalla tavalla.</p>
                    </div>
                    <div>
                        <h3 class="font-bold text-slate-900">Ennusteen epävarmuus</h3>
                        <p class="mt-2">30 päivän ennuste on mallin arvio markkinan hintatasosta. Se voi muuttua, kun sopimushinnat ja futuurit muuttuvat.</p>
                    </div>
                </div>
            </section>

            <section aria-labelledby="offers-heading">
                <h2 id="offers-heading" class="text-2xl font-bold tracking-tight text-slate-900">Katso yksittäiset tarjoukset listoilta</h2>
                <p class="mt-3 max-w-[70ch] text-base leading-relaxed text-slate-700">
                    Markkinajakauma auttaa valitsemaan sopimuskauden. Tarkista yksittäisen tarjouksen hinta ja ehdot määräaikaisten sopimusten listalta.
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
