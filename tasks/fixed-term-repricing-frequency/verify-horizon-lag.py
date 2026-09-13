#!/usr/bin/env python3
"""Independent raw-data and arithmetic checks. Does not call analysis calculations."""
from collections import defaultdict, Counter
import csv
from datetime import date, timedelta
from decimal import Decimal, ROUND_HALF_UP
from functools import lru_cache
import hashlib
import importlib.util
import json
import math
from pathlib import Path
import statistics
import sys
sys.dont_write_bytecode = True

HERE = Path(__file__).resolve().parent
EXPORT = HERE.parent/'supplier-premium-coverage/export-20260913T094138Z'
D = Decimal
BASES = ('observed_seller_data','canonical_calculation')
TERMS, HORIZONS, LAGS = (6,12,24), (14,30,45,60), (0,7,14,21,30,45,60)
START, END = date(2026,4,8), date(2026,9,13)

def read(name):
    return list(csv.DictReader((HERE/name).open()))

def numeric(x):
    if x is None or x == '':
        return None
    n = D(str(x))
    return n if n.is_finite() else None

def near(a,b,tol=1e-9):
    assert abs(float(a)-float(b)) < tol, (a,b)

def rounded(x):
    return x.quantize(D('.0001'), rounding=ROUND_HALF_UP)

def calendar(a,b):
    while a <= b:
        yield a
        a += timedelta(days=1)

