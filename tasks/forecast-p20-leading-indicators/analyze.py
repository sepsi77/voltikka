#!/usr/bin/env python3
"""Frozen offline quantile study. All writes stay in this folder."""
import sys
sys.dont_write_bytecode = True
from collections import Counter, defaultdict
import csv
from datetime import date, timedelta
from decimal import Decimal, ROUND_HALF_UP
import hashlib
import importlib.util
import json
import math
from pathlib import Path
import statistics as st

HERE = Path(__file__).resolve().parent
ROOT = HERE.parents[1]
OUT = HERE / 'results'
spec = importlib.util.spec_from_file_location('prior_experiment', HERE.parent/'forecast-accuracy-experiments/scripts/experiment.py')
old = importlib.util.module_from_spec(spec)
spec.loader.exec_module(old)
P = old.prior
QS = ('p20', 'median', 'p80')
FREEZE = '2026-07-27'

def load(p): return json.loads(p.read_text())
def digest(p): return hashlib.sha256(p.read_bytes()).hexdigest()
def save(p, value): p.write_text(json.dumps(value, sort_keys=True, default=str, allow_nan=False, separators=(',', ':'))+'\n')
def table(name, rows):
    save(OUT/(name+'.json'), rows)
    if rows:
        with (OUT/(name+'.csv')).open('w', newline='') as f:
            w = csv.DictWriter(f, fieldnames=list(rows[0])); w.writeheader()
            for r in rows:
                w.writerow({k:json.dumps(v, sort_keys=True, default=str) if isinstance(v, (list,dict)) else v for k,v in r.items()})

def sources():
    for manifest in ('inputs.json', 'source-hashes.json'):
        for name, sha in load(HERE/manifest).items(): assert digest(ROOT/name) == sha, name
    assert digest(HERE/'spec.md') == (HERE/'spec.sha256').read_text().split()[0]
    proof = load(old.PRIOR/'horizon-lag-reproducibility.json')
    assert proof['status'] == 'passed' and proof['runs'] == 2
    for name, info in proof['byte_identical_machine_artifacts'].items():
        assert digest(old.PRIOR/name) == info['sha256']
    assert load(old.PRIOR/'horizon-lag-php-checks.json')['status'] == 'passed'
    return len(load(HERE/'inputs.json'))

def rounded(x): return float(Decimal(str(x)).quantize(Decimal('.0001'), rounding=ROUND_HALF_UP))
def difference(a,b): return float(Decimal(str(a))-Decimal(str(b)))
def direction(x): return 'up' if x >= .15 else 'down' if x <= -.15 else 'flat'
def outcome(p,a):
    return 'correct' if p == a else 'missed_move' if p == 'flat' else 'false_move' if a == 'flat' else 'wrong_way'

def vector(row):
    values = [P.number(row.get(q+'_value')) for q in QS]
    return dict(zip(QS,values)) if all(v is not None for v in values) and values[0] <= values[1] <= values[2] else None

class Evidence(P.Evidence):
    def vector(self,b,t,d): return vector(self.stats.get((b,t,d),{}))

def feature(e,b,t,d):
    lag = d-timedelta(days=7)
    current, prior = e.vector(b,t,d), e.vector(b,t,lag)
    if current is None: return None, 'missing_invalid_current_vector'
    if prior is None: return None, 'missing_invalid_same_basis_lag_vector'
    h, earlier = e.hedge(d,t), e.hedge(lag,t)
    if h['price'] is None or earlier['price'] is None: return None, 'missing_rolling_H'
    fixed = old.basket(e,lag,P.next_month(d),t)
    return dict(id=f'{b}|{t}|{d}',basis=b,term=t,issue=str(d),lag=str(lag),
                current_id=e.stats[b,t,d]['id'],lag_id=e.stats[b,t,lag]['id'],current=current,prior=prior,
                **{q+'_change':difference(current[q],prior[q]) for q in QS},
                spread=difference(current['median'],current['p20']),rolling_change=h['price']-earlier['price'],
                basket_change=h['price']-fixed['price'] if fixed['price'] is not None else None,
                current_H=h['price'],lag_H=earlier['price'],fixed_lag_H=fixed['price'],
                trade=str(h['trade']),lag_trade=str(earlier['trade']),fixed_lag_trade=fixed['trade']), ''

