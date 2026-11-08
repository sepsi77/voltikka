"""Command line of the S model code (contract: tasks/temporal-orchestration/s-workflow-interface.md).

  python -m voltikka_forecast.s predict --issue-date=D --morning-cut=<UTC ISO> --afternoon-cut=<UTC ISO>
  python -m voltikka_forecast.s score --target-date=T
  python -m voltikka_forecast.s train --as-of=<UTC ISO>
  python -m voltikka_forecast.s activate --candidate=<version>
One-time setup (production writes; each needs the user's approval):
  python -m voltikka_forecast.s seed-misses --e30=<E30 predictions .csv.gz> --e32=<E32 predictions .csv.gz>
  python -m voltikka_forecast.s upload-archive --dir=<folder with pins.json and the archive files>

Each command prints one JSON object as its last stdout line and exits 0 on success. Progress
lines go before it. No secrets and no payloads are printed.
"""
import os

os.environ.setdefault("OMP_NUM_THREADS", "2")

import argparse
import json
import sys
import time
from datetime import date, datetime, timedelta, timezone
from pathlib import Path

import pandas as pd

from . import artifacts as A
from . import core as C
from . import data as D
from . import store as S
from . import v1

MODEL = v1.MODEL
LIVE_HISTORY_DAYS = 40


class Fail(Exception):
    """A failure with a closed key (the Temporal activity passes only the key on)."""

    def __init__(self, key, detail):
        super().__init__(detail)
        self.key = key


# Closed error keys of the last JSON line; the detail goes to an earlier line (the container log).
ERROR_KEYS = ((v1.InputMissing, "input_missing"), (S.PricesIncomplete, "prices_incomplete"),
              (A.ArtifactMismatch, "artifact_mismatch"), (v1.WrongVersion, "version_mismatch"))


def _utc(text):
    t = datetime.fromisoformat(text.replace("Z", "+00:00"))
    if t.tzinfo is None:
        raise Fail("bad_argument", f"{text}: a UTC time with an offset is needed")
    return t.astimezone(timezone.utc)


def _cuts(issue):
    """The frozen cuts of s_v1 (core.issue_time of both views) as UTC datetimes."""
    out = []
    for after in (False, True):
        C.ISSUE["after_prices"] = after
        out.append(datetime.fromtimestamp(C.issue_time(issue)[0], timezone.utc))
    C.ISSUE["after_prices"] = False
    return out


def _inputs(data, issue):
    """What the vintage used (latest stored time or count per input), for the vintage record."""
    fi = data["spot"][(data["spot"]["region"] == "FI") & data["spot"]["price_without_tax"].notna()]
    fg = data["fingrid"]
    return {
        "fi_last_price_utc": S.utc_text(fi["timestamp"].max()),
        "fingrid_last_utc": {int(k): S.utc_text(v) for k, v in fg.groupby("dataset_id")["timestamp"].max().items()},
        "outage_revisions": int(len(data["outages"])), "outage_revisions_se3": int(len(data["outages_se3"])),
        "futures_last_trade_date": str(data["futures"]["trade_date"].max()),
        "border_capacity_last_utc": S.utc_text(data["border_capacity"]["timestamp"].max()),
        "ecmwf_00z_run": f"{issue} 00:00:00",
        "ecmwf_00z_hours": int((data["weather_single_runs"]["issue_date"] == issue.isoformat()).sum()),
        "prev_day1_south_last_utc": S.utc_text(data["weather_previous_runs"]["target_ts"].max()),
    }


def predict(args, db):
    issue = date.fromisoformat(args.issue_date)
    morning, afternoon = _utc(args.morning_cut), _utc(args.afternoon_cut)
    if (morning, afternoon) != tuple(_cuts(issue)):
        raise Fail("cuts_differ", f"cuts differ from the frozen s_v1 cuts {[c.isoformat() for c in _cuts(issue)]}")
    started = S.now_text()
    existing = S.vintage(db, MODEL, issue)
    if existing:
        row = db.rows("select version from forecast_model_versions where id = %s", (existing["model_version_id"],))[0]
        return {"status": "exists", "vintage_id": existing["id"], "model_version": row["version"]}
    row = S.active_version(db, MODEL)
    if row is None:
        raise Fail("no_active_version", f"no active {MODEL} version")
    model = A.load_model(A.Store(), row, v1.code_sha256())
    data = D.load(db, first_issue=issue, last_issue=issue, history_from=issue - timedelta(days=LIVE_HISTORY_DAYS))
    output, parts = v1.predict(data, model, issue)
    vid = S.insert_vintage(db, model=MODEL, version_row=row, issue=issue, morning_cut=S.utc_text(morning.timestamp()),
                           afternoon_cut=S.utc_text(afternoon.timestamp()), started_at=started,
                           code_sha256=v1.code_sha256(), inputs=_inputs(data, issue), output=output, parts=parts)
    days = sorted(int(d) for d in output["day"].unique())
    return {"status": "stored", "vintage_id": vid, "model_version": row["version"], "targets": [f"D+{d}" for d in days]}


def score(args, db):
    target = date.fromisoformat(args.target_date)
    rows = S.score(db, MODEL, target)
    return {"status": "scored", "target_date": target.isoformat(), "rows": rows}


