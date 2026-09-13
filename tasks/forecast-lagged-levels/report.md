# Lagged futures levels — offline result

**Numerical errors decrease, but direction improves little. Do not select or release a lag from this test.**

Frozen grid: 0/7/14/21/30 calendar days; horizon 30; alpha .25; lambda .30; minimum 10 prior days; signal/outcome thresholds .15. Every vintage prices the same delivery months selected at its retail date. Each lag also recomputes historical premiums. No optional horizon test, tuning, or older data.

## Canonical comparison

All five lags cover July 27–August 14: 19 days, 57 median rows, 19 per term. Targets end September 13. Counts are correct/wrong-way/missed/false. Prices are c/kWh. Native-history intersection:

| Lag | n | MAE all | MAE 6/12/24 | RMSE | Bias | Direction counts |
|---:|---:|---:|---|---:|---:|---|
| 0 | 57 | 0.7320 | 1.5433/0.4349/0.2177 | 0.9614 | -0.7320 | 16/2/39/0 |
| 7 | 57 | 0.7028 | 1.5032/0.4038/0.2013 | 0.9341 | -0.7026 | 16/0/41/0 |
| 14 | 57 | 0.6799 | 1.4713/0.3789/0.1895 | 0.9129 | -0.6794 | 17/0/40/0 |
| 21 | 57 | 0.6747 | 1.4576/0.3778/0.1888 | 0.9074 | -0.6727 | 16/0/41/0 |
| 30 | 57 | 0.6975 | 1.4958/0.3977/0.1989 | 0.9329 | -0.6975 | 17/0/40/0 |

Unchanged price: MAE .7026, counts 13/0/44/0. Always-UP: 44/0/0/13; always-DOWN: 0/44/0/13; neither has a numerical forecast. Actuals are 44 rises, 13 flat, zero falls. Canonical downward recall cannot be measured. Matching class balance is not predictive skill.

The separate stored-v2 reference has 48 rows/16 days; August 2–4 are absent. MAE: original v2 .7952; repaired native L0 .7458; unchanged .7003. Lag MAEs 0/7/14/21/30 are .7458/.7067/.6854/.6738/.7090. Original rows and labels stay unchanged.

## History and earlier regime

Canonical native history starts April 9/16/23/30 and May 9 by lag; counts are 109–127/102–120/95–113/88–106/79–97. Common history starts May 9, uses 79–97 identical days for every lag, and ends strictly before issue. Canonical four-decimal forecasts and direction counts are unchanged; raw premiums can differ. No canonical mature row fails hedge coverage or warmup.

Observed-only comparison stays separate: May 19–June 26, 39 days/117 rows, 39 per term; actuals 67 up/40 flat/10 down. Lag MAEs are .5532/.5369/.5138/.5142/.5287; correct counts 40/38/38/42/41. Unchanged MAE is .5235. Common-history MAEs are .5535/.5370/.5136/.5139/.5287; direction counts do not change. Thus the numerical pattern partly repeats, but direction does not improve consistently.

Observed native mature eligibility is 207/186/165/144/117. Missing-current-H counts are 3/24/45/66/93; each lag loses another 30 term rows to warmup. No gate is relaxed.

## H/N decomposition

August 8, six months: R=13.1950. L0 H/N=11.4786/.8846, change −.2496. L7 H/N=11.9534/1.0636, change −.0534. L21 H/N=10.9331/2.1554, change −.0320: lower H is more than offset by higher learned premium. August 9 L0/L7 changes are −.1872/−.0401. Both wrong falls become missed rises, not correct calls. CSV flags higher lagged H, gap sign changes, and both dates.

## Verification and decision

Independent Decimal daily expansion checks 2,385 hedges; closed-form EWMA checks 9,540 candidates. Native L0 matches 147 actual-PHP generated median rows, including current/forecast/premium. Fifteen synthetic checks pass. Unrounded generation labels versus stored-four-decimal labels have zero disagreements. New outcomes use rounded actual differences; originals are not recalculated.

All source hashes pass; two runs are byte-identical. `results/metrics.*` contains all term/control errors and direction counts; coverage, history dates, cohort identities, and diagnostics are retained. Run `python3 tasks/forecast-lagged-levels/reproduce.py`.

These inspected, overlapping export-time cohorts share shocks and possible revisions. Observed-to-canonical continuity is an assumption, not a proven constant process. No p-values or confidence claim is justified. Keep the current model unchanged; a separately approved forward-only shadow comparison could test the frozen grid. No automatic release.
