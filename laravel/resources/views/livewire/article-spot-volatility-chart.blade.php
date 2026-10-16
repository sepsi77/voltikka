@php
    $hasData = !empty($chartData['labels']);
    $fmt = fn ($v, $decimals = 2) => $v === null
        ? '–'
        : number_format($v, $decimals, ',', ' ');
@endphp

<section class="vk-lr-figure" aria-labelledby="volatility-heading">
    <div class="vk-lr-figure-head">
        <p class="vk-lr-figure-kicker">Pörssisähkön hintavaihtelu</p>
        <x-site.section-heading id="volatility-heading" wave="300">Kuinka paljon tuntihinta voi vaihdella?</x-site.section-heading>
    </div>
    <p class="vk-lr-text">
        Edellä vertailtiin eri sopimusten hintoja. Tässä katsotaan pörssisähkön tärkeintä riskiä: saman vuorokauden tuntihinnat voivat poiketa paljon toisistaan.
        Värillinen alue näyttää tavallisen vaihteluvälin, ohut viiva viikon mediaanin ja pisteet viikon halvimman sekä kalleimman tunnin.
    </p>

    @if (!$hasData)
        <p class="vk-lr-empty">
            Volatiliteettitietoja ei ole vielä saatavilla.
        </p>
    @else
        <p id="volatility-takeaway" class="vk-lr-text">
            <strong>Viimeisen vuoden toteutuneet tuntihinnat:</strong> alin {{ $fmt($metrics['min']) }} c/kWh, ylin {{ $fmt($metrics['max']) }} c/kWh ja keskiarvo {{ $fmt($metrics['avg']) }} c/kWh.
        </p>

        {{-- Headline figures (Paper "Stat row") --}}
        <dl class="vk-lr-stats">
            <div class="vk-lr-stat vk-lr-stat--lake">
                <dt>Halvin tunti</dt>
                <dd class="tabular-nums">{{ $fmt($metrics['min']) }}<span class="vk-lr-stat-unit"> c/kWh</span></dd>
            </div>
            <div class="vk-lr-stat vk-lr-stat--high">
                <dt>Kallein tunti</dt>
                <dd class="tabular-nums">{{ $fmt($metrics['max']) }}<span class="vk-lr-stat-unit"> c/kWh</span></dd>
            </div>
            <div class="vk-lr-stat">
                <dt>Keskihinta</dt>
                <dd class="tabular-nums">{{ $fmt($metrics['avg']) }}<span class="vk-lr-stat-unit"> c/kWh</span></dd>
            </div>
            <div class="vk-lr-stat">
                <dt>Kalliita päiviä</dt>
                <dd class="tabular-nums">{{ $metrics['spikeDays'] }}<span class="vk-lr-stat-unit"> päivää</span></dd>
                <p class="vk-lr-stat-sub">Yli 20 c/kWh</p>
            </div>
            <div class="vk-lr-stat">
                <dt>Miinushintaisia päiviä</dt>
                <dd class="tabular-nums">{{ $metrics['negativeDays'] }}<span class="vk-lr-stat-unit"> päivää</span></dd>
                <p class="vk-lr-stat-sub">Alle 0 c/kWh</p>
            </div>
        </dl>

        <div class="flex flex-col gap-3">
            <div class="vk-lr-legend">
                <span class="vk-lr-legend-item">
                    <span class="vk-lr-swatch vk-lr-swatch--band" style="--lr-swatch: var(--color-cat-spot)"></span>
                    Tavallinen vaihteluväli (p20–p80)
                </span>
                <span class="vk-lr-legend-item">
                    <span class="vk-lr-swatch vk-lr-swatch--line" style="--lr-swatch: var(--color-cat-spot)"></span>
                    Viikon mediaani
                </span>
                <span class="vk-lr-legend-item">
                    <span class="vk-lr-swatch vk-lr-swatch--dot" style="--lr-swatch: var(--color-lake)"></span>
                    <span class="vk-lr-swatch vk-lr-swatch--dot" style="--lr-swatch: var(--color-price-high)"></span>
                    Halvin / kallein tunti
                </span>
            </div>

            <div class="vk-lr-chart" style="height: 300px;">
                <canvas
                    id="volatilityChart"
                    data-chart="{{ json_encode($chartData) }}"
                    role="img"
                    aria-label="Pörssisähkön tuntihintojen viikoittainen vaihtelu."
                    aria-describedby="volatility-takeaway"
                ></canvas>
            </div>
        </div>

        <details class="vk-lr-details">
            <summary>Näytä tiedot taulukkona</summary>
            <div class="vk-lr-table-wrap">
                <table class="vk-lr-table">
                    <caption class="sr-only">Pörssisähkön tuntihintojen viikoittainen vaihtelu.</caption>
                    <thead>
                        <tr>
                            <th scope="col">Viikko</th>
                            <th scope="col">Alin (c/kWh)</th>
                            <th scope="col">20 % alitti (c/kWh)</th>
                            <th scope="col">Mediaani (c/kWh)</th>
                            <th scope="col">80 % alitti (c/kWh)</th>
                            <th scope="col">Ylin (c/kWh)</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($chartData['labels'] as $index => $label)
                            <tr>
                                <th scope="row">{{ $label }}</th>
                                <td>{{ $fmt($chartData['min'][$index]) }}</td>
                                <td>{{ $fmt($chartData['p20'][$index]) }}</td>
                                <td>{{ $fmt($chartData['median'][$index]) }}</td>
                                <td>{{ $fmt($chartData['p80'][$index]) }}</td>
                                <td>{{ $fmt($chartData['max'][$index]) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </details>

        <p class="vk-lr-text vk-lr-text--small">
            Mitä leveämpi värillinen alue on, sitä enemmän ajoituksella voi olla merkitystä. Yksittäiset hintapiikit voivat olla rajuja, mutta ne osuvat usein vain pieneen osaan vuorokaudesta.
        </p>
    @endif

    @push('scripts')
        <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
        <script>
            function initVolatilityChart() {
                const ctx = document.getElementById('volatilityChart');
                if (!ctx) return;

                const dataAttr = ctx.getAttribute('data-chart');
                if (!dataAttr) return;

                if (ctx.chartInstance) {
                    ctx.chartInstance.destroy();
                }

                // Design tokens (resources/css/tokens.css): the canvas cannot read CSS variables itself.
                const token = (name) => getComputedStyle(document.documentElement).getPropertyValue(name).trim();
                const ink = token('--color-ink');
                const spot = token('--color-cat-spot');
                const tickFont = { family: 'Figtree, system-ui, sans-serif', size: 12, weight: '700' };
                // The p20–p80 band: the spot colour at 30 % (Paper legend swatch opacity .3).
                const alpha = (hex, a) => {
                    const m = /^#?([0-9a-f]{2})([0-9a-f]{2})([0-9a-f]{2})$/i.exec(hex);
                    return m ? `rgba(${parseInt(m[1], 16)}, ${parseInt(m[2], 16)}, ${parseInt(m[3], 16)}, ${a})` : hex;
                };
                const band = alpha(spot, 0.3);

                try {
                    const chartData = JSON.parse(dataAttr);

                    ctx.chartInstance = new Chart(ctx, {
                        type: 'line',
                        data: {
                            labels: chartData.labels,
                            datasets: [
                                {
                                    label: 'Halvin tunti',
                                    data: chartData.min,
                                    type: 'scatter',
                                    showLine: false,
                                    pointBackgroundColor: token('--color-lake'),
                                    pointBorderColor: token('--color-lake'),
                                    pointRadius: 2.2,
                                    pointHoverRadius: 4,
                                    order: 0,
                                },
                                {
                                    label: 'Kallein tunti',
                                    data: chartData.max,
                                    type: 'scatter',
                                    showLine: false,
                                    pointBackgroundColor: token('--color-price-high'),
                                    pointBorderColor: token('--color-price-high'),
                                    pointRadius: 2.2,
                                    pointHoverRadius: 4,
                                    order: 0,
                                },
                                {
                                    label: 'p80',
                                    data: chartData.p80,
                                    borderColor: 'transparent',
                                    backgroundColor: band,
                                    fill: '+1',
                                    pointRadius: 0,
                                    tension: 0.35,
                                    order: 2,
                                },
                                {
                                    label: 'p20',
                                    data: chartData.p20,
                                    borderColor: 'transparent',
                                    backgroundColor: band,
                                    fill: false,
                                    pointRadius: 0,
                                    tension: 0.35,
                                    order: 2,
                                },
                                {
                                    label: 'Mediaani',
                                    data: chartData.median,
                                    borderColor: spot,
                                    backgroundColor: spot,
                                    borderWidth: 2,
                                    pointRadius: 0,
                                    pointHoverRadius: 4,
                                    tension: 0.35,
                                    fill: false,
                                    order: 1,
                                },
                            ],
                        },
                        options: {
                            responsive: true,
                            maintainAspectRatio: false,
                            interaction: { intersect: false, mode: 'index' },
                            plugins: {
                                legend: { display: false },
                                tooltip: {
                                    backgroundColor: ink,
                                    titleFont: { family: 'Figtree, system-ui, sans-serif', size: 13, weight: 'bold' },
                                    bodyFont: { family: 'Figtree, system-ui, sans-serif', size: 12 },
                                    padding: 10,
                                    cornerRadius: 4,
                                    filter: function(item) {
                                        // Show all but skip the duplicate band-fill helper labels
                                        return item.dataset.label !== 'p80' && item.dataset.label !== 'p20';
                                    },
                                    callbacks: {
                                        label: function(context) {
                                            const v = context.parsed.y;
                                            if (v === null || v === undefined) return null;
                                            return context.dataset.label + ': ' + v.toLocaleString('fi-FI', { minimumFractionDigits: 2, maximumFractionDigits: 2 }) + ' c/kWh';
                                        },
                                        afterBody: function(items) {
                                            if (!items.length) return '';
                                            const idx = items[0].dataIndex;
                                            const lo = chartData.p20[idx];
                                            const hi = chartData.p80[idx];
                                            if (lo === null || hi === null) return '';
                                            const fmt = (n) => n.toLocaleString('fi-FI', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
                                            return 'Tavallinen vaihteluväli: ' + fmt(lo) + '–' + fmt(hi) + ' c/kWh';
                                        },
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
                                        maxTicksLimit: 8,
                                    }
                                },
                                y: {
                                    border: { display: false, dash: [3, 4] },
                                    ticks: {
                                        callback: function(value) { return value + ' c'; },
                                        color: token('--color-ink-muted'),
                                        font: tickFont,
                                    },
                                    grid: { color: token('--color-edge') }
                                }
                            }
                        }
                    });
                } catch (e) {
                    console.error('Volatility chart init error:', e);
                }
            }

            document.addEventListener('DOMContentLoaded', initVolatilityChart);
            document.addEventListener('livewire:navigated', initVolatilityChart);
        </script>
    @endpush
</section>