def make_pairs(e):
    features, pairs, exclusions = [], [], []
    for b in P.BASES:
        for d in P.days(P.START,P.END):
            for t in P.TERMS:
                f, reason = feature(e,b,t,d)
                if f: features.append(f)
                for h in (30,14):
                    target = d+timedelta(days=h)
                    v = e.vector(b,t,target)
                    why = reason or ('target_after_export' if target > P.END else 'missing_invalid_same_basis_target_vector' if v is None else '')
                    exclusions.append(dict(basis=b,issue=str(d),term=t,horizon=h,reason=why))
                    if why: continue
                    pairs.append(dict(**f,horizon=h,target=str(target),target_id=e.stats[b,t,target]['id'],actual=v,
                                      delta={q:difference(v[q],f['current'][q]) for q in QS},pair_id=f'{f["id"]}|{h}'))
    return features, pairs, exclusions

def complete(rows, basket=False):
    pool = [r for r in rows if not basket or r['basket_change'] is not None]
    terms = defaultdict(set)
    for r in pool: terms[r['basis'],r['issue'],r['horizon']].add(r['term'])
    return [r for r in pool if terms[r['basis'],r['issue'],r['horizon']] == set(P.TERMS)]

def solve(a,b):
    a = [list(row)+[v] for row,v in zip(a,b)]
    for j in range(len(b)):
        k = max(range(j,len(b)),key=lambda i:abs(a[i][j])); a[j],a[k] = a[k],a[j]
        assert abs(a[j][j]) > 1e-12
        pivot = a[j][j]; a[j] = [x/pivot for x in a[j]]
        for i in range(len(b)):
            if i != j:
                factor = a[i][j]; a[i] = [x-factor*y for x,y in zip(a[i],a[j])]
    return [row[-1] for row in a]

def fit(rows,names,q):
    means = [st.mean(r[k] for r in rows) for k in names]
    scales = [st.pstdev(r[k] for r in rows) for k in names]
    z = [[(r[k]-mu)/sd if sd else 0. for k,mu,sd in zip(names,means,scales)] for r in rows]
    intercept = st.mean(r['delta'][q] for r in rows)
    n = len(rows)
    a = [[sum(x[i]*x[j] for x in z)/n+(1. if i==j else 0.) for j in range(len(names))] for i in range(len(names))]
    rhs = [sum(x[j]*(r['delta'][q]-intercept) for x,r in zip(z,rows))/n for j in range(len(names))]
    slopes = solve(a,rhs)
    return dict(features=names,means=means,scales=scales,slopes=slopes,intercept=intercept,n=n,
                issue_days=len({r['issue'] for r in rows}),first_issue=min(r['issue'] for r in rows),
                last_issue=max(r['issue'] for r in rows),max_target=max(r['target'] for r in rows),
                train_ids=[r['pair_id'] for r in rows])

def predict(f,r):
    contributions = {k:s*(r[k]-mu)/sd if sd else 0. for k,mu,sd,s in zip(f['features'],f['means'],f['scales'],f['slopes'])}
    return f['intercept']+sum(contributions.values()), contributions

def models(study,q,cohort):
    history = [q+'_change']
    if study == 'A':
        result = {'intercept':[], 'history':history, 'rolling':history+['rolling_change']}
        if cohort == 'basket': result['basket'] = history+['basket_change']
    else:
        result = {'intercept':[], 'history':history, 'p20':history+['p20_change'],
                  'spread':history+['spread'], 'combined':history+['p20_change','rolling_change']}
    return result

def training_rows(rows,basis,cutoff,term):
    return [r for r in rows if r['basis']==basis and r['target'] < cutoff and (term=='all' or r['term']==term)]

