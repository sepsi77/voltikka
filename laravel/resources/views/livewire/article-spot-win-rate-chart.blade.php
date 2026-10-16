@php
    $hasData = !empty($chartData['labels']);
    $segmentRates = $chartData['segmentRates'] ?? [];
    $segmentMeta = $chartData['segmentMeta'] ?? [];
    // Series colours as design tokens (design spec §5 "Series colours"): fixed terms in the
    // fixed-price category colour and the field edge, open-ended as a dashed ink-muted line.
    // The chart script resolves the variables; the legend uses them directly.
    $segmentColors = [
        'fixed_term_12' => '--color-cat-fixed',
        'fixed_term_24' => '--color-field-edge',
        'open_ended' => '--color-ink-muted',
    ];
    $segmentDash = [
        'fixed_term_12' => '',
        'fixed_term_24' => '',
        'open_ended' => '6,4',
    ];
    $fmt = fn ($v) => $v === null ? '–' : number_format($v, 1, ',', ' ');
@endphp

<section class="vk-lr-figure" aria-labelledby="winrate-heading">
    <div class="vk-lr-figure-head">
        <p class="vk-lr-figure-kicker">Halvimpien sopimusten vertailu</p>
        <x-site.section-heading id="winrate-heading" wave="300">Kuinka usein pörssisähkö on ollut edullisempi?</x-site.section-heading>
    </div>
    <p class="vk-lr-text">
        Tässä ei verrata kaikkien sopimusten keskiarvoa. Jokaiselta päivältä {{ $chartData['from'] ?? '' }}–{{ $chartData['to'] ?? '' }}
        valitaan kunkin sopimustyypin edullinen taso: hinta, jonka alle 20&nbsp;% kyseisen päivän tarjouksista jäi.
        Jos pörssisähkön viiva on alempana, halvimmat pörssisopimukset ovat olleet halvempia kuin halvimmat vertailuryhmän sopimukset samana päivänä.
    </p>

    @if (!$hasData)
        <p class="vk-lr-empty">
            Vertailutietoa ei ole vielä saatavilla.
        </p>
    @else
        <p id="win-rate-takeaway" class="vk-lr-text">
            <strong>Pörssisähkön halvemmat vertailupäivät:</strong>
            @foreach ($segmentRates as $rate)
                {{ $rate['label'] }} {{ $fmt($rate['spot_win_pct']) }} %{{ $loop->last ? '.' : ',' }}
            @endforeach
        </p>

        {{-- Headline figures: pörssisähkön voitto-osuus jokaista vertailutyyppiä vastaan --}}
        <dl class="vk-lr-stats">
            @foreach ($segmentRates as $key => $rate)
                <div class="vk-lr-stat">
                    <dt>Halvempi kuin {{ $rate['label'] }}</dt>
                    <dd class="tabular-nums">
                        {{ $fmt($rate['spot_win_pct']) }}<span class="vk-lr-stat-unit">&nbsp;%</span>
                    </dd>
                    <p class="vk-lr-stat-sub">
                        @if ($rate['spot_wins'] !== null)
                            {{ $rate['spot_wins'] }} / {{ $rate['overlap_days'] }} päivää
                        @else
                            Ei aineistoa
                        @endif
                    </p>
                </div>
            @endforeach
            <div class="vk-lr-stat">
                <dt>Aineisto</dt>
                <dd class="tabular-nums">
                    {{ $chartData['total'] }}<span class="vk-lr-stat-unit">&nbsp;päivää</span>
                </dd>
            </div>
        </dl>

        <div class="flex flex-col gap-3">
            <div class="vk-lr-legend">
                <span class="vk-lr-legend-item">
                    <span class="vk-lr-swatch vk-lr-swatch--line" style="--lr-swatch: var(--color-cat-spot)"></span>
                    Pörssisähkö
                </span>
                @foreach ($segmentMeta as $key => $label)
                    <span class="vk-lr-legend-item">
                        <span class="vk-lr-swatch {{ ($segmentDash[$key] ?? '') !== '' ? 'vk-lr-swatch--dash' : 'vk-lr-swatch--line' }}" style="--lr-swatch: var({{ $segmentColors[$key] ?? '--color-ink-muted' }})"></span>
                        {{ $label }}
                    </span>
                @endforeach
                <span class="vk-lr-legend-unit">€/v, edullinen taso (p20)</span>
            </div>

            <div class="vk-lr-chart" style="height: 320px;">
                <canvas id="winRateChart"
                        data-chart="{{ json_encode($chartData) }}"
                        data-segment-colors="{{ json_encode($segmentColors) }}"
                        data-segment-dash="{{ json_encode($segmentDash) }}"
                        role="img"
                        aria-label="Pörssisähkön ja muiden sopimustyyppien edullisen hintatason päiväkohtainen vertailu."
                        aria-describedby="win-rate-takeaway"></canvas>
            </div>
        </div>

        <details class="vk-lr-details">
            <summary>Näytä tiedot taulukkona</summary>
            <div class="vk-lr-table-wrap">
                <table class="vk-lr-table">
                    <caption class="sr-only">Pörssisähkön ja muiden sopimustyyppien edullisen hintatason päiväkohtainen vertailu.</caption>
                    <thead>
                        <tr>
                            <th scope="col">Päivä</th>
                            <th scope="col">Pörssisähkö (€/v)</th>
                            @foreach ($segmentMeta as $label)
                                <th scope="col">{{ $label }} (€/v)</th>
                            @endforeach
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($chartData['labels'] as $index => $label)
                            <tr>
                                <th scope="row">{{ $label }}</th>
                                <td>{{ $chartData['spot'][$index] === null ? '–' : number_format($chartData['spot'][$index], 0, ',', ' ') }}</td>
                                @foreach ($segmentMeta as $key => $segmentLabel)
                                    <td>{{ ($chartData['series'][$key][$index] ?? null) === null ? '–' : number_format($chartData['series'][$key][$index], 0, ',', ' ') }}</td>
                                @endforeach
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </details>

        <p class="vk-lr-text vk-lr-text--small">
            Luvut eivät ota huomioon omaa kulutuksen ajoitustasi. Pörssisähkön hyöty voi kasvaa, jos pystyt käyttämään sähköä enemmän edullisina yö- ja viikonlopputunteina.
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

                // Design tokens (resources/css/tokens.css): the canvas cannot read CSS variables itself.
                const token = (name) => getComputedStyle(document.documentElement).getPropertyValue(name).trim();
                const ink = token('--color-ink');
                const tickFont = { family: 'Figtree, system-ui, sans-serif', size: 12, weight: '700' };

                try {
                    const chartData = JSON.parse(dataAttr);
                    const colors = JSON.parse(ctx.getAttribute('data-segment-colors') || '{}');
                    const dashes = JSON.parse(ctx.getAttribute('data-segment-dash') || '{}');

                    const datasets = [
                        {
                            label: 'Pörssisähkö',
                            data: chartData.spot,
                            borderColor: token('--color-cat-spot'),
                            backgroundColor: token('--color-cat-spot'),
                            borderWidth: 3,
                            pointRadius: 0,
                            pointHoverRadius: 5,
                            fill: false,
                            tension: 0.3,
                        },
                    ];

                    Object.entries(chartData.segmentMeta || {}).forEach(([key, label]) => {
                        const dashStr = dashes[key] || '';
                        const color = token(colors[key] || '--color-ink-muted');
                        datasets.push({
                            label,
                            data: (chartData.series && chartData.series[key]) || [],
                            borderColor: color,
                            backgroundColor: color,
                            borderWidth: 2.2,
                            borderDash: dashStr ? dashStr.split(',').map(Number) : [],
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
                                    backgroundColor: ink,
                                    titleFont: { family: 'Figtree, system-ui, sans-serif', size: 13, weight: 'bold' },
                                    bodyFont: { family: 'Figtree, system-ui, sans-serif', size: 12 },
                                    padding: 10,
                                    cornerRadius: 4,
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
                                    border: { color: ink, width: 1.8 },
                                    ticks: {
                                        color: token('--color-ink-muted'),
                                        font: tickFont,
                                        maxTicksLimit: 12
                                    }
                                },
                                y: {
                                    border: { display: false, dash: [3, 4] },
                                    ticks: {
                                        callback: function(value) {
                                            return value + ' €';
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
                    console.error('Win rate chart init error:', e);
                }
            }

            document.addEventListener('DOMContentLoaded', initWinRateChart);
            document.addEventListener('livewire:navigated', initWinRateChart);
        </script>
    @endpush
</section>
