"""Prototype SVG charts from metrics-YYYY-MM.json (no dependencies). Usage: python3 -I charts.py 2026-09"""

import json
import sys
from datetime import date
from pathlib import Path

INK, MUTED, EDGE, GROUND = "#16202E", "#5E6876", "#D9DFDC", "#F5F6F1"
SERIES = ["#2B5F8C", "#C4501C", "#6A4C9C"]  # lake, fox, violet; direct labels on every line
FONT = "font-family='Inter, system-ui, sans-serif'"
WEEKDAYS = ["ma", "ti", "ke", "to", "pe", "la", "su"]


def fi(x, n=1):
    return f"{x:.{n}f}".replace(".", ",")


def svg(w, h, body, title):
    return (f"<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 {w} {h}' width='{w}' height='{h}' {FONT} font-size='12'>"
            f"<title>{title}</title><rect width='{w}' height='{h}' fill='{GROUND}'/>{body}</svg>\n")


def daily_bars(m):
    days = m["spot"]["daily_means_c_kwh_vat"]
    mean = m["spot"]["mean_c_kwh_vat"]
    w, h, l, t, b = 760, 300, 48, 40, 36
    pw, ph = w - l - 16, h - t - b
    top = max(5 * ((max(days.values()) // 5) + 1), 5)
    y = lambda v: t + ph - v / top * ph
    bw = pw / len(days)
    out = [f"<text x='{l}' y='22' fill='{INK}' font-size='14' font-weight='600'>Pörssisähkön vuorokausikeskihinta, c/kWh (alv 25,5 %)</text>"]
    for v in range(0, int(top) + 1, 5):
        out.append(f"<line x1='{l}' x2='{w-16}' y1='{y(v)}' y2='{y(v)}' stroke='{EDGE}'/>"
                   f"<text x='{l-8}' y='{y(v)+4}' text-anchor='end' fill='{MUTED}'>{v}</text>")
    for i, (d, v) in enumerate(days.items()):
        x = l + i * bw + 1
        hh = max(y(0) - y(v), 1)
        out.append(f"<rect x='{x:.1f}' y='{y(v):.1f}' width='{bw-2:.1f}' height='{hh:.1f}' rx='2' fill='{SERIES[0]}'><title>{d}: {fi(v, 2)} c/kWh</title></rect>")
        dd = date.fromisoformat(d)
        if dd.day in (1, 8, 15, 22, 29):
            out.append(f"<text x='{x + bw/2:.1f}' y='{h-14}' text-anchor='middle' fill='{MUTED}'>{dd.day}.{dd.month}.</text>")
    out.append(f"<line x1='{l}' x2='{w-16}' y1='{y(mean)}' y2='{y(mean)}' stroke='{INK}' stroke-width='1.5' stroke-dasharray='5 4'/>"
               f"<text x='{w-16}' y='22' text-anchor='end' fill='{MUTED}'>katkoviiva = kuukauden keskihinta {fi(mean, 2)}</text>")
    return svg(w, h, "".join(out), "Daily mean spot price")


def heatmap(m):
    cells = m["spot"]["heatmap_weekday_hour_c_kwh_vat"]
    w, h, l, t = 760, 250, 40, 40
    cw, ch = (w - l - 16) / 24, 24
    vmax = max(cells.values())
    lo, hi = (0xE4, 0xEA, 0xF0), (0x14, 0x21, 0x3A)  # season-ground-talvi -> night, one hue light->dark

    def colour(v):
        k = max(0.0, min(1.0, v / vmax))
        return "#" + "".join(f"{round(a + (b_ - a) * k):02X}" for a, b_ in zip(lo, hi))

    out = [f"<text x='{l}' y='22' fill='{INK}' font-size='14' font-weight='600'>Keskihinta viikonpäivän ja tunnin mukaan, c/kWh (alv 25,5 %)</text>"]
    for wd in range(7):
        out.append(f"<text x='{l-8}' y='{t + wd*ch + 16}' text-anchor='end' fill='{MUTED}'>{WEEKDAYS[wd]}</text>")
        for hr in range(24):
            v = cells.get(f"{wd}-{hr}")
            if v is None:
                continue
            x, yy = l + hr * cw, t + wd * ch
            out.append(f"<rect x='{x:.1f}' y='{yy}' width='{cw-2:.1f}' height='{ch-2}' rx='2' fill='{colour(v)}'><title>{WEEKDAYS[wd]} klo {hr}: {fi(v, 2)} c/kWh</title></rect>")
    for hr in range(0, 24, 3):
        out.append(f"<text x='{l + hr*cw + cw/2:.1f}' y='{t + 7*ch + 16}' text-anchor='middle' fill='{MUTED}'>{hr}</text>")
    out.append(f"<text x='{w-16}' y='{t + 7*ch + 34}' text-anchor='end' fill='{MUTED}'>tunti (Suomen aikaa) · vaalea = halpa, tumma = kallis (max {fi(vmax)} c/kWh)</text>")
    return svg(w, h, "".join(out), "Spot price by weekday and hour")


def fixed_lines(m):
    series = m["contracts"]["daily_fixed_medians"]
    labels = {"fixed_term_6": "6 kk", "fixed_term_12": "12 kk", "fixed_term_24": "24 kk"}
    vals = [v for s in series.values() for v in s.values() if v is not None]
    vmin, vmax = int(min(vals)) - 1, int(max(vals)) + 1
    w, h, l, t, b, rpad = 760, 300, 48, 40, 36, 100
    pw, ph = w - l - rpad, h - t - b
    dates = sorted({d for s in series.values() for d in s})
    x = lambda i: l + i / (len(dates) - 1) * pw
    y = lambda v: t + ph - (v - vmin) / (vmax - vmin) * ph
    out = [f"<text x='{l}' y='22' fill='{INK}' font-size='14' font-weight='600'>Tarjolla olevien määräaikaisten sopimusten energiahinnan mediaani, c/kWh (alv 25,5 %)</text>"]
    for v in range(vmin, vmax + 1):
        out.append(f"<line x1='{l}' x2='{l+pw}' y1='{y(v)}' y2='{y(v)}' stroke='{EDGE}'/><text x='{l-8}' y='{y(v)+4}' text-anchor='end' fill='{MUTED}'>{v}</text>")
    for i, d in enumerate(dates):
        dd = date.fromisoformat(d)
        if dd.day in (1, 8, 15, 22, 29):
            out.append(f"<text x='{x(i):.1f}' y='{h-14}' text-anchor='middle' fill='{MUTED}'>{dd.day}.{dd.month}.</text>")
    for colour, (key, s) in zip(SERIES, series.items()):
        pts = [(x(dates.index(d)), y(v)) for d, v in sorted(s.items()) if v is not None]
        out.append(f"<polyline fill='none' stroke='{colour}' stroke-width='2' stroke-linejoin='round' points='{' '.join(f'{a:.1f},{c:.1f}' for a, c in pts)}'/>")
        last = sorted(s.items())[-1][1]
        out.append(f"<circle cx='{pts[-1][0]:.1f}' cy='{pts[-1][1]:.1f}' r='4' fill='{colour}' stroke='{GROUND}' stroke-width='2'/>"
                   f"<text x='{pts[-1][0]+8:.1f}' y='{pts[-1][1]+4:.1f}' fill='{INK}'>{labels[key]} {fi(last, 2)}</text>")
    return svg(w, h, "".join(out), "Median fixed-term energy price")


def main():
    month = sys.argv[1]
    here = Path(__file__).parent
    m = json.loads((here / f"metrics-{month}.json").read_text())
    for name, fn in [("daily-price", daily_bars), ("heatmap", heatmap), ("fixed-medians", fixed_lines)]:
        (here / f"chart-{month}-{name}.svg").write_text(fn(m))


if __name__ == "__main__":
    main()
