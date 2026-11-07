# S workflow ↔ model code interface (2026-11-07)

This file is the workflow side of the contract between the Temporal S workflows (this task) and
the S model code (`tasks/own-forecast-model-data/`). The model side, the full input list and the
cuts are in `../own-forecast-model-data/s-model-freeze.md` (model `s_v1`); that file wins where
the two differ. Change either only together with both tasks.

## Timeline of `s-forecast-D` (all times Europe/Helsinki, D = issue date)

| Time | Step |
|---|---|
| 10:55 | Temporal Schedule `s-forecast-daily` starts `SDailyForecast` (workflow ID `s-forecast-<scheduled time>`; overlap policy skip). |
| about 11:00 | Activity `entsoe:fetch --dataset=outages --zone=FI --zone=SE3 --zone=SE1_FI --zone=SE3_FI --zone=EE_FI` (the outages that `s_v1` reads; the proof of a morning outage fetch before the 11:30 cut). |
| 11:30 | Morning cut. Readiness check of the morning view. If an input is not ready, the as-of rule can never make it ready: the run ends at once, with one Sentry Issue that names the input. |
| 14:00 | Activities `fingrid:fetch` and the same outage fetch (afternoon data before the 14:30 cut). |
| 14:30 → 18:00 | Afternoon readiness check every 5 minutes. The first check where every input is ready starts the model run. |
| 18:00 | Deadline. No run: one Sentry Issue that names the missing inputs. |
| after the run | Scoring of target day D+1 (its prices are now complete). |

`SWeeklyRefit`: Temporal Schedule Monday 03:00 → train → activate. The frozen method has no
validation gate: `train` fails unless the refit is technically valid, and a trained candidate is
activated. A failed refit keeps the active version and sends one Sentry Issue.

## Cuts

- Morning view: every input as of `morning_cut` = D 11:30 Helsinki (a row is visible only if its
  `fetched_at` ≤ cut; the newest such vintage is used, never a newer one).
- Afternoon view: every input as of `afternoon_cut` = D 14:30 Helsinki, **except** the day-ahead
  prices of D+1: the run needs all of them, also when the auction result is late and they are
  stored after 14:30. Confirmed by the own-model session on 2026-11-07, with these conditions:
  the workflow waits for D+1 of all five zones (FI, SE1, SE3, EE, DE-LU); `afternoon_cut` is
  always D 14:30, also on a late day; the vintage stores the cut and its real start time (a record
  only, never an input); scoring keeps the third-party 14:30 rule and the report counts late days;
  no complete prices by 18:00 = no vintage and the day is not in the 56-day count.
- The workflow passes both cuts to the model as explicit UTC timestamps. The model never uses
  "now".

## Commands that the model code supplies

Each command is one process, prints exactly one JSON object as the last stdout line, and returns
exit 0 on success. Any other exit code is a failure. Progress lines before the last line are
allowed (they go to the activity log). No secrets or payloads in the output.

```
python -m <s_package> predict --issue-date=D --morning-cut=<UTC ISO> --afternoon-cut=<UTC ISO>
  → {"status":"stored","vintage_id":123,"model_version":"s-2026w45","targets":["D+2",…,"D+7"]}
  → {"status":"exists","vintage_id":123,…}        # same issue date already stored (idempotent)

python -m <s_package> score --target-date=T
  → {"status":"scored","target_date":"T","rows":6}  # idempotent upsert of the scores of T

python -m <s_package> train --as-of=<UTC ISO>          # s_train; exit ≠ 0 unless technically valid
  → {"status":"trained","candidate_version":"s-2026w46"}
python -m <s_package> activate --candidate=s-2026w46
  → {"status":"activated","active_version":"s-2026w46"}
```

- `predict` must be idempotent per issue date (a retry after a deploy must not store a second
  vintage).
- `predict` must fail (exit ≠ 0) when an input is missing; it must not fill a gap. The workflow
  checks readiness first, so such a failure means that the readiness facts and the model disagree.
- Database access: the same MySQL as Laravel, from the worker container environment
  (`DB_HOST`, `DB_PORT`, `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD`).

## Readiness facts (the PHP side, `php artisan orchestration:readiness s-forecast`)

`laravel/config/orchestration.php` lists the inputs of `s_v1` (freeze section 2): FI and neighbour
day-ahead prices (D in the morning view, D+1 in the afternoon view), JAO capacity through the end
of D and D+1, Fingrid 75/124/188/191/194/267/268 through the last hour before each cut, the
Open-Meteo `ecmwf_00z` run of D at all 38 collector points, and the same-day `eex_futures`
checkpoint. The ENTSO-E outage fetches before each cut are run by the workflow itself. The
third-party forecast, Fingrid 245/246 and the FMI forecasts are not run inputs.

The `S_MODEL_MODULE` package must be installed in the worker's virtual environment
(`orchestration/uv.lock`), with the freeze runtime pins (Python 3.13, scikit-learn 1.9.1,
pandas 3.1.0, numpy 2.5.4).

## Answers to the own-model session (`../own-forecast-model-data/coordination-temporal.md`, 2026-11-07)

1. **Data access.** The model commands run in the worker container (same image) as their own
   process, and read and write MySQL directly with the same `DB_*` variables as Laravel. Readiness
   stays in PHP (`orchestration:readiness`); the model data reader does not go through artisan,
   because the views need many tables as frames and a PHP copy of the reader would drift from
   `s_backtest.py`. No separate read-only user: the model writes its own vintages, versions and
   scores. Rule: the model code writes only its own tables, and those tables are created by Laravel
   migrations (the web service owns migrations). The archive bucket credentials become variables
   of the `voltikka-worker` service only.
2. **Model versions.** Accepted as proposed: fitted files in the private archive bucket, one
   `model_versions` row per version (key, SHA-256, scikit-learn version, fit facts, active flag) in
   MySQL. `activate` is one database write. `predict` reads the active version itself, checks the
   hash and the scikit-learn version before it loads the file, and reports the version in its JSON
   line. The worker has no volume: a local copy is only a cache in the container's temp directory.
3. **Package location.** A separate package, not inside `orchestration/`. The activities never
   import it; they start `python -m <S_MODEL_MODULE> …` as a child process (memory isolation from
   the worker, kill on cancellation, a crash cannot stop the worker, no pandas in the workflow
   sandbox). Proposal: top-level `forecast/` with its own `pyproject.toml`, distribution
   `voltikka-forecast`, module `voltikka_forecast.s` (`S_MODEL_MODULE=voltikka_forecast.s`). The
   functions reused from `research/s_backtest.py` move into the package (the backtest imports them
   from there), because only the package is installed in the image. The Temporal session adds it to
   `orchestration/pyproject.toml` as a uv path dependency, so `orchestration/uv.lock` pins the
   freeze runtime, and copies it in the `Dockerfile`. The contract is the command line in "Commands
   that the model code supplies" above; internal function signatures belong to the model session.

The own-model session accepted all three answers (its note, section 5). It owns `forecast/` and
writes the Laravel migrations for its tables; the Temporal session owns the uv path dependency,
`uv.lock` and the `Dockerfile` copy. Condition: the package-based backtest reproduces the E32
predictions file (SHA-256 `c35388e8…`, or values within 1e-9) in the same change.
