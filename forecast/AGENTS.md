# Own spot price forecast models (Python package `voltikka-forecast`)

The production code of Voltikka's own spot price forecasts. Task, decisions and status:
`../tasks/own-forecast-model-data/` (read `s-model-freeze.md` first). Status 2026-11-07: local
only; no production service runs it yet. The Temporal worker (`../orchestration/`) starts its
commands as child processes (`S_MODEL_MODULE=voltikka_forecast.s`); the command line is the
contract (`../tasks/temporal-orchestration/s-workflow-interface.md`).

## S model `s_v1` (`src/voltikka_forecast/s/`)

Hourly FI day-ahead price, p10/p50/p90 for D+2..D+7, one vintage per issue day D after the D+1
prices. Frozen 2026-11-06 after the E32 backtest; **do not change the model** without a new model
version and a new pre-declared live count (freeze section 5).

- `core.py`: the backtest functions, moved verbatim from `research/s_backtest.py` (commit
  237a9e4) on 2026-11-07. The backtest now imports them from here, so the backtest is the test of
  the production code. Changes made in the move, each proved to give byte-identical backtest
  predictions: `predict_block` split into `fit_block` + `apply_block` (and the same split for the
  stage-1 wind, net load, linear and level/shape parts); `build_table(first_issue=,
  targets_known_before=)` for live issues (targets without prices) and refits (the last issues
  before the refit Monday); holidays through 2100 (the research range ended in 2027).
  Module-level dicts are settings, mutated in place, never rebound.
- `v1.py`: the s_v1 settings of both views (`view()` = the backtest `main()` settings of E30 and
  E32), `train` (fit both views on rows with a target day before the refit Monday, the backtest
  block rule), `seasonal_shifts` (the `*_scal` rule from the miss store), `predict` (compose
  D+2 from the afternoon view and D+3..D+7 from the morning view, as the combined design) and
  `validate` (technical validity only; the frozen method has no accuracy gate). `predict` fails
  (`InputMissing`) when a live input is empty: `core.build_table` would quietly leave a feature
  empty and the trees would still predict.
- `data.py`: the input dict in the shape of the research export, from MySQL (PyMySQL, Laravel
  `DB_*` variables) or SQLite (tests). The same SQL, units and dtypes as `research/s_export.py`
  (MySQL `maturity` is text, `settlement_price` decimal: both converted). Frozen history comes
  from the archive folder (`pins.json` with a SHA-256 per file).
- `weather.py`: the research weather reducers for the stored rows (`ecmwf_00z` single runs,
  `ecmwf_prev_day1` southern temperature), same points, weights, 80 % rules and 3-decimal rounding.
  Stored JSON integers are read as floats: PHP stores 6.0 as 6, and Python `sum()` adds a mixed
  int/float list in another order, which changed 375 rounded means (rebuild check 2026-11-07).
- `store.py`: the own tables (Laravel migration `2026_11_07_100001`): model versions, vintages,
  vintage values, part predictions (the miss store), scores with the third-party value of its
  latest vintage before D 14:30.
- `artifacts.py`: model files and the archive in the private archive bucket (`FORECAST_BUCKET*`
  variables) or a local folder (`FORECAST_STORE_DIR`). A pickle is loaded only after its SHA-256,
  scikit-learn version and model code hash match.
- `__main__.py`: `predict`, `score`, `train`, `activate`, and the one-time `seed-misses` and
  `upload-archive`. The last stdout line is one JSON object; a failure has a closed `error` key
  (`input_missing`, `cuts_differ`, `no_active_version`, `artifact_mismatch`, `version_mismatch`,
  `prices_incomplete`, `refit_invalid`, `bad_argument`, `internal_error`); the detail is on the
  line before it (the container log), never in Temporal.

Rules:

- The model code writes only its own tables; Laravel migrations create them.
- `predict` accepts only the frozen cuts (D 11:30 and D 14:30 Helsinki), is idempotent per issue
  day, and uses the active version. It refuses a version fitted for a later Monday and a version
  fitted with other model code (`code_sha256` over `core.py`, `v1.py`, `data.py`, `weather.py`):
  a deploy that changes these files needs a refit before the next run.
- Weather is selected by `issued_at` (`ecmwf_00z`) or by valid hour (`ecmwf_prev_day1`), never by
  `fetched_at`: imported history rows have a 2026 `fetched_at`.
- The refit must run after the Monday Fingrid fetch (Sunday's hours are training targets).

## Runtime

Python 3.13 with the research runtime pins (`pyproject.toml`): numpy 2.5.4, pandas 3.1.0,
scikit-learn 1.9.1, scipy 1.18.1, joblib 1.6.0, threadpoolctl 3.7.0, tzdata 2026.6. A fitted model
is valid only with the same scikit-learn version. `python-dateutil` is below 3 because of boto3
(research had 4.0.0); the production replay runs in this environment.

## Checks

```bash
cd forecast && uv sync && .venv/bin/python -m pytest -q            # unit tests (SQLite)
# Research proofs (tasks/own-forecast-model-data/research/, git-ignored data):
OMP_NUM_THREADS=2 nice .venv/bin/python -I s_backtest.py 20261102 ... --tag E32R   # byte-identical to E32
nice .venv/bin/python -I s_rebuild_check.py 20261102 --archive <dir> --sqlite <db>  # tables from the database
OMP_NUM_THREADS=2 nice .venv/bin/python -I s_production_replay.py 20261102 <issue days>  # train + predict = backtest
```
