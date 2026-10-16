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

// ---------------------------------------------------------------------------
// Ink skin (redesign, Paper boards 6MP-0, D8Q-0, home 6HY-0, company AX2-0). It is
// the default for every data-line-chart (statistics, forecast, home, company and
// article pages), so the shared charts agree. data-chart-skin="legacy" keeps the
// old slate look. Colours come from the design tokens (resources/css/tokens.css).
// Series colours follow the label (design spec §5 "Series colours in multi-series
// charts"): a pricing category uses its category colour, open-ended is a dashed
// ink-muted line, a market median/reference line is dashed granite, the seller-set
// index total is the thick fox line, and a payload `leadStroke` pins series 0
// (the old coral default #f97316 maps to the fox token). Legends drawn in Blade
// must mirror inkSeriesStyle(). Data, scales and behaviour do not change.
// ---------------------------------------------------------------------------
function chartContainer(root) {
    // uPlot's own root (u.root) sits inside the data-line-chart container.
    return (root && root.closest ? root.closest('[data-line-chart]') : null) || root;
}

function isInkSkin(root) {
    const container = chartContainer(root);
    return Boolean(container && container.dataset && container.dataset.chartSkin !== 'legacy');
}

function readToken(name, fallback) {
    const value = getComputedStyle(document.documentElement).getPropertyValue(name).trim();
    return value || fallback;
}

function inkPalette() {
    return {
        ink: readToken('--color-ink', '#16202E'),
        inkBody: readToken('--color-ink-body', '#3A4656'),
        inkMuted: readToken('--color-ink-muted', '#5E6876'),
        edge: readToken('--color-edge', '#D9DFDC'),
        edgeStrong: readToken('--color-edge-strong', '#B9C2C0'),
        ground: readToken('--color-ground', '#F5F6F1'),
        fox: readToken('--color-fox', '#C4501C'),
        foxDeep: readToken('--color-fox-deep', '#9E3C10'),
        spot: readToken('--color-cat-spot', '#1C7A5E'),
        periodic: readToken('--color-cat-periodic', '#2B5F8C'),
        usage: readToken('--color-cat-usage', '#6A4C9C'),
        fixed: readToken('--color-cat-fixed', '#535D68'),
    };
}

function hexToRgba(hex, alpha) {
    const match = /^#?([0-9a-f]{6})$/i.exec(String(hex).trim());
    if (!match) return hex;
    const n = parseInt(match[1], 16);
    return `rgba(${(n >> 16) & 255}, ${(n >> 8) & 255}, ${n & 255}, ${alpha})`;
}

function inkSeriesStyle(label, idx, root, pal, payload = null) {
    const text = String(label || '');
    const container = chartContainer(root);
    if (container.dataset.lineChart === 'seller-set-index' && idx === 0) {
        return { stroke: pal.fox, width: 3.8, dash: [] };
    }
    if (idx === 0 && payload && payload.leadStroke) {
        const lead = String(payload.leadStroke).toLowerCase() === CORAL_500 ? pal.fox : payload.leadStroke;
        return { stroke: lead, width: 3.4, dash: [] };
    }
    if (/toistaiseksi/i.test(text)) return { stroke: pal.inkMuted, width: 2.6, dash: [6, 4] };
    if (/pörssi|marginaali/i.test(text)) return { stroke: pal.spot, width: 2.6, dash: [] };
    if (/määräaikai/i.test(text)) return { stroke: pal.fixed, width: 2.6, dash: [] };
    if (/jousto|hybrid|kulutusvaikut/i.test(text)) return { stroke: pal.usage, width: 2.6, dash: [] };
    if (/kvartaali|jaksoittain/i.test(text)) return { stroke: pal.periodic, width: 2.6, dash: [] };
    if (/mediaani|markkina/i.test(text)) return { stroke: pal.fixed, width: 2.6, dash: [6, 4] };
    return { stroke: pal.ink, width: 2.6, dash: [] };
}

// Board points: the latest-point marker is a filled dot in the series colour with a
// ground-colour ring. Markers on every period (weekly/monthly) stay small, plain dots.
function inkPoints(points, stroke, pal) {
    if (!points || points.show === false) return points;
    if (points.filter) {
        return { ...points, size: 9, width: 1.5, stroke: pal.ground, fill: stroke };
    }
    return { ...points, size: 4, width: 0, stroke, fill: stroke };
}

// Inked baseline under the plot (spec §4: 1.6px ink line under every chart), drawn
// slightly uneven like the board's hand-drawn line.
function drawInkBaseline(u, pal) {
    const ctx = u.ctx;
    const { left, top, width, height } = u.bbox;
    const dpr = window.devicePixelRatio || 1;
    const y = top + height + 2 * dpr;
    ctx.save();
    ctx.setLineDash([]);
    ctx.beginPath();
    ctx.moveTo(left, y);
    ctx.bezierCurveTo(left + width * 0.3, y - 1.2 * dpr, left + width * 0.7, y + 1.4 * dpr, left + width, y - 0.4 * dpr);
    ctx.lineWidth = 1.8 * dpr;
    ctx.lineCap = 'round';
    ctx.strokeStyle = pal.ink;
    ctx.stroke();
    ctx.restore();
}

