"""Voltikka seller-set energy price index chart (overall + families), read-only.
Usage: python3 -I index_chart.py <sqlite> <until YYYY-MM-DD> <out.svg>
"""

import sqlite3
import sys
from datetime import date

INK, MUTED, EDGE, GROUND = "#16202E", "#5E6876", "#D9DFDC", "#F5F6F1"
SERIES = ["#535D68", "#C4501C", "#2B5F8C"]  # site tokens: granite (cat-fixed), fox, lake (cat-periodic)
FONT = "font-family='Figtree, system-ui, sans-serif'"  # the site font; the page embeds the SVG inline
LINES = [
    ("seller_set_overall", "Hintaindeksi", INK, 3),
    ("seller_set_open_ended", "Toistaiseksi voimassa", SERIES[1], 2),
    ("seller_set_market_reset", "Kvartaali ja kuukausi", SERIES[2], 2),
    ("seller_set_fixed_term", "Määräaikaiset", SERIES[0], 2),
]
MONTHS = ["tammi", "helmi", "maalis", "huhti", "touko", "kesä", "heinä", "elo", "syys", "loka", "marras", "joulu"]


def main():
    db = sqlite3.connect(f"file:{sys.argv[1]}?mode=ro", uri=True)
    until, out = sys.argv[2], sys.argv[3]
    data = {}
    for seg, *_ in LINES:
        rows = db.execute(
            "select stat_date, avg_value from contract_price_daily_statistics where metric_key='seller_set_energy_price_index_v1' "
            "and segment_key=? and consumption_kwh is null and avg_value is not null and stat_date<=? order by stat_date",
            (seg, until),
        ).fetchall()
        data[seg] = [(date.fromisoformat(d), v) for d, v in rows]
    d0 = min(p[0][0] for p in data.values())
    d1 = max(p[-1][0] for p in data.values())
    w, h, l, r, t, b = 760, 320, 44, 182, 44, 30
    pw, ph = w - l - r, h - t - b
    lo, hi = 6, 16
    x = lambda d: l + (d - d0).days / (d1 - d0).days * pw
    y = lambda v: t + ph - (v - lo) / (hi - lo) * ph
    o = [f"<text x='{l}' y='22' fill='{INK}' font-size='14' font-weight='600'>Voltikan hintaindeksi: myyjien asettamat energiahinnat, c/kWh (alv 25,5 %)</text>"]
    for v in range(lo, hi + 1, 2):
        o.append(f"<line x1='{l}' x2='{l+pw}' y1='{y(v):.1f}' y2='{y(v):.1f}' stroke='{EDGE}'/><text x='{l-8}' y='{y(v)+4:.1f}' text-anchor='end' fill='{MUTED}'>{v}</text>")
    for m in range(d0.month + 1, d1.month + 1):
        dm = date(d0.year, m, 1)
        o.append(f"<text x='{x(dm):.1f}' y='{h-10}' text-anchor='middle' fill='{MUTED}'>{MONTHS[m-1]}</text>")
    labels = []
    for seg, name, col, sw in LINES:
        pts = data[seg]
        # Missing days (for example 2026-10-01) break the line instead of joining across the gap.
        path, prev = [], None
        for d, v in pts:
            cmd = "M" if prev is None or (d - prev).days > 1 else "L"
            path.append(f"{cmd}{x(d):.1f},{y(v):.1f}")
            prev = d
        o.append(f"<path d='{' '.join(path)}' fill='none' stroke='{col}' stroke-width='{sw}' stroke-linejoin='round'/>")
        labels.append([y(pts[-1][1]), name, pts[-1][1], col])
    labels.sort()
    for i in range(1, len(labels)):  # keep 14 px between direct labels
        labels[i][0] = max(labels[i][0], labels[i - 1][0] + 14)
    for ly, name, v, col in labels:
        val = f"{v:.1f}".replace(".", ",")
        o.append(f"<text x='{l+pw+8}' y='{ly+4:.1f}' fill='{INK}'><tspan fill='{col}' font-weight='600'>■</tspan> {name} {val}</text>")
    svg = (f"<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 {w} {h}' width='{w}' height='{h}' {FONT} font-size='12'>"
           f"<title>Voltikan hintaindeksi</title><rect width='{w}' height='{h}' fill='{GROUND}'/>{''.join(o)}</svg>\n")
    open(out, "w").write(svg)
    print(out, {s: (p[0], p[-1]) for s, p in data.items()})


if __name__ == "__main__":
    main()
