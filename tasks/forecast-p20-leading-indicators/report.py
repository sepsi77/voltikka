#!/usr/bin/env python3
"""Render all prespecified comparisons. No model selection."""
import sys
sys.dont_write_bytecode = True
import analyze as a


def main():
    a.sources()
    scores = a.load(a.OUT/'metrics.json'); inc = a.load(a.OUT/'increments.json')
    availability = a.load(a.OUT/'availability.json'); proof = a.load(a.OUT/'verification.json')
    def get(**wanted):
        defaults = dict(study='A',quantile='median',model='rolling',scope='pooled',term='all',protocol='transfer',horizon=30,cohort='native')
        defaults.update(wanted)
        return next(r for r in scores if all(r[k]==v for k,v in defaults.items()))
    def f(x): return 'undefined' if x is None else f'{x:+.4f}'
    def skill(x): return 'undefined' if x is None else f'{100*x:+.1f}%'
    lines = ['# P20 leading indicators — offline study', '',
        '**This is an already inspected retrospective period, not an untouched holdout. No model is selected or released.**', '',
        'Prices and errors are consumer c/kWh. P20 is the 20th percentile of offered unit prices. It is not the mean of the cheapest 20%, and not a fixed set of companies. The target is public fixed-term 6/12/24-month energy-price unit statistics, not annual-cost statistics. Product turnover and promotions can move these quantiles without the same offers changing price.', '',
        '## Main findings', '',
        '**A has limited support; B does not show a stable p20-change lead.** With pooled coefficients, rolling futures lower p20 MAE versus unchanged, history and intercept in both horizons and both regimes. That consistency is stronger than for median or p80 versus their own unchanged/intercept controls. But the primary futures increment versus history is smaller for p20 (−0.0345) than median (−0.0422) or p80 (−0.0697). A higher total p20 skill is not proof of stronger independent futures information.', '',
        'Primary pooled p20 skill is +34.2%, but the 24-month term loses to unchanged and both 12/24-month terms lose to intercept. Independent per-term p20 rolling fits lower aggregate error versus history by only 0.0094 and lose to intercept by 0.0294. At 14 days they lose to history by 0.0034. Thus the benefit is not shared reliably across all duration/control choices. Primary fixed-basket pooled forecasts lose to rolling for all three quantiles.', '',
        'For B, primary pooled p20-change lowers median MAE by 0.0559 versus history and 0.0605 versus intercept. The six-month gain offsets losses at 12 and 24 months. Combined futures add another 0.0557 reduction versus p20. All these pooled primary models call UP on every row: 27/36 correct, exactly the always-UP result. This is a numerical gain, not a directional lead advantage.', '',
        'The p20-change result reverses in independent per-term primary fits (+0.0149 error versus history) and older observed pooled 30-day fits (+0.0049 versus history, +0.1902 versus intercept). At 14 days p20-change also loses to history in both regimes and fit scopes. Spread helps older observed 14-day fits (−0.0310 pooled, −0.0535 per-term versus history), but loses in canonical 14-day fits. There is no stable spread lead across regimes.', '',
        'Direction is also inconsistent. Pooled B p20/history correct counts are 27/27 of 36 primary, 38/38 of 84 canonical 14-day, 39/39 of 66 older 30-day, and 90/90 of 162 older 14-day rows. Per-term p20/history counts are 27/26, 52/60, 39/39 and 64/69 on those respective denominators. A small primary gain does not repeat across horizons. Full unfavorable cases and controls are below.', '',
        '## Frozen method', '',
        'Primary: 30-day target, seven-day inputs. Secondary: 14-day target, the same inputs. Training labels are strictly before July 27 for the fixed observed-to-canonical transfer. Current, seven-day lag and target each stay within one basis. Earlier rolling-origin fits use only same-basis labels strictly before each issue. The transfer assumes coefficients can cross the basis change; no feature or label does. All three quantiles and all three terms share native rolling-H input support. No missing price is filled.', '',
        'Pooled fits share slopes across terms. Independent per-term fits are a prespecified sensitivity, not a model choice after the result. Each uses at least 20 unique training issue days. Ridge is mean squared change error plus 1.0 times squared training-standardized slopes; the intercept is the historical mean target change. There are no term dummies, tuned thresholds or extra lag grids. Raw fitted changes are used for numerical errors and model direction. Actual direction uses four-decimal change, with +/-0.15 inclusive up/down. Always-UP/DOWN are direction-only controls.', '',
        'The fixed-basket A-only sensitivity prices identical issue-selected delivery months at both strictly prior trade vintages. Rolling H selects next-full-month delivery separately at each endpoint. Both use FI Base, complete month-quarter-year fallback, actual month-day weights and VAT 1.255. No gap/EWMA model is included, so the prior study\'s unrelated ten-day warmup does not restrict this training cohort.', '',
        '## Exact coverage', '', '| Protocol | Horizon | Test rows/days | Issue dates | Target dates |', '|---|---:|---|---|---|']
    for protocol in ('transfer','rolling_observed'):
        for h in (30,14):
            r = get(protocol=protocol,horizon=h)
            lines.append(f"| {protocol} | {h} | {r['n']}/{r['issue_days']} | {r['first_issue']}–{r['last_issue']} | {r['first_target']}–{r['last_target']} |")
    lines += ['', 'These counts apply separately to each quantile and each model, not to independent term observations. Every retained date has 6/12/24-month rows. Native A and B share the exact test and training identities; A-median and B controls have identical predictions. Fixed-basket support is identical to native support in this export; its controls are nevertheless fitted on its explicitly matched cohort. All 477 owned quantile vectors are finite and ordered; none are rejected.', '', '| Horizon | Frozen pooled training rows/days | First/last issue | Last target |', '|---|---|---|---|']
    fits = a.load(a.OUT/'fits.json')
    for h in (30,14):
        r = next(r for r in fits.values() if r['protocol']=='transfer' and r['horizon']==h and r['scope']=='pooled' and r['cohort']=='native')
        lines.append(f"| {h} | {r['n']}/{r['issue_days']} | {r['first_issue']}–{r['last_issue']} | {r['max_target']} |")
    maximum = {h:max(r['training_days'] for r in availability if r['protocol']=='rolling_canonical' and r['horizon']==h) for h in (30,14)}
    lines += ['', f"Strict canonical rolling-origin fits are unavailable: maximum training issue days are {maximum[30]} at 30 days and {maximum[14]} at 14 days, below 20. They do not borrow observed rows.", '',
        '## A: Does futures information help p20 more?', '',
        'Each q has its own target change and history input. Negative MAE increments mean lower error. Skill is relative to that quantile\'s own unchanged-price MAE. Raw MAEs across different quantile targets do not show which target is more predictable.', '',
        '| Fit | Quantile | Term | Unchanged MAE | Rolling MAE | Skill | Rolling − history | Rolling − intercept |', '|---|---|---|---:|---:|---:|---:|---:|']
    for scope in ('pooled','per_term'):
        for q in a.QS:
            for t in ('all','6','12','24'):
                r = get(scope=scope,quantile=q,term=t); u = get(scope=scope,quantile=q,term=t,model='unchanged')
                lines.append(f"| {scope} | {q} | {t} | {u['mae']:.4f} | {r['mae']:.4f} | {skill(r['skill'])} | {f(r['mae_minus']['history'])} | {f(r['mae_minus']['intercept'])} |")
    lines += ['', '## B: Does p20 add early information for the median?', '',
        'P20-change and spread inputs use issue-date or earlier prices only. Spread is median minus p20 at issue. Combined means median-history + p20-change + rolling futures change; it was fixed before results. Compare it with p20, not with a model selected as best after the test.', '',
        '| Fit | Term | Model | MAE | Skill | − history | − intercept | Correct/wrong/missed/false |', '|---|---|---|---:|---:|---:|---:|---|']
    for scope in ('pooled','per_term'):
        for t in ('all','6','12','24'):
            for model in ('history','p20','spread','combined'):
                r = get(study='B',scope=scope,term=t,model=model)
                counts = '/'.join(str(r[k]) for k in ('correct','wrong_way','missed_move','false_move'))
                lines.append(f"| {scope} | {t} | {model} | {r['mae']:.4f} | {skill(r['skill'])} | {f(r['mae_minus']['history'])} | {f(r['mae_minus']['intercept'])} | {counts} |")
    lines += ['', '## Regime and horizon checks', '',
        'These are separate regimes and different target windows. Describe sign consistency; do not select an optimal horizon. All term-level errors, bias, RMSE and full three-class precision/recall remain in `results/metrics.csv` and JSON.', '',
        '| Protocol | Horizon | Study/target/model | Fit | MAE | Skill | − history | − intercept |', '|---|---:|---|---|---:|---:|---:|---:|']
    for protocol in ('transfer','rolling_observed'):
        for h in (30,14):
            for scope in ('pooled','per_term'):
                for study,q,model in [('A',q,'rolling') for q in a.QS]+[('B','median',m) for m in ('p20','spread','combined')]:
                    r = get(protocol=protocol,horizon=h,scope=scope,study=study,quantile=q,model=model)
                    lines.append(f"| {protocol} | {h} | {study}/{q}/{model} | {scope} | {r['mae']:.4f} | {skill(r['skill'])} | {f(r['mae_minus']['history'])} | {f(r['mae_minus']['intercept'])} |")
    lines += ['', '## Fixed-basket sensitivity (A only)', '', '| Protocol | Horizon | Quantile | Fit | Basket MAE | − history | − intercept | − rolling |', '|---|---:|---|---|---:|---:|---:|---:|']
    for protocol in ('transfer','rolling_observed'):
        for h in (30,14):
            for scope in ('pooled','per_term'):
                for q in a.QS:
                    r = get(protocol=protocol,horizon=h,scope=scope,quantile=q,model='basket',cohort='basket')
                    rolling = get(protocol=protocol,horizon=h,scope=scope,quantile=q,cohort='basket')
                    lines.append(f"| {protocol} | {h} | {q} | {scope} | {r['mae']:.4f} | {f(r['mae_minus']['history'])} | {f(r['mae_minus']['intercept'])} | {f(r['mae']-rolling['mae'])} |")
    lines += ['', '## Direction and class balance', '',
        'Rows are up/down/flat counts. Always-UP accuracy equals the up share: 75% when 27 of 36 actuals rise is not independent skill. Downward recall is undefined when there are no actual falls. No zero-denominator metric is replaced with zero.', '',
        '| Protocol | Horizon | Quantile | Actual up/down/flat | Rolling correct/n | UP correct/n | DOWN correct/n |', '|---|---:|---|---|---|---|---|']
    for protocol in ('transfer','rolling_observed'):
        for h in (30,14):
            for q in a.QS:
                r = get(protocol=protocol,horizon=h,quantile=q)
                up = get(protocol=protocol,horizon=h,quantile=q,model='always_up'); down = get(protocol=protocol,horizon=h,quantile=q,model='always_down')
                balance = '/'.join(str(r['actual_balance'][c]) for c in ('up','down','flat'))
                lines.append(f"| {protocol} | {h} | {q} | {balance} | {r['correct']}/{r['n']} | {up['correct']}/{up['n']} | {down['correct']}/{down['n']} |")
    lines += ['', '## Paired issue-date errors and unfavorable cases', '',
        'Each date first averages its three term errors. Negative is better. All dates and all model/control differences are saved, not only favorable examples. Date windows overlap and share shocks; these ranges are not confidence intervals.', '',
        '| Protocol | Study/target/model/control | Better/worse/tie days | Mean increment | Best date/value | Worst date/value |', '|---|---|---|---:|---|---|']
    for protocol in ('transfer','rolling_observed'):
        for study,q,model,control in [('A',q,'rolling','history') for q in a.QS]+[('B','median','p20','history'),('B','median','spread','history'),('B','median','combined','p20')]:
            r = next(r for r in inc if r['protocol']==protocol and r['horizon']==30 and r['cohort']=='native' and r['scope']=='pooled' and r['term']=='all' and r['study']==study and r['quantile']==q and r['model']==model and r['control']==control)
            lines.append(f"| {protocol} | {study}/{q}/{model}/{control} | {r['better_days']}/{r['worse_days']}/{r['tie_days']} ({r['issue_days']} total) | {f(r['mean'])} | {r['best_issue']} / {f(r['minimum'])} | {r['worst_issue']} / {f(r['maximum'])} |")
    lines += ['', 'Past seven-day p20 and median changes also move together contemporaneously (`comovement.csv`). That same-day relation is not evidence that p20 leads. Only future-target predictions and the earlier rolling-origin check address lead value, and this short retrospective evidence is limited.', '',
        '## Verification, limits and small next step', '',
        f"Independent verification passed {proof['raw_pairs']} raw pairs, {proof['raw_features']} feature rows, {proof['daily_expanded_baskets']} Decimal daily-expanded baskets, {proof['zero_lag_verified_H']} zero-lag comparisons to retained PHP-verified H evidence, {proof['normal_equation_fits']} normal-equation fit checks, {proof['predictions']} predictions and {len(proof['synthetic_checks'])} synthetic guards. Raw versus four-decimal saved model direction differs on {proof['raw_saved_class_disagreements']} predictions. All exact IDs, cutoffs, training means/scales/slopes, feature contributions and unavailable fits are retained. Repeated origins create many fit instances; they do not add model definitions or search parameters.", '',
        'Input/source/spec hashes are checked before and after. `reproduce.py` runs the analysis, independent verifier and renderer twice and requires byte-identical outputs. Run `python3 tasks/forecast-p20-leading-indicators/reproduce.py` from the repository root. No Laravel tests or asset build are needed because no application, CSS or JS changed.', '',
        'This export can contain revisions and is not archived issue-time evidence. Overlapping dates are not independent, and term rows share market shocks. Distribution composition is uncontrolled. Error increments are not causal fractions or a variance decomposition. No automatic adoption follows.', '',
        'Small next step: if the user wants to continue, repeat these same few comparisons on the next separate local export with matured targets, keeping dates, features and thresholds fixed. This needs no production model change or archive collector. Do not tune to this period or choose a model from these tables.', '']
    (a.HERE/'report.md').write_text('\n'.join(lines))
    print('Report rendered')

if __name__=='__main__': main()
