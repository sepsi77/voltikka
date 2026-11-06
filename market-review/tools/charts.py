"""Prototype SVG charts from metrics-YYYY-MM.json and, when present, drivers-YYYY-MM.json (no dependencies).
Usage: python3 -I charts.py 2026-10"""

import json
import sys
from datetime import date
from pathlib import Path

INK, MUTED, EDGE, GROUND = "#16202E", "#5E6876", "#D9DFDC", "#F5F6F1"
SERIES = ["#2B5F8C", "#C4501C", "#6A4C9C"]  # lake, fox, violet; direct labels on every line
SPOT, WIND, WARM, COLD = "#1C7A5E", "#535D68", "#C4501C", "#2B5F8C"  # site tokens: aurora (cat-spot), granite, fox, lake
FONT = "font-family='Figtree, system-ui, sans-serif'"  # the site font; the page embeds the SVG inline
WEEKDAYS = ["ma", "ti", "ke", "to", "pe", "la", "su"]


def fi(x, n=1):
    return f"{x:.{n}f}".replace(".", ",")


def svg(w, h, body, title):
    return (f"<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 {w} {h}' width='{w}' height='{h}' {FONT} font-size='12'>"
            f"<title>{title}</title><rect width='{w}' height='{h}' fill='{GROUND}'/>{body}</svg>\n")


def daily_bars(m, d=None):
    """Daily price bars. With drivers data, two panels below on the same x axis: wind GWh and temperature anomaly."""
    days = m["spot"]["daily_means_c_kwh_vat"]
    mean = m["spot"]["mean_c_kwh_vat"]
    panels = d is not None
    w, l, t, ph = 760, 48, 40, 224
    h = 550 if panels else 300
    pw = w - l - 16
    top = max(5 * ((max(days.values()) // 5) + 1), 5)
    y = lambda v: t + ph - v / top * ph
    bw = pw / len(days)
    xs = {day: l + i * bw for i, day in enumerate(days)}
    out = [f"<text x='{l}' y='22' fill='{INK}' font-size='14' font-weight='600'>Pörssisähkön vuorokausikeskihinta, c/kWh (alv 25,5 %)</text>"]
    for v in range(0, int(top) + 1, 5):
        out.append(f"<line x1='{l}' x2='{w-16}' y1='{y(v)}' y2='{y(v)}' stroke='{EDGE}'/>"
                   f"<text x='{l-8}' y='{y(v)+4}' text-anchor='end' fill='{MUTED}'>{v}</text>")
    for day, v in days.items():
        x = xs[day] + 1
        hh = max(y(0) - y(v), 1)
        out.append(f"<rect x='{x:.1f}' y='{y(v):.1f}' width='{bw-2:.1f}' height='{hh:.1f}' rx='2' fill='{SPOT}'><title>{day}: {fi(v, 2)} c/kWh</title></rect>")
    out.append(f"<line x1='{l}' x2='{w-16}' y1='{y(mean)}' y2='{y(mean)}' stroke='{INK}' stroke-width='1.5' stroke-dasharray='5 4'/>"
               f"<text x='{w-16}' y='22' text-anchor='end' fill='{MUTED}'>katkoviiva = kuukauden keskihinta {fi(mean, 2)}</text>")
    if panels:
        out += wind_panel(d["wind"]["daily_gwh"], xs, bw, l, w, 290, 300, 90)
        temp = d["temperature"][m["month"]]
        out += anomaly_panel(temp["daily_anomaly_c"], temp["missing_days"], xs, bw, l, w, 416, 426, 90)
    for day in days:
        dd = date.fromisoformat(day)
        if dd.day in (1, 8, 15, 22, 29):
            out.append(f"<text x='{xs[day] + bw/2:.1f}' y='{h-14}' text-anchor='middle' fill='{MUTED}'>{dd.day}.{dd.month}.</text>")
    return svg(w, h, "".join(out), "Daily mean spot price" + (", wind power and temperature anomaly" if panels else ""))


def wind_panel(series, xs, bw, l, w, label_y, t, ph):
    top = max(50 * (int(max(series.values()) // 50) + 1), 50)
    y = lambda v: t + ph - v / top * ph
    out = [f"<text x='{l}' y='{label_y}' fill='{INK}' font-weight='600'>Tuulivoiman tuotanto, GWh vuorokaudessa</text>"]
    for v in range(0, top + 1, 50):
        out.append(f"<line x1='{l}' x2='{w-16}' y1='{y(v)}' y2='{y(v)}' stroke='{EDGE}'/>"
                   f"<text x='{l-8}' y='{y(v)+4}' text-anchor='end' fill='{MUTED}'>{v}</text>")
    for day, v in series.items():
        if day not in xs:
            continue
        hh = max(y(0) - y(v), 1)
        out.append(f"<rect x='{xs[day] + 1:.1f}' y='{y(v):.1f}' width='{bw-2:.1f}' height='{hh:.1f}' rx='2' fill='{WIND}'><title>{day}: {fi(v)} GWh</title></rect>")
    return out


def anomaly_panel(series, missing, xs, bw, l, w, label_y, t, ph):
    peak = max(abs(v) for v in series.values())
    step = 5 if peak > 6 else 2
    lim = step * (int(peak // step) + 1)
    y = lambda v: t + ph / 2 - v / lim * ph / 2
    note = f" · {', '.join(f'{date.fromisoformat(x).day}.{date.fromisoformat(x).month}.' for x in missing)} puuttuu (ERA5 viive)" if missing else ""
    out = [f"<text x='{l}' y='{label_y}' fill='{INK}' font-weight='600'>Lämpötila vs. 1991–2020 normaali, °C</text>"
           f"<text x='{w-16}' y='{label_y}' text-anchor='end' fill='{MUTED}'>oranssi = lämpimämpi, sininen = kylmempi{note}</text>"]
    for v in range(-lim, lim + 1, step):
        out.append(f"<line x1='{l}' x2='{w-16}' y1='{y(v)}' y2='{y(v)}' stroke='{INK if v == 0 else EDGE}'/>"
                   f"<text x='{l-8}' y='{y(v)+4}' text-anchor='end' fill='{MUTED}'>{'+' if v > 0 else ''}{v}</text>")
    for day, v in series.items():
        if day not in xs:
            continue
        top, bottom = (y(v), y(0)) if v >= 0 else (y(0), y(v))
        colour = WARM if v >= 0 else COLD
        out.append(f"<rect x='{xs[day] + 1:.1f}' y='{top:.1f}' width='{bw-2:.1f}' height='{max(bottom - top, 1):.1f}' rx='2' fill='{colour}'><title>{day}: {'+' if v > 0 else ''}{fi(v)} °C</title></rect>")
    return out


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
    here = Path(__file__).resolve().parents[1] / "editions" / month  # market-review/editions/<month>/
    m = json.loads((here / f"metrics-{month}.json").read_text())
    drivers = here / f"drivers-{month}.json"  # optional: wind and temperature panels under the price chart
    d = json.loads(drivers.read_text()) if drivers.exists() else None
    for name, fn in [("daily-price", lambda x: daily_bars(x, d)), ("heatmap", heatmap), ("fixed-medians", fixed_lines)]:
        (here / f"chart-{month}-{name}.svg").write_text(fn(m))


if __name__ == "__main__":
    main()
