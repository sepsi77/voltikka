// Shared engine of the market review videos. All motion is a function of the virtual time t,
// which render.mjs passes to window.renderFrame(t). No CSS animations, no clocks.
//
// Timing comes from timeline/<variant>.js (window.TL): S('k_oct1') is the start of a voice line,
// E('k_oct1') its end. A scene is shown between two times and hidden outside them.

const TL = window.TL;
const D = window.DATA;
const S = (id) => { if (!TL.lines[id]) throw new Error(`No line ${id}`); return TL.lines[id].start; };
const E = (id) => TL.lines[id].end;
// A point inside a line: f = 0 start, 1 end. Use it for a beat in the middle of a line, so it follows a change of voice speed.
const A = (id, f) => S(id) + f * (E(id) - S(id));

const clamp = (x, a = 0, b = 1) => Math.max(a, Math.min(b, x));
const EASE = {
    lin: (x) => x,
    out: (x) => 1 - Math.pow(1 - x, 3),
    io: (x) => (x < 0.5 ? 4 * x * x * x : 1 - Math.pow(-2 * x + 2, 3) / 2),
    back: (x) => { const c = 1.6; return 1 + (c + 1) * Math.pow(x - 1, 3) + c * Math.pow(x - 1, 2); },
};
// Progress 0..1 of an animation that starts at t0 and lasts dur seconds.
const P = (t, t0, dur, e = 'out') => EASE[e](clamp((t - t0) / dur));
const lerp = (a, b, x) => a + (b - a) * x;

const tracks = [];
const tick = (fn) => tracks.push(fn);
window.renderFrame = (t) => { for (const f of tracks) f(t); };

const $ = (sel, root = document) => root.querySelector(sel);
const $$ = (sel, root = document) => [...root.querySelectorAll(sel)];
function el(html) { const d = document.createElement('div'); d.innerHTML = html.trim(); return d.firstElementChild; }

// Finnish number format: comma decimals, narrow no-break space thousands.
function fi(v, dec = 0) {
    const s = Math.abs(v).toFixed(dec).split('.');
    s[0] = s[0].replace(/\B(?=(\d{3})+(?!\d))/g, ' ');
    return (v < 0 ? '−' : '') + s.join(',');
}

// A scene: visible from t0 to t1. The next scene has a higher z and comes in over this one (a wipe
// or a fade-in), so by default this scene stays solid for `hold` seconds after t1 and then goes:
// a fade-out here would show the dark body through both scenes (grey frames). Use fadeOut only
// when the next scene is below this one. 'wipe' slides a paper scene in from the right.
function scene(id, t0, t1, { fadeIn = 0.5, fadeOut = 0, hold = 0.8, wipe = false, z = 1 } = {}) {
    const s = document.getElementById(id);
    s.style.zIndex = z;
    const end = t1 + Math.max(fadeOut, fadeOut ? 0 : hold);
    tick((t) => {
        const on = t >= t0 - 0.001 && t < end;
        s.style.display = on ? 'block' : 'none';
        if (!on) return;
        const out = fadeOut ? 1 - P(t, t1, fadeOut, 'lin') : 1;
        if (wipe) {
            const x = P(t, t0, 0.7, 'io');
            s.style.clipPath = `inset(0 0 0 ${(1 - x) * 100}%)`;
            s.style.opacity = out;
        } else {
            s.style.opacity = Math.min(fadeIn ? P(t, t0, fadeIn, 'lin') : 1, out);
        }
    });
    return s;
}

// Fade + rise in at t0, optional out at t1.
function show(node, t0, { t1 = Infinity, dy = 40, dur = 0.6, dx = 0, outDur = 0.35 } = {}) {
    tick((t) => {
        const a = P(t, t0, dur), b = P(t, t1, outDur, 'lin');
        node.style.opacity = a * (1 - b);
        node.style.transform = `translate(${(1 - a) * dx}px, ${(1 - a) * dy - b * 20}px)`;
    });
}

// Words of a text node rise in one by one.
function words(node, t0, { gap = 0.09, t1 = Infinity, dy = 50 } = {}) {
    const text = node.textContent.trim();
    node.innerHTML = text.split(/\s+/).map((w) => `<span class="w">${w}</span>`).join(' ');
    $$('.w', node).forEach((w, i) => show(w, t0 + i * gap, { t1, dy }));
}

// Count a number up from a to b.
function count(node, a, b, t0, dur = 1.2, dec = 0, { prefix = '', suffix = '' } = {}) {
    tick((t) => { node.textContent = prefix + fi(lerp(a, b, P(t, t0, dur)), dec) + suffix; });
}

// Draw an SVG path (stroke) from start to end.
function draw(path, t0, dur = 1, e = 'io') {
    const len = path.getTotalLength();
    path.style.strokeDasharray = `${len} ${len}`;
    tick((t) => { path.style.strokeDashoffset = len * (1 - P(t, t0, dur, e)); });
}

