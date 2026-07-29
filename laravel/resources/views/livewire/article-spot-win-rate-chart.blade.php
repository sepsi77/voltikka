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
    <p class="text-xs font-semibold uppercase tracking-[0.16em] text-coral-700">Halvimpien sopimusten vertailu</p>
    <h2 id="winrate-heading" class="mt-2 text-2xl font-bold tracking-tight text-slate-900">
        Kuinka usein pörssisähkö on ollut edullisempi?
    </h2>
    <p class="mt-2 max-w-prose text-base leading-7 text-slate-600">
        Tässä ei verrata kaikkien sopimusten keskiarvoa. Jokaiselta päivältä {{ $chartData['from'] ?? '' }}–{{ $chartData['to'] ?? '' }}
        valitaan kunkin sopimustyypin edullinen taso: hinta, jonka alle 20&nbsp;% kyseisen päivän tarjouksista jäi.
        Jos pörssisähkön viiva on alempana, halvimmat pörssisopimukset ovat olleet halvempia kuin halvimmat vertailuryhmän sopimukset samana päivänä.
    </p>

    @if (!$hasData)
        <div class="mt-6 rounded-xl border border-dashed border-slate-200 bg-slate-50 p-8 text-center text-sm text-slate-600">
            Vertailutietoa ei ole vielä saatavilla.
        </div>
    @else
        <p id="win-rate-takeaway" class="mt-6 max-w-prose text-base leading-7 text-slate-700">
            <strong>Pörssisähkön halvemmat vertailupäivät:</strong>
            @foreach ($segmentRates as $rate)
                {{ $rate['label'] }} {{ $fmt($rate['spot_win_pct']) }} %{{ $loop->last ? '.' : ',' }}
            @endforeach
        </p>

        {{-- Headline figures: pörssisähkön voitto-osuus jokaista vertailutyyppiä vastaan --}}
        <dl class="mt-6 grid grid-cols-2 sm:grid-cols-4 gap-x-6 gap-y-6 border-t border-slate-200 pt-6">
            @foreach ($segmentRates as $key => $rate)
                <div>
                    <dt class="text-[11px] font-semibold tracking-[0.16em] uppercase text-slate-500">Halvempi kuin {{ $rate['label'] }}</dt>
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
                        data-segment-dash="{{ json_encode($segmentDash) }}"
                        role="img"
                        aria-label="Pörssisähkön ja muiden sopimustyyppien edullisen hintatason päiväkohtainen vertailu."
                        aria-describedby="win-rate-takeaway"></canvas>
            </div>
        </div>

        <details class="mt-6 border-t border-slate-200 pt-4">
            <summary class="cursor-pointer font-semibold text-slate-700 underline decoration-slate-300 underline-offset-4 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-coral-500">
                Näytä tiedot taulukkona
            </summary>
            <div class="mt-4 overflow-x-auto">
                <table class="min-w-full border-collapse text-left text-sm text-slate-700 tabular-nums">
                    <caption class="sr-only">Pörssisähkön ja muiden sopimustyyppien edullisen hintatason päiväkohtainen vertailu.</caption>
                    <thead>
                        <tr class="border-b border-slate-200">
                            <th scope="col" class="whitespace-nowrap px-3 py-2 font-semibold text-slate-900">Päivä</th>
                            <th scope="col" class="whitespace-nowrap px-3 py-2 font-semibold text-slate-900">Pörssisähkö (€/v)</th>
                            @foreach ($segmentMeta as $label)
                                <th scope="col" class="whitespace-nowrap px-3 py-2 font-semibold text-slate-900">{{ $label }} (€/v)</th>
                            @endforeach
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($chartData['labels'] as $index => $label)
                            <tr class="border-b border-slate-100">
                                <th scope="row" class="whitespace-nowrap px-3 py-2 font-medium text-slate-900">{{ $label }}</th>
                                <td class="whitespace-nowrap px-3 py-2">{{ $chartData['spot'][$index] === null ? '–' : number_format($chartData['spot'][$index], 0, ',', ' ') }}</td>
                                @foreach ($segmentMeta as $key => $segmentLabel)
                                    <td class="whitespace-nowrap px-3 py-2">{{ ($chartData['series'][$key][$index] ?? null) === null ? '–' : number_format($chartData['series'][$key][$index], 0, ',', ' ') }}</td>
                                @endforeach
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </details>

        <p class="mt-5 max-w-prose text-base leading-7 text-slate-600">
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
