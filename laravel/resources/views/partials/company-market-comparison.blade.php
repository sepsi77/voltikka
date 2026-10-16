@php
    use Illuminate\Support\Carbon;

    $reference = number_format($marketComparison['reference_consumption'], 0, ',', ' ');
    $statDate = Carbon::parse($marketComparison['stat_date'])->translatedFormat('j.n.Y');
    $chart = $marketComparison['chart'] ?? null;
    $historicalFallback = ($marketComparison['is_historical_fallback'] ?? false) === true;

    // Redesign: the seller line is drawn in fox (--color-fox, #C4501C) instead of the service's
    // coral. The renderer draws on a canvas, which cannot read a CSS variable, so the hex is
    // repeated here; presentation only, the payload data is unchanged.
    if ($chart) {
        $chart['leadStroke'] = '#C4501C';
    }
@endphp

{{-- Market comparison (Paper board AX2-0, "Sähkön hinta"): one ruled row per contract type
     with an inked range track, then the weekly trend chart. No cards. --}}
<section id="hintavertailu" class="vk-co-section" aria-labelledby="hintavertailu-title">
    <div class="vk-co-head vk-co-head--split vk-co-head--top">
        <div class="vk-co-title-wrap">
            <h2 id="hintavertailu-title" class="vk-co-title">{{ $company->name }}: sähkön hinta</h2>
            <x-ink.wave width="280" stroke="3.5" class="max-w-full" />
        </div>

        <div class="vk-co-intro">
            <p class="vk-co-intro-lead">Sähkön hinta riippuu sopimustyypistä ja vuosikulutuksesta.</p>
            <p class="vk-co-intro-text">
                Jokainen rivi näyttää yhtiön {{ $historicalFallback ? 'päivätyn' : 'nykyisen' }} 12 kuukauden hinta-arvion sekä saman sopimustyypin markkinamediaanin ja keskimmäisen 60 %:n hintahaarukan.
                Harmaa palkki näyttää hintahaarukan, pystyviiva mediaanin ja oranssi piste yhtiön hinnan.
            </p>
            <p class="vk-co-intro-small">
                @if ($historicalFallback)
                    <span class="font-bold text-ink">Nykyinen laskettu vertailu ei ole saatavilla.</span>
                    Alla on viimeisin yhtenäinen historiallinen hintavertailu {{ $statDate }}. Se ei ole tämän päivän hintavertailu.
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
        </div>
    </div>

    <ul class="vk-co-legend" aria-label="Selite">
        <li><span class="vk-co-legend-dot" aria-hidden="true"></span>{{ $company->name }}</li>
        <li><span class="vk-co-legend-band" aria-hidden="true"></span>Markkinan haarukka</li>
        <li><span class="vk-co-legend-median" aria-hidden="true"></span>Markkinan mediaani</li>
    </ul>

    <ul class="vk-co-ranges">
        @foreach ($marketComparison['rows'] as $row)
            @php
                $delta = $row['delta_vs_median'];
                $positionLabel = match ($row['position']) {
                    'below_p20' => 'Markkinan halvimmassa 20 %:ssa',
                    'above_p80' => 'Markkinan kalleimmassa 20 %:ssa',
                    default => 'Markkinan tavanomaisessa haarukassa',
                };
                $positionClass = match ($row['position']) {
                    'below_p20' => 'text-aurora',
                    'above_p80' => 'text-price-high',
                    default => 'text-ink',
                };
            @endphp
            <li class="vk-co-range-row">
                <div class="vk-co-range-main">
                    <div class="vk-co-range-name">
                        <h3>{{ $row['label'] }}</h3>
                        <p>
                            Yhtiöllä {{ $row['company_contract_count'] }}
                            {{ $row['company_contract_count'] === 1 ? 'sopimus' : 'sopimusta' }}
                        </p>
                    </div>

                    {{-- Geometry is precomputed in CompanyMarketComparisonService; keep this
                         presentation-only. Each row has its own scale (p20-p80 spread), so the
                         track shows no shared euro axis. --}}
                    <div class="vk-co-track" aria-hidden="true">
                        <span class="vk-co-track-line"></span>
                        <span class="vk-co-track-band" style="left: {{ $row['band_left_percent'] }}%; width: {{ $row['band_width_percent'] }}%;"></span>
                        <span class="vk-co-track-median" style="left: {{ $row['median_percent'] }}%;"></span>
                        <span class="vk-co-track-marker" style="left: {{ $row['marker_percent'] }}%;"></span>
                    </div>

                    <div class="vk-co-range-value">
                        <p class="vk-co-range-price">{{ number_format($row['company_value'], 0, ',', ' ') }} {{ "\u{20AC}" }}/v</p>
                        <p class="vk-co-range-position {{ $positionClass }}">{{ $positionLabel }}.</p>
                        <p class="vk-co-range-delta">
                            @if (abs($delta) < 1)
                                Sama kuin markkinan mediaani.
                            @else
                                {{ number_format(abs($delta), 0, ',', ' ') }} {{ "\u{20AC}" }} {{ $delta < 0 ? 'halvempi' : 'kalliimpi' }} kuin markkinan mediaani.
                            @endif
                        </p>
                    </div>
                </div>

                <dl class="vk-co-range-stats">
                    <div>
                        <dt>Markkinan halvempi 20 %:</dt>
                        <dd>{{ number_format($row['market_p20'], 0, ',', ' ') }} {{ "\u{20AC}" }}</dd>
                    </div>
                    <div>
                        <dt>Mediaani:</dt>
                        <dd>{{ number_format($row['market_median'], 0, ',', ' ') }} {{ "\u{20AC}" }}</dd>
                    </div>
                    <div>
                        <dt>Kalliimpi 20 %:</dt>
                        <dd>{{ number_format($row['market_p80'], 0, ',', ' ') }} {{ "\u{20AC}" }}</dd>
                    </div>
                    <div>
                        <dt>Markkinalla vertailussa:</dt>
                        <dd>{{ $row['market_contract_count'] }} sopimusta</dd>
                    </div>
                </dl>
            </li>
        @endforeach
    </ul>

    @if ($chart)
        <div class="vk-co-trend">
            <h3 class="vk-co-subtitle">
                Hintakehitys: {{ $marketComparison['chart_segment_label'] }}
            </h3>
            <p class="vk-co-intro-text">
                Viikoittainen vertailu edeltävältä 12 kuukaudelta. Varjostettu alue näyttää markkinan keskimmäisen 60 %:n.
                @if ($historicalFallback)
                    Pisteet ovat päivättyjä vuosikustannusarvioita. Viimeisin piste on {{ $statDate }}.
                @elseif (($chart['current_pricing_basis'] ?? null) === 'canonical_calculation' && ($chart['canonical_from'] ?? null) !== null)
                    Vanhemmat arviot perustuvat myyjiltä havaittuihin hintoihin. {{ Carbon::parse($chart['canonical_from'])->translatedFormat('j.n.Y') }} alkaen pisteet ovat Voltikan laskemia vertailuhintoja.
                @endif
            </p>

            <ul class="vk-co-legend" aria-label="Kaavion selite">
                <li>
                    <svg width="22" height="10" viewBox="0 0 22 10" aria-hidden="true" class="shrink-0">
                        <line x1="1" y1="5" x2="21" y2="5" style="stroke: var(--color-fox)" stroke-width="3.4" stroke-linecap="round" />
                    </svg>
                    {{ $company->name }}
                </li>
                <li>
                    <x-ink.series-line label="Markkinan mediaani" width="22" />
                    Markkinan mediaani
                </li>
            </ul>

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

    <div class="vk-co-footnote-row">
        <p class="vk-co-footnote">Luvut perustuvat Voltikan päivittäiseen hintaseurantaan.</p>
        <a href="/sahkosopimus/tilastot" class="vk-co-ink-link vk-co-ink-link--ui vk-focus">Avaa koko hintatilasto &rarr;</a>
    </div>
</section>
