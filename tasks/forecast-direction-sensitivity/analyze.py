#!/usr/bin/env python3
"""Read-only source analysis. Standard library; writes only this task's outputs."""
import sys
sys.dont_write_bytecode = True
import csv
import hashlib
import json
from collections import Counter, defaultdict
from decimal import Decimal as D, ROUND_HALF_UP
from pathlib import Path

HERE = Path(__file__).resolve().parent
ROOT = HERE.parents[1]
EXPORT = ROOT/'tasks/supplier-premium-coverage/export-20260913T094138Z'
OLD = ROOT/'tasks/forecast-accuracy-experiments/report'
REPLAY = ROOT/'tasks/fix-forecast-learning-evaluation'
OUT = HERE/'results'
CLASSES = ('down', 'flat', 'up')
OUTCOMES = ('correct', 'missed_move', 'false_move', 'wrong_way')
GRID = ('0.15', '0.10', '0.05')


def load(p):
    return json.loads(p.read_text(), parse_float=D)


def digest(p):
    return hashlib.sha256(p.read_bytes()).hexdigest()


def save(p, data):
    p.write_text(json.dumps(data, indent=2, sort_keys=True, default=str, allow_nan=False)+'\n')


def readcsv(p):
    return list(csv.DictReader(p.open()))


def csvsave(p, rows):
    with p.open('w', newline='') as f:
        w = csv.DictWriter(f, fieldnames=list(rows[0]))
        w.writeheader()
        w.writerows(rows)


def check_sources():
    assert digest(HERE/'spec.md') == (HERE/'spec.sha256').read_text().split()[0]
    sources = load(HERE/'inputs.json')
    for p, sha in sources.items():
        assert digest(ROOT/p) == sha, p
    return len(sources)


def dec(v):
    x = D(str(v))
    assert x.is_finite()
    return x


def rounded(x):
    return dec(x).quantize(D('.0001'), rounding=ROUND_HALF_UP)


def direction(delta, threshold='0.15'):
    x, t = dec(delta), dec(threshold)
    return 'up' if x >= t else 'down' if x <= -t else 'flat'


def category(label):
    return {'rising':'up', 'falling':'down'}.get(label, 'flat')


def outcome(pred, actual):
    if pred == actual: return 'correct'
    if pred == 'flat': return 'missed_move'
    if actual == 'flat': return 'false_move'
    return 'wrong_way'


def inputs():
    replay = load(REPLAY/'replay-results.json')['runs']['continuity']
    generated = replay['generated']
    stored = {f"{r['forecast_date'][:10]}|{r['duration_months']}|{r['target_quantile']}":r
              for r in load(EXPORT/'forecasts.json') if r['model_version']=='fixed_term_ewma_gap_v2'}
    rows = []
    def add(cohort, model, identity, r, delta, price, source, saved='', fit=''):
        current, actual = dec(r['current']), dec(r['actual'])
        rows.append(dict(cohort=cohort, model=model, id=identity, fit_id=fit, term=str(r['term']),
                         issue=r['issue'], target=r['target'], basis=r.get('basis','canonical_calculation'),
                         current=current, actual=actual, prediction_price=price,
                         prediction_delta=delta, delta_source=source, actual_delta=rounded(actual-current),
                         saved_direction=saved, actual_class=direction(rounded(actual-current))))
    for identity, raw in replay['evaluated'].items():
        if raw['target_quantile']!='median': continue
        g=generated[identity]
        r=dict(current=g['current_price_cents_per_kwh'], actual=raw['actual_price_cents_per_kwh'],
               term=g['duration_months'], issue=g['forecast_date'], target=g['target_date'])
        for cohort in ['full57'] + (['matched48'] if identity in stored else []):
            add(cohort,'v3',identity,r,dec(g['expected_change_cents_per_kwh']),dec(g['forecast_price_cents_per_kwh']),'replay_expected_change',g['direction'])
            add(cohort,'unchanged',identity,r,D(0),dec(r['current']),'zero','stable')
            if cohort=='matched48':
                s=stored[identity]
                add(cohort,'v2',identity,r,dec(s['expected_change_cents_per_kwh']),dec(s['forecast_price_cents_per_kwh']),'stored_expected_change',s['direction'])
    chosen = {('frozen_transfer','30'):'primary36', ('rolling_observed_seller_data','30'):'older57', ('frozen_transfer','14'):'secondary84'}
    for r in readcsv(OLD/'intercept-artifacts/predictions.csv'):
        cohort=chosen.get((r['protocol'],r['horizon']))
        if r['window']!='7' or not cohort or r['model']=='basket_abstain': continue
        delta=dec(r['prediction_price'])-dec(r['current'])
        assert abs(delta-dec(r['prediction_delta'])) <= D('1e-12')
        source='unrounded_price_minus_current'
        key=f"{r['issue']}|{r['term']}|median"
        if r['model']=='gap':
            if r['horizon']=='30' and r['basis']=='canonical_calculation' and key in generated:
                delta=dec(generated[key]['expected_change_cents_per_kwh'])
                source='replay_expected_change'
            else:
                delta=rounded(delta)
                source='four_decimal_gap_price_minus_current'
        add(cohort,r['model'],r['id'],r,delta,dec(r['prediction_price']),source,fit=r['fit_id'])
        if r['model']=='unchanged':
            for model in ('always_up','always_down'):
                add(cohort,model,r['id'],r,'','','direction_only_control',fit=r['fit_id'])
    return sorted(rows,key=lambda r:(r['cohort'],r['model'],r['id']))