def run_models(pairs):
    predictions, fits, availability = [], {}, []
    for h in (30,14):
        for cohort in ('native','basket'):
            pool = complete([r for r in pairs if r['horizon']==h],cohort=='basket')
            for protocol, basis in [('transfer',P.BASES[1]),('rolling_observed',P.BASES[0]),('rolling_canonical',P.BASES[1])]:
                test = [r for r in pool if r['basis']==basis and (protocol!='transfer' or r['issue']>=FREEZE)]
                cutoffs = [FREEZE] if protocol=='transfer' else sorted({r['issue'] for r in test})
                for cutoff in cutoffs:
                    testing = test if protocol=='transfer' else [r for r in test if r['issue']==cutoff]
                    train_basis = P.BASES[0] if protocol=='transfer' else basis
                    for scope,term in [('pooled','all'),('per_term',6),('per_term',12),('per_term',24)]:
                        training = training_rows(pool,train_basis,cutoff,term)
                        local = [r for r in testing if term=='all' or r['term']==term]
                        days = len({r['issue'] for r in training})
                        prefix = f'{h}|{cohort}|{protocol}|{cutoff}|{scope}|{term}'
                        availability.append(dict(id=prefix,horizon=h,cohort=cohort,protocol=protocol,cutoff=cutoff,scope=scope,
                            term=term,training_basis=train_basis,training_rows=len(training),training_days=days,
                            test_rows=len(local),available=days>=20))
                        if days<20: continue
                        for study in (('A','B') if cohort=='native' else ('A',)):
                            for q in QS if study=='A' else ('median',):
                                for model,names in models(study,q,cohort).items():
                                    fit_id = f'{prefix}|{study}|{q}|{model}'
                                    f = dict(**fit(training,names,q),cutoff=cutoff,training_basis=train_basis,scope=scope,
                                             term=term,quantile=q,model=model,study=study,horizon=h,cohort=cohort,protocol=protocol)
                                    fits[fit_id] = f
                                    for r in local:
                                        value, contributions = predict(f,r)
                                        predictions.append(prediction(r,study,cohort,protocol,scope,q,model,value,fit_id,contributions))
                                for model,value in [('unchanged',0.),('always_up',None),('always_down',None)]:
                                    for r in local:
                                        predictions.append(prediction(r,study,cohort,protocol,scope,q,model,value,None,{}))
    return predictions,fits,availability

def prediction(r,study,cohort,protocol,scope,q,model,value,fit_id,contributions):
    actual_class = direction(rounded(r['delta'][q]))
    predicted_class = model[7:] if model.startswith('always_') else direction(value)
    return dict(pair_id=r['pair_id'],study=study,cohort=cohort,protocol=protocol,scope=scope,quantile=q,model=model,
                horizon=r['horizon'],term=r['term'],issue=r['issue'],target=r['target'],basis=r['basis'],
                current=r['current'][q],actual=r['actual'][q],delta=r['delta'][q],actual_delta_4dp=rounded(r['delta'][q]),
                prediction_delta=value,forecast=r['current'][q]+value if value is not None else None,
                error=value-r['delta'][q] if value is not None else None,predicted_class=predicted_class,
                actual_class=actual_class,outcome=outcome(predicted_class,actual_class),fit_id=fit_id,contributions=contributions,
                saved_delta_class=direction(rounded(value)) if value is not None else None)

GROUP = ('study','cohort','protocol','scope','horizon','quantile')
def metrics(rows):
    n = len(rows); issues = sorted({r['issue'] for r in rows})
    errors = [r['error'] for r in rows if r['error'] is not None]
    counts = Counter(r['outcome'] for r in rows)
    classes = ('up','down','flat')
    confusion = {a:{p:sum(r['actual_class']==a and r['predicted_class']==p for r in rows) for p in classes} for a in classes}
    baseline = st.mean(abs(r['delta']) for r in rows)
    mae = st.mean(abs(e) for e in errors) if len(errors)==n else None
    return dict(n=n,issue_days=len(issues),first_issue=issues[0],last_issue=issues[-1],
        first_target=min(r['target'] for r in rows),last_target=max(r['target'] for r in rows),
        mae=mae,rmse=math.sqrt(st.mean(e*e for e in errors)) if len(errors)==n else None,
        bias=st.mean(errors) if len(errors)==n else None,skill=1-mae/baseline if mae is not None and baseline else None,
        **{k:counts[k] for k in ('correct','wrong_way','missed_move','false_move')},
        actual_balance={c:sum(confusion[c].values()) for c in classes},
        predicted_balance={c:sum(confusion[a][c] for a in classes) for c in classes},confusion=confusion,
        precision={c:confusion[c][c]/sum(confusion[a][c] for a in classes) if sum(confusion[a][c] for a in classes) else None for c in classes},
        recall={c:confusion[c][c]/sum(confusion[c].values()) if sum(confusion[c].values()) else None for c in classes},
        raw_saved_class_disagreements=sum(r['prediction_delta'] is not None and r['predicted_class']!=r['saved_delta_class'] for r in rows))

