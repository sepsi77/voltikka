#!/usr/bin/env python3
"""Offline futures-only test. Writes only this folder; no retail lag gate."""
import sys
sys.dont_write_bytecode = True
from pathlib import Path
import importlib.util
import json
import hashlib
from datetime import date, timedelta
from collections import Counter, defaultdict
import statistics as st

HERE = Path(__file__).resolve().parent
ROOT = HERE.parents[1]
spec = importlib.util.spec_from_file_location('study', HERE.parent/'forecast-p20-leading-indicators/analyze.py')
S = importlib.util.module_from_spec(spec)
spec.loader.exec_module(S)
P = S.P
MODELS = ('unchanged','production_mean','feature_mean','feature_futures','production_futures')

def save(name, obj):
    (HERE/name).write_text(json.dumps(obj, sort_keys=True, default=str, allow_nan=False, separators=(',', ':'))+'\n')

def hashes():
    pins = json.loads((HERE/'inputs.json').read_text())
    for path, sha in pins.items():
        assert S.digest(ROOT/path) == sha, path
    return pins

def seam(e,t):
    return min((d for b,term,d in e.stats if term==t and b==P.BASES[1]), default=date.max)

def basis(e,t,d):
    return P.BASES[d >= seam(e,t)]

def pair(e,t,d,h):
    end = d+timedelta(days=h)
    b = basis(e,t,d)
    if end>P.END: return None,'target_after_export'
    if basis(e,t,end)!=b: return None,'cross_seam'
    c,a = e.price(b,t,d),e.price(b,t,end)
    if c is None: return None,'missing_invalid_current'
    if a is None: return None,'missing_invalid_target'
    return dict(pair_id=f'{t}|{d}|{h}',term=t,issue=str(d),target=str(end),horizon=h,basis=b,
                current=c,actual=a,delta={'median':S.difference(a,c)},current_id=e.stats[b,t,d]['id'],target_id=e.stats[b,t,end]['id']),''

def features(e,t,d):
    lag=d-timedelta(days=7)
    baskets={k:S.old.basket(e,endpoint,start,t) for k,endpoint,start in
             [('current',d,P.next_month(d)),('fixed_lag',lag,P.next_month(d)),('rolling_lag',lag,P.next_month(lag))]}
    x={}
    for cohort,k in [('fixed','fixed_lag'),('rolling','rolling_lag')]:
        c,l=baskets['current']['price'],baskets[k]['price']
        x[cohort]=c-l if c is not None and l is not None else None
    return dict(issue=str(d),lag=str(lag),term=t,x=x,baskets=baskets)

def train(pool,r):
    return [p for p in pool if p['term']==r['term'] and p['horizon']==r['horizon'] and p['target']<r['issue']]

def summarize(predictions):
    groups=defaultdict(list)
    for r in predictions:
        for t in (str(r['term']),'all'):
            for b in (r['basis'],'all'):
                for a in (r['actual_class'],'all'):
                    groups[r['horizon'],r['cohort'],t,b,a].append(r)
    result=[]
    for key,rs in sorted(groups.items()):
        meta=dict(zip(('horizon','cohort','term','basis','actual_class'),key))
        for model in MODELS:
            errors=[r['models'][model]['error'] for r in rs]
            counts=Counter(r['models'][model]['outcome'] for r in rs)
            paired={}
            for control in ('unchanged','production_mean','feature_mean'):
                daily=defaultdict(list)
                for r in rs:
                    daily[r['issue']].append(abs(r['models'][control]['error'])-abs(r['models'][model]['error']))
                vals=[st.mean(v) for v in daily.values()]
                paired[control]=dict(mean=st.mean(abs(r['models'][control]['error'])-abs(r['models'][model]['error']) for r in rs),
                    issue_mean=st.mean(vals),better_days=sum(v>0 for v in vals),worse_days=sum(v<0 for v in vals),tie_days=sum(v==0 for v in vals))
            result.append(dict(**meta,model=model,n=len(rs),issue_days=len({r['issue'] for r in rs}),
                first_issue=min(r['issue'] for r in rs),last_issue=max(r['issue'] for r in rs),
                first_target=min(r['target'] for r in rs),last_target=max(r['target'] for r in rs),
                mae=st.mean(abs(v) for v in errors),bias=st.mean(errors),
                **{k:counts[k] for k in ('correct','missed_move','wrong_way','false_move')},paired_improvement=paired))
    return result

