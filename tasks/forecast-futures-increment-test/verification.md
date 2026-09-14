# Verification

Command from repository root:

```sh
python3 -B tasks/forecast-futures-increment-test/reproduce.py
```

Actual result: two successful analysis runs and two successful independent verification runs. Each analysis produced 690 exact retail pairs, 780 fits and 780 matched test rows, each with all five models. The fixed and rolling cohorts each have 123 primary and 267 secondary test rows. All four machine artifacts were byte-identical between runs. `reproducibility.json` contains their SHA256 hashes, the unchanged original-input hashes and executable hashes. `inputs.json` and `spec.sha256` were written before code/results. `source-hashes.json` was written before the first analysis run. No executable changes were needed after that run.

`checks.json` records the independent first/last primary fit checks for every duration (six fits). Decimal arithmetic verifies the mean, population scale and one-predictor ridge normal equation. All fits are also checked for unrestricted training membership, feature-only membership, separate term/horizon identity, minimum 20 unique starts and strict maximum label dates. Rows receive equal weight 1/n; ridge uses penalty 1, not sum-error scaling.

Independent raw-row checks reconstruct latest-ID endpoint ownership and exact calendar distances. Basket checks use raw FI Base trades strictly before the endpoint, select month/quarter/year instruments, expand month-day weights, and apply VAT with Decimal arithmetic. The same delivery months and weights are checked across primary valuations. Instrument plus trade date is a unique futures-row identity in this export; source files and hashes retain its raw ID.

Adversarial checks cover missing retail lag, absent p20/p80, invalid latest median, invalid canonical presence, no observed fallback after the seam, no seam-spanning pairs, strict label cutoff, saved signed threshold boundaries, missing futures lag, constant predictor and a month-roll example. Missing retail lag and quantile vectors do not remove futures-only cases. Synthetic month-roll change is nonzero for rolling delivery but zero for the unchanged fixed basket.

All predictions are independently checked for model arithmetic and saved four-decimal forecast/error values. Every metric-group MAE, bias and paired mean improvement is independently recomputed. Each prediction record contains exactly the same five models for one target. `results.json` includes issue-level paired improvement summaries; positive values mean lower absolute error. Empty direction subgroups have no metric row, not zero MAE; specifically primary canonical down N=0 and primary 6/24-month down N=0.

Availability across both horizons and feature cohorts: 780 evaluated; 96 missing futures; 408 under 20 unrestricted training starts; 96 under 20 feature training starts. Retail endpoint exclusions are separate in `evidence.json`, grouped by term/horizon/owned basis. All three canonical presence seams are July 27, 2026. Original statistics/futures counts are 477/2,239.

No Laravel tests or frontend build apply: this work changes only offline Python and task artifacts. No database, network, production, dependency, app file, prior artifact, commit or push was used.
