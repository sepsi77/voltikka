import uPlot from 'uplot';
import 'uplot/dist/uPlot.min.css';
import { logicalSeriesPointOptions } from './contract-price-chart-options.js';

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

// The lead series colour is resolved in three places (the line, the tooltip
// swatch and the end label) and they did not previously agree: the line was
// coral only on a spot palette, while the end label was always coral. An
// optional `leadStroke` in the payload pins all three to one colour. Without
// it every existing chart keeps its previous behaviour exactly.
//
// The company page sets it, because its two series are "this seller" and
// "market median"; the default would draw both in near-identical navy.
function resolveLeadStroke(payload, isSpotPalette, fallback) {
    return payload.leadStroke || (isSpotPalette ? CORAL_500 : fallback);
}

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
    const { unit, decimals, series, band } = payload;
    const unitLabel = unit === 'eur' ? '€/v' : 'c/kWh';
    const isSpotPalette = root.dataset.lineChart === 'spot' || (series[0] && /pörssi/i.test(series[0].label));
    const bandFill = isSpotPalette ? 'rgba(249, 115, 22, 0.12)' : 'rgba(100, 116, 139, 0.18)';

    const splinePath = uPlot.paths.spline();
    const uplotSeries = [{}];
    const bands = [];

    if (band) {
        // Two invisible boundary series, then a fill band between them.
        uplotSeries.push(
            { label: 'p20', stroke: 'transparent', points: { show: false }, paths: splinePath },
            { label: 'p80', stroke: 'transparent', points: { show: false }, paths: splinePath },
        );
        bands.push({ series: [2, 1], fill: bandFill });
    }

    series.forEach((s, idx) => {
        if (idx === 0) {
            const stroke = resolveLeadStroke(payload, isSpotPalette, SLATE_800);
            uplotSeries.push({
                label: s.label,
                stroke,
                width: 2.2,
                points: logicalSeriesPointOptions(payload.showPoints, stroke, payload.showLatestPoint),
                paths: splinePath,
            });
            return;
        }
        const style = NON_LEAD_STYLES[(idx - 1) % NON_LEAD_STYLES.length];
        uplotSeries.push({
            label: s.label,
            stroke: style.stroke,
            width: style.width,
            dash: style.dash.length ? style.dash : undefined,
            points: logicalSeriesPointOptions(payload.showPoints, style.stroke, payload.showLatestPoint),
            paths: splinePath,
        });
    });

    return {
        width: root.clientWidth,
        height: root.clientHeight || 320,
        padding: [16, 16, 8, 8],
        bands,
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
                    const span = dataMax - dataMin;
                    const ratio = dataMax > 0 ? span / dataMax : 0;
                    // If the data is essentially flat, anchor the axis at 0 so
                    // tiny noise doesn't look like dramatic movement.
                    if (ratio < 0.10) {
                        return [0, dataMax * 1.15 || 1];
                    }
                    const pad = span * 0.12 || dataMax * 0.05 || 1;
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
                size: 30,
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
        badge.style.top = '0';
        badge.style.right = '4px';
        badge.style.fontSize = '12px';
        badge.style.fontWeight = '600';
        badge.style.letterSpacing = '0.02em';
        badge.style.color = SLATE_500;
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
            ? (payload.leadStroke || CORAL_500)
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
    tip.style.background = 'rgba(255, 255, 255, 0.98)';
    tip.style.color = SLATE_900;
    tip.style.border = `1px solid ${SLATE_200}`;
    tip.style.borderRadius = '10px';
    tip.style.fontSize = '12px';
    tip.style.lineHeight = '1.4';
    tip.style.fontFamily = '"Plus Jakarta Sans", system-ui, sans-serif';
    tip.style.fontVariantNumeric = 'tabular-nums';
    tip.style.boxShadow = '0 16px 36px -18px rgba(15, 23, 42, 0.35)';
    tip.style.whiteSpace = 'nowrap';
    tip.style.opacity = '0';
    tip.style.transform = 'translate(8px, -50%)';
    tip.style.transition = 'opacity 120ms ease-out';
    root.appendChild(tip);
    return tip;
}

