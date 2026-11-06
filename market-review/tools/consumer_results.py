"""Winners and cost of certainty from run_consumer.php rows. Usage: python3 -I consumer_results.py <rows.csv> <out.json>

Rules (prototype of the public winner rules, decisions.md 2026-11-01):
- national availability, the contract's annual kWh limits fit the home type, priced by the engine;
- ranked by the normal-price cost (promotions ignored);
- coming months: the mean over the three weather analogs (the contract must price in all three);
- names with restriction words are flagged for a manual terms check (existing customers, members,
  students, business), and so are names with promotion words whose normal price equals the actual
  price (the normal price is not known); a flagged contract cannot win until its terms are checked;
- the overall winner excludes consumption-effect contracts (the engine prices the base only; the
  seller does not quantify the effect) and, for the coming months, market-reset contracts (the
  period engine holds the current reset price flat; it never projects resets for a bill period).
  Their categories are shown with that note.
Energy cost only plus the electricity tax line; network fees are not included.
"""

import csv
import json
import re
import statistics as st
import sys
from collections import defaultdict

TAX_C_KWH = 2.918  # class I incl. VAT 25.5 % from 2026-04-01; network price lists, verify on vero.fi
GROUP_NAMES = {"BE01": "kerrostaloasunto", "BE03": "pientalo, ei sähkölämmitystä", "BE02": "sähkölämmitteinen pientalo"}
BUCKETS = ["spot", "fixed_term", "fixed_open_ended", "market_reset", "consumption_effect"]
RESTRICTED = re.compile(r"kanta-?asiak|opiskelij|yrity|jäsen|osakas|henkilöstö|työntekijä|asukas", re.I)
PROMO = re.compile(r"tarjous|ensimmäi|kampanja|etu\b|alennus|0 ?€", re.I)
NOT_OVERALL = {"past": {"consumption_effect"}, "forward": {"consumption_effect", "market_reset"}}


def main():
    rows = [r for r in csv.DictReader(open(sys.argv[1])) if r["available"] == "1" and r["national"] == "1" and r["fits_limits"] == "1"]
    for r in rows:
        r["normal"] = float(r["normal_eur"])
        r["actual"] = float(r["actual_eur"])
        r["kwh"] = float(r["kwh"])
        r["flag"] = bool(RESTRICTED.search(r["name"])) or (bool(PROMO.search(r["name"])) and abs(r["normal"] - r["actual"]) < 0.005)

    out = {"tax_c_kwh_incl_vat": TAX_C_KWH, "groups": {}}
    for g, label in GROUP_NAMES.items():
        res = {"label": label}
        # Past month.
        past = sorted((r for r in rows if r["group"] == g and r["scenario"].startswith("past_")), key=lambda r: r["normal"])
        res["past"] = summary(past, past[0]["kwh"] if past else 0, "past")
        # Coming 12 months: mean over the analogs.
        by_contract = defaultdict(list)
        kwh = {}
        for r in rows:
            if r["group"] == g and r["scenario"].startswith("forward_"):
                by_contract[r["contract_id"]].append(r)
                kwh[r["scenario"]] = r["kwh"]
        n_scen = len(kwh)
        merged = []
        for cid, lst in by_contract.items():
            if len(lst) != n_scen:
                continue
            m = dict(lst[0])
            m["normal"] = st.mean(x["normal"] for x in lst)
            m["actual"] = st.mean(x["actual"] for x in lst)
            m["normal_by_scenario"] = {x["scenario"]: x["normal"] for x in lst}
            merged.append(m)
        merged.sort(key=lambda r: r["normal"])
        res["forward"] = summary(merged, st.mean(kwh.values()), "forward")
        res["forward"]["kwh_by_scenario"] = kwh
        # Cost of certainty: median fixed-term vs median spot, per scenario.
        cert = {}
        for scen in kwh:
            spot = [r["normal"] for r in rows if r["group"] == g and r["scenario"] == scen and r["bucket"] == "spot"]
            fixed = [r["normal"] for r in rows if r["group"] == g and r["scenario"] == scen and r["bucket"] == "fixed_term"]
            cert[scen] = {"median_spot": round(st.median(spot), 0), "median_fixed_term": round(st.median(fixed), 0), "fixed_minus_spot": round(st.median(fixed) - st.median(spot), 0)}
        res["forward"]["certainty"] = cert
        out["groups"][g] = res

    with open(sys.argv[2], "w") as f:
        json.dump(out, f, ensure_ascii=False, indent=1)

    for g, res in out["groups"].items():
        for part in ("past", "forward"):
            s = res[part]
            print(f"\n== {res['label']} {part} ({s['kwh']:.0f} kWh, +{s['tax_eur']:.0f} € tax) n={s['n']}")
            for w in s["overall"]:
                print(f"   overall {w['normal']:8.2f} € {w['bucket']:18s} {w['company']} – {w['name']}")
            for b, c in s["categories"].items():
                print(f"   {b:18s}{'' if c['in_overall'] else '*'} n={c['n']:3d} best {c['best']['normal']:8.2f} median {c['median']:8.2f}  {c['best']['company']} – {c['best']['name']}")
            if s["flagged_skipped"]:
                print("   skipped (terms check):", "; ".join(s["flagged_skipped"]))
        for scen, c in res["forward"]["certainty"].items():
            print(f"   certainty {scen}: spot {c['median_spot']:.0f} fixed {c['median_fixed_term']:.0f} -> {c['fixed_minus_spot']:+.0f} €")


def pick(r):
    return {k: r[k] for k in ("contract_id", "company", "name", "bucket", "contract_type", "fixed_time_range", "pricing_model")} | {"normal": round(r["normal"], 2), "actual": round(r["actual"], 2)}


def summary(ranked, kwh, part):
    eligible = [r for r in ranked if r["bucket"] not in NOT_OVERALL[part]]
    clean = [r for r in eligible if not r["flag"]]
    skipped = []
    for r in eligible:
        if r["flag"]:
            skipped.append(f"{r['company']} – {r['name']}")
        else:
            break
    cats = {}
    for b in BUCKETS:
        lst = [r for r in ranked if r["bucket"] == b]
        ok = [r for r in lst if not r["flag"]]
        if ok:
            cats[b] = {"n": len(lst), "best": pick(ok[0]), "median": round(st.median(r["normal"] for r in lst), 2), "in_overall": b not in NOT_OVERALL[part]}
    return {"n": len(ranked), "kwh": kwh, "tax_eur": round(kwh * TAX_C_KWH / 100, 2), "overall": [pick(r) for r in clean[:3]], "categories": cats, "flagged_skipped": skipped}


if __name__ == "__main__":
    main()