// "nyt 13,09": an inked label with a short arrow at the latest point of the lead
// series (Paper 7C0-0). Only on charts that opt in with data-chart-now.
function attachNowLabel(u, payload, pal) {
    const root = u.root;
    const container = chartContainer(root);
    if (container.dataset.chartNow === undefined) return;
    let el = root.querySelector('[data-now-label]');
    if (!el) {
        el = document.createElement('div');
        el.setAttribute('data-now-label', '');
        el.setAttribute('aria-hidden', 'true');
        el.style.position = 'absolute';
        el.style.pointerEvents = 'none';
        el.style.display = 'flex';
        el.style.alignItems = 'flex-end';
        el.style.gap = '2px';
        el.style.whiteSpace = 'nowrap';
        el.style.fontFamily = '"Bricolage Grotesque", "Figtree", system-ui, sans-serif';
        el.style.fontWeight = '800';
        el.style.fontSize = '15px';
        el.style.lineHeight = '18px';
        el.style.color = pal.foxDeep;
        root.appendChild(el);
    }
    const dataOffset = payload.band ? 3 : 1;
    const ys = u.data[dataOffset] || [];
    let idx = ys.length - 1;
    while (idx >= 0 && (ys[idx] === null || ys[idx] === undefined || Number.isNaN(ys[idx]))) idx -= 1;
    if (idx < 0) {
        el.style.display = 'none';
        return;
    }
    const dpr = window.devicePixelRatio || 1;
    const x = u.valToPos(u.data[0][idx], 'x') + u.bbox.left / dpr;
    const y = u.valToPos(ys[idx], 'y') + u.bbox.top / dpr;
    el.style.display = 'flex';
    // The container can name the published current figure (data-chart-now="13,09"), so the
    // label matches the key figure even when the chart's last period is an average.
    const nowText = container.dataset.chartNow || formatNumber(ys[idx], payload.decimals);
    el.textContent = '';
    el.innerHTML = `<span></span>`
        + `<svg width="30" height="26" viewBox="0 0 30 26" style="display:block;overflow:visible"><path d="M2 4 C 12 1, 22 5, 27 19 M21 15 L27.5 20.5 L29 12" fill="none" stroke="${pal.fox}" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>`;
    el.firstChild.textContent = `nyt ${nowText}`;
    el.style.left = `${Math.max(0, x - el.offsetWidth - 2)}px`;
    el.style.top = `${Math.max(0, y - el.offsetHeight - 4)}px`;
}

