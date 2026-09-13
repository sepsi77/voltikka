#!/usr/bin/env python3
"""Render this task's report from verified metrics."""
import sys
sys.dont_write_bytecode = True
from collections import Counter
import analyze as a


def main():
    ms=a.readcsv(a.OUT/'metrics.csv'); ts=a.readcsv(a.OUT/'transitions.csv'); misses=a.readcsv(a.OUT/'misses.csv')
    lines=['# Forecast direction sensitivity — offline, post-hoc',
           '', '## Decision', '',
           '**Keep the 0.15 prediction threshold. Do not start a lower-threshold trial for repaired v3 from this evidence.** Of its 33 missed rises, only six predictions are positive, none are zero, and 27 are negative. Lower thresholds mostly turn a neutral signal into a wrong fall signal. This is a wrong-sign problem, not mainly an excess of small positive predictions.',
           '', 'This does not require months of delay or proof of superiority. The present test directly rejects this simple candidate on the available rows. If a practical learned indicator is wanted, a small separately approved shadow trial of the historical-mean control and retail/rolling signals is reasonable, but these data do not identify a better production model. Do not replace price forecasts with an always-UP rule.',
           '', '## Fixed definitions and provenance', '',
           'Prediction thresholds: **0.15 / 0.10 / 0.05 c/kWh**. Actual outcomes always use **±0.15**, inclusive. Actual target minus current is Decimal, rounded half away from zero to four decimals. A change strictly inside the actual band is flat. This tests different signal rules against one unchanged target definition; extra alerts are not themselves improved accuracy. Lambda stays 0.30. No model was fit again.',
           '', 'V3 uses the original continuity replay JSON `expected_change_cents_per_kwh`; v2 uses the stored export field. The forecast and current price were rounded separately. Their difference is only a cross-check (maximum permitted error 0.0001), not the primary signal. All saved/numeric direction categories agree at 0.15. Slightly rising and slightly falling both map to flat. The separate saved-label baseline uses raw Decimal actual differences, as in the prior investigation; it is not overwritten.',
           '', 'Code inspection detail: `FixedTermForecastEvaluationService` rounds actual change to four decimals before classification. In the pinned working tree, `FixedTermPriceForecastService` still passes unrounded lambda × gap to `directionLabel`, then stores expected change at four decimals. The requested research rule uses that published field. There are zero saved-label/numeric disagreements in these selected v3/v2 rows; do not assume this proves all future rounding boundaries agree.',
           '', 'Learned and intercept signals use original unrounded prediction price minus current, with Decimal arithmetic and no rounding. They agree with original prediction_delta within 1e-12. Primary gap uses replay expected change; 141 older/secondary gap rows use the earlier published four-decimal price minus current because those artifacts have no expected-change field. This is explicitly separate from the primary replay field rule.',
           '', 'All 477 exported public-median statistic groups also match independently reconstructed raw snapshot medians and contract counts. Current and target prices and exact statistic IDs are checked again for selected rows. Original fits, predictions, prior verification artifacts and export bytes are pinned; no previous writer runs.',
           '', '## Matched 48 median rows', '',
           'July 27–August 14: 16 issue days × three terms. Stored v2 is absent on August 2–4 (nine median rows). Actual balance: **36 up, 12 flat, zero down**. Three-class balanced accuracy is unavailable. Down recall is undefined, not 0%. Counts below are correct / missed move / false move / wrong way.',
           '', '| Model | Prediction threshold | Correct | Missed | False | Wrong | Correct rate |', '|---|---:|---:|---:|---:|---:|---:|']
    def metric_table(cohorts,terms):
        for r in ms:
            if r['cohort'] in cohorts and r['term'] in terms:
                yield f"| {r['cohort']} {r['model']} {r['term']} | {r['threshold']} | {r['correct']} | {r['missed_move']} | {r['false_move']} | {r['wrong_way']} | {float(r['correct_rate']):.1%} |"
    lines.extend(metric_table(['matched48'],['all']))
    lines += ['', 'The saved labels reproduce v3 **13/33/0/2**, v2 **11/28/3/6**, and unchanged **12/36/0/0**. V3 term baselines are 6 months **1/13/0/2**, 12 months **3/13/0/0**, and 24 months **9/7/0/0**.', '',
              '### Lower-threshold transitions', '', '| Cohort/model/term | Threshold | Rescued misses | New false moves | New wrong ways | Correct lost |', '|---|---:|---:|---:|---:|---:|']
    for r in ts:
        if r['cohort']=='matched48' and r['model']=='v3':
            lines.append(f"| {r['cohort']} {r['model']} {r['term']} | {r['threshold']} | {r['rescued_misses']} | {r['new_false_moves']} | {r['new_wrong_ways']} | {r['correct_lost']} |")
    lines += ['', 'Both lower thresholds rescue **zero** of the 33 misses. Thus none of the six positive missed predictions reaches 0.05. The 0.10 rule creates four new wrong-way calls and three false moves; 0.05 creates 15 new wrong-way calls and five false moves. Every lost correct row was an actual flat. Price errors do not change when only direction thresholds change.', '',
              '### Sign split of saved v3 misses', '', '| Term | Positive | Zero | Negative |', '|---|---:|---:|---:|']
    for term in ('6','12','24','all'):
        c=Counter(r['sign'] for r in misses if term=='all' or r['term']==term)
        lines.append(f"| {term} | {c['positive']} | {c['zero']} | {c['negative']} |")
    lines += ['', '### Every saved v3 missed move', '',
              'IDs end in term and median. All actual directions below are up; all saved categories and numeric 0.15 categories are flat. Prices and changes are c/kWh.', '',
              '| ID | Target | Current | Actual | Forecast | Expected change | Forecast − current | Saved label | At 0.10 | At 0.05 |',
              '|---|---|---:|---:|---:|---:|---:|---|---|---|']
    for r in sorted(misses,key=lambda r:(int(r['term']),r['issue'])):
        lines.append(f"| {r['id'].replace('|',' / ')} | {r['target']} | {r['current']} | {r['actual']} | {r['prediction_price']} | {r['prediction_delta']} | {a.dec(r['prediction_price'])-a.dec(r['current'])} | {r['saved_direction']} | {r['at_010']} | {r['at_005']} |")
    lines += ['', '## Full v3 sensitivity: separate 57-row cohort', '',
              '19 issue days, July 27–August 14; actual balance 44 up / 13 flat / zero down. This is not the matched 48-row cohort. Compare thresholds only within this table.', '',
              '| Model/cohort/term | Threshold | Correct | Missed | False | Wrong | Correct rate |', '|---|---:|---:|---:|---:|---:|---:|']
    lines.extend(metric_table(['full57'],['all']))
    lines += ['', '## Learned models and constant-direction controls', '',
              'All models within a cohort have exactly the same IDs, dates, current values and targets. Primary: 36 rows / 12 days, August 3–14, 30-day canonical target and seven-day input. Older: 57 rows / 19 days, observed rolling-origin, 30-day target and seven-day input. Secondary: 84 rows / 28 days, frozen canonical 14-day target and seven-day input. These are separate targets/regimes, not a pooled score or horizon selection.', '',
              'Always-UP and always-DOWN are fixed, hindsight-free **direction-only controls**. Neither is a numeric price forecast. They show class imbalance, not a method that can identify a future move. No constants were fitted.', '',
              '| Model/cohort/term | Threshold | Correct | Missed | False | Wrong | Correct rate |', '|---|---:|---:|---:|---:|---:|---:|']
    lines.extend(metric_table(['primary36','older57','secondary84'],['all']))
    lines += ['', 'The original **75%** primary direction result is exactly 27/36. Intercept, retail, rolling and basket all say up on every primary row at every tested threshold. So does always-UP. Actual balance is 27 up / nine flat / zero down. This cannot establish falling-price performance or feature value for direction. Always-DOWN scores zero, with nine false moves and 27 wrong ways.', '',
              'Older actual balance is 39 up / 16 flat / two down. Intercept and retail equal always-UP: 39/57 (68.4%). Rolling rises from 35 to 36 to 38 correct as the threshold falls; basket from 33 to 35 to 36. Neither beats the up-only control. Two falling rows are too few to establish stable fall performance.', '',
              'Secondary balance is 38 up / 45 flat / one down. Unchanged scores 45/84 (53.6%); gap at 0.15 scores 44/84. Intercept and retail equal always-UP at 38/84 (45.2%). Rolling and basket move from 32 to 35 to 38 correct, but still lose to unchanged. One falling row is weak evidence. Lower thresholds do not supply a stable cross-regime improvement.', '',
              '### Term trade-offs', '',
              'Matched v3 has no rescued misses in any term. Its six-month wrong-sign risk grows; 12/24-month flat outcomes can become false calls. Primary learned models all call up in every term: their term scores reflect only actual class balance, not term skill. Previous numerical-price gains were concentrated in six months; the price-error report is unchanged. No term-specific model or threshold is selected.', '',
              '| Model/cohort/term | Threshold | Correct | Missed | False | Wrong | Correct rate |', '|---|---:|---:|---:|---:|---:|---:|']
    lines.extend(metric_table(['matched48','primary36','older57','secondary84'],['6','12','24']))
    lines += ['', '## Complete machine evidence', '',
              '- `results/rows.csv` and `.json`: exact cohorts, IDs, fit IDs, prices, numerical signals and actual classes.',
              '- `results/metrics.csv` and `.json`: 348 groups, including all terms and full57, complete 3×3 confusion matrices (actual rows, prediction columns), exhaustive outcome counts/rates, dates, class balance, and all precision/recall numerators and denominators. Empty CSV / JSON null means undefined. Balanced accuracy is null unless all three actual classes occur.',
              '- `results/transitions.csv` and `.json`: 232 groups with complete 4×4 outcome and 3×3 prediction transitions and conserved totals.',
              '- `results/misses.*`, `saved-baselines.*`, `precision-audit.*`: full audit without changes to the previous baseline.',
              '- `results/summary.json`, `verification.json`, `reproducibility.json`: exclusions, checks and repeat hashes. No selected row failed or is missing. Nine mature v2 rows are unavailable; 90 immature v3 median rows and 294 nonmedian generated rows are out of scope. The learned source has 3,096 excluded rows (other windows/protocols and basket abstention), not failed forecasts.',
              '', '## Limits and release boundary', '',
              'This is post-hoc sensitivity on an already inspected holdout. All 66 pairs of primary issue windows overlap; terms share shocks. Revised export-time prices and futures do not prove original-vintage availability. Observed-to-canonical continuity is an assumption. Strict canonical rolling training remains unavailable: at most 13 matured prior issue days versus the unchanged 20-day gate. No future labels were used to train the pinned fits, but prior inspection still prevents fresh validation claims.', '',
              'The qualified outlook application work already exists locally and is undeployed. This task changes no application behavior. The application currently saves one direction threshold and reuses it for outcome evaluation. A future signal-threshold change would need explicit separate signal/outcome metadata and model identity, plus approval. Keep the outcome threshold 0.15 and preserve historical labels. Do not rename accuracy by moving its target boundary.', '',
              '## Reproduce', '', 'From repository root:', '', '```sh',
              'python3 tasks/forecast-direction-sensitivity/reproduce.py', '```', '',
              'This runs only the new analysis, independent verifier and report renderer twice. It checks byte-identical outputs and all 1,886 preserved files. No application tests or frontend build apply because this task changes no application, CSS or JS. No production, DB, dependencies, commit or push is used.']
    (a.HERE/'report.md').write_text('\n'.join(lines)+'\n')

if __name__=='__main__': main()
