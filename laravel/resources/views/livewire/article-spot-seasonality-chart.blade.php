@php
    $hasData = !empty($chartData['labels']);
    $fmt = fn ($v, $decimals = 2) => $v === null
        ? '–'
        : number_format($v, $decimals, ',', ' ');
@endphp

<section class="vk-lr-figure" aria-labelledby="seasonality-heading">
    <div class="vk-lr-figure-head">
        <p class="vk-lr-figure-kicker">Spot-hinnan kausivaihtelu</p>
        <x-site.section-heading id="seasonality-heading" wave="270">Milloin sähkö on halvinta?</x-site.section-heading>
    </div>
    <p class="vk-lr-text">
        Pörssisähkön hinta vaihtelee selvästi vuodenajan mukaan. Kuvaaja näyttää kuukausittaiset keskiarvot
        viimeiseltä 13 kuukaudelta: päivähinnat (klo 7–22) ja yöhinnat (klo 22–7). ALV 25,5 % sisältyy hintoihin.
    </p>

    @if (!$hasData)
        <p class="vk-lr-empty">
            Kausitilastoa ei ole vielä saatavilla.
        </p>
    @else
        <p id="seasonality-takeaway" class="vk-lr-text">
            <strong>Aineiston halvin kuukausi oli {{ $metrics['cheapestLabel'] }}</strong>, jolloin keskihinta oli {{ $fmt($metrics['cheapestPrice']) }} c/kWh. Kallein oli {{ $metrics['expensiveLabel'] }}, jolloin keskihinta oli {{ $fmt($metrics['expensivePrice']) }} c/kWh.
        </p>

        {{-- Headline figures (Paper "Stat row") --}}
        <dl class="vk-lr-stats">
            <div class="vk-lr-stat vk-lr-stat--low">
                <dt>Halvin kuukausi</dt>
                <dd class="tabular-nums">{{ $fmt($metrics['cheapestPrice']) }}<span class="vk-lr-stat-unit"> c/kWh</span></dd>
                <p class="vk-lr-stat-sub">{{ $metrics['cheapestLabel'] }}</p>
            </div>
            <div class="vk-lr-stat vk-lr-stat--high">
                <dt>Kallein kuukausi</dt>
                <dd class="tabular-nums">{{ $fmt($metrics['expensivePrice']) }}<span class="vk-lr-stat-unit"> c/kWh</span></dd>
                <p class="vk-lr-stat-sub">{{ $metrics['expensiveLabel'] }}</p>
            </div>
            <div class="vk-lr-stat">
                <dt>Päivän keskihinta</dt>
                <dd class="tabular-nums">{{ $fmt($metrics['avgDay']) }}<span class="vk-lr-stat-unit"> c/kWh</span></dd>
                <p class="vk-lr-stat-sub">Klo 7–22</p>
            </div>
            <div class="vk-lr-stat">
                <dt>Yön keskihinta</dt>
                <dd class="tabular-nums">{{ $fmt($metrics['avgNight']) }}<span class="vk-lr-stat-unit"> c/kWh</span></dd>
                <p class="vk-lr-stat-sub">Klo 22–7</p>
            </div>
        </dl>

        <div class="flex flex-col gap-3">
            <div class="vk-lr-legend">
                <span class="vk-lr-legend-item">
                    <span class="vk-lr-swatch" style="--lr-swatch: var(--color-fox)"></span>
                    Päivä (7–22)
                </span>
                <span class="vk-lr-legend-item">
                    <span class="vk-lr-swatch" style="--lr-swatch: var(--color-lake)"></span>
                    Yö (22–7)
                </span>
            </div>

            <div class="vk-lr-chart" style="height: 280px;">
                <canvas
                    id="seasonalityChart"
                    data-chart="{{ json_encode($chartData) }}"
                    role="img"
                    aria-label="Pörssisähkön kuukausittaiset päivä- ja yöhinnat."
                    aria-describedby="seasonality-takeaway"
                ></canvas>
            </div>
        </div>

        <details class="vk-lr-details">
            <summary>Näytä tiedot taulukkona</summary>
            <div class="vk-lr-table-wrap">
                <table class="vk-lr-table">
                    <caption class="sr-only">Pörssisähkön kuukausittaiset päivä- ja yöhinnat.</caption>
                    <thead>
                        <tr>
                            <th scope="col">Kuukausi</th>
                            <th scope="col">Päivä klo 7–22 (c/kWh)</th>
                            <th scope="col">Yö klo 22–7 (c/kWh)</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($chartData['labels'] as $index => $label)
                            <tr>
                                <th scope="row">{{ $label }}</th>
                                <td>{{ $fmt($chartData['day'][$index]) }}</td>
                                <td>{{ $fmt($chartData['night'][$index]) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </details>

        <p class="vk-lr-text vk-lr-text--small">
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

                // Design tokens (resources/css/tokens.css): the canvas cannot read CSS variables itself.
                const token = (name) => getComputedStyle(document.documentElement).getPropertyValue(name).trim();
                const ink = token('--color-ink');
                const tickFont = { family: 'Figtree, system-ui, sans-serif', size: 12, weight: '700' };

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
                                    backgroundColor: token('--color-fox'),
                                    borderRadius: { topLeft: 2, topRight: 2 },
                                    borderSkipped: 'bottom',
                                    barPercentage: 0.7,
                                },
                                {
                                    label: 'Yö (22–7)',
                                    data: chartData.night,
                                    backgroundColor: token('--color-lake'),
                                    borderRadius: { topLeft: 2, topRight: 2 },
                                    borderSkipped: 'bottom',
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
                                    backgroundColor: ink,
                                    titleFont: { family: 'Figtree, system-ui, sans-serif', size: 13, weight: 'bold' },
                                    bodyFont: { family: 'Figtree, system-ui, sans-serif', size: 12 },
                                    padding: 10,
                                    cornerRadius: 4,
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
                                    border: { color: ink, width: 1.8 },
                                    ticks: {
                                        color: token('--color-ink-muted'),
                                        font: tickFont,
                                        autoSkip: true,
                                        maxRotation: 0,
                                    }
                                },
                                y: {
                                    beginAtZero: true,
                                    border: { display: false, dash: [3, 4] },
                                    ticks: {
                                        callback: function(value) {
                                            return value + ' c';
                                        },
                                        color: token('--color-ink-muted'),
                                        font: tickFont,
                                    },
                                    grid: {
                                        color: token('--color-edge'),
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
