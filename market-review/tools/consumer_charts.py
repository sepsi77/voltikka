"""Consumer layer chart: the monthly bill outlook per home type (small multiples, one shared
y-scale). Usage: python3 -I consumer_charts.py consumer-outlook-2026-11.json chart-2026-11-bill-outlook.svg
"""

import json
import sys

# Same style constants as charts.py (copied, so this script runs with python3 -I).
INK, MUTED, EDGE, GROUND = "#16202E", "#5E6876", "#D9DFDC", "#F5F6F1"
SERIES = ["#1C7A5E", "#535D68"]  # site tokens: cat-spot (aurora), cat-fixed (granite)
FONT = "font-family='Figtree, system-ui, sans-serif'"  # the site font; the page embeds the SVG inline


def svg(w, h, body, title):
    return (f"<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 {w} {h}' width='{w}' height='{h}' {FONT} font-size='12'>"
            f"<title>{title}</title><rect width='{w}' height='{h}' fill='{GROUND}'/>{body}</svg>\n")

GROUPS = [("BE03", "Pientalo, ei sähkölämmitystä (noin 6 800 kWh/v)"), ("BE02", "Sähkölämmitteinen pientalo (noin 11 100 kWh/v)")]
MONTHS = {"01": "tammi", "02": "helmi", "03": "maalis", "04": "huhti", "05": "touko", "06": "kesä",
          "07": "heinä", "08": "elo", "09": "syys", "10": "loka", "11": "marras", "12": "joulu"}


def bill_outlook(o):
    months = o["months"]
    top = max(v["spot_eur_max"] for g, _ in GROUPS for v in o["groups"][g]["monthly"].values())
    top = 50 * (int(top // 50) + 1)
    w, panel_h, l, t, gap = 760, 150, 52, 70, 50
    h = t + len(GROUPS) * (panel_h + gap) + 10
    pw = w - l - 16
    out = [
        f"<text x='{l}' y='22' fill='{INK}' font-size='14' font-weight='600'>Arvioitu sähkölasku kuukaudessa, € (energia + sähkövero, alv 25,5 %)</text>",
        f"<rect x='{l}' y='36' width='12' height='12' rx='2' fill='{SERIES[0]}'/><text x='{l+18}' y='46' fill='{INK}'>pörssisähkö (tyypillinen marginaali ja perusmaksu); viiva = kolmen talven vaihteluväli</text>",
        f"<rect x='{l}' y='52' width='12' height='12' rx='2' fill='{SERIES[1]}'/><text x='{l+18}' y='62' fill='{INK}'>12 kk kiinteä hinta (mediaanihinta)</text>",
    ]
    slot = pw / len(months)
    bw = min(26, slot / 3)
    for gi, (g, label) in enumerate(GROUPS):
        y0 = t + gi * (panel_h + gap) + 18
        y = lambda v: y0 + panel_h - v / top * panel_h
        out.append(f"<text x='{l}' y='{y0 - 4}' fill='{INK}' font-weight='600'>{label}</text>")
        for v in range(0, top + 1, max(50, top // 4 // 50 * 50)):
            out.append(f"<line x1='{l}' x2='{w-16}' y1='{y(v):.1f}' y2='{y(v):.1f}' stroke='{EDGE}'/>"
                       f"<text x='{l-8}' y='{y(v)+4:.1f}' text-anchor='end' fill='{MUTED}'>{v}</text>")
        for i, m in enumerate(months):
            v = o["groups"][g]["monthly"][m]
            cx = l + i * slot + slot / 2
            xs, xf = cx - bw - 1, cx + 1
            for x, val, col, tip in ((xs, v["spot_eur_mean"], SERIES[0], "pörssisähkö"), (xf, v["fixed_eur_mean"], SERIES[1], "12 kk kiinteä")):
                out.append(f"<rect x='{x:.1f}' y='{y(val):.1f}' width='{bw:.1f}' height='{max(y(0) - y(val), 1):.1f}' rx='2' fill='{col}'>"
                           f"<title>{MONTHS[m[5:]]}kuu {m[:4]}, {tip}: {val} €</title></rect>")
            mx = xs + bw / 2
            out.append(f"<line x1='{mx:.1f}' x2='{mx:.1f}' y1='{y(v['spot_eur_min']):.1f}' y2='{y(v['spot_eur_max']):.1f}' stroke='{INK}' stroke-width='1.5'/>")
            out.append(f"<text x='{cx:.1f}' y='{y(0) + 14:.1f}' text-anchor='middle' fill='{MUTED}'>{MONTHS[m[5:]]}</text>")
        sw, sr = o["groups"][g]["seasons"]["winter"], o["groups"][g]["seasons"]["rest"]
        out.append(f"<text x='{w-16}' y='{y0 - 4}' text-anchor='end' fill='{MUTED}'>marras–maalis: pörssi {sw['spot_eur_mean']} €, kiinteä {sw['fixed_eur_mean']} € · huhti–loka: {sr['spot_eur_mean']} € / {sr['fixed_eur_mean']} €</text>")
    return svg(w, h, "".join(out), "Monthly bill outlook by home type")


if __name__ == "__main__":
    data = json.load(open(sys.argv[1]))
    open(sys.argv[2], "w").write(bill_outlook(data))
    print(sys.argv[2])