def month_after(d,offset=1):
    n = d.year*12+d.month-1+offset
    return date(n//12,n%12+1,1)

manifest = json.loads((EXPORT/'manifest.json').read_text())
assert hashlib.sha256((EXPORT/'manifest.json').read_bytes()).hexdigest() == (EXPORT/'manifest.sha256').read_text().split()[0]
data = {}
for name,q in manifest['queries'].items():
    raw = (EXPORT/q['file']).read_bytes()
    assert len(raw) == q['bytes'] and hashlib.sha256(raw).hexdigest() == q['sha256']
    data[name] = json.loads(raw)
    assert len(data[name]) == q['row_count'] == q['expected_count']
    for sql in ('sql','count_sql'):
        assert hashlib.sha256(q[sql].encode()).hexdigest() == q[sql+'_sha256']
curves = defaultdict(dict)
for r in data['futures']:
    if r['area']=='FI' and r['product']=='Base':
        curves[date.fromisoformat(r['trade_date'])][r['maturity_type'],r['maturity']] = numeric(r['settlement_price'])

@lru_cache(None)
def hedge(issue,term):
    prior = [d for d in curves if d < issue]
    if not prior:
        return None
    curve = curves[max(prior)]
    # Independent daily expansion, rather than multiplying monthly settlements by days.
    start, end = month_after(issue), month_after(issue,term+1)-timedelta(days=1)
    prices = []
    for day in calendar(start,end):
        keys = [('month',day.strftime('%Y%m')),('quarter',f'{day.year}{1+3*((day.month-1)//3):02}'),('year',f'{day.year}01')]
        key = next((k for k in keys if k in curve),None)
        p = curve[key] if key else None
        if p is None:
            return None
        prices.append(p)
    return sum(prices)/len(prices)*D('.1255')

owned = {}
for r in sorted(data['statistics'],key=lambda r:int(r['id'])):
    if (r['method_version']=='unit_statistics_v1' and r['metric_key']=='energy_price' and r['consumption_kwh'] is None
            and r['segment_key'] in [f'fixed_term_{t}' for t in TERMS] and r['pricing_basis'] in BASES):
        owned[r['pricing_basis'],int(r['segment_key'].split('_')[-1]),date.fromisoformat(r['stat_date'])] = r
prices = {k:numeric(r['median_value']) for k,r in owned.items()}

@lru_cache(None)
def forecast(basis,term,issue):
    boundaries = [d for b,t,d in owned if b==BASES[1] and t==term and d<=issue]
    boundary = min(boundaries) if basis==BASES[1] and boundaries else None
    premiums, used = [], []
    for d in calendar(START,issue-timedelta(days=1)):
        b = BASES[0] if boundary and d<boundary else basis
        p,h = prices.get((b,term,d)),hedge(d,term)
        if p is not None and h is not None:
            premiums.append(p-h)
            used.append(d)
    if len(premiums)<10 or hedge(issue,term) is None:
        return None,used,None
    # Closed-form geometric weights independently verify iterative EWMA.
    n = len(premiums)
    ewma = premiums[0]*D('.75')**(n-1)+sum(premiums[i]*D('.25')*D('.75')**(n-1-i) for i in range(1,n))
    p = prices[basis,term,issue]
    return rounded(p+D('.30')*(hedge(issue,term)+ewma-p)),used,ewma

hedges = read('horizon-lag-hedges.csv')
for r in hedges:
    d,t = date.fromisoformat(r['date']),int(r['term'])
    h = hedge(d,t)
    assert (h is None)==(r['price']=='')
    if h is not None:
        near(h,r['price'])
        assert date.fromisoformat(r['trade'])==max(x for x in curves if x<d)
        assert r['basket']==str(month_after(d))
    strip=json.loads(r['strip'])
    if strip:
        assert sum(x['days'] for x in strip)==(month_after(d,t+1)-month_after(d)).days
        for x in strip:
            m=date.fromisoformat(x['month'])
            assert x['days']==(month_after(m)-m).days

rows = read('horizon-lag-forecasts.csv')
actual_keys = set()
for r in rows:
    b,t,h,d = r['basis'],int(r['term']),int(r['horizon']),date.fromisoformat(r['issue'])
    target = date.fromisoformat(r['target'])
    assert target-d==timedelta(days=h) and target<=END
    key=(b,t,h,d)
    assert key not in actual_keys
    actual_keys.add(key)
    near(r['current'],prices[b,t,d]); near(r['actual'],prices[b,t,target]); near(r['unchanged'],r['current'])
    f,used,ewma=forecast(b,t,d)
    assert (f is None)==(r['gap']=='')
    if f is not None:
        near(f,r['gap']); near(ewma,r['normal_premium'])
    assert len(used)==int(r['history_count'])
    if used:
        assert r['history_start']==str(used[0]) and r['history_end']==str(used[-1]) and used[-1]<d
    lag=d-timedelta(days=14)
    assert r['momentum_lag_date']==str(lag)
    old=prices.get((b,t,lag))
    assert (old is None)==(r['momentum']=='')
    if old is not None:
        near(old,r['momentum_lag_value'])
        near(rounded(prices[b,t,d]+(prices[b,t,d]-old)*D(h)/14),r['momentum'])
    assert int(r['matched'])==int(f is not None and old is not None)
expected_keys={(b,t,h,d) for b,t,d in prices for h in HORIZONS
               if prices[b,t,d] is not None and prices.get((b,t,d+timedelta(days=h))) is not None and d+timedelta(days=h)<=END}
assert actual_keys==expected_keys
for c in read('horizon-lag-coverage.csv'):
    b,t,h=c['basis'],int(c['term']),int(c['horizon'])
    available=[r for r in rows if r['basis']==b and int(r['term'])==t and int(r['horizon'])==h]
    count = Counter()
    for issue in calendar(START,END):
        target=issue+timedelta(days=h)
        if target>END:
            count['target_after_export']+=1
        elif prices.get((b,t,issue)) is None:
            count['missing_invalid_current']+=1
        elif prices.get((b,t,target)) is None:
            count['missing_invalid_same_basis_target']+=1
        else:
            count['exact_pairs']+=1
    for field in ('target_after_export','missing_invalid_current','missing_invalid_same_basis_target','exact_pairs'):
        assert count[field]==int(c[field])
    assert len(available)==int(c['exact_pairs'])
    assert sum(r['gap']!='' for r in available)==int(c['gap_pairs'])
    assert sum(int(r['matched']) for r in available)==int(c['all_model_pairs'])
    assert int(c['calendar_starts'])==sum(int(c[k]) for k in ('target_after_export','missing_invalid_current','missing_invalid_same_basis_target','exact_pairs'))
    assert len(available)==int(c['gap_pairs'])+int(c['gap_unavailable'])
    assert sum(r['momentum']=='' for r in available)==int(c['momentum_unavailable'])

for s in read('horizon-lag-metrics.csv'):
    candidates=[r for r in rows if r['basis']==s['basis'] and (s['term']=='all' or r['term']==s['term'])]
    common=set.intersection(*[{(r['issue'],r['term']) for r in candidates if int(r['horizon'])==h and r['matched']=='1'} for h in (14,30,45)])
    selected=[r for r in candidates if r['horizon']==s['horizon']]
    if s['cohort']=='gap_available_only':
        selected=[r for r in selected if r['gap']!='']
    else:
        selected=[r for r in selected if r['matched']=='1']
        if s['cohort']=='common_14_30_45_all_models':
            selected=[r for r in selected if (r['issue'],r['term']) in common] if s['horizon']!='60' else []
    assert len(selected)==int(s['n']) and len({r['issue'] for r in selected})==int(s['issue_days'])
    for model in ('unchanged','gap','momentum'):
        values=[abs(D(r[model])-D(r['actual'])) for r in selected if r[model]!='']
        if values and len(values)==len(selected):
            near(sum(values)/len(values),s[model+'_mae'])
        else:
            assert s[model+'_mae']==''
    for model in ('gap','momentum'):
        if s[model+'_mae'] and float(s['unchanged_mae']):
            near(1-float(s[model+'_mae'])/float(s['unchanged_mae']),s[model+'_improvement_fraction'])

panels=defaultdict(dict)
for (b,t,d),p in prices.items():
    if p is not None:
        panels['market','public_market',str(t),b][d]=p
suppliers=defaultdict(list)
for r in data['snapshots']:
    t=int(r['fixed_time_range'].replace('Fixed',''))
    p=numeric(r['energy_price_cents_per_kwh'])
    if (t in TERMS and r['pricing_model']=='FixedPrice' and r['segment_key']==f'fixed_term_{t}'
            and r['metering']=='General' and not r['includes_spot_price'] and p is not None):
        suppliers[r['company_name'],str(t),r['pricing_basis'],date.fromisoformat(r['snapshot_date'])].append(p)
for (company,t,b,d),values in suppliers.items():
    panels['supplier_general',company,t,b][d]=statistics.median(values)
for r in read('supplier-daily-medians.csv'):
    near(panels['supplier_general',r['supplier'],r['term'],r['basis']][date.fromisoformat(r['date'])],r['median'])

changes=read('horizon-lag-changes.csv')
groups=defaultdict(list)
for r in changes:
    k=tuple(r[x] for x in ('panel','company','term','basis'))
    p=panels[k]
    d=date.fromisoformat(r['date']); lag=int(r['lag']); t=int(r['term'])
    window=list(calendar(d-timedelta(days=7),d))
    assert all(x in p for x in window)
    near(p[d]-p[window[0]],r['retail_change'])
    events=[str(x) for x in window[1:] if abs(p[x]-p[x-timedelta(days=1)])>D('.0001')]
    assert '|'.join(events)==r['events']
    start,end=d-timedelta(days=lag+7),d-timedelta(days=lag)
    assert r['hedge_start']==str(start) and r['hedge_end']==str(end)
    near(hedge(end,t)-hedge(start,t),r['hedge_change'])
    for endpoint,field in ((start,'trade_start'),(end,'trade_end')):
        assert r[field]==str(max(x for x in curves if x<endpoint))
    assert r['basket_start']==str(month_after(start)) and r['basket_end']==str(month_after(end))
    assert int(r['roll'])==int(any(x.day==1 for x in list(calendar(start,end))[1:]))
    groups[k,lag].append(r)
for c in read('horizon-lag-exclusions.csv'):
    k=tuple(c[x] for x in ('panel','company','term','basis')); lag=int(c['lag']); t=int(c['term'])
    p=panels[k]; counted=Counter()
    for d in p:
        counted['retail_dates']+=1
        if not all(x in p for x in calendar(d-timedelta(days=7),d)):
            counted['missing_retail_window']+=1; continue
        counted['complete_retail_windows']+=1
        if hedge(d-timedelta(days=lag+7),t) is None or hedge(d-timedelta(days=lag),t) is None:
            counted['missing_hedge_endpoints']+=1; continue
        counted['unscreened_pairs']+=1
    for field in counted:
        assert counted[field]==int(c[field])
    group=groups[k,lag]
    assert len(group)==int(c['unscreened_pairs'])
    assert sum(int(r['roll']) for r in group)==int(c['removed_rolls'])
    assert int(c['screened_pairs'])+int(c['removed_rolls'])==len(group)
for s in read('horizon-lag-associations.csv'):
    k=tuple(s[x] for x in ('panel','company','term','basis')); lag=int(s['lag'])
    bylag={l:[r for r in groups[k,l] if s['screened']=='0' or r['roll']=='0'] for l in LAGS}
    selected=bylag[lag]
    if s['cohort']!='individual':
        grid=LAGS if s['cohort']=='common_all_lags' else (0,7,14)
        common=set.intersection(*[{r['date'] for r in bylag[l]} for l in grid])
        selected=[r for r in selected if r['date'] in common] if lag in grid else []
    assert len(selected)==int(s['sample_days'])
    events={v for r in selected for v in r['events'].split('|') if v}
    assert len(events)==int(s['distinct_retail_change_events']) and int(s['reportable'])==int(len(events)>=5)
    xs=[float(r['hedge_change']) for r in selected]; ys=[float(r['retail_change']) for r in selected]
    if len(xs)>1 and len(set(xs))>1 and len(set(ys))>1:
        mx,my=sum(xs)/len(xs),sum(ys)/len(ys)
        corr=sum((x-mx)*(y-my) for x,y in zip(xs,ys))/math.sqrt(sum((x-mx)**2 for x in xs)*sum((y-my)**2 for y in ys))
        near(corr,s['correlation'])
    else:
        assert s['correlation']==''
    nz=[(x,y) for x,y in zip(xs,ys) if abs(x)>1e-10 and abs(y)>1e-4]
    assert len(nz)==int(s['nonzero_pairs'])
    if nz:
        near(sum(x*y>0 for x,y in nz)/len(nz),s['sign_agreement'])
    else:
        assert s['sign_agreement']==''

# Synthetic guards cover absent conditions in this complete export.
spec=importlib.util.spec_from_file_location('analysis',HERE/'horizon-lag-analysis.py')
m=importlib.util.module_from_spec(spec); spec.loader.exec_module(m)
fixture={'statistics':list(data['statistics']),'futures':list(data['futures'])}
original=next(r for r in fixture['statistics'] if r['pricing_basis']==BASES[1] and r['segment_key']=='fixed_term_6')
bad=dict(original,id=999999,median_value=None)
fixture['statistics'] += [bad,dict(original,id=999998,pricing_basis=BASES[0],median_value='999')]
e=m.Evidence(fixture); boundary=date.fromisoformat(original['stat_date'])
f=e.forecast(BASES[1],6,boundary+timedelta(days=1))
assert e.price(BASES[1],6,boundary) is None and f['transition']==boundary
assert all(d!=boundary and d<boundary+timedelta(days=1) for d,b in f['history'])
assert m.number('nan') is None and m.number('inf') is None and m.number('invalid') is None and m.number(0)==0
# Future retail presence cannot change a prior issue's boundary or estimate.
cut=date(2026,7,20)
e2=m.Evidence({'statistics':[r for r in data['statistics'] if r['stat_date']<=str(cut)],'futures':data['futures']})
e3=m.Evidence(data)
assert e2.forecast(BASES[1],6,cut)==e3.forecast(BASES[1],6,cut)
for cut,basis in ((date(2026,7,20),BASES[0]),(date(2026,8,20),BASES[1])):
    truncated=m.Evidence({'statistics':[r for r in data['statistics'] if r['stat_date']<=str(cut)],
                          'futures':[r for r in data['futures'] if r['trade_date']<str(cut)]})
    for term in TERMS:
        assert truncated.forecast(basis,term,cut)==e3.forecast(basis,term,cut)
        assert truncated.hedge(cut,term)==e3.hedge(cut,term)
assert e3.hedge(START,6)['price'] is None
latest=max(d for d in curves if d<END)
missing=m.Evidence({'statistics':data['statistics'],'futures':[r for r in data['futures'] if r['trade_date']!=str(latest)] +
                    [dict(data['futures'][0],trade_date=str(latest),maturity_type='month',maturity='202610')]})
assert missing.hedge(END,24)['price'] is None
summary=json.loads((HERE/'horizon-lag-summary.json').read_text())
assert summary['previous_30day_matched']['n']==48 and summary['previous_30day_matched']['issue_days']==16
near(summary['previous_30day_matched']['gap_mae'],.7458104166666665)
near(summary['previous_30day_matched']['unchanged_mae'],.7002937499999997)
result=dict(status='passed',hedge_rows=len(hedges),forecast_rows=len(rows),lag_change_rows=len(changes),
            synthetic_guards=['latest invalid ownership','no observed fallback','strict prior history','no future boundary',
                              'zero valid','nonfinite invalid','missing prior trade','missing delivery strip'],
            coefficient_fitting='none; no training-target selection', source_manifest_sha256=summary['export_manifest_sha256'])
(HERE/'horizon-lag-independent-checks.json').write_text(json.dumps(result,indent=2)+'\n')
print(json.dumps(result,indent=2))
