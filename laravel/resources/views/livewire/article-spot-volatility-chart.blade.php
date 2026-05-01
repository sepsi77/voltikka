@php
    $hasData = !empty($chartData['labels']);
    $fmt = fn ($v, $decimals = 2) => $v === null
        ? '–'
        : number_format($v, $decimals, ',', ' ');
@endphp

<section class="not-prose my-10" aria-labelledby="volatility-heading">
    <p class="text-xs font-semibold uppercase tracking-[0.16em] text-coral-700">Pörssisähkön hintavaihtelu</p>
    <h2 id="volatility-heading" class="mt-2 text-2xl font-bold tracking-tight text-slate-900">
        Kuinka paljon tuntihinta voi vaihdella?
    </h2>
    <p class="mt-2 max-w-prose text-base leading-7 text-slate-600">
        Edellä vertailtiin eri sopimusten hintoja. Tässä katsotaan pörssisähkön tärkeintä riskiä: saman vuorokauden tuntihinnat voivat poiketa paljon toisistaan.
        Värillinen alue näyttää tavallisen vaihteluvälin, ohut viiva viikon mediaanin ja pisteet viikon halvimman sekä kalleimman tunnin.
    </p>

    @if (!$hasData)
        <div class="mt-6 rounded-xl border border-dashed border-slate-200 bg-slate-50 p-8 text-center text-sm text-slate-600">
            Volatiliteettitietoja ei ole vielä saatavilla.
        </div>
    @else
        {{-- Headline figures --}}
        <dl class="mt-6 grid grid-cols-2 lg:grid-cols-5 gap-x-6 gap-y-6 border-t border-slate-200 pt-6">
            <div>
                <dt class="text-[11px] font-semibold tracking-[0.16em] uppercase text-slate-500">Halvin tunti</dt>
                <dd class="mt-1 text-2xl font-extrabold tabular-nums text-slate-900">
                    {{ $fmt($metrics['min']) }}<span class="text-sm font-medium text-slate-500"> c/kWh</span>
                </dd>
            </div>
            <div>
                <dt class="text-[11px] font-semibold tracking-[0.16em] uppercase text-slate-500">Kallein tunti</dt>
                <dd class="mt-1 text-2xl font-extrabold tabular-nums text-slate-900">
                    {{ $fmt($metrics['max']) }}<span class="text-sm font-medium text-slate-500"> c/kWh</span>
                </dd>
            </div>
            <div>
                <dt class="text-[11px] font-semibold tracking-[0.16em] uppercase text-slate-500">Keskihinta</dt>
                <dd class="mt-1 text-2xl font-extrabold tabular-nums text-slate-900">
                    {{ $fmt($metrics['avg']) }}<span class="text-sm font-medium text-slate-500"> c/kWh</span>
                </dd>
            </div>
            <div>
                <dt class="text-[11px] font-semibold tracking-[0.16em] uppercase text-slate-500">Kalliita päiviä</dt>
                <dd class="mt-1 text-2xl font-extrabold tabular-nums text-slate-900">
                    {{ $metrics['spikeDays'] }}<span class="text-sm font-medium text-slate-500"> päivää</span>
                </dd>
                <p class="mt-1 text-[11px] text-slate-500">Yli 20 c/kWh</p>
            </div>
            <div>
                <dt class="text-[11px] font-semibold tracking-[0.16em] uppercase text-slate-500">Miinushintaisia päiviä</dt>
                <dd class="mt-1 text-2xl font-extrabold tabular-nums text-slate-900">
                    {{ $metrics['negativeDays'] }}<span class="text-sm font-medium text-slate-500"> päivää</span>
                </dd>
                <p class="mt-1 text-[11px] text-slate-500">Alle 0 c/kWh</p>
            </div>
        </dl>

        <div class="relative mt-8">
            <div class="flex flex-wrap items-center gap-x-5 gap-y-2 mb-4">
                <span class="flex items-center gap-2 text-sm text-slate-600">
                    <span class="h-3 w-3 rounded-sm" style="background:rgba(249,115,22,0.18);border:1px solid rgba(249,115,22,0.4)"></span>
                    Tavallinen vaihteluväli (p20–p80)
                </span>
                <span class="flex items-center gap-2 text-sm text-slate-600">
                    <span class="h-0.5 w-4 bg-coral-500"></span>
                    Viikon mediaani
                </span>
                <span class="flex items-center gap-2 text-sm text-slate-600">
                    <span class="h-1.5 w-1.5 rounded-full bg-slate-400"></span>
                    Halvin / kallein tunti
                </span>
            </div>

            <div class="relative" style="height: 300px;">
                <canvas id="volatilityChart" data-chart="{{ json_encode($chartData) }}"></canvas>
            </div>
        </div>

        <p class="mt-5 max-w-prose text-base leading-7 text-slate-600">
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
                                    pointBackgroundColor: '#94a3b8',
                                    pointBorderColor: '#94a3b8',
                                    pointRadius: 2,
                                    pointHoverRadius: 4,
                                    order: 0,
                                },
                                {
                                    label: 'Kallein tunti',
                                    data: chartData.max,
                                    type: 'scatter',
                                    showLine: false,
                                    pointBackgroundColor: '#94a3b8',
                                    pointBorderColor: '#94a3b8',
                                    pointRadius: 2,
                                    pointHoverRadius: 4,
                                    order: 0,
                                },
                                {
                                    label: 'p80',
                                    data: chartData.p80,
                                    borderColor: 'transparent',
                                    backgroundColor: 'rgba(249,115,22,0.18)',
                                    fill: '+1',
                                    pointRadius: 0,
                                    tension: 0.35,
                                    order: 2,
                                },
                                {
                                    label: 'p20',
                                    data: chartData.p20,
                                    borderColor: 'transparent',
                                    backgroundColor: 'rgba(249,115,22,0.18)',
                                    fill: false,
                                    pointRadius: 0,
                                    tension: 0.35,
                                    order: 2,
                                },
                                {
                                    label: 'Mediaani',
                                    data: chartData.median,
                                    borderColor: '#f97316',
                                    backgroundColor: '#f97316',
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
                                    backgroundColor: '#0f172a',
                                    titleFont: { size: 13, weight: 'bold' },
                                    bodyFont: { size: 12 },
                                    padding: 10,
                                    cornerRadius: 8,
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
                                    ticks: {
                                        font: { size: 11 },
                                        autoSkip: true,
                                        maxRotation: 0,
                                        maxTicksLimit: 8,
                                    }
                                },
                                y: {
                                    ticks: {
                                        callback: function(value) { return value + ' c'; },
                                        font: { size: 11 }
                                    },
                                    grid: { color: '#f1f5f9' }
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
