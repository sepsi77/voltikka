#!/usr/bin/env python3
"""Paired comparison of S backtest candidates, with the experiment decision rule.

Reads one or more prediction files (data/s-predictions-<stamp>[-<tag>].csv.gz, columns
<model>_p10/_p50/_p90) and compares a CANDIDATE with a BASELINE on the same rows: the rows where
the reference forecast (c_thirdparty) and both models have a p50 ("thirdparty rows").

Periods (fixed 2026-11-02, before the experiments):
  DEV  = issues 2024-07-01 .. 2025-06-30 (decide here)
  HOLD = issues 2025-07-01 .. end        (confirm here)
Decision rule (fixed 2026-11-02):
  KEEP when the MAE difference (candidate - baseline) is < 0 on DEV with the upper bound of its
  90 % bootstrap interval < 0 (resampling issue days), AND the HOLD difference is <= 0, AND the
  pinball loss of the calibrated range (*_scal) is not worse by more than 0.1 on DEV + HOLD.
  Otherwise DISCARD. A change that only helps one horizon group can be kept for that group
  only, if the same rule holds inside that group (report per horizon).

A model can be a horizon split: "A@1-4+B@5-7" takes A for D+1..D+4 and B for D+5..D+7, and a
blend: "A|B|0.3" is 0.7 A + 0.3 B (p50 and the calibrated range bounds).

Usage: python3 -I s_compare.py [--k=5-7] BASELINE CANDIDATE file [file ...]
       (a model name is looked up in every file; give the file that holds it)
"""
import sys
from datetime import date

import numpy as np
import pandas as pd

DEV = (date(2024, 7, 1), date(2025, 7, 1))
HOLD = (date(2025, 7, 1), date(2100, 1, 1))


def parts(spec):
    """'A@1-4+B@5-7' -> [('A', 1, 4), ('B', 5, 7)]; a plain name covers every horizon.
    'A#2' is model A from the 2nd prediction file (the same name in two runs)."""
    out = []
    for piece in spec.split("+"):
        name, _, ks = piece.partition("@")
        lo, _, hi = (ks or "1-7").partition("-")
        out.append((name, int(lo), int(hi or lo)))
    return out


def base_names(name):
    """'A|B|0.3' (a blend: 0.7 A + 0.3 B) -> ['A', 'B']; a plain name -> [name]."""
    return name.split("|")[:2] if "|" in name else [name]  # 'A|B|w[|shift]' -> [A, B]


def blend_col(df, name, suffix):
    """'A|B|w': every column blended. 'A|B|w|shift' (E12b): p50 blended; the range bounds are A's,
    moved by the same p50 change."""
    if "|" not in name:
        return df[f"{name}{suffix}"] if f"{name}{suffix}" in df else None
    a, b, w, *mode = name.split("|")
    w = float(w)
    if mode == ["shift"] and suffix != "_p50":
        if f"{a}{suffix}" not in df or f"{b}_p50" not in df:
            return None
        return df[f"{a}{suffix}"] + w * (df[f"{b}_p50"] - df[f"{a}_p50"])
    if f"{a}{suffix}" not in df or f"{b}{suffix}" not in df:
        return None
    return (1 - w) * df[f"{a}{suffix}"] + w * df[f"{b}{suffix}"]


def compose(df, spec):
    """A horizon-split and/or blended model: columns spec_p50/_scal_p10/_scal_p90."""
    if "@" not in spec and "|" not in spec:
        return
    for suffix in ("_p50", "_scal_p10", "_scal_p90"):
        col = pd.Series(np.nan, index=df.index)
        for name, lo, hi in parts(spec):
            src = blend_col(df, name, suffix)
            if src is not None:
                m = df["k"].between(lo, hi)
                col[m] = src[m]
        df[f"{spec}{suffix}"] = col