// End value (round 3, Paper "R3 · Data · Tilastot"): a dot in the lead colour with a ground
// ring and the value beside it, at the latest point of the lead series. Only on charts that
// opt in with data-chart-end. A non-empty value is the published key figure, so the label
// uses the same rounding as the figure above the chart (a weekly chart's last point can be an
// average that rounds differently); an empty value formats the last point.
function attachEndValue(u, payload, pal) {
    const root = u.root;
    const container = chartContainer(root);
    if (container.dataset.chartEnd === undefined) return;
    let el = root.querySelector('[data-end-value]');
    if (!el) {
        el = document.createElement('div');
        el.setAttribute('data-end-value', '');
        el.setAttribute('aria-hidden', 'true');
        el.style.position = 'absolute';
        el.style.pointerEvents = 'none';
        el.style.display = 'flex';
        el.style.alignItems = 'center';
        el.style.gap = '5px';
        el.style.whiteSpace = 'nowrap';
        el.style.fontFamily = '"Figtree", system-ui, sans-serif';
        el.style.fontWeight = '800';
        el.style.fontSize = '14px';
        el.style.lineHeight = '18px';
        root.appendChild(el);
    }
    const dataOffset = payload.band ? 3 : 1;
    const ys = u.data[dataOffset] || [];
    let idx = ys.length - 1;
    while (idx >= 0 && (ys[idx] === null || ys[idx] === undefined || Number.isNaN(ys[idx]))) idx -= 1;
    if (idx < 0) {
        el.style.display = 'none';
        return;
    }
    const stroke = inkSeriesStyle(payload.series[0]?.label, 0, root, pal, payload).stroke;
    const dpr = window.devicePixelRatio || 1;
    const x = u.valToPos(u.data[0][idx], 'x') + u.bbox.left / dpr;
    const y = u.valToPos(ys[idx], 'y') + u.bbox.top / dpr;
    const text = container.dataset.chartEnd || formatNumber(ys[idx], payload.decimals);
    el.innerHTML = `<span style="display:block;width:9px;height:9px;border-radius:50%;box-sizing:content-box;background:${stroke};border:2px solid ${pal.ground}"></span><span></span>`;
    el.lastChild.textContent = text;
    el.style.color = stroke;
    el.style.display = 'flex';
    el.style.left = `${x - 6.5}px`;
    el.style.top = `${y - 9}px`;
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
    const ink = isInkSkin(root);
    const pal = ink ? inkPalette() : null;
    const bandSeriesIdx = Math.max(0, series.findIndex((s) => /mediaani|markkina/i.test(String(s.label || '')) && !/marginaali/i.test(String(s.label || ''))));
    const bandFill = ink
        ? hexToRgba(inkSeriesStyle(series[bandSeriesIdx]?.label, bandSeriesIdx, root, pal, payload).stroke, 0.16)
        : (isSpotPalette ? 'rgba(249, 115, 22, 0.12)' : 'rgba(100, 116, 139, 0.18)');

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
        if (ink) {
            const style = inkSeriesStyle(s.label, idx, root, pal, payload);
            uplotSeries.push({
                label: s.label,
                stroke: style.stroke,
                width: style.width,
                dash: style.dash.length ? style.dash : undefined,
                points: inkPoints(logicalSeriesPointOptions(payload.showPoints, style.stroke, payload.showLatestPoint), style.stroke, pal),
                paths: splinePath,
            });
            return;
        }
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

    const axisFont = ink ? '700 11px "Figtree", system-ui, sans-serif' : '12px "Figtree", system-ui, sans-serif';
    const axes = ink
        ? [
            {
                stroke: pal.inkMuted,
                grid: { show: false },
                ticks: { show: false },
                font: axisFont,
                size: 34,
                gap: 10,
                values: (_u, splits) => splits.map((ts) => formatFinnishDate(ts)),
            },
            {
                stroke: pal.inkMuted,
                grid: { stroke: pal.edge, width: 1 },
                ticks: { show: false },
                font: axisFont,
                size: 48,
                gap: 6,
                values: (_u, splits) => splits.map((v) => formatNumber(v, decimals)),
            },
        ]
        : null;

    return {
        width: root.clientWidth,
        height: root.clientHeight || 320,
        // An end value label (data-chart-end) needs room at the right of the last point.
        padding: ink ? (root.dataset.chartEnd !== undefined ? [12, 56, 4, 4] : [24, 16, 4, 4]) : [16, 16, 8, 8],
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
        axes: axes || [
            {
                stroke: SLATE_500,
                grid: { stroke: SLATE_200, width: 1 },
                ticks: { stroke: SLATE_300, width: 1, size: 4 },
                font: '12px "Figtree", system-ui, sans-serif',
                size: 30,
                values: (_u, splits) => splits.map((ts) => formatFinnishDate(ts)),
            },
            {
                stroke: SLATE_500,
                grid: { stroke: SLATE_200, width: 1 },
                ticks: { stroke: SLATE_300, width: 1, size: 4 },
                font: '12px "Figtree", system-ui, sans-serif',
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
                    if (ink) attachNowLabel(u, payload, pal);
                    if (ink) attachEndValue(u, payload, pal);
                },
            ],
            setSize: [
                (u) => {
                    attachEndLabels(u, payload, false);
                    attachUnitBadge(u, unitLabel);
                    if (ink) attachNowLabel(u, payload, pal);
                    if (ink) attachEndValue(u, payload, pal);
                },
            ],
            draw: ink ? [(u) => drawInkBaseline(u, pal)] : [],
            setCursor: [
                (u) => updateTooltip(u, payload),
            ],
        },
    };
}

function attachUnitBadge(u, unitLabel) {
    // A chart whose caption already names the unit opts out (data-chart-unit-badge="off").
    if (chartContainer(u.root).dataset.chartUnitBadge === 'off') return;
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
        badge.style.fontFamily = '"Figtree", system-ui, sans-serif';
        if (isInkSkin(u.root)) {
            badge.style.fontSize = '11px';
            badge.style.fontWeight = '700';
            badge.style.letterSpacing = '0';
            badge.style.color = readToken('--color-ink-muted', SLATE_500);
        }
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
        el.style.fontFamily = '"Figtree", system-ui, sans-serif';
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
    tip.style.fontFamily = '"Figtree", system-ui, sans-serif';
    tip.style.fontVariantNumeric = 'tabular-nums';
    tip.style.boxShadow = '0 16px 36px -18px rgba(15, 23, 42, 0.35)';
    tip.style.whiteSpace = 'nowrap';
    tip.style.opacity = '0';
    tip.style.transform = 'translate(8px, -50%)';
    tip.style.transition = 'opacity 120ms ease-out';
    if (isInkSkin(root)) {
        // No card look: the ground colour with an inked edge, no radius and no shadow.
        tip.style.background = readToken('--color-ground', '#F5F6F1');
        tip.style.color = readToken('--color-ink', SLATE_900);
        tip.style.border = `1.5px solid ${readToken('--color-ink', SLATE_900)}`;
        tip.style.borderRadius = '0';
        tip.style.boxShadow = 'none';
    }
    root.appendChild(tip);
    return tip;
}

function tooltipSeriesStyle(sIdx, isSpotPalette, hasBand, payload, root) {
    if (isInkSkin(root)) {
        return inkSeriesStyle(payload.series[sIdx]?.label, sIdx, root, inkPalette(), payload);
    }
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
        const style = tooltipSeriesStyle(sIdx, isSpotPalette, Boolean(payload.band), payload, u.root);
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
    root.querySelectorAll('[data-end-labels],[data-uplot-tooltip],[data-unit-badge],[data-now-label],[data-end-value]').forEach((el) => el.remove());
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
