import uPlot from 'uplot';
import 'uplot/dist/uPlot.min.css';

const SLATE_900 = '#0f172a';
const SLATE_800 = '#1e293b';
const SLATE_700 = '#334155';
const SLATE_500 = '#64748b';
const SLATE_400 = '#94a3b8';
const SLATE_300 = '#cbd5e1';
const SLATE_200 = '#e2e8f0';
const CORAL_500 = '#f97316';

// Style ramp for non-lead chart series: each tuple is [stroke, dash, width].
// We vary BOTH color (slate-800 / slate-500) and dash pattern so lines are
// distinguishable in print, in screenshots, and for users with low color contrast.
const NON_LEAD_STYLES = [
    { stroke: SLATE_800, dash: [],            width: 1.8 }, // 1st non-lead: dark, solid
    { stroke: SLATE_500, dash: [10, 4],       width: 1.8 }, // 2nd non-lead: long dashes
    { stroke: SLATE_700, dash: [4, 3],        width: 2.0 }, // 3rd non-lead: dense small dashes (formerly hard-to-see dots)
    { stroke: SLATE_500, dash: [6, 3, 2, 3],  width: 1.8 }, // 4th: dash-dot
];

const FI_MONTHS_SHORT = ['tam', 'hel', 'maa', 'huh', 'tou', 'kes', 'hei', 'elo', 'syy', 'lok', 'mar', 'jou'];

function formatFinnishDate(ts) {
    const d = new Date(ts * 1000);
    return `${d.getDate()}.${FI_MONTHS_SHORT[d.getMonth()]}`;
}

function formatFinnishDateLong(ts) {
    const d = new Date(ts * 1000);
    return `${d.getDate()}.${d.getMonth() + 1}.${d.getFullYear()}`;
}

function formatNumber(value, decimals) {
    if (value === null || value === undefined || Number.isNaN(value)) {
        return '–';
    }
    return new Intl.NumberFormat('fi-FI', {
        minimumFractionDigits: decimals,
        maximumFractionDigits: decimals,
    }).format(value);
}

function buildOptions(payload, root) {
    const { unit, decimals, series } = payload;
    const unitLabel = unit === 'eur' ? '€/v' : 'c/kWh';

    const splinePath = uPlot.paths.spline();
    const uplotSeries = [
        {},
        ...series.map((s, idx) => {
            if (idx === 0) {
                return {
                    label: s.label,
                    stroke: CORAL_500,
                    width: 2.5,
                    points: { show: false },
                    paths: splinePath,
                };
            }
            const style = NON_LEAD_STYLES[(idx - 1) % NON_LEAD_STYLES.length];
            return {
                label: s.label,
                stroke: style.stroke,
                width: style.width,
                dash: style.dash.length ? style.dash : undefined,
                points: { show: false },
                paths: splinePath,
            };
        }),
    ];

    return {
        width: root.clientWidth,
        height: root.clientHeight || 320,
        padding: [16, 16, 24, 8],
        cursor: {
            drag: { setScale: false },
            points: { size: 6, fill: (_u, sIdx) => uplotSeries[sIdx].stroke },
        },
        legend: { show: false },
        scales: {
            x: { time: true },
            y: {
                range: (_u, dataMin, dataMax) => {
                    if (dataMin === null || dataMax === null) return [0, 1];
                    const pad = (dataMax - dataMin) * 0.12 || dataMax * 0.05 || 1;
                    return [Math.max(0, dataMin - pad), dataMax + pad];
                },
            },
        },
        axes: [
            {
                stroke: SLATE_500,
                grid: { stroke: SLATE_200, width: 1 },
                ticks: { stroke: SLATE_300, width: 1, size: 4 },
                font: '12px "Plus Jakarta Sans", system-ui, sans-serif',
                values: (_u, splits) => splits.map((ts) => formatFinnishDate(ts)),
            },
            {
                stroke: SLATE_500,
                grid: { stroke: SLATE_200, width: 1 },
                ticks: { stroke: SLATE_300, width: 1, size: 4 },
                font: '12px "Plus Jakarta Sans", system-ui, sans-serif',
                size: 48,
                values: (_u, splits) => splits.map((v) => formatNumber(v, decimals)),
            },
        ],
        series: uplotSeries,
        hooks: {
            ready: [
                (u) => {
                    attachEndLabels(u, payload, false);
                    attachUnitBadge(u, unitLabel);
                },
            ],
            setSize: [
                (u) => {
                    attachEndLabels(u, payload, false);
                    attachUnitBadge(u, unitLabel);
                },
            ],
            setCursor: [
                (u) => updateTooltip(u, payload),
            ],
        },
    };
}