def load(files, specs):
    names = sorted({b for spec in specs for n, _, _ in parts(spec) for b in base_names(n)})
    frames = {}
    keys = ["issue", "target_ts"]
    base = None
    for fi, f in enumerate(files, 1):
        df = pd.read_csv(f)
        if base is None:
            base = df[keys + ["k", "y", "c_thirdparty_p50"]].copy()
        for n in names:
            src, _, only = n.partition("#")
            if only and int(only) != fi:
                continue
            cols = [f"{src}_p50", f"{src}_scal_p10", f"{src}_scal_p90"]
            if f"{src}_p50" in df and n not in frames:
                have = [c for c in cols if c in df]
                frames[n] = df[keys + have].rename(columns={c: n + c[len(src):] for c in have})
    missing = [n for n in names if n not in frames]
    if missing:
        raise SystemExit(f"not found in the files: {missing}")
    out = base
    for n, fr in frames.items():
        extra = [c for c in fr.columns if c in keys or c not in out.columns]
        out = out.merge(fr[extra], on=keys, how="inner")
    out["issue"] = pd.to_datetime(out["issue"]).dt.date
    for spec in specs:
        compose(out, spec)
    return out


def pinball(y, p10, p50, p90):
    def one(f, q):
        d = y - f
        return np.maximum(q * d, (q - 1) * d)
    return (one(p10, 0.1) + one(p50, 0.5) + one(p90, 0.9)) / 3


def boot_ci(sums, counts, n=2000, seed=0):
    """90 % interval of the row-weighted mean, resampling whole issue days."""
    rng = np.random.default_rng(seed)
    idx = rng.integers(0, len(sums), (n, len(sums)))
    means = sums[idx].sum(axis=1) / counts[idx].sum(axis=1)
    return float(np.quantile(means, 0.05)), float(np.quantile(means, 0.95))


def main():
    args = sys.argv[1:]
    k_range = None
    if args and args[0].startswith("--k="):
        lo, _, hi = args.pop(0)[4:].partition("-")
        k_range = (int(lo), int(hi or lo))
    baseline, cand, files = args[0], args[1], args[2:]
    df = load(files, [baseline, cand])
    if k_range:
        df = df[df["k"].between(*k_range)]
    df = df[df["c_thirdparty_p50"].notna() & df[f"{baseline}_p50"].notna() & df[f"{cand}_p50"].notna()]
    df["d_abs"] = (df[f"{cand}_p50"] - df["y"]).abs() - (df[f"{baseline}_p50"] - df["y"]).abs()
    has_range = all(f"{m}_scal_p{q}" in df for m in (baseline, cand) for q in (10, 90))
    if has_range:
        df["d_pin"] = (pinball(df["y"], df[f"{cand}_scal_p10"], df[f"{cand}_p50"], df[f"{cand}_scal_p90"])
                       - pinball(df["y"], df[f"{baseline}_scal_p10"], df[f"{baseline}_p50"], df[f"{baseline}_scal_p90"]))
    print(f"{cand} vs {baseline} on thirdparty rows")
    verdict = {}
    for label, (a, b) in (("DEV", DEV), ("HOLD", HOLD)):
        s = df[(df["issue"] >= a) & (df["issue"] < b)]
        g = s.groupby("issue")["d_abs"]
        lo, hi = boot_ci(g.sum().to_numpy(), g.count().to_numpy())
        base_mae = (s[f"{baseline}_p50"] - s["y"]).abs().mean()
        line = (f"  {label:4} days {s['issue'].nunique():4}  baseline MAE {base_mae:6.2f}  diff {s['d_abs'].mean():+6.2f}"
                f"  90% CI [{lo:+.2f}, {hi:+.2f}]")
        if has_range:
            line += f"  pinball diff {s['d_pin'].mean():+.3f}"
        print(line)
        by_k = s.groupby("k")["d_abs"].mean()
        print("        per horizon: " + " ".join(f"D+{k} {v:+.2f}" for k, v in by_k.items()))
        verdict[label] = (s["d_abs"].mean(), hi, s["d_pin"].mean() if has_range else 0.0)
    dev, hold = verdict["DEV"], verdict["HOLD"]
    # Pinball on DEV + HOLD rows only (rows before DEV are early blocks, not decision periods).
    in_periods = df["issue"] >= DEV[0]
    pin_ok = (df.loc[in_periods, "d_pin"].mean() if has_range else 0.0) <= 0.1
    keep = dev[0] < 0 and dev[1] < 0 and hold[0] <= 0 and pin_ok
    print(f"  DECISION: {'KEEP' if keep else 'DISCARD'}"
          f" (dev diff<0 and CI<0: {dev[0] < 0 and dev[1] < 0}; hold<=0: {hold[0] <= 0}; pinball ok: {pin_ok})")


if __name__ == "__main__":
    main()