function tooltipSeriesStyle(sIdx, isSpotPalette, hasBand, payload) {
    if (sIdx === 0) {
        return {
            stroke: resolveLeadStroke(payload, isSpotPalette, hasBand ? SLATE_800 : CORAL_500),
            width: 2.4,
            dash: [],
        };
    }

    return NON_LEAD_STYLES[(sIdx - 1) % NON_LEAD_STYLES.length];
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

    const lines = [`<div style="color:${SLATE_500};margin-bottom:6px;font-weight:700">${formatFinnishDateLong(ts)}</div>`];

    const dataOffset = payload.band ? 3 : 1;

    if (payload.band) {
        const lo = u.data[1][idx];
        const hi = u.data[2][idx];
        if (lo !== null && lo !== undefined && hi !== null && hi !== undefined) {
            lines.push(
                `<div style="color:${SLATE_400};font-size:11px;margin-bottom:4px">${formatNumber(lo, payload.decimals)}–${formatNumber(hi, payload.decimals)} ${payload.unit === 'eur' ? '€' : 'c/kWh'}</div>`
            );
        }
    }

    const isSpotPalette = u.root.dataset.lineChart === 'spot' || (payload.series[0] && /pörssi/i.test(payload.series[0].label));

    const rows = payload.series.map((s, sIdx) => {
        const y = u.data[dataOffset + sIdx][idx];
        const style = tooltipSeriesStyle(sIdx, isSpotPalette, Boolean(payload.band), payload);
        const display = y === null || y === undefined ? '–' : `${formatNumber(y, payload.decimals)} ${payload.unit === 'eur' ? '€' : 'c/kWh'}`;
        const dash = style.dash && style.dash.length ? `stroke-dasharray="${style.dash.join(' ')}"` : '';
        const sortY = y === null || y === undefined ? Number.NEGATIVE_INFINITY : Number(y);

        return {
            sortY,
            html:
                `<div style="display:grid;grid-template-columns:52px minmax(0,1fr) auto;align-items:center;column-gap:10px;min-width:270px;padding:2px 0">` +
                `<svg width="52" height="14" viewBox="0 0 52 14" aria-hidden="true" style="display:block;overflow:visible">` +
                `<line x1="2" y1="7" x2="50" y2="7" stroke="${style.stroke}" stroke-width="${Math.max(style.width, 2.4)}" stroke-linecap="round" ${dash}/>` +
                `</svg>` +
                `<span style="min-width:0;overflow:hidden;text-overflow:ellipsis;color:${SLATE_700};font-weight:650">${s.label}</span>` +
                `<span style="font-weight:850;color:${SLATE_900};padding-left:16px">${display}</span>` +
                `</div>`,
        };
    }).sort((a, b) => b.sortY - a.sortY);

    rows.forEach((row) => lines.push(row.html));

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

    // If the container hasn't been measured yet (e.g., script runs before CSS
    // is fully applied, or container is briefly hidden), defer until the next
    // frame. Without this the chart can fall back to a 320px default and
    // overflow a shorter container.
    if (root.clientHeight === 0 || root.clientWidth === 0) {
        requestAnimationFrame(() => mount(root, payload));
        return;
    }

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

    const data = [xs];
    if (payload.band) {
        data.push(payload.band.lower, payload.band.upper);
    }
    payload.series.forEach((s) => data.push(s.values));

    const opts = buildOptions(payload, root);
    const chart = new uPlot(opts, data, root);

    const ro = new ResizeObserver((entries) => {
        for (const entry of entries) {
            const h = entry.contentRect.height || root.clientHeight || 320;
            chart.setSize({ width: entry.contentRect.width, height: h });
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
