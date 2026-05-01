@php
    $hasData = !empty($chartData['labels']);
    $spotPercent = $chartData['total'] > 0 ? round($chartData['spotWins'] / $chartData['total'] * 100, 1) : 0;
    $fixedPercent = $chartData['total'] > 0 ? round($chartData['fixedWins'] / $chartData['total'] * 100, 1) : 0;
@endphp

<section class="not-prose my-10 rounded-2xl border border-slate-200 bg-white p-5 shadow-sm md:p-6" aria-labelledby="winrate-heading">
    <div>
        <p class="text-xs font-semibold uppercase tracking-[0.16em] text-coral-700">Pörssisähkö vs kiinteä — päivittäinen vertailu</p>
        <h2 id="winrate-heading" class="mt-2 text-2xl font-bold tracking-tight text-slate-900">
            Kuinka usein pörssisähkö on ollut edullisempi?
        </h2>
        <p class="mt-2 max-w-prose text-sm leading-6 text-slate-600">
            Kuvaaja näyttää pörssisähkön ja kiinteähintaisen sopimuksen mediaanikustannuksen
            jokaiselta päivältä {{ $chartData['from'] ?? '' }}–{{ $chartData['to'] ?? '' }}.
            Kun oranssi viiva on sinisen alapuolella, pörssisähkö on ollut edullisempi.
        </p>
    </div>

    @if (!$hasData)
        <div class="mt-6 rounded-xl border border-dashed border-slate-200 bg-slate-50 p-8 text-center text-sm text-slate-600">
            Vertailutietoa ei ole vielä saatavilla.
        </div>
    @else
        {{-- Stats row --}}
        <div class="mt-6 grid grid-cols-1 sm:grid-cols-3 gap-3">
            <div class="rounded-lg border border-slate-200 bg-white p-4 text-center">
                <p class="text-xs font-medium text-slate-500 uppercase tracking-wide">Pörssisähkö edullisempi</p>
                <p class="mt-1 text-3xl font-extrabold text-coral-600 tabular-nums">{{ $spotPercent }}<span class="text-lg">%</span></p>
                <p class="mt-1 text-xs text-slate-500">{{ $chartData['spotWins'] }} / {{ $chartData['total'] }} päivää</p>
            </div>
            <div class="rounded-lg border border-slate-200 bg-white p-4 text-center">
                <p class="text-xs font-medium text-slate-500 uppercase tracking-wide">Kiinteä edullisempi</p>
                <p class="mt-1 text-3xl font-extrabold text-slate-900 tabular-nums">{{ $fixedPercent }}<span class="text-lg">%</span></p>
                <p class="mt-1 text-xs text-slate-500">{{ $chartData['fixedWins'] }} / {{ $chartData['total'] }} päivää</p>
            </div>
            <div class="rounded-lg border border-slate-200 bg-white p-4 text-center">
                <p class="text-xs font-medium text-slate-500 uppercase tracking-wide">Aineisto</p>
                <p class="mt-1 text-3xl font-extrabold text-slate-900 tabular-nums">{{ $chartData['total'] }}</p>
                <p class="mt-1 text-xs text-slate-500">päivää</p>
            </div>
        </div>

        {{-- Chart --}}
        <div class="relative mt-6">
            <div class="flex justify-center gap-6 mb-4">
                <div class="flex items-center gap-2">
                    <svg width="24" height="10" viewBox="0 0 24 10" aria-hidden="true" class="shrink-0">
                        <line x1="1" y1="5" x2="23" y2="5" stroke="#f97316" stroke-width="2.5" stroke-linecap="round"/>
                    </svg>
                    <span class="text-sm text-slate-600">Pörssisähkö</span>
                </div>
                <div class="flex items-center gap-2">
                    <svg width="24" height="10" viewBox="0 0 24 10" aria-hidden="true" class="shrink-0">
                        <line x1="1" y1="5" x2="23" y2="5" stroke="#3b82f6" stroke-width="2.5" stroke-linecap="round" stroke-dasharray="6,3"/>
                    </svg>
                    <span class="text-sm text-slate-600">Kiinteä 12 kk</span>
                </div>
            </div>

            <div class="relative" style="height: 300px;">
                <canvas id="winRateChart" data-chart="{{ json_encode($chartData) }}"></canvas>
            </div>
        </div>

        <p class="mt-4 text-sm leading-6 text-slate-600">
            Huomaa, että tämä vertailu perustuu sopimusten mediaanihintoihin eikä ota huomioon yksittäisen
            kuluttajan mahdollisuuksia ajoittaa kulutusta. Pörssisähkön todellinen etu korostuu,
            jos pystyt hyödyntämään edullisia yö- ja viikonloppuhintoja.
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

                    ctx.chartInstance = new Chart(ctx, {
                        type: 'line',
                        data: {
                            labels: chartData.labels,
                            datasets: [
                                {
                                    label: 'Pörssisähkö',
                                    data: chartData.spot,
                                    borderColor: '#f97316',
                                    backgroundColor: 'rgba(249, 115, 22, 0.08)',
                                    borderWidth: 2.5,
                                    pointRadius: 0,
                                    pointHoverRadius: 5,
                                    fill: true,
                                    tension: 0.3,
                                },
                                {
                                    label: 'Kiinteä 12 kk',
                                    data: chartData.fixed,
                                    borderColor: '#3b82f6',
                                    backgroundColor: 'transparent',
                                    borderWidth: 2,
                                    borderDash: [6, 3],
                                    pointRadius: 0,
                                    pointHoverRadius: 5,
                                    fill: false,
                                    tension: 0.3,
                                }
                            ]
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
                                    backgroundColor: '#1e293b',
                                    titleFont: { size: 13, weight: 'bold' },
                                    bodyFont: { size: 13 },
                                    padding: 12,
                                    cornerRadius: 8,
                                    callbacks: {
                                        label: function(context) {
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
                                        font: { size: 12 }
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
