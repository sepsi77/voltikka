// Bar charts of the monthly market review (/markkinakatsaus). Chart.js comes from the same CDN
// script as the article pages (window.Chart). The line chart (price index) uses the shared uPlot
// renderer in contract-price-statistics.js instead.
//
// Each chart is a <canvas data-review-chart="bill-outlook|daily-drivers"> inside a container with
// a <script type="application/json"> payload (frozen: resources/market-reviews/<month>/kuva-*.json).
// Colours come from the design tokens at runtime, because a canvas cannot read CSS variables.

const token = (name) => getComputedStyle(document.documentElement).getPropertyValue(name).trim();
const fi = (value, decimals = 0) => Number(value).toLocaleString('fi-FI', {
    minimumFractionDigits: decimals,
    maximumFractionDigits: decimals,
});
const MONTHS = ['tammikuu', 'helmikuu', 'maaliskuu', 'huhtikuu', 'toukokuu', 'kesäkuu', 'heinäkuu', 'elokuu', 'syyskuu', 'lokakuu', 'marraskuu', 'joulukuu'];
const monthTitle = (month) => `${MONTHS[Number(month.slice(5, 7)) - 1]} ${month.slice(0, 4)}`;
const signed = (value, decimals = 1) => `${value > 0 ? '+' : value < 0 ? '−' : ''}${fi(Math.abs(value), decimals)}`;

function palette() {
    return {
        ink: token('--color-ink'),
        muted: token('--color-ink-muted'),
        edge: token('--color-edge'),
        ground: token('--color-ground'),
        spot: token('--color-cat-spot'),
        fixed: token('--color-cat-fixed'),
        warm: token('--color-fox'),
        cold: token('--color-lake'),
    };
}

function baseOptions(pal) {
    const tick = { color: pal.muted, font: { family: 'Figtree, system-ui, sans-serif', size: 12, weight: '600' } };
    return {
        tick,
        options: {
            responsive: true,
            maintainAspectRatio: false,
            animation: false,
            interaction: { mode: 'index', intersect: false },
            plugins: {
                legend: { display: false },
                tooltip: {
                    backgroundColor: pal.ink,
                    cornerRadius: 4,
                    padding: 10,
                    titleFont: { family: 'Figtree, system-ui, sans-serif', size: 13, weight: '800' },
                    bodyFont: { family: 'Figtree, system-ui, sans-serif', size: 13 },
                    boxPadding: 4,
                },
            },
        },
    };
}

// Three-winter range of the spot bill: a thin ink whisker over each spot bar.
const whiskerPlugin = {
    id: 'reviewWhisker',
    afterDatasetsDraw(chart, _args, options) {
        const meta = chart.getDatasetMeta(0);
        const { low, high, color } = options;
        if (!low || !high || meta.hidden) return;
        const y = chart.scales.y;
        const ctx = chart.ctx;
        ctx.save();
        ctx.strokeStyle = color;
        ctx.lineWidth = 1.5;
        meta.data.forEach((bar, i) => {
            if (low[i] == null || high[i] == null) return;
            const x = bar.x;
            const top = y.getPixelForValue(high[i]);
            const bottom = y.getPixelForValue(low[i]);
            ctx.beginPath();
            ctx.moveTo(x, top);
            ctx.lineTo(x, bottom);
            ctx.moveTo(x - 3, top);
            ctx.lineTo(x + 3, top);
            ctx.moveTo(x - 3, bottom);
            ctx.lineTo(x + 3, bottom);
            ctx.stroke();
        });
        ctx.restore();
    },
};

function billOutlook(canvas, payload) {
    const home = payload.homes.find((h) => h.key === canvas.dataset.home);
    if (!home) return null;
    const pal = palette();
    const { tick, options } = baseOptions(pal);
    return new window.Chart(canvas, {
        type: 'bar',
        data: {
            labels: payload.labels,
            datasets: [
                { label: 'Pörssisähkö', data: home.spot, backgroundColor: pal.spot, borderRadius: 3, categoryPercentage: 0.72, barPercentage: 0.9 },
                { label: '12 kk kiinteä hinta', data: home.fixed, backgroundColor: pal.fixed, borderRadius: 3, categoryPercentage: 0.72, barPercentage: 0.9 },
            ],
        },
        plugins: [whiskerPlugin],
        options: {
            ...options,
            plugins: {
                ...options.plugins,
                reviewWhisker: { low: home.spot_low, high: home.spot_high, color: pal.ink },
                tooltip: {
                    ...options.plugins.tooltip,
                    callbacks: {
                        title: (items) => (items.length ? monthTitle(payload.months[items[0].dataIndex]) : ''),
                        label: (context) => {
                            const value = `${context.dataset.label}: ${fi(context.parsed.y)} €`;
                            if (context.datasetIndex !== 0) return ` ${value}`;
                            const i = context.dataIndex;
                            return ` ${value} (säävaihtelu ${fi(home.spot_low[i])}–${fi(home.spot_high[i])} €)`;
                        },
                    },
                },
            },
            scales: {
                x: { grid: { display: false }, border: { color: pal.ink, width: 1.8 }, ticks: { ...tick, maxRotation: 0, autoSkip: false } },
                y: {
                    beginAtZero: true,
                    grid: { color: pal.edge },
                    border: { display: false },
                    ticks: { ...tick, callback: (value) => `${fi(value)} €` },
                },
            },
        },
    });
}

