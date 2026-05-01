@php
    $hasData = !empty($chartData['labels']);
    $segmentRates = $chartData['segmentRates'] ?? [];
    $segmentMeta = $chartData['segmentMeta'] ?? [];
    $segmentColors = [
        'fixed_term_12' => '#3b82f6',
        'fixed_term_24' => '#0ea5e9',
        'open_ended' => '#64748b',
    ];
    $segmentDash = [
        'fixed_term_12' => '6,3',
        'fixed_term_24' => '2,3',
        'open_ended' => '10,4',
    ];
    $fmt = fn ($v) => $v === null ? '–' : number_format($v, 1, ',', ' ');
@endphp

<section class="not-prose" aria-labelledby="winrate-heading">
    <p class="text-xs font-semibold uppercase tracking-[0.16em] text-coral-700">Pörssisähkö vs. kiinteät, edullisin viidennes</p>
    <h2 id="winrate-heading" class="mt-2 text-2xl font-bold tracking-tight text-slate-900">
        Kuinka usein pörssisähkö on ollut edullisempi?
    </h2>
    <p class="mt-2 max-w-prose text-base leading-7 text-slate-600">
        Vertailu suosii hintatietoista kuluttajaa: jokaiselta päivältä {{ $chartData['from'] ?? '' }}–{{ $chartData['to'] ?? '' }}
        otetaan kunkin sopimustyypin <strong>edullisimman viidenneksen raja-arvo</strong> (p20). Kun oranssi viiva on muiden alapuolella,
        myös tarjolla olevien edullisten kiinteähintaisten sopimusten joukosta paras pörssisopimus on ollut halvempi.
    </p>

    @if (!$hasData)
        <div class="mt-6 rounded-xl border border-dashed border-slate-200 bg-slate-50 p-8 text-center text-sm text-slate-600">
            Vertailutietoa ei ole vielä saatavilla.
        </div>
    @else
        {{-- Headline figures: pörssisähkön voitto-osuus jokaista vertailutyyppiä vastaan --}}
        <dl class="mt-6 grid grid-cols-2 sm:grid-cols-4 gap-x-6 gap-y-6 border-t border-slate-200 pt-6">
            @foreach ($segmentRates as $key => $rate)
                <div>
                    <dt class="text-[11px] font-semibold tracking-[0.16em] uppercase text-slate-500">vs. {{ $rate['label'] }}</dt>
                    <dd class="mt-1 text-2xl font-extrabold tabular-nums text-coral-600">
                        {{ $fmt($rate['spot_win_pct']) }}<span class="text-sm font-medium text-coral-600">&nbsp;%</span>
                    </dd>
                    <p class="mt-1 text-[11px] text-slate-500">
                        @if ($rate['spot_wins'] !== null)
                            {{ $rate['spot_wins'] }} / {{ $rate['overlap_days'] }} päivää
                        @else
                            Ei aineistoa
                        @endif
                    </p>
                </div>
            @endforeach
            <div>
                <dt class="text-[11px] font-semibold tracking-[0.16em] uppercase text-slate-500">Aineisto</dt>
                <dd class="mt-1 text-2xl font-extrabold tabular-nums text-slate-900">
                    {{ $chartData['total'] }}<span class="text-sm font-medium text-slate-500">&nbsp;päivää</span>
                </dd>
            </div>
        </dl>

        <div class="relative mt-8">
            <div class="flex flex-wrap items-center gap-x-5 gap-y-2 mb-4">
                <span class="flex items-center gap-2 text-sm text-slate-600">
                    <span class="h-0.5 w-4 bg-coral-500"></span>
                    Pörssisähkö
                </span>
                @foreach ($segmentMeta as $key => $label)
                    <span class="flex items-center gap-2 text-sm text-slate-600">
                        <svg width="20" height="6" viewBox="0 0 20 6" aria-hidden="true" class="shrink-0">
                            <line x1="0" y1="3" x2="20" y2="3" stroke="{{ $segmentColors[$key] ?? '#64748b' }}" stroke-width="2" stroke-linecap="round" stroke-dasharray="{{ $segmentDash[$key] ?? '6,3' }}"/>
                        </svg>
                        {{ $label }}
                    </span>
                @endforeach
            </div>

            <div class="relative" style="height: 320px;">
                <canvas id="winRateChart"
                        data-chart="{{ json_encode($chartData) }}"
                        data-segment-colors="{{ json_encode($segmentColors) }}"
                        data-segment-dash="{{ json_encode($segmentDash) }}"></canvas>
            </div>
        </div>

        <p class="mt-5 max-w-prose text-base leading-7 text-slate-600">
            Vertailu perustuu kunkin sopimustyypin edullisimman viidenneksen raja-arvoon (p20),
            eli siihen hintaan, jonka alle viidennes saman tyypin tarjouksista kyseisenä päivänä jäi.
            Luvut eivät ota huomioon yksittäisen kuluttajan mahdollisuuksia ajoittaa kulutusta:
            pörssisähkön todellinen etu korostuu, jos pystyt hyödyntämään edullisia yö- ja viikonloppuhintoja.
        </p>
    @endif

    @push('scripts')
        <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
        <script>
            function initWinRateChart() {
                const ctx = document.getElementById('winRateChart');
                if (!ctx) return;

                const dataAttr = ctx.getAttribute('data-chart');
                if (!dataAttr) return;

                if (ctx.chartInstance) {
                    ctx.chartInstance.destroy();
                }

                try {
                    const chartData = JSON.parse(dataAttr);
                    const colors = JSON.parse(ctx.getAttribute('data-segment-colors') || '{}');
                    const dashes = JSON.parse(ctx.getAttribute('data-segment-dash') || '{}');

                    const datasets = [
                        {
                            label: 'Pörssisähkö',
                            data: chartData.spot,
                            borderColor: '#f97316',
                            backgroundColor: 'rgba(249, 115, 22, 0.06)',
                            borderWidth: 2.5,
                            pointRadius: 0,
                            pointHoverRadius: 5,
                            fill: false,
                            tension: 0.3,
                        },
                    ];

                    Object.entries(chartData.segmentMeta || {}).forEach(([key, label]) => {
                        const dashStr = dashes[key] || '6,3';
                        datasets.push({
                            label,
                            data: (chartData.series && chartData.series[key]) || [],
                            borderColor: colors[key] || '#64748b',
                            backgroundColor: 'transparent',
                            borderWidth: 2,
                            borderDash: dashStr.split(',').map(Number),
                            pointRadius: 0,
                            pointHoverRadius: 5,
                            fill: false,
                            tension: 0.3,
                            spanGaps: true,
                        });
                    });

                    ctx.chartInstance = new Chart(ctx, {
                        type: 'line',
                        data: {
                            labels: chartData.labels,
                            datasets,
                        },
                        options: {
                            responsive: true,
                            maintainAspectRatio: false,
                            interaction: {
                                intersect: false,
                                mode: 'index'
                            },
                            plugins: {
                                legend: {
                                    display: false
                                },
                                tooltip: {
                                    backgroundColor: '#0f172a',
                                    titleFont: { size: 13, weight: 'bold' },
                                    bodyFont: { size: 12 },
                                    padding: 10,
                                    cornerRadius: 8,
                                    callbacks: {
                                        label: function(context) {
                                            if (context.parsed.y === null) return null;
                                            return context.dataset.label + ': ' + context.parsed.y.toLocaleString('fi-FI') + ' €/v';
                                        }
                                    }
                                }
                            },
                            scales: {
                                x: {
                                    grid: { display: false },
                                    ticks: {
                                        font: { size: 11 },
                                        maxTicksLimit: 12
                                    }
                                },
                                y: {
                                    ticks: {
                                        callback: function(value) {
                                            return value + ' €';
                                        },
                                        font: { size: 11 }
                                    },
                                    grid: {
                                        color: '#f1f5f9'
                                    }
                                }
                            }
                        }
                    });
                } catch (e) {
                    console.error('Win rate chart init error:', e);
                }
            }

            document.addEventListener('DOMContentLoaded', initWinRateChart);
            document.addEventListener('livewire:navigated', initWinRateChart);
        </script>
    @endpush
</section>