def train(args, db):
    as_of = _utc(args.as_of)
    monday = v1.refit_monday(as_of.astimezone(v1.C.HEL).date())
    store = A.Store()
    t0 = time.time()
    archive = D.read_archive(store.archive_dir())
    data = D.load(db, first_issue=C.FIRST_ISSUE, last_issue=monday - timedelta(days=1), archive=archive)
    print(f"inputs read, {time.time() - t0:.0f} s", flush=True)
    model = v1.train(data, monday, S.misses(db, MODEL))
    problems = v1.validate(model)
    if problems:
        raise Fail("refit_invalid", "refit is not valid: " + "; ".join(problems))
    payload, sha = A.dump_model(model)
    key = f"models/{MODEL}/{monday}-{sha[:12]}.pkl"
    store.put(key, payload)
    version = f"{MODEL}-{monday}-{sha[:8]}"
    S.insert_version(db, {"model": MODEL, "version": version, "refit_monday": monday.isoformat(), "object_key": key,
                          "sha256": sha, "size_bytes": len(payload), "sklearn_version": model["runtime"]["sklearn"],
                          "code_sha256": model["code_sha256"], "fit_facts": model["facts"]})
    print(f"fitted {version}, {len(payload)} bytes, {time.time() - t0:.0f} s", flush=True)
    return {"status": "trained", "candidate_version": version}


def activate(args, db):
    S.activate(db, MODEL, args.candidate)
    return {"status": "activated", "active_version": args.candidate}


def seed_misses(args, db):
    """The out-of-sample misses of the frozen backtests (best_d1 from E32, best_d24 and best_d57
    from E30), so that the first refits have the range calibration of the backtest."""
    if db.rows("select id from forecast_part_predictions where model = %s and source in ('E30', 'E32') limit 1", (MODEL,)):
        return {"status": "exists"}
    n = 0
    for path, tag, view, plan in ((args.e32, "E32", "afternoon", (("best_d1", (1,)),)),
                                  (args.e30, "E30", "morning", (("best_d24", (2, 3, 4)), ("best_d57", tuple(range(1, 8)))))):
        frame = pd.read_csv(path)
        for part, ks in plan:
            rows = frame[frame["k"].isin(ks) & frame[f"{part}_p50"].notna()]
            parts = pd.DataFrame({"view": view, "part": part, "issue": rows["issue"], "target_ts": rows["target_ts"],
                                  "tdate": rows["tdate"], "k": rows["k"], "month": rows["month"],
                                  "p10": rows[f"{part}_p10"], "p50": rows[f"{part}_p50"], "p90": rows[f"{part}_p90"],
                                  "y": rows["y"]})
            for start in range(0, len(parts), 5000):
                S.insert_parts(db, MODEL, parts.iloc[start:start + 5000], source=tag)
            n += len(parts)
            print(f"{tag} {part}: {len(parts)} rows", flush=True)
    return {"status": "seeded", "rows": n}


def upload_archive(args, db):
    folder = Path(args.dir)
    D.read_archive(folder)  # checks every pin first
    store = A.Store()
    for name in ("pins.json", *D.ARCHIVE_FILES):
        store.put(f"archive/s/{name}", (folder / name).read_bytes())
    return {"status": "uploaded", "files": len(D.ARCHIVE_FILES) + 1}


COMMANDS = {"predict": predict, "score": score, "train": train, "activate": activate, "seed-misses": seed_misses,
            "upload-archive": upload_archive}


def main(argv=None):
    ap = argparse.ArgumentParser(prog="python -m voltikka_forecast.s")
    sub = ap.add_subparsers(dest="command", required=True)
    p = sub.add_parser("predict")
    p.add_argument("--issue-date", required=True)
    p.add_argument("--morning-cut", required=True)
    p.add_argument("--afternoon-cut", required=True)
    sub.add_parser("score").add_argument("--target-date", required=True)
    sub.add_parser("train").add_argument("--as-of", required=True)
    sub.add_parser("activate").add_argument("--candidate", required=True)
    p = sub.add_parser("seed-misses")
    p.add_argument("--e30", required=True)
    p.add_argument("--e32", required=True)
    sub.add_parser("upload-archive").add_argument("--dir", required=True)
    args = ap.parse_args(argv)
    try:
        # Inside the try: a missing DB_* variable or a refused connection also gives the JSON line.
        db = D.Db.from_sqlite(os.environ["FORECAST_SQLITE"]) if os.environ.get("FORECAST_SQLITE") else D.Db.from_env()
        result = COMMANDS[args.command](args, db)
    except Exception as e:
        key = e.key if isinstance(e, Fail) else next((k for cls, k in ERROR_KEYS if isinstance(e, cls)), "internal_error")
        # Own messages name dates, columns and versions only; other exceptions (a driver error can
        # name the host) give their class only. A KeyError names only the missing key (for
        # example the variable DB_HOST), never a value.
        detail = str(e) if key != "internal_error" else type(e).__name__
        if isinstance(e, KeyError):
            detail += f" {e}"
        print(f"{args.command} failed: {detail}", flush=True)
        print(json.dumps({"status": "failed", "command": args.command, "error": key}))
        return 1
    print(json.dumps(result))
    return 0


if __name__ == "__main__":
    sys.exit(main())