function dailyDrivers(canvas, payload) {
    const pal = palette();
    const { tick, options } = baseOptions(pal);
    const panel = (title, weight) => ({
        type: 'linear',
        position: 'left',
        stack: 'drivers',
        stackWeight: weight,
        offset: true,
        grid: { color: pal.edge },
        border: { display: false },
        title: { display: true, text: title, color: pal.ink, font: { family: 'Figtree, system-ui, sans-serif', size: 12, weight: '800' } },
        ticks: { ...tick, maxTicksLimit: weight > 1 ? 5 : 3 },
    });
    return new window.Chart(canvas, {
        data: {
            labels: payload.labels,
            datasets: [
                { type: 'bar', label: 'Pörssisähkö', data: payload.price, backgroundColor: pal.spot, borderRadius: 2, yAxisID: 'price', order: 2, grouped: false },
                {
                    type: 'line',
                    label: 'Kuukauden keskihinta',
                    data: payload.labels.map(() => payload.price_mean),
                    borderColor: pal.ink,
                    borderWidth: 1.5,
                    borderDash: [5, 4],
                    pointRadius: 0,
                    pointHoverRadius: 0,
                    yAxisID: 'price',
                    order: 1,
                },
                { type: 'bar', label: 'Tuulivoima', data: payload.wind, backgroundColor: pal.fixed, borderRadius: 2, yAxisID: 'wind', order: 2, grouped: false },
                {
                    type: 'bar',
                    label: 'Lämpötila',
                    data: payload.temperature_anomaly,
                    backgroundColor: payload.temperature_anomaly.map((v) => (v != null && v < 0 ? pal.cold : pal.warm)),
                    borderRadius: 2,
                    grouped: false,
                    yAxisID: 'temp',
                    order: 2,
                },
            ],
        },
        options: {
            ...options,
            plugins: {
                ...options.plugins,
                tooltip: {
                    ...options.plugins.tooltip,
                    filter: (item) => item.datasetIndex !== 1,
                    callbacks: {
                        title: (items) => (items.length ? payload.labels[items[0].dataIndex] : ''),
                        label: (context) => {
                            const v = context.parsed.y;
                            if (v == null) return ` ${context.dataset.label}: ei havaintoa`;
                            if (context.datasetIndex === 0) return ` Pörssisähkö: ${fi(v, 2)} c/kWh`;
                            if (context.datasetIndex === 2) return ` Tuulivoima: ${fi(v, 1)} GWh`;
                            return ` Lämpötila: ${signed(v)} °C normaalista`;
                        },
                    },
                },
            },
            scales: {
                x: { grid: { display: false }, border: { color: pal.ink, width: 1.8 }, ticks: { ...tick, maxRotation: 0, autoSkip: true, maxTicksLimit: 8 } },
                temp: panel('°C', 1),
                wind: { ...panel('GWh', 1), beginAtZero: true },
                price: { ...panel('c/kWh', 2.4), beginAtZero: true },
            },
        },
    });
}

const builders = { 'bill-outlook': billOutlook, 'daily-drivers': dailyDrivers };

function readPayload(canvas) {
    const script = canvas.closest('[data-review-chart-data]')?.querySelector('script[type="application/json"]');
    if (!script) return null;
    try {
        return JSON.parse(script.textContent);
    } catch {
        return null;
    }
}

function init() {
    if (!window.Chart) return;
    document.querySelectorAll('canvas[data-review-chart]').forEach((canvas) => {
        const build = builders[canvas.dataset.reviewChart];
        const payload = readPayload(canvas);
        if (!build || !payload) return;
        if (canvas.chartInstance) canvas.chartInstance.destroy();
        canvas.chartInstance = build(canvas, payload);
    });
}

document.addEventListener('DOMContentLoaded', init);
document.addEventListener('livewire:navigated', init);