// Ken Burns on a painted plate.
function drift(img, t0, t1, { from = 1.08, to = 1.0, ox = '50%', oy = '50%' } = {}) {
    img.style.transformOrigin = `${ox} ${oy}`;
    tick((t) => { img.style.transform = `scale(${lerp(from, to, P(t, t0, Math.max(0.1, t1 - t0), 'lin'))})`; });
}

// A hand-inked wavy underline / circle as SVG markup.
const UNDERLINE = '<svg viewBox="0 0 300 14" preserveAspectRatio="none"><path pathLength="1" d="M3 9 C 50 3, 90 13, 150 7 S 250 3, 297 8"/></svg>';
const CIRCLE = (w, h) => `<svg class="ink" viewBox="0 0 300 120" preserveAspectRatio="none" style="position:absolute;left:-30px;top:-22px;width:${w + 60}px;height:${h + 44}px;overflow:visible"><path d="M40 30 C 110 0, 250 4, 288 46 C 312 82, 240 116, 140 114 C 50 112, 6 90, 12 60 C 16 40, 40 24, 80 16"/></svg>`;

const LOGO = (s = 40) => `<svg width="${s}" height="${s}" viewBox="0 0 34 34"><path d="M5 29 C 6 16, 14 7, 27 5 C 24 12, 22 20, 13 26 C 10 28, 7 29, 5 29 Z" fill="#C4501C"/><path d="M29.5 11.5 l1 2.2 2.2 1 -2.2 1 -1 2.2 -1 -2.2 -2.2 -1 2.2 -1 z" fill="#1C7A5E"/><circle cx="31" cy="4" r="1.6" fill="#6A4C9C"/></svg>`;

// Corner wordmark with the edition and an optional chapter rail. chapters: [[name, t0], ...].
function chrome(t0, t1, chapters = [], { title = 'Sähkömarkkinakatsaus · lokakuu 2026', darkFrom = [] } = {}) {
    const c = el(`<div class="chrome"><div class="wm">${LOGO(40)}<span>Voltikka</span><em>${title}</em></div><div class="rail"></div></div>`);
    c.style.zIndex = 50;
    document.body.appendChild(c);
    const rail = $('.rail', c);
    const items = chapters.map(([name]) => { const i = el(`<div class="item">${name}${UNDERLINE}</div>`); rail.appendChild(i); return i; });
    items.forEach((i) => { const p = $('path', i); p.style.strokeDasharray = '1 1'; });
    tick((t) => {
        c.style.display = t >= t0 && t < t1 ? 'block' : 'none';
        c.style.opacity = P(t, t0, 0.5, 'lin') * (1 - P(t, t1 - 0.4, 0.4, 'lin'));
        c.classList.toggle('dark', darkFrom.some(([a, b]) => t >= a && t < b));
        let active = -1;
        chapters.forEach(([, s], k) => { if (t >= s) active = k; });
        items.forEach((i, k) => {
            i.classList.toggle('on', k === active);
            $('path', i).style.strokeDashoffset = k === active ? 1 - P(t, chapters[k][1], 0.6, 'io') : 1;
        });
    });
}

// ---------- Charts ----------

