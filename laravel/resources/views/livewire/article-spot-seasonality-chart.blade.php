@php
    $hasData = !empty($chartData['labels']);
    $fmt = fn ($v, $decimals = 2) => $v === null
        ? '–'
        : number_format($v, $decimals, ',', ' ');
@endphp

<section class="not-prose" aria-labelledby="seasonality-heading">
    <p class="text-xs font-semibold uppercase tracking-[0.16em] text-coral-700">Spot-hinnan kausivaihtelu</p>
    <h2 id="seasonality-heading" class="mt-2 text-2xl font-bold tracking-tight text-slate-900">
        Milloin sähkö on halvinta?
    </h2>
    <p class="mt-2 max-w-prose text-base leading-7 text-slate-600">
        Pörssisähkön hinta vaihtelee selvästi vuodenajan mukaan. Kuvaaja näyttää kuukausittaiset keskiarvot
        viimeiseltä 13 kuukaudelta: päivähinnat (klo 7–22) ja yöhinnat (klo 22–7). ALV 25,5 % sisältyy hintoihin.
    </p>

    @if (!$hasData)
        <div class="mt-6 rounded-xl border border-dashed border-slate-200 bg-slate-50 p-8 text-center text-sm text-slate-600">
            Kausitilastoa ei ole vielä saatavilla.
        </div>
    @else
        {{-- Headline figures --}}
        <dl class="mt-6 grid grid-cols-2 sm:grid-cols-4 gap-x-6 gap-y-6 border-t border-slate-200 pt-6">
            <div>
                <dt class="text-[11px] font-semibold tracking-[0.16em] uppercase text-slate-500">Halvin kuukausi</dt>
                <dd class="mt-1 text-2xl font-extrabold tabular-nums text-slate-900">
                    {{ $fmt($metrics['cheapestPrice']) }}<span class="text-sm font-medium text-slate-500"> c/kWh</span>
                </dd>
                <p class="mt-1 text-[11px] text-slate-500">{{ $metrics['cheapestLabel'] }}</p>
            </div>
            <div>
                <dt class="text-[11px] font-semibold tracking-[0.16em] uppercase text-slate-500">Kallein kuukausi</dt>
                <dd class="mt-1 text-2xl font-extrabold tabular-nums text-slate-900">
                    {{ $fmt($metrics['expensivePrice']) }}<span class="text-sm font-medium text-slate-500"> c/kWh</span>
                </dd>
                <p class="mt-1 text-[11px] text-slate-500">{{ $metrics['expensiveLabel'] }}</p>
            </div>
            <div>
                <dt class="text-[11px] font-semibold tracking-[0.16em] uppercase text-slate-500">Päivän keskihinta</dt>
                <dd class="mt-1 text-2xl font-extrabold tabular-nums text-slate-900">
                    {{ $fmt($metrics['avgDay']) }}<span class="text-sm font-medium text-slate-500"> c/kWh</span>
                </dd>
                <p class="mt-1 text-[11px] text-slate-500">Klo 7–22</p>
            </div>
            <div>
                <dt class="text-[11px] font-semibold tracking-[0.16em] uppercase text-slate-500">Yön keskihinta</dt>
                <dd class="mt-1 text-2xl font-extrabold tabular-nums text-slate-900">
                    {{ $fmt($metrics['avgNight']) }}<span class="text-sm font-medium text-slate-500"> c/kWh</span>
                </dd>
                <p class="mt-1 text-[11px] text-slate-500">Klo 22–7</p>
            </div>
        </dl>

        <div class="relative mt-8">
            <div class="flex flex-wrap items-center gap-x-5 gap-y-2 mb-4">
                <span class="flex items-center gap-2 text-sm text-slate-600">
                    <span class="h-3 w-3 rounded-sm bg-coral-500"></span>
                    Päivä (7–22)
                </span>
                <span class="flex items-center gap-2 text-sm text-slate-600">
                    <span class="h-3 w-3 rounded-sm bg-slate-400"></span>
                    Yö (22–7)
                </span>
            </div>

            <div class="relative" style="height: 280px;">
                <canvas id="seasonalityChart" data-chart="{{ json_encode($chartData) }}"></canvas>
            </div>
        </div>

        <p class="mt-5 max-w-prose text-base leading-7 text-slate-600">
            Talvipäivinä kysyntäpiikit nostavat hintaa merkittävästi, kun taas yöllä ja viikonloppuisin hinta
            on usein matalampi. Ero päivän ja yön välillä voi olla useita senttejä kilowattitunnilta.
        </p>
    @endif

    @push('scripts')
        <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
        <script>
            function initSeasonalityChart() {
                const ctx = document.getElementById('seasonalityChart');
                if (!ctx) return;

                const dataAttr = ctx.getAttribute('data-chart');
                if (!dataAttr) return;

                if (ctx.chartInstance) {
                    ctx.chartInstance.destroy();
                }

                try {
                    const chartData = JSON.parse(dataAttr);

                    ctx.chartInstance = new Chart(ctx, {
                        type: 'bar',
                        data: {
                            labels: chartData.labels,
                            datasets: [
                                {
                                    label: 'Päivä (7–22)',
                                    data: chartData.day,
                                    backgroundColor: '#f97316',
                                    borderRadius: 4,
                                    barPercentage: 0.7,
                                },
                                {
                                    label: 'Yö (22–7)',
                                    data: chartData.night,
                                    backgroundColor: '#94a3b8',
                                    borderRadius: 4,
                                    barPercentage: 0.7,
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
                                    backgroundColor: '#0f172a',
                                    titleFont: { size: 13, weight: 'bold' },
                                    bodyFont: { size: 12 },
                                    padding: 10,
                                    cornerRadius: 8,
                                    callbacks: {
                                        label: function(context) {
                                            return context.dataset.label + ': ' + context.parsed.y.toLocaleString('fi-FI', { minimumFractionDigits: 2, maximumFractionDigits: 2 }) + ' c/kWh';
                                        }
                                    }
                                }
                            },
                            scales: {
                                x: {
                                    grid: { display: false },
                                    ticks: {
                                        font: { size: 11 }
                                    }
                                },
                                y: {
                                    beginAtZero: true,
                                    ticks: {
                                        callback: function(value) {
                                            return value + ' c';
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
                    console.error('Seasonality chart init error:', e);
                }
            }

            document.addEventListener('DOMContentLoaded', initSeasonalityChart);
            document.addEventListener('livewire:navigated', initSeasonalityChart);
        </script>
    @endpush
</section>
