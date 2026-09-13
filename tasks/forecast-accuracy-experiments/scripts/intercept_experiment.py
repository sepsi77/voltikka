#!/usr/bin/env python3
"""Additive post-hoc ablation. Never run the original experiment writers."""
import sys
sys.dont_write_bytecode = True
from collections import defaultdict
import csv
import json
import statistics as st
import experiment as x

HERE = x.HERE
OUT = HERE/'report/intercept-artifacts'
MODELS = ('unchanged', 'intercept', 'gap', 'retail', 'rolling', 'basket', 'basket_abstain')


def load(path):
    return json.loads(path.read_text())


def readcsv(path):
    with path.open() as f:
        return list(csv.DictReader(f))


def save(name, value):
    OUT.mkdir(parents=True, exist_ok=True)
    (OUT/name).write_text(json.dumps(value, indent=2, sort_keys=True, allow_nan=False)+'\n')


def csvsave(name, rows):
    with (OUT/name).open('w', newline='') as f:
        writer = csv.DictWriter(f, fieldnames=list(rows[0]))
        writer.writeheader()
        writer.writerows(rows)


def check_inputs():
    assert x.digest(HERE/'intercept-spec.md') == (HERE/'intercept-spec.sha256').read_text().split()[0]
    for paths in (load(HERE/'intercept-inputs.json'), load(x.ART/'sources.json')):
        for path, sha in paths.items():
            assert x.digest(x.ROOT/path) == sha, path


def training_mean(rows):
    return st.mean(r['actual']-r['current'] for r in rows)


def grouped(rows):
    groups = defaultdict(list)
    for r in rows:
        for term in (str(r['term']), 'all'):
            groups[r['protocol'], r['window'], r['horizon'], r['model'], term].append(r)
    return groups


def summarize(rows):
    groups = grouped(rows)
    result = []
    for (p,w,h,m,t), group in sorted(groups.items()):
        base = x.metrics(groups[p,w,h,'intercept',t])['mae']
        values = x.metrics(group)
        adjustment = [r['prediction_delta']-r['intercept_delta'] for r in group]
        result.append(dict(protocol=p, window=w, horizon=h, model=m, term=t, **values,
                           mae_minus_intercept=values['mae']-base,
                           relative_mae_minus_intercept=values['mae']/base-1 if base else None,
                           mean_absolute_adjustment=st.mean(abs(v) for v in adjustment),
                           minimum_adjustment=min(adjustment), maximum_adjustment=max(adjustment)))
    return result


def table(rows):
    text = '| Model | Rows/days | MAE | RMSE | Bias | Direction % | Skill % | MAE − intercept | Relative % |\n'
    text += '|---|---:|---:|---:|---:|---:|---:|---:|---:|\n'
    for m in MODELS:
        r = next(r for r in rows if r['model']==m)
        text += (f"| {m} | {r['n']}/{r['issue_days']} | {r['mae']:.6f} | {r['rmse']:.6f} | "
                 f"{r['bias']:+.6f} | {100*r['direction_accuracy']:.2f} | {100*r['skill']:+.2f} | "
                 f"{r['mae_minus_intercept']:+.6f} | {100*r['relative_mae_minus_intercept']:+.2f} |\n")
    return text


