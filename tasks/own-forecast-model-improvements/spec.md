# Further development of the own spot-price forecast models (S and M)

Created 2026-11-06 (user decision). Status: backlog; no work started.

## Why this task exists

The research phase of `tasks/own-forecast-model-data/` stops after experiment E32 (user decision
2026-11-06): the user wants the models in production soon, without more waiting for tests. The
remaining improvement ideas are kept here, so they are not lost and do not delay production.

## Starting point

- S production candidate: `s_best` with the weekly refit (E30), issue time from E32. HOLD MAE
  25.7 €/MWh against 27.0 for the nordpool-predict-fi forecast (2025-07-01..2026-10-31).
- M production candidate: `m_best` (MALE DEV 0.273 / HOLD 0.458).
- Experiment log, protocol and decision rules: `tasks/own-forecast-model-data/research/experiments.md`
  (S: paired MAE on the third-party rows, DEV 2024-07..2025-06, HOLD from 2025-07; M: MALE).
  Backtest code: `research/s_backtest.py`, `s_compare.py`, `m_backtest.py`.

## Rules

- A change reaches production only as a new model version: backtest with the same protocol, then
  the live scoring of the production version must still pass. The production model is not
  changed in place.
- Expected value (2026-11-06 estimate): model tweaks (settings, features, blends) about
  0.1–0.3 €/MWh each, often nothing; 5 of the last 6 tweak experiments failed. New or newer
  information gave the large gains (E8 weather run age, E26/E30 refit frequency). Prefer ideas
  that add information.
- Production inputs only: every new input needs a production collector with as-of vintages,
  and it joins the "run only when every input is ready" rule
  (`tasks/temporal-orchestration/spec.md`, "Forecast workflows").
- CPU: research runs with `OMP_NUM_THREADS=2 nice -n 15`, one backtest at a time.

## Backlog (from the research task, with evidence)

S, information:
1. Newer weather run at an afternoon issue: the ECMWF 06 UTC run may be public before 14:30
   Helsinki (check availability and the FMI/Open-Meteo archive first).
2. Ensemble weather (ECMWF ENS spread) for the range (p10–p90), mainly D+3..D+7.
3. Retrain the research table with FMI hourly, Syke and the Open-Meteo neighbour wind after a
   fresh sync (old task item).

S, model:
4. Winter range calibration: seasonal conformal gives 0.68 coverage in winter, target 0.80.
5. Cheapest-hours output: main-model level plus a shape model for the hour order.
6. D+5..D+7: we are equal to or slightly worse than the reference at D+6 (HOLD +0.39);
   fundamentals or a futures anchor.
7. Daily refit (weekly → daily; E30 suggests a small gain at most).
8. E31 recency weights were a near miss for D+2–4 (HOLD −0.18, DEV CI included 0); retest only
   with new data.
8a. Linear part guard (E32): `l_lear_hour` blew up in April 2026 (month MAE 42.5 E30, 73.6 E32),
    because `fut_month` starts on 2026-04-08 and the first weekly blocks had few training rows
    with a value. Let a column enter the linear fit only with enough training days that have a
    value. Any new input of a later model version has the same risk.
8b. Afternoon models for D+3..D+8 (E32): with the D+1 prices known, the trees were worse at
    D+3–D+4 (best_d24 HOLD 22.6→23.4) and the production design uses the morning view there. No
    cause found; a D+8 output needs this first.

M:
9. M30: dated installed wind and nuclear capacity, as-of.
10. SEAS5 runoff vs NVE/ENTSO-E reservoir inflow; S2S (EC46-type) archive for weeks 1–6; hydro
    balance proxy inputs (reservoirs, snow, precipitation vs normal).
11. M27 recheck.

Data:
12. Compare FMI ECMWF, FMI edited and Open-Meteo ECMWF IFS (weather and price level) after
    4–8 weeks of production vintages (from 2026-11-03).
