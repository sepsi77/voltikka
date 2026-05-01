@php
    $hasData = !empty($chartData['labels']);
@endphp

<section class="not-prose my-10 rounded-2xl border border-slate-200 bg-white p-5 shadow-sm md:p-6" aria-labelledby="seasonality-heading">
    <div>
        <p class="text-xs font-semibold uppercase tracking-[0.16em] text-coral-700">Spot-hinnan kausivaihtelu</p>
        <h2 id="seasonality-heading" class="mt-2 text-2xl font-bold tracking-tight text-slate-900">
            Milloin sähkö on halvinta?
        </h2>
        <p class="mt-2 max-w-prose text-sm leading-6 text-slate-600">
            Pörssisähkön hinta vaihtelee selvästi vuodenajan mukaan. Kuvaaja näyttää kuukausittaiset keskiarvot
            viimeiseltä 13 kuukaudelta: päivähinnat (klo 7–22) ja yöhinnat (klo 22–7). ALV 25,5 % sisältyy hintoihin.
        </p>
    </div>

    @if (!$hasData)
        <div class="mt-6 rounded-xl border border-dashed border-slate-200 bg-slate-50 p-8 text-center text-sm text-slate-600">
            Kausitilastoa ei ole vielä saatavilla.
        </div>
    @else
        <div class="relative mt-6">
            <div class="flex justify-center gap-6 mb-4">
                <div class="flex items-center gap-2">
                    <div class="h-3 w-3 rounded-sm bg-coral-500"></div>
                    <span class="text-sm text-slate-600">Päivä (7–22)</span>
                </div>
                <div class="flex items-center gap-2">
                    <div class="h-3 w-3 rounded-sm bg-slate-400"></div>
                    <span class="text-sm text-slate-600">Yö (22–7)</span>
                </div>
            </div>

            <div class="relative" style="height: 280px;">
                <canvas id="seasonalityChart" data-chart="{{ json_encode($chartData) }}"></canvas>
            </div>
        </div>

        <p class="mt-4 text-sm leading-6 text-slate-600">
            Yöllä ja viikonloppuisin hinta on usein matalampi, kun taas talvipäivinä kysyntäpiikit nostavat
            hintaa merkittävästi. Ero päivän ja yön välillä voi olla useita senttejä kilowattitunnilta.
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
                                    backgroundColor: '#1e293b',
                                    titleFont: { size: 14, weight: 'bold' },
                                    bodyFont: { size: 13 },
                                    padding: 12,
                                    cornerRadius: 8,
                                    callbacks: {
                                        label: function(context) {
                                            return context.dataset.label + ': ' + context.parsed.y.toLocaleString('fi-FI') + ' c/kWh';
                                        }
                                    }
                                }
                            },
                            scales: {
                                x: {
                                    grid: { display: false },
                                    ticks: {
                                        font: { size: 12 }
                                    }
                                },
                                y: {
                                    beginAtZero: true,
                                    ticks: {
                                        callback: function(value) {
                                            return value + ' c';
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
                    console.error('Seasonality chart init error:', e);
                }
            }

            document.addEventListener('DOMContentLoaded', initSeasonalityChart);
            document.addEventListener('livewire:navigated', initSeasonalityChart);
        </script>
    @endpush
</section>