// Monthly bill outlook of one home type, Nov–Oct: the site chart "Kuva 1" (same frozen data, D.site.kuva1,
// and look: spot bars --color-cat-spot, 12-month fixed median --color-cat-fixed, an ink whisker for the
// three-winter range of the spot bill, € ticks). Returns { node, grow(t0), mark(season, t0) }; mark highlights
// 'winter' (Nov–Mar) or 'rest' (Apr–Oct).
const COL = { spot: '#1C7A5E', fixed: '#535D68', ink: '#16202E', muted: '#5E6876', edge: '#D9DFDC', fox: '#C4501C', lake: '#2B5F8C' };
function billChart(group, { w = 1000, h = 520 } = {}) {
    const k = D.site.kuva1, home = k.homes.find((x) => x.key === group);
    const max = Math.max(...home.spot_high, ...home.fixed);
    const step = max > 250 ? 50 : 20;
    const top = Math.ceil(max / step) * step;
    const pl = 96, pb = 50, pt = 20;
    const cw = (w - pl) / 12, bw = cw * 0.72 * 0.45;
    const y = (v) => pt + (h - pt - pb) * (1 - v / top);
    let s = `<svg class="chart" width="${w}" height="${h}" viewBox="0 0 ${w} ${h}">`;
    for (let v = 0; v <= top; v += step) s += `<line x1="${pl}" x2="${w}" y1="${y(v)}" y2="${y(v)}" stroke="${COL.edge}" stroke-width="2"/><text class="axis-label" x="${pl - 14}" y="${y(v) + 7}" text-anchor="end">${v} €</text>`;
    s += `<g class="season winter" opacity="0"><rect x="${pl}" y="${pt - 10}" width="${cw * 5}" height="${h - pt - pb + 10}" fill="#2B5F8C" fill-opacity="0.07"/></g>`;
    s += `<g class="season rest" opacity="0"><rect x="${pl + cw * 5}" y="${pt - 10}" width="${cw * 7}" height="${h - pt - pb + 10}" fill="#E0A21B" fill-opacity="0.09"/></g>`;
    home.spot.forEach((v, i) => {
        const x = pl + cw * i + cw * 0.14;
        s += `<rect class="bar spot" data-i="${i}" x="${x}" y="${y(v)}" width="${bw}" height="${y(0) - y(v)}" fill="${COL.spot}" rx="4"/>`;
        s += `<rect class="bar fixed" data-i="${i}" x="${x + bw + 5}" y="${y(home.fixed[i])}" width="${bw}" height="${y(0) - y(home.fixed[i])}" fill="${COL.fixed}" rx="4"/>`;
        const cx = x + bw / 2, t = y(home.spot_high[i]), b = y(home.spot_low[i]);
        s += `<path class="whisker" data-i="${i}" d="M${cx} ${t} V${b} M${cx - 7} ${t} H${cx + 7} M${cx - 7} ${b} H${cx + 7}" stroke="${COL.ink}" stroke-width="3" fill="none" opacity="0"/>`;
        s += `<text class="axis-label" x="${pl + cw * i + cw / 2}" y="${h - 14}" text-anchor="middle">${k.labels[i]}</text>`;
    });
    s += `<line x1="${pl}" x2="${w}" y1="${y(0)}" y2="${y(0)}" stroke="${COL.ink}" stroke-width="3"/></svg>`;
    const node = el(`<div style="position:relative">${s}</div>`);
    return {
        node,
        grow(t0, dur = 1.6) {
            $$('.bar', node).forEach((b) => {
                const i = +b.dataset.i, H = +b.getAttribute('height'), Y = +b.getAttribute('y');
                tick((t) => { const p = P(t, t0 + i * (dur / 14), 0.5); b.setAttribute('y', Y + H * (1 - p)); b.setAttribute('height', H * p); });
            });
            $$('.whisker', node).forEach((wk) => { const i = +wk.dataset.i; tick((t) => wk.setAttribute('opacity', P(t, t0 + i * (dur / 14) + 0.45, 0.3))); });
        },
        mark(season, t0, t1 = Infinity) { const gs = $(`.season.${season}`, node); tick((t) => gs.setAttribute('opacity', P(t, t0, 0.5) * (1 - P(t, t1, 0.4)))); },
    };
}

// Legend items: [colour, label] (a square key, as on the site), or ['whisker', label] or ['dash', label] for line keys.
function legend(items) {
    const key = (c) => c === 'whisker' ? `<svg width="18" height="26" viewBox="0 0 18 26"><path d="M9 2 V24 M3 2 H15 M3 24 H15" stroke="${COL.ink}" stroke-width="3" fill="none"/></svg>`
        : c === 'dash' ? `<svg width="34" height="6" viewBox="0 0 34 6"><path d="M0 3 H34" stroke="${COL.ink}" stroke-width="3" stroke-dasharray="7 5"/></svg>`
        : c === 'split' ? `<span class="dot" style="background:linear-gradient(90deg, ${COL.fox} 50%, ${COL.lake} 50%);border-radius:3px"></span>`
        : `<span class="dot" style="background:${c};border-radius:3px"></span>`;
    return items.map(([c, l, id]) => `<span ${id ? `id="${id}" ` : ''}style="display:inline-flex;align-items:center;gap:10px;margin-right:30px">${key(c)}${l}</span>`).join('');
}

// An ink stamp (.stamp inside node) lands at t0: scale down with a small overshoot.
function stamp(node, t0, rot = -2) {
    const st = node.classList.contains('stamp') ? node : $('.stamp', node);
    tick((t) => { const p = P(t, t0, 0.45, 'back'); st.style.opacity = clamp(p * 3); st.style.transform = `scale(${lerp(1.5, 1, p)}) rotate(${rot}deg)`; });
}

// A bar that grows from the left (CSS width set in markup).
function growX(node, t0, dur = 0.8) { node.style.transformOrigin = '0 0'; tick((t) => { node.style.transform = `scaleX(${P(t, t0, dur)})`; }); }

// The end card: drops in on a cord and settles.
function dropIn(node, t0) {
    tick((t) => {
        const u = t - t0;
        const yv = u < 0 ? -1300 : u < 0.6 ? -1300 * Math.pow(1 - u / 0.6, 2) : 30 * Math.exp(-5 * (u - 0.6)) * Math.sin((u - 0.6) * 11);
        node.style.transform = `translateY(${yv}px)`;
    });
}