def report(metrics, means):
    def select(p,w,h,t='all'):
        return [r for r in metrics if (r['protocol'],r['window'],r['horizon'],r['term'])==(p,w,h,t)]
    primary = {r['model']:r for r in select('frozen_transfer',7,30)}
    text = '''# Intercept-only post-hoc diagnostic

This user-approved continuation is separate from the original frozen experiment. The holdout was already inspected. This is **not untouched out-of-sample validation** and no result permits production adoption. `intercept-spec.md` and its checksum were frozen before these metrics. Original outputs and predictions remain unchanged.

## Main result

'''
    text += (f"The primary training mean is **{means['frozen_transfer|7|30|2026-07-27']['mean_delta']:.6f} c/kWh**. "
             f"Intercept-only MAE is **{primary['intercept']['mae']:.6f}**, versus unchanged {primary['unchanged']['mae']:.6f}, "
             f"retail {primary['retail']['mae']:.6f}, rolling {primary['rolling']['mae']:.6f}, and basket {primary['basket']['mae']:.6f}.\n\n")
    for model in ('retail','rolling','basket'):
        r=primary[model]
        text += f"- {model}: MAE minus intercept {r['mae_minus_intercept']:+.6f} c/kWh ({100*r['relative_mae_minus_intercept']:+.2f}%).\n"
    text += '''
Retail's gain against unchanged is mostly reproduced by the historical mean: its features lower primary MAE only slightly versus intercept. Rolling lowers primary error versus intercept. Fixed basket does not. These are paired error differences, not causal percentages of gain or variance explained. Against unchanged, the primary pooled gain is limited to the six-month term; 12 and 24 months lose, including intercept-only. No term-specific model is selected.

## Method and identities

Price = current + equal-row mean(training target − current), shared across all three terms. The mean is the standardized ridge intercept, not raw-feature b0. Each of 136 original fit sets keeps its exact retail/rolling/basket training IDs; no model is refit. Frozen training uses observed labels strictly before July 27. Rolling-origin means use each issue's same-basis labels strictly before that issue. The 20-prior-issue-DAY minimum is unchanged. Every training and test day has all three terms, so equal row weights and equal issue weights agree. No feature-free row expansion, term mean, calibration or intercept abstention is used.

Strict canonical rolling remains unavailable (maximum 13 prior issue days); no older-basis fill is used. Original availability and exclusions remain pinned. Predictions retain all 3,564 original rows and add 594 intercept rows. Each row saves its fit ID, pair ID, current, target, intercept, predicted price and paired absolute-error increment. `means.json` saves all training IDs, counts and cutoffs. Target is the same public offered energy-price median, not a controlled index or a price level fitted as the response.

Bias is predicted minus actual; direction is down/flat/up with absolute delta strictly below .15 flat. Skill = 1 − MAE / unchanged MAE. MAE − intercept and relative % use the same paired rows; negative is better. All price/error units are c/kWh. Signed errors can cancel across terms, and term MAE gains and losses can offset in the pooled difference. No causal fraction follows.

## All original cohorts, with per-term scores

'''
    for p in ('frozen_transfer','rolling_observed_seller_data'):
        for w,h in ((7,30),(7,14),(14,30),(14,14)):
            rows=select(p,w,h)
            r=rows[0]
            text += (f"### {p}: input {w}, target {h}\n\n"
                     f"{r['first_issue']}–{r['last_issue']}; {r['n']} rows / {r['issue_days']} days. "
                     f"Overlapping issue-window pairs: {r['overlapping_issue_pairs']}/{r['total_issue_pairs']}.\n\n"+table(rows))
            for t in ('6','12','24'):
                text += f'\n#### {t} months\n\n'+table(select(p,w,h,t))
    text += '\n## Feature adjustment from the training mean\n\n'
    text += '''Adjustment = existing learned predicted delta − the corresponding intercept-only delta. The absolute mean and signed range measure departure from the training mean, not independent feature causality. Slopes are conditional estimates with ridge shrinkage. Both retail and futures coefficients change between joint fits; a futures-versus-intercept comparison is not an isolated futures coefficient effect.

| Protocol | Input/target | Term | Model | Mean absolute adjustment | Minimum | Maximum |
|---|---|---|---|---:|---:|---:|
'''
    for r in metrics:
        if r['model'] in ('retail','rolling','basket'):
            text += (f"| {r['protocol']} | {r['window']}/{r['horizon']} | {r['term']} | {r['model']} | "
                     f"{r['mean_absolute_adjustment']:.6f} | {r['minimum_adjustment']:+.6f} | {r['maximum_adjustment']:+.6f} |\n")
    text += '\n## Transfer and secondary checks\n\n'
    for p in ('frozen_transfer','rolling_observed_seller_data'):
        for w,h in ((7,30),(7,14),(14,30),(14,14)):
            r={r['model']:r for r in select(p,w,h)}
            text += (f"- {p}, input {w}/target {h}: intercept MAE {r['intercept']['mae']:.6f}; "
                     f"rolling minus intercept {r['rolling']['mae_minus_intercept']:+.6f} "
                     f"({100*r['rolling']['relative_mae_minus_intercept']:+.2f}%); "
                     f"rolling minus retail {r['rolling']['mae']-r['retail']['mae']:+.6f}.\n")
    text += '''
With seven-day inputs, rolling improves over intercept in the frozen 14-day test, but both still lose to unchanged. In older observed rolling-origin tests, rolling loses to intercept at 30 days and beats it at 14 days. The same signs hold with 14-day inputs. Thus, added feature information does not show a stable benefit across targets and regimes, although rolling beats retail in all eight pooled cohorts.

The common issue/term cross-horizon subsets are retained separately in `common-metrics.csv` and `common-identities.json`, including per-term scores. Different labels and cohort lengths do not identify an optimal horizon. Fourteen-day inputs remain a predeclared sensitivity, not a replacement primary.

## Limits and reproduction

The primary has 36 term rows but only 12 issue days, August 3–14. All 66 primary issue-window pairs overlap. Training windows overlap too. Term rows share shocks. The observed-to-canonical transfer assumes regime continuity, not a common proven process. Older observed rolling results are separate evidence. Revised export-time statistics and curves do not prove original-vintage availability. Offer composition can move medians without identical offers repricing. The selected sample and prior inspection prevent independent validation or causal conclusions. No model, term, horizon, application default, collector or future experiment is selected or started.

Run from the repository root:

```sh
python3 tasks/forecast-accuracy-experiments/scripts/intercept_experiment.py
python3 tasks/forecast-accuracy-experiments/scripts/verify_intercept.py
python3 tasks/forecast-accuracy-experiments/scripts/reproduce_intercept.py
```

Independent verification reads raw export statistic identities and recomputes every training delta and mean, checks strict chronology, 20-day gates, balanced weights, exact original cohorts and predictions, all reported metrics, common subsets and synthetic constant/changing-mean data. It also runs original verification with writes disabled and reuses original PHP/H evidence by pinned hash. The two-run command checks identical new outputs and unchanged pinned originals and export sources. Machine evidence is in `report/intercept-artifacts/verification.json` and `reproducibility.json`. No Laravel test or build is needed: no application, CSS or JS changed.
'''
    (HERE/'report/intercept-report.md').write_text(text)


