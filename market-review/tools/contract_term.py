"""Spot cost over a 24-36-month contract period, for comparison with long fixed contracts.

Usage: python3 -I contract_term.py <consumer-inputs.json> <consumer-outlook.json> <group> <margin c/kWh> <fee EUR/kk>

Year 1 is the consumer_outlook.py monthly spot cost (typical contract). For each month the
consumption-weighted spot premium over that month's future (incl. VAT) is kept, and years 2 and 3
apply it to the later futures (EEX 30.10.2026): Q4-27, Q1..Q3-28, Q4-28 implied from Y2028, and
year 3 scaled by Y2029/Y2028. Fixed contracts keep one price, so compare with their normal price
per year from the engine rows (consumer-rows CSV).
"""

import json
import sys

TAX = 2.918
TYPICAL_MARGIN, TYPICAL_FEE = 0.5, 4.12  # consumer_outlook.py typical spot contract on 2.11.2026
Q = {"Q4_27": 66.46, "Q1_27": 115.87, "Q1_28": 87.33, "Q2_27": 48.26, "Q2_28": 45.4, "Q3_27": 38.46, "Q3_28": 37.8,
     "Y28": 56.89, "Y29": 56.43}  # EUR/MWh settlements 30.10.2026


def main():
    inp, out, grp = json.load(open(sys.argv[1])), json.load(open(sys.argv[2])), sys.argv[3]
    margin, fee = float(sys.argv[4]), float(sys.argv[5])
    f1 = {m: v["future_c_kwh_vat0"] for m, v in next(iter(inp["forward"].values()))["months"].items()}
    q4_26 = (f1["2026-11"] + f1["2026-12"]) / 2 * 10
    q4_28 = 4 * Q["Y28"] - Q["Q1_28"] - Q["Q2_28"] - Q["Q3_28"]
    ratio = {}
    for m in f1:
        mo = int(m[5:])
        ratio[m] = (Q["Q4_27"] / q4_26 if mo in (11, 12) else Q["Q1_28"] / Q["Q1_27"] if mo <= 3
                    else Q["Q2_28"] / Q["Q2_27"] if mo <= 6 else Q["Q3_28"] / Q["Q3_27"] if mo <= 9 else q4_28 / Q["Q4_27"])
    years = [0.0, 0.0, 0.0]
    for m, v in out["groups"][grp]["monthly"].items():
        kwh = v["kwh_mean"]
        weighted = (v["spot_eur_mean"] - TYPICAL_FEE) / kwh * 100 - TYPICAL_MARGIN - TAX
        premium = weighted - f1[m] * 1.255
        for y, fut in enumerate((f1[m], f1[m] * ratio[m], f1[m] * ratio[m] * Q["Y29"] / Q["Y28"])):
            years[y] += kwh * (fut * 1.255 + premium + margin + TAX) / 100 + fee
    print(grp, "spot EUR per year incl. electricity tax:", [round(y) for y in years])


if __name__ == "__main__":
    main()
