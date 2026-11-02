#!/usr/bin/env python3
"""Baseline (c) history: every published vintage of the nordpool-predict-fi forecast, read from
the git history of its deploy/prediction.json, for SCORING ONLY (never a model input).

The repository is untrusted data: this script reads blobs with `git cat-file` from a bare,
blob-less clone and never checks out or runs its code. Prepare the clone once (about 15 MB):

  mkdir research/data/npf-history && cd research/data/npf-history
  git clone --filter=blob:none --no-checkout --bare https://github.com/vividfog/nordpool-predict-fi.git repo.git
  git -C repo.git log --format= --raw --no-abbrev -- deploy/prediction.json \
    | awk '{print $4}' | grep -v '^0*$' | sort -u > oids.txt
  split -l 500 oids.txt part.; for f in part.*; do xargs git -C repo.git fetch --no-tags \
    --no-write-fetch-head origin < "$f"; done      # 4,454 blobs on 2026-11-02

As-of time of a vintage: its committer time. The push can be later than the commit; that risk is
small for a bot that commits and pushes in one run, and it is documented in decisions.md.

Units: the file holds [UTC ms, c/kWh incl. VAT]. VAT is removed with the rate of the target hour's
Helsinki date (10 % 2022-12-01..2023-04-30, 24 % to 2024-08-31, 25.5 % from 2024-09-01) and the value
is converted to EUR/MWh.

Output: research/data/s-export-<stamp>/thirdparty_history.csv (vintage_utc, commit, target_ts,
eur_mwh); its SHA-256 and the repository HEAD are added to research/s-inputs-<stamp>.json.

Usage: python3 -I s_thirdparty_history.py <YYYYMMDD of the export> [--repo PATH]
"""
import argparse
import csv
import hashlib
import json
import subprocess
from datetime import date, datetime, timezone
from pathlib import Path
from zoneinfo import ZoneInfo

HERE = Path(__file__).resolve().parent
HEL = ZoneInfo("Europe/Helsinki")
PATH = "deploy/prediction.json"


def vat(d):
    if date(2022, 12, 1) <= d <= date(2023, 4, 30):
        return 0.10
    return 0.24 if d < date(2024, 9, 1) else 0.255


def git(repo, *args, data=None):
    return subprocess.run(["git", "-C", str(repo), *args], input=data, capture_output=True, check=True).stdout


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument("stamp")
    ap.add_argument("--repo", default=str(HERE / "data" / "npf-history" / "repo.git"))
    args = ap.parse_args()
    repo = Path(args.repo)

    log = git(repo, "log", "--format=C %H %cI", "--raw", "--no-abbrev", "--", PATH).decode()
    commits, current = [], None
    for line in log.splitlines():
        if line.startswith("C "):
            _, sha, when = line.split()
            current = [sha, datetime.fromisoformat(when).astimezone(timezone.utc)]
        elif line.startswith(":") and current:
            oid = line.split()[3]
            if set(oid) != {"0"}:
                commits.append((current[1], current[0], oid))
    commits.sort()

    out = HERE / "data" / f"s-export-{args.stamp}" / "thirdparty_history.csv"
    rows = skipped = 0
    with open(out, "w", newline="") as fh:
        w = csv.writer(fh, lineterminator="\n")
        w.writerow(["vintage_utc", "commit", "target_ts", "eur_mwh"])
        for when, sha, oid in commits:
            try:
                points = json.loads(git(repo, "cat-file", "blob", oid))
            except (subprocess.CalledProcessError, json.JSONDecodeError):
                skipped += 1
                continue
            stamp = when.strftime("%Y-%m-%dT%H:%M:%SZ")
            for p in points:
                if not (isinstance(p, list) and len(p) == 2 and all(isinstance(x, (int, float)) for x in p)):
                    continue
                ts = int(p[0] // 1000)
                d = datetime.fromtimestamp(ts, timezone.utc).astimezone(HEL).date()
                w.writerow([stamp, sha[:12], ts, f"{p[1] / (1 + vat(d)) * 10.0:.4f}"])
                rows += 1

    pins_path = HERE / f"s-inputs-{args.stamp}.json"
    pins = json.loads(pins_path.read_text())
    pins["files"]["thirdparty_history.csv"] = {
        "rows": rows, "sha256": hashlib.sha256(out.read_bytes()).hexdigest(),
        "source": "https://github.com/vividfog/nordpool-predict-fi deploy/prediction.json git history",
        "repo_head": git(repo, "rev-parse", "HEAD").decode().strip(), "vintages": len(commits) - skipped,
        "skipped_vintages": skipped,
    }
    pins_path.write_text(json.dumps(pins, indent=2) + "\n")
    print(f"{out}: {rows} rows from {len(commits) - skipped} vintages ({skipped} skipped)")


if __name__ == "__main__":
    main()