def main():
    check_inputs()
    pairs = {r['id']:r for r in load(x.ART/'pairs.json')}
    fits = load(x.ART/'fits.json')
    available = {r['fit_id']:r for r in readcsv(x.ART/'availability.csv')}
    means = {}
    for fid, models in fits.items():
        ids = models['retail']['identities']
        training = [pairs[i] for i in ids]
        mean = training_mean(training)
        for fit in models.values():
            assert fit['identities']==ids
            assert abs(mean-fit['standardized_intercept'])<=1e-10
        assert len({r['issue'] for r in training})>=20
        assert all(r['target']<available[fid]['cutoff'] for r in training)
        means[fid] = dict(mean_delta=mean, identities=ids, rows=len(ids),
                          issue_days=len({r['issue'] for r in training}), cutoff=available[fid]['cutoff'],
                          max_target=max(r['target'] for r in training))
    predictions = []
    for original in readcsv(x.ART/'predictions.csv'):
        r = dict(original)
        for k in ('term','window','horizon'): r[k]=int(r[k])
        for k in ('current','actual','delta','prediction_delta'): r[k]=float(r[k])
        candidates = [r]
        if r['model']=='unchanged':
            candidates.append(dict(r, model='intercept', prediction_delta=means[r['fit_id']]['mean_delta']))
        for row in candidates:
            mean = means[row['fit_id']]['mean_delta']
            predictions.append(dict(row, intercept_delta=mean, prediction_price=row['current']+row['prediction_delta'],
                                    absolute_error_increment=abs(row['prediction_delta']-row['delta'])-abs(mean-row['delta'])))
    metrics = summarize(predictions)
    common, identities = [], {}
    for window in (7,14):
        keys = set.intersection(*[{(r['issue'],r['term']) for r in predictions if r['protocol']=='frozen_transfer'
                                  and r['window']==window and r['horizon']==h} for h in (14,30)])
        identities[str(window)] = sorted(keys)
        common += summarize([r for r in predictions if r['protocol']=='frozen_transfer' and r['window']==window
                             and (r['issue'],r['term']) in keys])
    save('means.json',means)
    csvsave('predictions.csv',predictions)
    csvsave('metrics.csv',metrics)
    csvsave('common-metrics.csv',common)
    save('common-identities.json',identities)
    sources = [HERE/'intercept-spec.md',HERE/'intercept-spec.sha256',HERE/'intercept-inputs.json',
               *sorted((HERE/'scripts').glob('*intercept*.py'))]
    save('sources.json',{str(p.relative_to(x.ROOT)):x.digest(p) for p in sources})
    report(metrics,means)
    check_inputs()
    print(f'Calculated {len(means)} means, {len(predictions)} paired predictions, {len(metrics)} metric groups.')


if __name__=='__main__':
    main()
