# Offline futures-only increment test

This folder is the only write scope. No application, database, network, production, dependency, prior-artifact or release changes are allowed.

`spec.md` and input hashes were frozen before metrics; executable hashes were frozen before the first run. Reuse Evidence/fit/predict from the prior p20 study and basket from the earlier accuracy experiment, but never reuse their retail/vector feature eligibility. Only finite owned median endpoints gate retail pairs. Latest-ID ownership and first canonical presence control a per-term observed-prefix/canonical-continuation seam. Both endpoints stay on one basis.

Models fit each 6/12/24-month term separately with expanding strictly completed labels. Production mean uses all eligible completed pairs. Feature mean and ridge use only futures-eligible pairs; all need 20 unique starts. Ridge penalty is 1 with training-only population scaling and unpenalized intercept. Hybrid replaces the feature intercept with the unrestricted mean, retaining the centered futures contribution. All models use production four-decimal saved delta/forecast rounding and inclusive +/-0.15 direction thresholds.

Primary is fixed-delivery seven-day futures change, median target at 30 days. Rolling delivery and 14-day median are secondary checks, not model selection. `evidence.json` stores shared pairs, baskets, fit IDs and availability. `predictions.json` stores matched five-model outputs. `results.json` stores term/regime/direction summaries, paired improvements and explicitly synthetic sensitivity. Empty subgroups are absent and must not be read as zero error.

Reproduce from root with `python3 -B tasks/forecast-futures-increment-test/reproduce.py`. It runs twice and checks byte equality plus original hashes. Independent Decimal checks and synthetic tests are in `verify.py`; proof is in `checks.json` and `reproducibility.json`.

Result: primary hybrid MAE 0.268038 versus production-rule mean 0.303476, but gains concentrate in six-month targets. All primary fitted predictions are UP. Only five primary down targets exist, all observed 12-month, and all are wrong-way calls. Do not adopt or claim falling accuracy. See the 403-word `report.md` and detailed `verification.md` for limits and evidence.
