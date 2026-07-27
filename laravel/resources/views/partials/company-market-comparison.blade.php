@php
    use Illuminate\Support\Carbon;

    $reference = number_format($marketComparison['reference_consumption'], 0, ',', ' ');
    $statDate = Carbon::parse($marketComparison['stat_date'])->translatedFormat('j.n.Y');
    $chart = $marketComparison['chart'] ?? null;
    $historicalFallback = ($marketComparison['is_historical_fallback'] ?? false) === true;
@endphp

<section id="hintavertailu" class="mb-10">
    <h2 class="text-2xl font-bold text-slate-900 mb-2">{{ $company->name }}: sähkön hinta</h2>

    <p class="text-slate-600 mb-1 max-w-prose">
        Sähkön hinta riippuu sopimustyypistä ja vuosikulutuksesta.
        Jokainen rivi näyttää yhtiön {{ $historicalFallback ? 'päivätyn' : 'nykyisen' }} 12 kuukauden hinta-arvion sekä saman sopimustyypin markkinamediaanin ja keskimmäisen 60 %:n hintahaarukan.
        Harmaa palkki näyttää hintahaarukan, pystyviiva mediaanin ja oranssi piste yhtiön hinnan.
    </p>
    <p class="text-slate-600 mb-6 max-w-prose">
        @if ($historicalFallback)
            <span class="font-semibold text-slate-800">Nykyinen laskettu vertailu ei ole saatavilla.</span>
            Alla on viimeisin yhtenäinen historiallinen hintavertailu {{ $statDate }} myyjiltä havaituista hinnoista. Se ei ole tämän päivän hintavertailu.
        @elseif (($marketComparison['pricing_basis'] ?? null) === 'canonical_calculation')
            Vertailu perustuu {{ $statDate }} Voltikan laskemiin nykyhintoihin.
        @else
            Vertailu perustuu {{ $statDate }} myyjiltä havaittuihin hintoihin.
        @endif
        Luvut ovat 12 kuukauden kokonaishintoja {{ $reference }} kWh vuosikulutuksella, sis. alv 25,5 %. Siirtomaksu ei sisälly.
        @if ($marketComparison['is_snapped'])
            Käytämme lähintä laskettua kulutustasoa, {{ $reference }} kWh.
        @endif
    </p>

    <div class="rounded-2xl border border-slate-200 bg-white p-5 sm:p-6">
        <ul class="space-y-6">
            @foreach ($marketComparison['rows'] as $row)
                @php
                    $delta = $row['delta_vs_median'];
                    $positionLabel = match ($row['position']) {
                        'below_p20' => 'Markkinan halvimmassa 20 %:ssa',
                        'above_p80' => 'Markkinan kalleimmassa 20 %:ssa',
                        default => 'Markkinan tavanomaisessa haarukassa',
                    };
                @endphp
                <li>
                    <div class="flex flex-wrap items-baseline justify-between gap-x-3 gap-y-1">
                        <h3 class="text-sm font-bold text-slate-900">{{ $row['label'] }}</h3>
                        <p class="text-sm text-slate-500">
                            Yhtiöllä {{ $row['company_contract_count'] }}
                            {{ $row['company_contract_count'] === 1 ? 'sopimus' : 'sopimusta' }}
                        </p>
                    </div>

                    <div class="mt-3 flex flex-wrap items-baseline gap-x-3 gap-y-1">
                        <p class="text-xl font-extrabold tabular-nums text-slate-900">
                            {{ number_format($row['company_value'], 0, ',', ' ') }} {{ "\u{20AC}" }}<span class="text-base font-semibold text-slate-500">/v</span>
                        </p>
                        <p class="text-sm text-slate-600">
                            {{ $positionLabel }}.
                            @if (abs($delta) < 1)
                                Sama kuin markkinan mediaani.
                            @else
                                {{ number_format(abs($delta), 0, ',', ' ') }} {{ "\u{20AC}" }} {{ $delta < 0 ? 'halvempi' : 'kalliimpi' }} kuin markkinan mediaani.
                            @endif
                        </p>
                    </div>

                    {{-- Geometry is precomputed in CompanyMarketComparisonService; keep this presentation-only. --}}
                    <div class="relative mt-3 h-8" aria-hidden="true">
                        <div class="absolute inset-x-0 top-[15px] h-0.5 rounded-full bg-slate-200"></div>
                        <div
                            class="absolute top-2 h-4 rounded-md bg-slate-300 ring-1 ring-inset ring-slate-400/40"
                            style="left: {{ $row['band_left_percent'] }}%; width: {{ $row['band_width_percent'] }}%;"
                        ></div>
                        <div
                            class="absolute top-1.5 h-5 w-0.5 bg-slate-600"
                            style="left: {{ $row['median_percent'] }}%;"
                        ></div>
                        <div
                            class="absolute top-0.5 -ml-1.5 h-7 w-3 rounded-full border-2 border-white bg-coral-600 shadow-md"
                            style="left: {{ $row['marker_percent'] }}%;"
                        ></div>
                    </div>

                    <dl class="mt-1 flex flex-wrap gap-x-5 gap-y-1 text-xs text-slate-500">
                        <div class="flex gap-1">
                            <dt>Markkinan halvempi 20 %:</dt>
                            <dd class="font-semibold tabular-nums text-slate-700">{{ number_format($row['market_p20'], 0, ',', ' ') }} {{ "\u{20AC}" }}</dd>
                        </div>
                        <div class="flex gap-1">
                            <dt>Mediaani:</dt>
                            <dd class="font-semibold tabular-nums text-slate-700">{{ number_format($row['market_median'], 0, ',', ' ') }} {{ "\u{20AC}" }}</dd>
                        </div>
                        <div class="flex gap-1">
                            <dt>Kalliimpi 20 %:</dt>
                            <dd class="font-semibold tabular-nums text-slate-700">{{ number_format($row['market_p80'], 0, ',', ' ') }} {{ "\u{20AC}" }}</dd>
                        </div>
                        <div class="flex gap-1">
                            <dt>Markkinalla vertailussa:</dt>
                            <dd class="font-semibold tabular-nums text-slate-700">{{ $row['market_contract_count'] }} sopimusta</dd>
                        </div>
                    </dl>
                </li>
            @endforeach
        </ul>

        <ul class="mt-6 flex flex-wrap gap-x-5 gap-y-2 border-t border-slate-100 pt-4 text-xs text-slate-600">
            <li class="inline-flex items-center gap-2">
                <span class="h-3 w-3 rounded-full bg-coral-600" aria-hidden="true"></span>
                {{ $company->name }}
            </li>
            <li class="inline-flex items-center gap-2">
                <span class="h-1 w-6 rounded-full bg-slate-300" aria-hidden="true"></span>
                Markkinan haarukka
            </li>
            <li class="inline-flex items-center gap-2">
                <span class="h-4 w-px bg-slate-500" aria-hidden="true"></span>
                Markkinan mediaani
            </li>
        </ul>
    </div>

    @if ($chart)
        <div class="mt-6 rounded-2xl border border-slate-200 bg-white p-5 sm:p-6">
            <h3 class="text-base font-bold text-slate-900">
                Hintakehitys: {{ $marketComparison['chart_segment_label'] }}
            </h3>
            <p class="mt-1 text-sm text-slate-600 max-w-prose">
                Viikoittainen vertailu edeltävältä 12 kuukaudelta. Varjostettu alue näyttää markkinan keskimmäisen 60 %:n.
                @if ($historicalFallback)
                    Kaikki pisteet ovat päivättyjä myyjiltä havaittuja hintoja. Viimeisin piste on {{ $statDate }}.
                @elseif (($chart['current_pricing_basis'] ?? null) === 'canonical_calculation' && ($chart['canonical_from'] ?? null) !== null)
                    Vanhemmat pisteet ovat myyjiltä havaittuja hintoja. {{ Carbon::parse($chart['canonical_from'])->translatedFormat('j.n.Y') }} alkaen pisteet ovat Voltikan laskemia vertailuhintoja.
                @endif
            </p>

            <div class="relative mt-5">
                <div
                    wire:key="company-market-chart-{{ $marketComparison['chart_segment_key'] }}-{{ $marketComparison['reference_consumption'] }}-{{ $marketComparison['stat_date'] }}"
                    wire:ignore
                    data-line-chart
                    class="relative h-64 w-full select-none"
                    role="img"
                    aria-label="{{ $company->name }} verrattuna markkinan mediaaniin, {{ $marketComparison['chart_segment_label'] }}, {{ $reference }} kilowattitunnin kulutuksella."
                >
                    <script type="application/json">{!! json_encode($chart, JSON_UNESCAPED_UNICODE) !!}</script>
                </div>

                <ul class="mt-3 flex flex-wrap justify-center gap-x-5 gap-y-2 text-xs text-slate-700" aria-label="Kaavion selite">
                    <li class="inline-flex items-center gap-2">
                        <svg width="24" height="10" viewBox="0 0 24 10" aria-hidden="true" class="shrink-0">
                            <line x1="1" y1="5" x2="23" y2="5" stroke="#f97316" stroke-width="2.5" stroke-linecap="round" />
                        </svg>
                        <span class="font-semibold text-coral-700">{{ $company->name }}</span>
                    </li>
                    <li class="inline-flex items-center gap-2">
                        <svg width="24" height="10" viewBox="0 0 24 10" aria-hidden="true" class="shrink-0">
                            {{-- Must match NON_LEAD_STYLES[0] in resources/js/contract-price-statistics.js --}}
                            <line x1="1" y1="5" x2="23" y2="5" stroke="#1e293b" stroke-width="1.8" stroke-linecap="round" />
                        </svg>
                        <span>Markkinan mediaani</span>
                    </li>
                </ul>
            </div>

            <table class="sr-only">
                <caption>{{ $company->name }} ja markkinan mediaani, {{ $marketComparison['chart_segment_label'] }}, euroa vuodessa</caption>
                <thead>
                    <tr>
                        <th scope="col">Viikko</th>
                        <th scope="col">{{ $company->name }}</th>
                        <th scope="col">Markkinan mediaani</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($chart['x'] as $i => $timestamp)
                        <tr>
                            <th scope="row">{{ Carbon::createFromTimestamp($timestamp)->translatedFormat('j.n.Y') }}</th>
                            <td>{{ $chart['series'][0]['values'][$i] === null ? 'ei tietoa' : number_format($chart['series'][0]['values'][$i], 0, ',', ' ') . ' euroa' }}</td>
                            <td>{{ $chart['series'][1]['values'][$i] === null ? 'ei tietoa' : number_format($chart['series'][1]['values'][$i], 0, ',', ' ') . ' euroa' }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>

        @push('scripts')
            @once
                @vite('resources/js/contract-price-statistics.js')
            @endonce
        @endpush
    @endif

    <p class="mt-3 text-xs text-slate-500">
        Luvut perustuvat Voltikan päivittäiseen hintaseurantaan.
        <a href="/sahkosopimus/tilastot" class="font-medium text-coral-600 hover:text-coral-700">Avaa koko hintatilasto &rarr;</a>
    </p>
</section>
