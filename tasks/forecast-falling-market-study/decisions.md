# Decisions

## 2026-10-16 diagnosis

- Fresh read-only production sync (local backup `/tmp/voltikka-database-20261016-002711-29691.sqlite`). Retail medians fell from mid-September (12 m 12.50 → 11.69 c/kWh on Oct 12, 6 m 15.77 → 14.20). All 66 stored `fixed_term_futures_adjusted_v1` median rows (Sep 14 – Oct 15) say `rising`.
- Cause: unrestricted expanding mean of completed changes. On Oct 15: mean +0.657/+0.336/+0.256 (6/12/24 m), futures contribution −0.026/−0.011/−0.003. The mean averages every change since January, mostly rises, so it barely reacts to a turn. `tasks/forecast-futures-increment-test/report.md` already reported "Every fitted model predicts UP" and recommended no adoption.
- Mechanism seen in the data: retail ≈ futures basket (same delivery months) + premium; 12 m premium ranged about 1.9–3.8 c/kWh. Low premium preceded rises (May, July); high premium (3.76 on Sep 23) preceded falls.
- The user chose further investigation before any code change (options offered: remove drift, normal-premium model, pause direction, investigate).

## Study design

- Pre-declared spec frozen and hashed before the harness ran. The model list includes variants that were already inspected on 2026-10-16 in a scratch backtest, so the retrospective window is descriptive only. The decision uses the holdout: issue dates ≤ Oct 15 whose targets are after Oct 15. Those outcomes did not exist at freeze time.
- Holdout predictions are saved in `results-20261016.json` before outcomes exist. On the follow-up rerun, holdout predictions must equal these saved values unless the rerun reports revised retail rows dated ≤ Oct 15; any difference must be explained before the decision rule is applied.
- Offline CSV harness, standard library only; no app code, no production write. M1 reproduces all 66 stored production median forecasts within 0.0001 (exact match). Two runs are byte-identical.

## Retrospective (descriptive, not selection evidence)

- Median h30, 177 rows, 146 up / 20 flat / 11 down. MAE: M0 .763, M1 .614, M2 .812, M3 .476, M4 1.007, M5 .980, M6 .865, M7 .910. On the 11 actual falls, M1 and M3 are wrong-way 11/11; M0 MAE .330 is best.
- Median h14, 318 rows (59 down): M1 .410, M2 .421, M0 .457, M6 .445. On falls, M6 catches 46/59 with zero wrong-way; M1 catches 0 with 29 wrong-way.
- Median h7, 381 rows (52 down): M1 .232, M2 .245, M7 .259, M0 .278. From Aug 15, M2 has the lowest MAE (.252).
- Pattern: drift models win while prices rise and fail on every fall; premium-return models catch falls but call falls in rising markets; futures-only rarely goes wrong-way but does not beat unchanged at 30 days.

## Interim release: M2 as `fixed_term_futures_only_v1` (user decision, 2026-10-16)

- The user did not want to wait for the holdout and asked for a model that is at least a bit more accurate than the always-rising one now. Chosen: M2 (drift term removed, futures ridge kept). Reasons: fewest wrong-way calls in every tested window; lowest MAE from Aug 15 at h7 (.252 vs M1 .272) and h14 (.475 vs .490); on actual falls MAE .726 vs M1 1.229 (h30) and .421 vs .651 (h14); smallest code change; no directional bet. Stated limit: in the retrospective h30 window M2 MAE .812 is worse than M1 .614 and M0 .763 (a rising market).
- The fitted slopes are near zero (Oct 15 median slopes −.049/−.014/−.003), so M2 nearly equals "unchanged": all nine Oct 15 lanes are slightly rising/falling, |change| ≤ .064.
- New model name so stored old rows stay separately evaluable. Production has no `PRICE_FORECASTING_*` variable, so the config default selects it. The unapplied full mean is saved as `unapplied_historical_mean_change_cents_per_kwh`. Laravel dry run for Oct 15 equals harness M2 for 6/12/24 m.
- Release gap: a completed scheduled day blocks manual writes for that date, so new rows first appear at the next scheduled run (07:30 Helsinki the day after deploy). The page shows its empty state until then.
- The holdout and decision rule are unchanged. M2 is now the live baseline; the follow-up decides whether it stays or a refinement replaces it.