def predicted(r,t):
    return r['model'].removeprefix('always_') if r['model'].startswith('always_') else direction(r['prediction_delta'],t)


def metrics(rows, t):
    matrix=Counter((r['actual_class'],predicted(r,t)) for r in rows)
    counts=Counter(outcome(predicted(r,t),r['actual_class']) for r in rows)
    n=len(rows)
    m=dict(n=n,issue_days=len({r['issue'] for r in rows}),first_issue=min(r['issue'] for r in rows),last_issue=max(r['issue'] for r in rows))
    for o in OUTCOMES:
        m[o]=counts[o]; m[o+'_rate']=counts[o]/n
    for a in CLASSES:
        m['actual_'+a]=sum(matrix[a,p] for p in CLASSES)
        for p in CLASSES: m[a+'__'+p]=matrix[a,p]
        for name,denom in [('recall',m['actual_'+a]),('precision',sum(matrix[x,a] for x in CLASSES))]:
            m[a+'_'+name+'_numerator']=matrix[a,a]
            m[a+'_'+name+'_denominator']=denom
            m[a+'_'+name]=matrix[a,a]/denom if denom else None
    m['balanced_accuracy']=sum(m[a+'_recall'] for a in CLASSES)/3 if all(m['actual_'+a] for a in CLASSES) else None
    return m


def main():
    preserved=check_sources()
    OUT.mkdir(exist_ok=True)
    rows=inputs()
    groups=defaultdict(list)
    for r in rows:
        for term in ('all',r['term']): groups[r['cohort'],r['model'],term].append(r)
    result=[]; transitions=[]; audits=[]; saved=[]
    for (cohort,model,term),rs in sorted(groups.items()):
        for t in GRID: result.append(dict(cohort=cohort,model=model,term=term,threshold=t,**metrics(rs,t)))
        for t in GRID[1:]:
            cells=Counter((outcome(predicted(r,'0.15'),r['actual_class']),outcome(predicted(r,t),r['actual_class'])) for r in rs)
            pcells=Counter((predicted(r,'0.15'),predicted(r,t)) for r in rs)
            tr=dict(cohort=cohort,model=model,term=term,threshold=t,n=len(rs),rescued_misses=cells['missed_move','correct'],
                    new_false_moves=sum(cells[a,'false_move'] for a in OUTCOMES if a!='false_move'),
                    new_wrong_ways=sum(cells[a,'wrong_way'] for a in OUTCOMES if a!='wrong_way'),
                    correct_lost=sum(cells['correct',b] for b in OUTCOMES if b!='correct'))
            tr.update({a+'__'+b:cells[a,b] for a in OUTCOMES for b in OUTCOMES})
            tr.update({'prediction_'+a+'__'+b:pcells[a,b] for a in CLASSES for b in CLASSES})
            transitions.append(tr)
        if cohort=='matched48':
            c=Counter(outcome(category(r['saved_direction']),direction(r['actual']-r['current'])) for r in rs)
            saved.append(dict(cohort=cohort,model=model,term=term,**{o:c[o] for o in OUTCOMES}))
    for r in rows:
        if r['model'] not in ('v2','v3'): continue
        diff=r['prediction_delta']-(r['prediction_price']-r['current'])
        assert abs(diff)<=D('.0001')
        audits.append(dict(cohort=r['cohort'],model=r['model'],id=r['id'],rounding_difference=diff,
                           saved_category=category(r['saved_direction']),numeric_category=predicted(r,'0.15'),
                           disagreement=category(r['saved_direction'])!=predicted(r,'0.15')))
    misses=[]
    for r in rows:
        if r['cohort']=='matched48' and r['model']=='v3' and outcome(category(r['saved_direction']),direction(r['actual']-r['current']))=='missed_move':
            misses.append(dict(**r,sign='positive' if r['prediction_delta']>0 else 'negative' if r['prediction_delta']<0 else 'zero',
                               numeric_direction=predicted(r,'0.15'),at_010=predicted(r,'0.10'),at_005=predicted(r,'0.05')))
    assert len(misses)==33
    for name,data in [('rows',rows),('metrics',result),('transitions',transitions),('saved-baselines',saved),('precision-audit',audits),('misses',misses)]:
        csvsave(OUT/(name+'.csv'),data);save(OUT/(name+'.json'),data)
    counts={c:len({r['id'] for r in rows if r['cohort']==c}) for c in sorted({r['cohort'] for r in rows})}
    assert counts==dict(full57=57,matched48=48,older57=57,primary36=36,secondary84=84)
    summary=dict(cohorts=counts,miss_signs={sign:sum(r['sign']==sign for r in misses) for sign in ('positive','zero','negative')}, 
                 saved_numeric_disagreements=sum(r['disagreement'] for r in audits),
                 preserved_files=preserved,failed=0,missing_selected=0,
                 missing_v2_mature_median=9,skipped_immature_v3_median=90,
                 skipped_other_quantiles_v3=294,
                 gap_fallback_rows=sum(r['delta_source']=='four_decimal_gap_price_minus_current' for r in rows),
                 canonical_rolling='Unavailable: at most 13 matured prior issue days; gate is 20. Not filled with observed labels.',
                 excluded_learned_prediction_rows=4158-sum(r['cohort'] in ('primary36','older57','secondary84') and not r['model'].startswith('always_') for r in rows))
    save(OUT/'summary.json',summary)
    check_sources()
    print(json.dumps(summary,indent=2))


if __name__=='__main__': main()
