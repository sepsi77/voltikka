#!/usr/bin/env python3
"""Render the fixed report from retained checked results."""
import sys
sys.dont_write_bytecode = True
import csv
import json
import experiment as x


def main():
    metrics=list(csv.DictReader((x.ART/'metrics.csv').open()))
    fits=json.loads((x.ART/'fits.json').read_text())
    pairs={r['id']:r for r in json.loads((x.ART/'pairs.json').read_text())}
    availability=list(csv.DictReader((x.ART/'availability.csv').open()))
    def table(rows):
        out='| Model | Rows / days | MAE | RMSE | Bias | Direction % | MAE skill % |\n|---|---:|---:|---:|---:|---:|---:|\n'
        for r in rows:
            out+=f"| {r['model']} | {r['n']} / {r['issue_days']} | {float(r['mae']):.6f} | {float(r['rmse']):.6f} | {float(r['bias']):+.6f} | {100*float(r['direction_accuracy']):.2f} | {100*float(r['skill']):+.2f} |\n"
        return out
    def selected(protocol,window,horizon,term='all'):
        return sorted([r for r in metrics if r['protocol']==protocol and r['window']==str(window)
                       and r['horizon']==str(horizon) and r['term']==str(term)],key=lambda r:x.MODELS.index(r['model']))
    text='''# Offline forecast accuracy experiments

## Result and decision boundary

**The fixed-delivery-basket feature does not improve the primary test over rolling futures or retail-only.** On 12 canonical issue days, the 30-day MAEs are unchanged **0.608625**, repaired fixed gap **0.656925**, learned retail **0.479421**, learned retail + rolling futures **0.436333**, and learned retail + fixed basket **0.485761 c/kWh**. Fixed basket has 0.049428 more error than rolling futures and 0.006340 more than retail-only. Abstention changes none of these 36 primary predictions and does not help.

The learned models beat unchanged on this selected 30-day cohort, but this is **not a production recommendation**. Their positive intercepts carry much of the predicted rise. No intercept-only model was predeclared; the experiment does not identify how much of the gain is due to feature information rather than the older regime's mean target change. The older observed rolling-origin 30-day result reverses the gain against unchanged. At the secondary 14-day target, all learned and gap models lose to unchanged on MAE, although learned RMSE is lower. In the primary test, all learned models improve MAE only for the six-month term; they lose to unchanged for 12 and 24 months. The pooled gain is not a shared term-level gain.

These are retrospective results on a chronological holdout already inspected by prior research. They are contaminated by prior analysis, not untouched out-of-sample proof. The specification was frozen before this task computed new metrics; no parameter or cohort was changed after those metrics.

## Source, target and model freeze

The only data export used is `tasks/supplier-premium-coverage/export-20260913T094138Z`. It is complete for April 8–September 13, 2026. Manifest SHA256: `6b7dd7093cafab8f91bb8417becba321747da4bc4334a19924374cc6be05efb6`. All payload hashes, bytes, expected/actual counts, SQL and count-SQL hashes pass. The export has 477 scoped public statistics and 2,239 FI Base futures rows. Owned medians and futures have no invalid numeric values. Latest-ID statistic ownership is applied before validity checks.

Target: public 6/12/24-month `energy_price` median, null consumption, `unit_statistics_v1`. Eligible Time/Season offers are included. This is not a General-only supplier median or an identical-product price. No controlled index is constructed here. The separate index task must remain a separate estimand until the manager combines its evidence.

Unchanged is the zero-change reference, not an application default changed by this task. Fixed gap reuses hash-verified exact prior rows checked against the actual local undeployed v3 service: current + .30 × (H + EWMA normal premium − current), alpha .25, at least 10 strictly prior usable days. Its canonical EWMA has an observed prefix before the canonical seam and canonical-only continuation. That continuity assumption differs from strictly same-basis learned rolling-origin training. The 14-day gap is a reference sensitivity, not a separately calibrated v3 model.

Primary learned features use seven-day exact endpoint changes. Fourteen-day input is a separate predeclared sensitivity, not a winning-window search. Retail-only and the two futures models use the same training rows, retail feature and test identities. All coefficients are shared across terms, with equal row weights. Each fit minimizes mean squared delta error + 1.0 × squared standardized slopes. Population scales come from training only; the intercept is unpenalized. There are no term dummies, supplier parameters or hyperparameter choices. Missing inputs stay missing. Zero-valued data remain valid. There is no annual-v2 history ban.

Rolling H uses next-full-month delivery at each endpoint. Fixed basket instead selects the issue-date next-full-month strip and prices those EXACT months at both strictly earlier trade vintages, with month → quarter → year fallback, complete coverage, VAT 1.255 and identical actual month-day weights. It removes delivery-roll movement, not the forecast level. Fallback instruments can differ between vintages. No future curve corrects a past roll. Five of the 12 primary issue days cross a calendar-month delivery roll over the seven-day input window (August 3–7). On the other seven days, fixed-basket and rolling input changes are equal. Their fitted predictions can still differ because their training features and fitted coefficients differ.

Raw learned predictions are not rounded. The separate basket abstention output sets absolute predicted changes below .15 c/kWh to zero. Direction accuracy uses down/flat/up with that same strict threshold; all rows are in its denominator. Thus abstention cannot change this three-class direction score. Bias is prediction minus actual; skill is 1 − model MAE / unchanged MAE on exactly paired rows.

## Training and test identity audit

Training freezes at July 27 using only observed-basis labels STRICTLY before July 27. Current, prior retail endpoint and target all share the observed basis. Canonical holdout inputs and targets share canonical basis. Observed-to-canonical coefficient transfer is a history-continuity ASSUMPTION, not a proven common data-generating process. No canonical outcome fits or selects the frozen model.

| Input days | Target days | Training rows / issue days | Training issues | Last training target | Test rows / issue days | Test issues |
|---:|---:|---:|---|---|---:|---|
'''
    for w,h in ((7,30),(7,14),(14,30),(14,14)):
        f=fits[f'frozen_transfer|{w}|{h}|2026-07-27']['retail']
        m=selected('frozen_transfer',w,h)[0]
        dates=sorted({pairs[i]['issue'] for i in f['identities']})
        text+=f"| {w} | {h} | {f['n']} / {f['issue_days']} | {dates[0]}–{dates[-1]} | {f['max_target']} | {m['n']} / {m['issue_days']} | {m['first_issue']}–{m['last_issue']} |\n"
    text+='''
Raw canonical 30-day pairs cover 19 issue days (July 27–August 14), 57 term rows. Exact seven-day canonical retail history removes seven days, leaving August 3–14: 12 days / 36 rows. Exact 14-day history removes 14 days, leaving August 10–14: five days / 15 rows. No observed value fills these gaps. Raw canonical 14-day pairs have 35 days / 105 rows; paired seven-/14-day inputs retain 28/21 days. Every retained day has all three terms. All curves are complete on retained rows.

`pairs.json` saves issue, target and lag dates, all three statistic IDs, basis, term, current, actual and features. `fits.json` saves every fit's exact training IDs. `predictions.csv` links each model row to that same pair ID and fit ID. `exclusions.csv` saves one result for every current-date/term/window/horizon candidate. Only same-basis current dates start candidates. No calendar date is filled.

The fixed seven-day training cohort starts April 19 because the paired v3 reference requires 10 prior hedge/retail days. Fourteen-day input starts April 23 because April 22's earlier futures endpoint has no strictly prior trade. These gates are applied identically to all learned models.

## Primary 30-day target, seven-day inputs

'''+table(selected('frozen_transfer',7,30))
    text+='\n### Primary per-term results\n\n'
    for term in (6,12,24):
        text+=f'#### {term} months\n\n'+table(selected('frozen_transfer',7,30,term))+'\n'
    text+='## Secondary 14-day target, seven-day inputs\n\n'+table(selected('frozen_transfer',7,14))
    text+='''
Fixed basket is worse than rolling by 0.022065 and retail-only by 0.009641 c/kWh. Abstention raises MAE from 0.328629 to 0.335658. It changes six rows on six issue days, only small predicted moves, not three-class direction.

## Predeclared 14-day input sensitivity

These five 30-day issue dates were known to be selected before fitting. Do not compare their apparent gain with the larger seven-day-input cohort as a model-selection result.

### 30-day target

'''+table(selected('frozen_transfer',14,30))
    text+='\n### 14-day target\n\n'+table(selected('frozen_transfer',14,14))
    text+='''
Fixed basket again loses to rolling at both targets. It loses to retail-only at 30 days and slightly improves on retail-only at 14 days, while still losing to unchanged. The extra abstention sensitivity is retained separately for completeness; it does not alter the primary definition.

## Coefficients and scales

Every row below uses training only. `b0` is the raw-feature intercept. `mean delta` is the standardized-coordinate intercept. Features are in c/kWh; raw slopes multiply raw input changes. The feature order is retail, then futures. Complete precision, standardized slopes and 136 fit identities are in `fits.json`.

| Input / target days | Model | Mean delta | b0 | Raw slopes | Feature means | Population scales |
|---|---|---:|---:|---|---|---|
'''
    fmt=lambda values:', '.join(f'{v:.6f}' for v in values)
    for w,h in ((7,30),(7,14),(14,30),(14,14)):
        for m,f in sorted(fits[f'frozen_transfer|{w}|{h}|2026-07-27'].items()):
            text+=f"| {w} / {h} | {m} | {f['standardized_intercept']:.6f} | {f['raw_intercept']:.6f} | {fmt(f['raw_slopes'])} | {fmt(f['means'])} | {fmt(f['scales'])} |\n"
    text+='''
## Strictly same-basis rolling origin

At every eligible issue, each learned fit uses only same-basis training labels strictly before that issue and at least 20 unique prior issue DAYS. No canonical model is available: the maximum eligible prior count is **13 days**, below 20. No older basis is borrowed. All unavailable issue fits and their counts remain in `availability.csv`.

Observed rolling-origin results below are a separate older regime. They are not canonical validation. At 30 days, basket beats rolling slightly but all learned models lose to unchanged. At 14 days, rolling wins this older cohort, while basket is near unchanged. Thus the primary transfer result does not generalize across these two tests.

### Observed 30-day target, seven-day inputs

'''+table(selected('rolling_observed_seller_data',7,30))
    text+='\n### Observed 14-day target, seven-day inputs\n\n'+table(selected('rolling_observed_seller_data',7,14))
    text+='\nObserved rolling-origin test ranges:\n\n'
    for w,h in ((7,30),(7,14),(14,30),(14,14)):
        m=selected('rolling_observed_seller_data',w,h)[0]
        aa=[a for a in availability if a['protocol']=='rolling_observed_seller_data' and a['window']==str(w) and a['horizon']==str(h) and a['available']=='True']
        text+=f"- Input {w}, target {h}: {m['first_issue']}–{m['last_issue']}, {m['issue_days']} issue days; training grows from {min(int(a['training_days']) for a in aa)} to {max(int(a['training_days']) for a in aa)} prior issue days.\n"
    text+='''
All secondary observed results and every term's MAE/RMSE/bias/direction/skill remain in `metrics.csv`. No horizon is selected from smaller absolute error.

## Common dates, dependence and limits

The frozen cross-horizon common cohort is August 3–14 (12 days / 36 term identities) for seven-day inputs, and August 10–14 (five days / 15 identities) for 14-day inputs. `common-horizons.json` gives the exact common IDs and each target's metrics. These are different labels even on common issue dates; no optimal horizon follows from their MAEs.

All 66 pairs of primary 30-day issue windows overlap: the 12 issues span only 11 calendar days. All 10 issue pairs in the five-day sensitivity overlap. At the 14-day/seven-day test, 273 of 378 issue pairs overlap. The three term rows share dates, retail conditions and futures shocks; 36 rows are not 36 independent observations. Training windows overlap as well. A 20-day minimum is a guard, not proof of adequate independent evidence. No independent-row confidence interval or p-value is supplied.

Export-time prices and statistics can be revised. Strict trade dates prevent nominal future-trade use, but do not prove that the original job had the revised rows. Prior replay found three May 26 stored median hedge differences because the export contains May 25 trades where the original job used May 22. No production archived-vintage collector is deployed for this experiment. Repeated errors, shared shocks, tariff/offer mix, eligibility, discounts and basis changes prevent causal supplier-response claims. Public medians can move when offers enter or leave without any identical offer repricing. Strong directional results on five days are not calibrated evidence.

## Verification and reproduction

From the repository root:

```sh
python3 tasks/forecast-accuracy-experiments/scripts/experiment.py
python3 tasks/forecast-accuracy-experiments/scripts/verify.py
python3 tasks/forecast-accuracy-experiments/scripts/report.py
python3 tasks/forecast-accuracy-experiments/scripts/reproduce.py
```

The verifier independently rebuilds raw owned statistic identities and expands delivery prices day by day with Decimal arithmetic. It checks all 729 basket vintages, including missing vintages; 474 matching issue-vintage rolling costs agree with the prior actual-PHP-verified hedge rows. It checks 954 features, 1,098 exact eligible pairs, 136 three-model fit sets, 3,564 prediction rows, all cohort identities, strict cutoffs, metric denominators and overlap arithmetic. Ridge normal-equation residuals independently validate every fit. Synthetic tests cover known lambda-zero and lambda-one solutions, zero-scale and all-constant features, missing trade, strict endpoint, valid zero, incomplete strip and invalid owned month without fallback. The prior PHP evidence is reused by verified hash; no application test, database fixture or production command is run here.

The reproducibility command runs calculation and verification twice, renders this report twice, and requires byte-identical artifacts and unchanged export/source hashes. Source and spec hashes are in `sources.json`; `spec.sha256` was saved before new metrics. Verification and reproducibility results are retained as JSON. No dependency was installed and no original export was changed.

## Minimal prospective experiment contract (proposal only)

Keep unchanged as the mandatory zero-change benchmark. Before future labels arrive, freeze one canonical target definition, the seven-day retail/rolling/fixed-basket features, shared-term ridge objective, 30-day primary and separately fitted 14-day secondary target, 20-prior-issue-day gate, .15 abstention, and exact paired-cohort/missing-data rules. Use strictly canonical-only rolling-origin labels; leave models unavailable until the gate passes. Do not silently reuse this observed-to-canonical transfer fit as prospective validation.

For every issue, archive immutable inputs with capture timestamps: exact public statistic IDs/values/basis/method, source revisions and constituent/eligibility evidence, full selected curve vintage with instrument IDs/settlements, identical-basket month/day weights at both feature endpoints, model/spec/source hashes, training IDs, scales and coefficients, predictions and unavailable reasons. Archive exact target-date evidence separately when it arrives; do not overwrite original inputs with later revisions. Retain original and revised-label scores separately.

Predeclare one review after at least 120 eligible issue days, multiple delivery rolls and distinct shocks; do not repeatedly inspect and stop at a winning result. Report unique dates, dependent-window uncertainty, all terms, common cohorts and unchanged skill. If an intercept-only control is desired to test the large mean-change contribution, freeze it in that new contract before new outcomes, not as a new winner in this report. Controlled-index results require their own frozen target and membership rules. Any application default or production collector needs separate approval. This is a proposal, not a deployed collector, dated follow-up or schedule.
'''
    (x.HERE/'report/report.md').write_text(text)
    print('Report rendered from retained metrics.')


if __name__=='__main__': main()
