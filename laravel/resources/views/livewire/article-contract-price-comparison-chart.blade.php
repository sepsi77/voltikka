@php
    use Illuminate\Support\Carbon;

    $hasData = ! empty($leadChartPayload['x']);
    $from = $dataWindow['from'] ? Carbon::parse($dataWindow['from'])->translatedFormat('j.n.Y') : null;
    $to = $dataWindow['to'] ? Carbon::parse($dataWindow['to'])->translatedFormat('j.n.Y') : null;
    $latestMedians = [];

    foreach ($leadChartPayload['series'] ?? [] as $series) {
        for ($index = count($series['values']) - 1; $index >= 0; $index--) {
            if ($series['values'][$index] !== null) {
                $latestMedians[] = [
                    'label' => $series['label'],
                    'value' => $series['values'][$index],
                    'date' => Carbon::createFromTimestamp((int) $leadChartPayload['x'][$index])->translatedFormat('j.n.Y'),
                ];
                break;
            }
        }
    }
@endphp

<section class="not-prose" aria-labelledby="contract-price-comparison-heading">
    <p class="text-xs font-semibold uppercase tracking-[0.16em] text-coral-700">Voltikka hintatilastot</p>
    <h2 id="contract-price-comparison-heading" class="mt-2 text-2xl font-bold tracking-tight text-slate-900">
        Sähkösopimusten mediaanihinta vertailussa
    </h2>
    <p class="mt-2 max-w-prose text-base leading-7 text-slate-600">
        Viikoittainen vertailu näyttää sopimustyyppien tyypillisen vuosikustannuksen 5&nbsp;000 kWh kulutuksella. Luku on mediaani: puolet sopimuksista oli halvempia ja puolet kalliimpia.
    </p>
    <p class="mt-2 max-w-prose text-base leading-7 text-slate-600">
        Kaavio ei näytä halvinta tarjousta eikä kaikkien sopimusten keskiarvoa. Pörssisähkön lukema sisältää sopimusten marginaalit ja edeltävän 12 kuukauden toteutuneen pörssihinnan.
    </p>
    @if ($from && $to)
        <p class="mt-3 text-[11px] font-semibold tracking-[0.16em] uppercase text-slate-500">Aineisto: {{ $from }}–{{ $to }}. Sis. ALV 25,5 %.</p>
    @endif

    @if (! $hasData)
        <div class="mt-6 rounded-xl border border-dashed border-slate-200 bg-slate-50 p-8 text-center text-sm text-slate-600">
            Hintatilastoa ei ole vielä saatavilla tälle vertailulle.
        </div>
    @else
        <p id="contract-price-comparison-takeaway" class="mt-6 max-w-prose text-base leading-7 text-slate-700">
            <strong>Uusin saatavilla oleva viikkotaso:</strong>
            @foreach ($latestMedians as $median)
                {{ $median['label'] }} {{ number_format($median['value'], 0, ',', ' ') }} €/v ({{ $median['date'] }}){{ $loop->last ? '.' : ',' }}
            @endforeach
        </p>

        <div class="relative mt-8">
            <div
                wire:key="article-contract-price-chart-weekly-5000-{{ $dataWindow['to'] }}"
                wire:ignore
                data-line-chart
                class="relative h-72 w-full select-none"
                role="img"
                aria-label="Sopimustyyppien vuosikustannus {{ $consumptionLabel }} kilowattitunnin kulutuksella."
                aria-describedby="contract-price-comparison-takeaway"
            >
                <script type="application/json">{!! json_encode($leadChartPayload, JSON_UNESCAPED_UNICODE) !!}</script>
            </div>

            @php
                // Must match NON_LEAD_STYLES in resources/js/contract-price-statistics.js
                $nonLeadStyles = [
                    ['stroke' => '#1e293b', 'dash' => null,        'width' => 1.8],
                    ['stroke' => '#64748b', 'dash' => '8,3',       'width' => 1.8],
                    ['stroke' => '#334155', 'dash' => '3,2',       'width' => 2.0],
                    ['stroke' => '#64748b', 'dash' => '5,2,2,2',   'width' => 1.8],
                ];
            @endphp
            <ul class="mt-3 flex flex-wrap justify-center gap-x-5 gap-y-2 text-xs text-slate-700" aria-label="Kaavion selite">
                @foreach ($leadChartPayload['series'] as $i => $series)
                    @php
                        $isLead = $i === 0;
                        $style = $isLead ? null : $nonLeadStyles[($i - 1) % count($nonLeadStyles)];
                        $stroke = $isLead ? '#f97316' : $style['stroke'];
                        $dash = $isLead ? null : $style['dash'];
                    @endphp
                    <li class="inline-flex items-center gap-2">
                        <svg width="24" height="10" viewBox="0 0 24 10" aria-hidden="true" class="shrink-0">
                            <line x1="1" y1="5" x2="23" y2="5"
                                  stroke="{{ $stroke }}"
                                  stroke-width="{{ $isLead ? 2.5 : ($style['width'] ?? 1.8) }}"
                                  stroke-linecap="round"
                                  @if ($dash) stroke-dasharray="{{ $dash }}" @endif />
                        </svg>
                        <span class="{{ $isLead ? 'font-semibold text-coral-700' : '' }}">{{ $series['label'] }}</span>
                    </li>
                @endforeach
            </ul>
        </div>

        <details class="mt-6 border-t border-slate-200 pt-4">
            <summary class="cursor-pointer font-semibold text-slate-700 underline decoration-slate-300 underline-offset-4 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-coral-500">
                Näytä tiedot taulukkona
            </summary>
            <div class="mt-4 overflow-x-auto">
                <table class="min-w-full border-collapse text-left text-sm text-slate-700 tabular-nums">
                    <caption class="sr-only">Sähkösopimustyyppien viikoittaiset mediaanivuosikustannukset 5 000 kilowattitunnin kulutuksella.</caption>
                    <thead>
                        <tr class="border-b border-slate-200">
                            <th scope="col" class="whitespace-nowrap px-3 py-2 font-semibold text-slate-900">Viikko</th>
                            @foreach ($leadChartPayload['series'] as $series)
                                <th scope="col" class="whitespace-nowrap px-3 py-2 font-semibold text-slate-900">{{ $series['label'] }} (€/v)</th>
                            @endforeach
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($leadChartPayload['x'] as $index => $timestamp)
                            <tr class="border-b border-slate-100">
                                <th scope="row" class="whitespace-nowrap px-3 py-2 font-medium text-slate-900">{{ Carbon::createFromTimestamp((int) $timestamp)->translatedFormat('j.n.Y') }}</th>
                                @foreach ($leadChartPayload['series'] as $series)
                                    <td class="whitespace-nowrap px-3 py-2">{{ $series['values'][$index] === null ? '–' : number_format($series['values'][$index], 0, ',', ' ') }}</td>
                                @endforeach
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </details>

        <p class="mt-5 max-w-prose text-base leading-7 text-slate-600">
            Käyrät näyttävät, millä tasolla eri sopimustyyppejä on tyypillisesti ollut tarjolla. Oma sopimuksesi voi olla tätä halvempi tai kalliimpi.
            <a href="/sahkosopimus/tilastot" class="font-semibold text-coral-700 underline decoration-coral-200 underline-offset-4 hover:text-coral-800">Avaa koko hintatilasto</a>.
        </p>
    @endif

    @push('scripts')
        @once
            @vite('resources/js/contract-price-statistics.js')
        @endonce
    @endpush
</section>
