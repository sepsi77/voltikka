// Shared parts of the portrait Shorts. Load after lib.js; read short.css for the safe zones.

// The wordmark in the header, from t0 to t1.
function shortMark(t0 = -1, t1 = Infinity, title = 'Sähkömarkkinakatsaus · lokakuu 2026') {
    const m = el(`<div class="sh-wm">${LOGO(38)}<span>Voltikka</span><em>${title}</em></div>`);
    document.body.appendChild(m);
    tick((t) => { m.style.display = t >= t0 && t < t1 ? 'flex' : 'none'; });
    return m;
}

// Burned-in captions from the timeline: each line (its subtitle text when it has one) is cut into
// chunks of at most about `max` characters, with time shared by character count. A chunk shows
// only while its line is spoken. Numbers with a space ("1 500") stay whole. Words in `strong`
// (lower case, without punctuation) are coloured. Lines in `skip` get no caption, because the same
// words are already large on the screen (a hook title, the end card).
function captions({ max = 30, strong = [], skip = [] } = {}) {
    const box = el('<div class="cap"><span></span></div>');
    document.body.appendChild(box);
    const span = $('span', box);
    const chunks = [];
    for (const id of TL.order) {
        if (skip.includes(id)) continue;
        const l = TL.lines[id];
        const words = (l.sub ?? l.text).replace(/(\d) (\d{3})/g, '$1 $2').split(/\s+/);
        const parts = [];
        let cur = '';
        for (const w of words) {
            if (cur && (cur + ' ' + w).length > max) { parts.push(cur); cur = w; } else cur = cur ? cur + ' ' + w : w;
            // End a chunk at a sentence end or a colon when it is long enough.
            if (/[.?!:]$/.test(w) && cur.length >= 12) { parts.push(cur); cur = ''; }
        }
        if (cur) parts.push(cur);
        const total = parts.reduce((a, p) => a + p.length, 0);
        let t0 = l.start;
        for (const p of parts) {
            const t1 = t0 + (l.end - l.start) * p.length / total;
            chunks.push({ t0, t1, p });
            t0 = t1;
        }
    }
    const fmt = (p) => p.split(' ').map((w) => strong.includes(w.toLowerCase().replace(/[^a-zåäö0-9, –+−]/g, '')) ? `<b>${w}</b>` : w).join(' ');
    let shown = null;
    tick((t) => {
        const c = chunks.find((c) => t >= c.t0 && t < c.t1);
        box.style.display = c ? 'block' : 'none';
        if (!c) return;
        if (shown !== c) { span.innerHTML = fmt(c.p); shown = c; }
        const p = P(t, c.t0, 0.14);
        span.style.display = 'inline';
        box.style.transform = `scale(${lerp(0.92, 1, p)})`;
        box.style.opacity = clamp(p * 2);
    });
}

// The end card: a painted band, the card with the address, and the fox (end cards only: brand rule).
// head: the line above the address.
function shortEnd(id, t0, end, { plate, head = 'Laske hinnat omalla kulutuksellasi', sub = 'Seuraava katsaus joulukuun alussa.' }) {
    const s = document.getElementById(id);
    s.innerHTML = `<img class="band" src="${plate}" alt="" style="top:200px">
      <div class="abs" id="${id}-card" style="left:90px; top:780px; width:900px; padding:44px 60px 48px; background:var(--paper); clip-path:polygon(10% 0, 90% 0, 100% 7%, 100% 100%, 0 100%, 0 7%)">
        <div style="display:flex; align-items:center; gap:16px">${LOGO(64)}<span style="font:800 72px/1 var(--font-display); letter-spacing:-0.02em">Voltikka</span></div>
        <div class="h-2" style="margin-top:36px; font-size:56px">${head}</div>
        <div class="num" style="font-size:96px; color:var(--color-fox-deep); margin-top:18px">voltikka.fi</div>
        <div class="label" style="margin-top:28px">${sub}</div>
      </div>
      <img class="abs" id="${id}-fox" src="../../../../design/mascot/fox-sit-skyward-v1.png" alt="" style="left:690px; top:460px; width:340px">`;
    drift($('.band', s), t0, end, { from: 1.08, to: 1.0, oy: '60%' });
    dropIn($(`#${id}-card`), t0 + 0.2);
    show($(`#${id}-fox`), t0 + 1.0, { dx: 60, dy: 30, dur: 0.9 });
    tick((t) => { s.style.opacity = 1 - P(t, end - 0.6, 0.6, 'lin'); });
}