def main():
    hashes()
    _,data=P.verified_export()
    e=S.Evidence(data)
    fs={f'{t}|{d}':features(e,t,d) for t in P.TERMS for d in P.days(P.START,P.END)}
    pairs=[]; excluded=Counter()
    for t in P.TERMS:
        for d in P.days(P.START,P.END):
            for h in (30,14):
                r,why=pair(e,t,d,h)
                excluded[t,h,basis(e,t,d),why or 'eligible_pair']+=1
                if r: pairs.append(r)
    predictions=[]; fits={}; availability=[]; sensitivity=[]
    for cohort in ('fixed','rolling'):
        pool=[dict(r,x=fs[f'{r["term"]}|{r["issue"]}']['x'][cohort]) for r in pairs]
        eligible=[r for r in pool if r['x'] is not None]
        for r in pool:
            full=train(pairs,r); local=train(eligible,r)
            reason='missing_futures' if r['x'] is None else 'production_history_under20' if len(full)<20 else 'feature_history_under20' if len(local)<20 else 'evaluated'
            availability.append(dict(pair_id=r['pair_id'],cohort=cohort,reason=reason,production_n=len(full),feature_n=len(local)))
            if reason!='evaluated': continue
            fid=f'{cohort}|{r["pair_id"]}'
            f=S.fit(local,['x'],'median')
            mean=st.mean(p['delta']['median'] for p in full)
            f.update(production_mean=mean,production_train_ids=[p['pair_id'] for p in full],production_max_target=max(p['target'] for p in full),
                     term=r['term'],horizon=r['horizon'],issue=r['issue'],cohort=cohort)
            fits[fid]=f
            conventional,contrib=S.predict(f,r)
            values=(0.,mean,f['intercept'],conventional,mean+contrib['x'])
            actual_delta=S.rounded(S.difference(r['actual'],S.rounded(r['current'])))
            ac=S.direction(actual_delta)
            models={}
            for model,raw in zip(MODELS,values):
                delta=S.rounded(raw); forecast=S.rounded(r['current']+delta)
                pc=S.direction(delta)
                models[model]=dict(raw_delta=raw,saved_delta=delta,forecast=forecast,error=S.difference(forecast,r['actual']),predicted_class=pc,outcome=S.outcome(pc,ac))
            predictions.append(dict(pair_id=r['pair_id'],fit_id=fid,term=r['term'],issue=r['issue'],target=r['target'],basis=r['basis'],
                                    horizon=r['horizon'],cohort=cohort,x=r['x'],actual_delta=actual_delta,actual_class=ac,models=models))
    for t in P.TERMS:
        fid=next(k for k,f in fits.items() if f['cohort']=='fixed' and f['horizon']==30 and f['term']==t)
        f=fits[fid]
        for x in (-1,0,1):
            _,c=S.predict(f,{'x':x}); delta=S.rounded(f['production_mean']+c['x'])
            sensitivity.append(dict(term=t,fit_id=fid,synthetic_x=x,hybrid_delta=delta,direction=S.direction(delta),label='behavior NOT accuracy'))
    save('evidence.json',dict(pairs=pairs,features=fs,fits=fits,availability=availability,
         exclusions=[dict(term=k[0],horizon=k[1],basis=k[2],reason=k[3],n=v) for k,v in sorted(excluded.items())],
         seams={t:str(seam(e,t)) for t in P.TERMS},source_counts={k:len(data[k]) for k in ('statistics','futures')}))
    save('predictions.json',predictions)
    save('results.json',dict(metrics=summarize(predictions),sensitivity=sensitivity,
                           availability_counts=dict(Counter(r['reason'] for r in availability))))
    hashes()
    print(f'{len(pairs)} pairs; {len(fits)} fits; {len(predictions)} matched tests (five models each)')

if __name__=='__main__': main()
