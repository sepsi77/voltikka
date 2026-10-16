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

<section class="vk-lr-figure" aria-labelledby="contract-price-comparison-heading">
    <div class="vk-lr-figure-head">
        <p class="vk-lr-figure-kicker">Voltikka hintatilastot</p>
        <h2 id="contract-price-comparison-heading" class="vk-lr-duo-title">
            Sähkösopimusten mediaanihinta vertailussa
        </h2>
    </div>
    <p class="vk-lr-text">
        Viikoittainen vertailu näyttää sopimustyyppien tyypillisen vuosikustannuksen 5&nbsp;000 kWh kulutuksella. Luku on mediaani: puolet sopimuksista oli halvempia ja puolet kalliimpia.
    </p>
    <p class="vk-lr-text">
        Kaavio ei näytä halvinta tarjousta eikä kaikkien sopimusten keskiarvoa. Pörssisähkön lukema sisältää sopimusten marginaalit ja edeltävän 12 kuukauden toteutuneen pörssihinnan.
    </p>

    @if (! $hasData)
        <p class="vk-lr-empty">
            Hintatilastoa ei ole vielä saatavilla tälle vertailulle.
        </p>
    @else
        <div class="flex flex-col gap-3">
            <ul class="vk-lr-legend" aria-label="Kaavion selite">
                @foreach ($leadChartPayload['series'] as $i => $series)
                    <li class="vk-lr-legend-item">
                        <x-ink.series-line :label="$series['label']" :lead="$i === 0 ? 'var(--color-cat-spot)' : null" />
                        <span>{{ $series['label'] }}</span>
                    </li>
                @endforeach
                <li class="vk-lr-legend-unit">€/v, 5 000 kWh</li>
            </ul>

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
        </div>

        <div class="flex max-w-[760px] flex-col gap-3">
            @if ($from && $to)
                <p class="vk-lr-note">Aineisto: {{ $from }}–{{ $to }}. Sis. ALV 25,5 %.</p>
            @endif
            <p id="contract-price-comparison-takeaway" class="vk-lr-note">
                <strong class="font-bold text-ink-body">Uusin saatavilla oleva viikkotaso:</strong>
                @foreach ($latestMedians as $median)
                    {{ $median['label'] }} {{ number_format($median['value'], 0, ',', ' ') }} €/v ({{ $median['date'] }}){{ $loop->last ? '.' : ',' }}
                @endforeach
            </p>
        </div>

        <details class="vk-lr-details">
            <summary>Näytä tiedot taulukkona</summary>
            <div class="vk-lr-table-wrap">
                <table class="vk-lr-table">
                    <caption class="sr-only">Sähkösopimustyyppien viikoittaiset mediaanivuosikustannukset 5 000 kilowattitunnin kulutuksella.</caption>
                    <thead>
                        <tr>
                            <th scope="col">Viikko</th>
                            @foreach ($leadChartPayload['series'] as $series)
                                <th scope="col">{{ $series['label'] }} (€/v)</th>
                            @endforeach
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($leadChartPayload['x'] as $index => $timestamp)
                            <tr>
                                <th scope="row">{{ Carbon::createFromTimestamp((int) $timestamp)->translatedFormat('j.n.Y') }}</th>
                                @foreach ($leadChartPayload['series'] as $series)
                                    <td>{{ $series['values'][$index] === null ? '–' : number_format($series['values'][$index], 0, ',', ' ') }}</td>
                                @endforeach
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </details>

        <p class="vk-lr-text vk-lr-text--small">
            Käyrät näyttävät, millä tasolla eri sopimustyyppejä on tyypillisesti ollut tarjolla. Oma sopimuksesi voi olla tätä halvempi tai kalliimpi.
            <a href="/sahkosopimus/tilastot" class="vk-text-link vk-focus">Avaa koko hintatilasto</a>.
        </p>
    @endif

    @push('scripts')
        @once
            @vite('resources/js/contract-price-statistics.js')
        @endonce
    @endpush
</section>