function attachUnitBadge(u, unitLabel) {
    let badge = u.root.querySelector('[data-unit-badge]');
    if (!badge) {
        badge = document.createElement('div');
        badge.setAttribute('data-unit-badge', '');
        badge.style.position = 'absolute';
        badge.style.top = '2px';
        badge.style.left = '4px';
        badge.style.fontSize = '11px';
        badge.style.fontWeight = '600';
        badge.style.letterSpacing = '0.04em';
        badge.style.color = SLATE_400;
        badge.style.fontFamily = '"Plus Jakarta Sans", system-ui, sans-serif';
        badge.style.pointerEvents = 'none';
        u.root.appendChild(badge);
    }
    badge.textContent = unitLabel;
}

function attachEndLabels(u, payload, enabled = true) {
    const root = u.root;
    let layer = root.querySelector('[data-end-labels]');
    if (!enabled) {
        if (layer) layer.innerHTML = '';
        return;
    }
    if (!layer) {
        layer = document.createElement('div');
        layer.setAttribute('data-end-labels', '');
        layer.style.position = 'absolute';
        layer.style.inset = '0';
        layer.style.pointerEvents = 'none';
        root.appendChild(layer);
    }
    layer.innerHTML = '';

    const { series } = payload;
    const xs = u.data[0];
    const lastIdx = xs.length - 1;
    if (lastIdx < 0) return;

    const lastX = u.valToPos(xs[lastIdx], 'x');

    const labels = series.map((s, idx) => {
        const ys = u.data[idx + 1];
        const lastY = ys[lastIdx];
        if (lastY === null || lastY === undefined) return null;
        return { label: s.label, value: lastY, posY: u.valToPos(lastY, 'y') };
    }).filter(Boolean);

    labels.sort((a, b) => a.posY - b.posY);

    const minGap = 20;
    for (let i = 1; i < labels.length; i++) {
        if (labels[i].posY - labels[i - 1].posY < minGap) {
            labels[i].posY = labels[i - 1].posY + minGap;
        }
    }

    labels.forEach((entry) => {
        const i = series.findIndex((s) => s.label === entry.label);
        const el = document.createElement('div');
        el.textContent = entry.label;
        el.style.position = 'absolute';
        el.style.left = `${lastX + 6}px`;
        el.style.top = `${entry.posY - 7}px`;
        el.style.fontSize = '11px';
        el.style.fontWeight = '600';
        el.style.lineHeight = '1';
        el.style.whiteSpace = 'nowrap';
        el.style.color = i === 0
            ? CORAL_500
            : NON_LEAD_STYLES[(i - 1) % NON_LEAD_STYLES.length].stroke;
        el.style.fontFamily = '"Plus Jakarta Sans", system-ui, sans-serif';
        layer.appendChild(el);
    });
}

function ensureTooltip(root) {
    let tip = root.querySelector('[data-uplot-tooltip]');
    if (tip) return tip;
    tip = document.createElement('div');
    tip.setAttribute('data-uplot-tooltip', '');
    tip.style.position = 'absolute';
    tip.style.pointerEvents = 'none';
    tip.style.zIndex = '20';
    tip.style.padding = '10px 12px';
    tip.style.background = SLATE_900;
    tip.style.color = '#f8fafc';
    tip.style.borderRadius = '8px';
    tip.style.fontSize = '12px';
    tip.style.lineHeight = '1.4';
    tip.style.fontFamily = '"Plus Jakarta Sans", system-ui, sans-serif';
    tip.style.fontVariantNumeric = 'tabular-nums';
    tip.style.boxShadow = '0 6px 16px -4px rgba(15, 23, 42, 0.25)';
    tip.style.whiteSpace = 'nowrap';
    tip.style.opacity = '0';
    tip.style.transform = 'translate(8px, -50%)';
    tip.style.transition = 'opacity 120ms ease-out';
    root.appendChild(tip);
    return tip;
}