def summarize(predictions):
    groups = defaultdict(list)
    for r in predictions:
        for term in (str(r['term']),'all'): groups[tuple(r[k] for k in GROUP)+(term,)].append(r)
    output, daily, increments = [], [], []
    for key, rows in sorted(groups.items()):
        meta = dict(zip(GROUP+('term',),key))
        bymodel = defaultdict(list)
        for r in rows: bymodel[r['model']].append(r)
        scores = {m:metrics(rs) for m,rs in bymodel.items()}
        for model, score in sorted(scores.items()):
            controls = {c:score['mae']-scores[c]['mae'] if score['mae'] is not None else None for c in ('unchanged','intercept','history')}
            output.append(dict(**meta,model=model,**score,mae_minus=controls))
            if score['mae'] is None: continue
            for control in ('unchanged','intercept','history')+(('p20',) if meta['study']=='B' and model=='combined' else ()):
                if control==model: continue
                ref = {r['pair_id']:r for r in bymodel[control]}
                bydate = defaultdict(list)
                for r in bymodel[model]: bydate[r['issue']].append(abs(r['error'])-abs(ref[r['pair_id']]['error']))
                local = [dict(**meta,model=model,control=control,issue=d,n=len(v),error_difference=st.mean(v)) for d,v in sorted(bydate.items())]
                daily.extend(local); v = [r['error_difference'] for r in local]
                increments.append(dict(**meta,model=model,control=control,issue_days=len(v),rows=sum(r['n'] for r in local),
                    mean=st.mean(v),median=st.median(v),minimum=min(v),maximum=max(v),
                    better_days=sum(x<0 for x in v),worse_days=sum(x>0 for x in v),tie_days=sum(x==0 for x in v),
                    best_issue=min(local,key=lambda r:r['error_difference'])['issue'],worst_issue=max(local,key=lambda r:r['error_difference'])['issue']))
    return output,daily,increments

def comovement(features):
    rows = []
    for b in P.BASES:
        for t in (*P.TERMS,'all'):
            rs = [r for r in features if r['basis']==b and (t=='all' or r['term']==t)]
            x,y = [r['p20_change'] for r in rs],[r['median_change'] for r in rs]
            mx,my = st.mean(x),st.mean(y)
            den = math.sqrt(sum((v-mx)**2 for v in x)*sum((v-my)**2 for v in y))
            rows.append(dict(basis=b,term=t,n=len(rs),issue_days=len({r['issue'] for r in rs}),
                contemporaneous_past_change_correlation=sum((a-mx)*(b-my) for a,b in zip(x,y))/den if den else None,
                note='Same seven-day endpoints: not lead evidence'))
    return rows

def main():
    sources(); manifest,data = P.verified_export(); e = Evidence(data); OUT.mkdir(exist_ok=True)
    features,pairs,exclusions = make_pairs(e)
    predictions,fits,availability = run_models(pairs)
    scores,daily,increments = summarize(predictions)
    baskets = []
    for d in P.days(P.START,P.END):
        for t in P.TERMS:
            for lag in (0,7):
                baskets.append(dict(issue=str(d),term=t,lag=lag,cutoff=str(d-timedelta(days=lag)),
                                    **old.basket(e,d-timedelta(days=lag),P.next_month(d),t)))
    for name,rs in [('features',features),('pairs',pairs),('exclusions',exclusions),('predictions',predictions),
                    ('availability',availability),('metrics',scores),('paired-daily',daily),('increments',increments),
                    ('baskets',baskets),('comovement',comovement(features))]: table(name,rs)
    save(OUT/'fits.json',fits)
    save(OUT/'audit.json',dict(statistics=len(data['statistics']),futures=len(data['futures']),stored_forecasts=len(data['forecasts']),
        owned_statistics=len(e.stats),invalid_vectors=sum(e.vector(*key) is None for key in e.stats),
        missing_nonfinite_vectors=sum(any(P.number(r.get(q+'_value')) is None for q in QS) for r in e.stats.values()),
        out_of_order_vectors=sum(all(P.number(r.get(q+'_value')) is not None for q in QS) and not vector(r) for r in e.stats.values()),
        manifest_sha256=digest(P.EXPORT/'manifest.json'),spec_sha256=digest(HERE/'spec.md'),source_files=sources()))
    print(f'Analysis: {len(pairs)} pairs, {len(fits)} fits, {len(predictions)} predictions, {len(scores)} metric groups')

if __name__=='__main__': main()