function updateTooltip(u, payload) {
    const tip = ensureTooltip(u.root);
    const { idx } = u.cursor;

    if (idx === null || idx === undefined) {
        tip.style.opacity = '0';
        return;
    }

    const xs = u.data[0];
    const ts = xs[idx];
    if (ts === undefined) {
        tip.style.opacity = '0';
        return;
    }

    const lines = [`<div style="color:${SLATE_400};margin-bottom:4px;font-weight:500">${formatFinnishDateLong(ts)}</div>`];

    payload.series.forEach((s, sIdx) => {
        const y = u.data[sIdx + 1][idx];
        const display = y === null || y === undefined ? '–' : `${formatNumber(y, payload.decimals)} ${payload.unit === 'eur' ? '€' : 'c/kWh'}`;
        const dot = sIdx === 0
            ? CORAL_500
            : NON_LEAD_STYLES[(sIdx - 1) % NON_LEAD_STYLES.length].stroke;
        lines.push(
            `<div style="display:flex;align-items:center;gap:6px;justify-content:space-between;gap:16px">` +
            `<span style="display:inline-flex;align-items:center;gap:6px"><span style="width:8px;height:8px;border-radius:9999px;background:${dot};display:inline-block"></span>${s.label}</span>` +
            `<span style="font-weight:700;color:#fff">${display}</span>` +
            `</div>`
        );
    });

    tip.innerHTML = lines.join('');
    const x = u.valToPos(ts, 'x');
    const left = x + u.bbox.left / window.devicePixelRatio;
    const tipWidth = tip.offsetWidth;
    const rootWidth = u.root.clientWidth;
    const placeRight = left + tipWidth + 24 < rootWidth;
    tip.style.left = `${placeRight ? left + 12 : left - tipWidth - 12}px`;
    tip.style.top = `${u.cursor.top}px`;
    tip.style.opacity = '1';
}

function mount(root, payload) {
    if (!root || !payload || !Array.isArray(payload.series) || payload.series.length === 0) return;

    const xs = payload.x || [];
    if (xs.length < 2) {
        const empty = document.createElement('div');
        empty.style.position = 'absolute';
        empty.style.inset = '0';
        empty.style.display = 'flex';
        empty.style.alignItems = 'center';
        empty.style.justifyContent = 'center';
        empty.style.color = SLATE_500;
        empty.style.fontSize = '14px';
        empty.textContent = 'Aineistoa kertyy. Käyrä piirtyy heti kun jaksoja on vähintään kaksi.';
        root.appendChild(empty);
        return;
    }

    const data = [xs, ...payload.series.map((s) => s.values)];
    const opts = buildOptions(payload, root);
    const chart = new uPlot(opts, data, root);

    const ro = new ResizeObserver((entries) => {
        for (const entry of entries) {
            chart.setSize({ width: entry.contentRect.width, height: 320 });
        }
    });
    ro.observe(root);

    root.__uPlotChart = chart;
    root.__uPlotResize = ro;
}

function unmount(root) {
    if (root.__uPlotChart) {
        root.__uPlotResize?.disconnect();
        root.__uPlotChart.destroy();
        delete root.__uPlotChart;
        delete root.__uPlotResize;
    }
    root.querySelectorAll('[data-end-labels],[data-uplot-tooltip],[data-unit-badge]').forEach((el) => el.remove());
}

function readPayload(root) {
    const script = root.querySelector('script[type="application/json"]');
    if (!script) return null;
    try {
        return JSON.parse(script.textContent);
    } catch {
        return null;
    }
}

function init() {
    document.querySelectorAll('[data-line-chart]').forEach((root) => {
        if (root.__uPlotChart) return;
        const payload = readPayload(root);
        if (payload) mount(root, payload);
    });
}

function reinit() {
    document.querySelectorAll('[data-line-chart]').forEach((root) => {
        unmount(root);
        const payload = readPayload(root);
        if (payload) mount(root, payload);
    });
}

document.addEventListener('DOMContentLoaded', init);
document.addEventListener('livewire:navigated', init);
document.addEventListener('livewire:initialized', () => {
    init();
    if (window.Livewire && typeof window.Livewire.hook === 'function') {
        window.Livewire.hook('morph.added', ({ el }) => {
            if (!(el instanceof Element)) return;
            const targets = el.matches('[data-line-chart]')
                ? [el]
                : Array.from(el.querySelectorAll('[data-line-chart]'));
            targets.forEach((root) => {
                if (root.__uPlotChart) return;
                const payload = readPayload(root);
                if (payload) mount(root, payload);
            });
        });
        window.Livewire.hook('morph.removed', ({ el }) => {
            if (!(el instanceof Element)) return;
            const targets = el.matches('[data-line-chart]')
                ? [el]
                : Array.from(el.querySelectorAll('[data-line-chart]'));
            targets.forEach((root) => unmount(root));
        });
    }
});

document.addEventListener('contract-price-statistics:rerender', reinit);

window.VoltikkaContractPriceChart = { init, reinit };
