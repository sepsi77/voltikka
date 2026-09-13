#!/usr/bin/env python3
"""Independent raw-data, Decimal, normal-equation and cohort checks."""
import sys
sys.dont_write_bytecode = True
from collections import Counter, defaultdict
from copy import deepcopy
import csv
from datetime import date, timedelta
from decimal import Decimal as D, ROUND_HALF_UP
from functools import lru_cache
import math
import analyze as a


def close(x,y,tol=1e-9):
    assert abs(float(x)-float(y)) <= tol, (x,y)
def cat(x): return 'up' if x>=D('.15') else 'down' if x<=D('-.15') else 'flat'
def round4(x): return x.quantize(D('.0001'),rounding=ROUND_HALF_UP)
def result(p,t): return 'correct' if p==t else 'missed_move' if p=='flat' else 'false_move' if t=='flat' else 'wrong_way'

class Raw:
    def __init__(self,data):
        self.stats = {}
        for r in sorted(data['statistics'],key=lambda r:int(r['id'])):
            if (r['method_version']=='unit_statistics_v1' and r['metric_key']=='energy_price' and r['consumption_kwh'] is None
                and r['segment_key'] in ['fixed_term_'+str(t) for t in (6,12,24)] and r['pricing_basis'] in a.P.BASES):
                self.stats[r['pricing_basis'],int(r['segment_key'].split('_')[-1]),r['stat_date']] = r
        self.curves = defaultdict(dict)
        for r in data['futures']:
            if r['area']=='FI' and r['product']=='Base':
                self.curves[r['trade_date']][r['maturity_type'],r['maturity']] = D(str(r['settlement_price'])) if r['settlement_price'] is not None else None
    def vector(self,b,t,d):
        row = self.stats.get((b,t,d),{})
        v = [D(str(row[q+'_value'])) if row.get(q+'_value') is not None else None for q in a.QS]
        return dict(zip(a.QS,v)) if all(x is not None and x.is_finite() for x in v) and v[0]<=v[1]<=v[2] else None
    @lru_cache(None)
    def hedge(self,issue,t,cutoff):
        start = (date.fromisoformat(issue).replace(day=28)+timedelta(days=4)).replace(day=1)
        trades = [d for d in self.curves if d<cutoff]
        if not trades: return None,None,[],str(start)
        trade = max(trades); curve = self.curves[trade]
        end_index = start.year*12+start.month-1+t
        end = date(end_index//12,end_index%12+1,1)
        day = start; slots = {}; values = []; missing = False
        while day < end:
            keys = [('month',day.strftime('%Y%m')),('quarter',f'{day.year}{(day.month-1)//3*3+1:02d}'),('year',f'{day.year}01')]
            key = next((k for k in keys if k in curve),None)
            value = curve[key] if key else None
            month = str(day.replace(day=1))
            if month not in slots: slots[month] = dict(month=month,days=0,instrument=list(key) if key else None,settlement=value)
            slots[month]['days'] += 1
            if value is None or not value.is_finite(): missing = True
            else: values.append(value)
            day += timedelta(days=1)
        return (None if missing else sum(values)/len(values)/10*D('1.255')),trade,list(slots.values()),str(start)


def feature_checks(raw,features):
    for r in features:
        b,t,d = r['basis'],r['term'],r['issue']
        assert r['lag']==str(date.fromisoformat(d)-timedelta(days=7))
        v,p = raw.vector(b,t,d),raw.vector(b,t,r['lag'])
        assert v is not None and p is not None
        assert raw.stats[b,t,d]['id']==r['current_id'] and raw.stats[b,t,r['lag']]['id']==r['lag_id']
        for q in a.QS:
            close(v[q],r['current'][q]); close(p[q],r['prior'][q]); close(v[q]-p[q],r[q+'_change'])
        close(v['median']-v['p20'],r['spread'])
        h,trade,_,_ = raw.hedge(d,t,d)
        lag,lagtrade,_,_ = raw.hedge(r['lag'],t,r['lag'])
        fixed,fixedtrade,_,_ = raw.hedge(d,t,r['lag'])
        close(h,r['current_H']); close(lag,r['lag_H']); close(h-lag,r['rolling_change'])
        assert trade==r['trade'] and lagtrade==r['lag_trade'] and fixedtrade==r['fixed_lag_trade']
        assert trade<d and lagtrade<r['lag']
        if fixed is None: assert r['basket_change'] is None
        else: close(fixed,r['fixed_lag_H']); close(h-fixed,r['basket_change'])


def fit_checks(pairs,fits):
    for f in fits.values():
        rs = [pairs[i] for i in f['train_ids']]
        days = {r['issue'] for r in rs}
        assert len(days)==f['issue_days']>=20 and len(rs)==f['n']
        assert all(r['target']<f['cutoff'] and r['basis']==f['training_basis'] for r in rs)
        if f['protocol']=='transfer': assert f['cutoff']=='2026-07-27' and f['training_basis']==a.P.BASES[0]
        else: assert f['training_basis']==(a.P.BASES[0] if f['protocol']=='rolling_observed' else a.P.BASES[1])
        assert max(r['target'] for r in rs)==f['max_target']
        if f['scope']=='per_term': assert {r['term'] for r in rs}=={f['term']} and len(rs)==len(days)
        else:
            assert len(rs)==3*len(days)
            for d in days: assert {r['term'] for r in rs if r['issue']==d}=={6,12,24}
        expected = [r for r in pairs.values() if r['horizon']==f['horizon'] and r['basis']==f['training_basis']
                    and r['target']<f['cutoff'] and (f['scope']=='pooled' or r['term']==f['term'])
                    and (f['cohort']!='basket' or r['basket_change'] is not None)]
        # The pinned real input has no incomplete term days; this must not silently drop training rows.
        assert {r['pair_id'] for r in expected}==set(f['train_ids'])
        y = [D(str(r['delta'][f['quantile']])) for r in rs]
        mean = sum(y)/len(y); close(mean,f['intercept'])
        zs = []
        for j,k in enumerate(f['features']):
            x = [D(str(r[k])) for r in rs]; mu = sum(x)/len(x)
            sd = (sum((v-mu)**2 for v in x)/len(x)).sqrt()
            close(mu,f['means'][j]); close(sd,f['scales'][j])
            zs.append([(v-mu)/sd if sd else D(0) for v in x])
            if not sd: assert f['slopes'][j]==0
        # Independently check derivative = 0 for mean-square + sum(beta^2), not the analysis solver.
        errors = [mean+sum(D(str(s))*z[i] for s,z in zip(f['slopes'],zs))-y[i] for i in range(len(rs))]
        close(sum(errors)/len(errors),0)
        for s,z in zip(f['slopes'],zs): close(sum(v*e for v,e in zip(z,errors))/len(rs)+D(str(s)),0)


def prediction_checks(pairs,fits,predictions):
    cohorts = defaultdict(dict); identity_groups = defaultdict(dict)
    for r in predictions:
        pair = pairs[r['pair_id']]; q = r['quantile']
        for field in ('term','issue','target','basis','horizon'): assert r[field]==pair[field]
        close(r['current'],pair['current'][q]); close(r['actual'],pair['actual'][q]); close(r['delta'],pair['delta'][q])
        actual = D(str(r['actual']))-D(str(r['current']))
        close(round4(actual),r['actual_delta_4dp']); assert cat(round4(actual))==r['actual_class']
        if r['model'].startswith('always_'):
            assert r['error'] is None and r['forecast'] is None and r['prediction_delta'] is None
            predicted = r['model'][7:]
        else:
            if r['fit_id']:
                f = fits[r['fit_id']]
                assert f['scope']==r['scope'] and f['quantile']==q
                assert f['cutoff']<=r['issue'] and all(pairs[i]['target']<r['issue'] for i in f['train_ids'])
                if f['protocol']!='transfer': assert f['cutoff']==r['issue']
                if r['scope']=='per_term': assert f['term']==r['term']
                contributions = {k:D(str(s))*(D(str(pair[k]))-D(str(mu)))/D(str(sd)) if sd else D(0)
                                 for k,s,mu,sd in zip(f['features'],f['slopes'],f['means'],f['scales'])}
                assert set(contributions)==set(r['contributions'])
                for k,v in contributions.items(): close(v,r['contributions'][k])
                close(D(str(f['intercept']))+sum(contributions.values()),r['prediction_delta'])
                ikey = tuple(r[k] for k in ('cohort','protocol','scope','horizon','issue','term'))
                identity_groups[ikey][r['study'],q,r['model']] = set(f['train_ids'])
            else: assert r['model']=='unchanged' and r['prediction_delta']==0
            close(r['prediction_delta']-r['delta'],r['error']); close(r['current']+r['prediction_delta'],r['forecast'])
            predicted = cat(D(str(r['prediction_delta'])))
            assert cat(round4(D(str(r['prediction_delta']))))==r['saved_delta_class']
        assert predicted==r['predicted_class'] and result(predicted,r['actual_class'])==r['outcome']
        key = tuple(r[k] for k in ('cohort','protocol','scope','horizon'))
        cohorts[key].setdefault((r['study'],q,r['model']),set()).add(r['pair_id'])
    for groups in cohorts.values():
        sets = list(groups.values()); assert all(s==sets[0] for s in sets)
    for groups in identity_groups.values():
        sets = list(groups.values()); assert all(s==sets[0] for s in sets)
    for key,groups in cohorts.items():
        if key[0]=='basket':
            native = cohorts[('native',)+key[1:]]
            assert groups['A','median','history']==native['A','median','history']
    # A median controls and B median controls are identical numerical forecasts.
    lookup = {(tuple(r[k] for k in ('cohort','protocol','scope','horizon','pair_id','model')),r['study']):r for r in predictions if r['quantile']=='median'}
    for (key,study),r in lookup.items():
        if study=='B' and r['model'] in ('unchanged','intercept','history'):
            close(r['prediction_delta'],lookup[key,'A']['prediction_delta'])
    return len(cohorts),len(identity_groups)


def metric_checks(predictions,metrics,daily,increments):
    groups = defaultdict(list)
    for r in predictions:
        for t in (str(r['term']),'all'):
            key = tuple(r[k] for k in a.GROUP)+(t,r['model'])
            groups[key].append(r)
    assert len(groups)==len(metrics)
    for m in metrics:
        key = tuple(m[k] for k in a.GROUP)+(m['term'],m['model'])
        rs = groups[key]; n = len(rs)
        assert n==m['n'] and len({r['issue'] for r in rs})==m['issue_days']
        assert min(r['issue'] for r in rs)==m['first_issue'] and max(r['issue'] for r in rs)==m['last_issue']
        confusion = Counter((r['actual_class'],r['predicted_class']) for r in rs)
        for actual in ('up','down','flat'):
            for pred in ('up','down','flat'): assert confusion[actual,pred]==m['confusion'][actual][pred]
            actual_n = sum(confusion[actual,p] for p in ('up','down','flat'))
            pred_n = sum(confusion[p,actual] for p in ('up','down','flat'))
            assert actual_n==m['actual_balance'][actual] and pred_n==m['predicted_balance'][actual]
            if actual_n: close(confusion[actual,actual]/actual_n,m['recall'][actual])
            else: assert m['recall'][actual] is None
            if pred_n: close(confusion[actual,actual]/pred_n,m['precision'][actual])
            else: assert m['precision'][actual] is None
        for name in ('correct','wrong_way','missed_move','false_move'): assert m[name]==sum(r['outcome']==name for r in rs)
        assert sum(m[k] for k in ('correct','wrong_way','missed_move','false_move'))==n
        if rs[0]['error'] is None: assert m['mae'] is None and all(x is None for x in m['mae_minus'].values()); continue
        errors = [D(str(r['prediction_delta']))-D(str(r['delta'])) for r in rs]
        mae = sum(abs(e) for e in errors)/n
        close(mae,m['mae']); close(sum(errors)/n,m['bias']); close((sum(e*e for e in errors)/n).sqrt(),m['rmse'])
        unchanged = sum(abs(D(str(r['delta']))) for r in rs)/n
        if unchanged: close(1-mae/unchanged,m['skill'])
        for control,delta in m['mae_minus'].items():
            ref = groups[key[:-1]+(control,)]
            close(mae-sum(abs(D(str(r['error']))) for r in ref)/n,delta)
    dg = defaultdict(list)
    for r in daily:
        key = tuple(r[k] for k in a.GROUP)+(r['term'],)
        model = [x for x in groups[key+(r['model'],)] if x['issue']==r['issue']]
        ref = {x['pair_id']:x for x in groups[key+(r['control'],)] if x['issue']==r['issue']}
        assert len(model)==r['n']==len(ref)
        value = sum(abs(D(str(x['error'])))-abs(D(str(ref[x['pair_id']]['error']))) for x in model)/len(model)
        close(value,r['error_difference'])
        dg[key+(r['model'],r['control'])].append(r)
    for r in increments:
        rs = dg[tuple(r[k] for k in a.GROUP)+(r['term'],r['model'],r['control'])]
        v = [x['error_difference'] for x in rs]
        close(sum(v)/len(v),r['mean']); assert len(v)==r['issue_days'] and sum(x['n'] for x in rs)==r['rows']
        assert sum(x<0 for x in v)==r['better_days'] and sum(x>0 for x in v)==r['worse_days'] and sum(x==0 for x in v)==r['tie_days']
        ordered = sorted(v); middle = len(v)//2
        close(ordered[middle] if len(v)%2 else (ordered[middle-1]+ordered[middle])/2,r['median'])
        close(min(v),r['minimum']); close(max(v),r['maximum'])
        assert min(rs,key=lambda x:x['error_difference'])['issue']==r['best_issue']
        assert max(rs,key=lambda x:x['error_difference'])['issue']==r['worst_issue']


def synthetic(data):
    checks = []
    for name,row,valid in [('zero',dict(p20_value=0,median_value=0,p80_value=0),True),
        ('negative',dict(p20_value=-2,median_value=-1,p80_value=0),True),
        ('missing',dict(p20_value=None,median_value=1,p80_value=2),False),
        ('nonfinite',dict(p20_value=0,median_value=float('nan'),p80_value=2),False),
        ('order',dict(p20_value=2,median_value=1,p80_value=3),False)]:
        assert (a.vector(row) is not None)==valid; checks.append(name)
    sample = deepcopy(data['statistics'][0]); newer = dict(sample,id=999999,p20_value=None)
    e = a.Evidence(dict(statistics=[newer,sample],futures=[]))
    assert e.vector(sample['pricing_basis'],6,date.fromisoformat(sample['stat_date'])) is None; checks.append('latest_invalid_ownership')
    original = a.Evidence(data); b = a.P.BASES[1]; issue = date(2026,8,3)
    before,_ = a.feature(original,b,6,issue)
    mutated = deepcopy(data)
    for r in mutated['statistics']:
        if r['stat_date']>str(issue): r['p20_value']='-999'
    after,_ = a.feature(a.Evidence(mutated),b,6,issue)
    assert before==after; checks.append('future_p20_cannot_change_features')
    missing = deepcopy(data)
    for r in missing['statistics']:
        if r['stat_date']==str(issue) and r['pricing_basis']==b and r['segment_key']=='fixed_term_6': r['p80_value']=None
    assert a.feature(a.Evidence(missing),b,6,issue)[0] is None; checks.append('missing_quantile_excludes_all_models')
    toy = [dict(basis=b,issue=str(issue),horizon=30,term=t,basket_change=0) for t in (6,12,24)]
    assert a.complete(toy)==toy and a.complete(toy[:2])==[]; checks.append('complete_term_day_intersection')
    toy[0]['basket_change']=None
    assert a.complete(toy,basket=True)==[]; checks.append('basket_matched_controls_missing')
    assert a.feature(original,b,6,date(2026,7,27))[0] is None; checks.append('no_observed_bridge')
    sample_rows = [dict(basis=b,term=6,target=t) for t in ('2026-07-26','2026-07-27','2026-07-28')]
    assert [r['target'] for r in a.training_rows(sample_rows,b,'2026-07-27',6)]==['2026-07-26']; checks.append('strict_label_boundary')
    rows = [dict(x=3,delta={'median':float(i)},issue=f'2026-01-{i+1:02}',target='2026-02-01',pair_id=str(i)) for i in range(20)]
    f = a.fit(rows,['x'],'median'); assert f['slopes']==[0.] and f['intercept']==9.5 and a.predict(f,{'x':999})[0]==9.5; checks.append('constant_feature')
    for v in ('0.15','-0.15','0.1499','-0.1499'):
        assert a.direction(float(v))==cat(D(v))
    assert a.direction(a.rounded(D('1.15')-D('1')))== 'up'; checks.append('four_decimal_boundary')
    def future(typ,mat,value,trade='2028-01-01'):
        return dict(area='FI',product='Base',trade_date=trade,maturity_type=typ,maturity=mat,settlement_price=value)
    futures = [future('month','202802',0),future('quarter','202801',20),future('year','202801',30)]
    e = a.Evidence(dict(statistics=[],futures=futures))
    h = a.old.basket(e,date(2028,1,31),date(2028,2,1),6)
    assert h['strip'][0]['days']==29 and h['strip'][0]['settlement']==0
    assert [r['instrument'][0] for r in h['strip'][:3]]==['month','quarter','year']; checks.append('leap_weights_fallback_zero')
    close(h['price'],Raw(dict(statistics=[],futures=futures)).hedge('2028-01-31',6,'2028-01-31')[0])
    assert a.old.basket(e,date(2028,1,1),date(2028,2,1),6)['price'] is None; checks.append('strict_trade_boundary')
    bad = a.Evidence(dict(statistics=[],futures=futures+[future('month','202802',99,'2028-01-30')]))
    assert a.old.basket(bad,date(2028,1,31),date(2028,2,1),6)['price'] is None; checks.append('no_older_curve_fill')
    bad = a.Evidence(dict(statistics=[],futures=futures+[future('month','202803',None)]))
    assert a.old.basket(bad,date(2028,1,31),date(2028,2,1),6)['price'] is None; checks.append('invalid_specific_slot_no_fallback')
    assert a.P.next_month(date(2028,2,29))==date(2028,3,1); checks.append('month_end')
    return checks


def main():
    a.sources(); _,data = a.P.verified_export(); raw = Raw(data)
    features = a.load(a.OUT/'features.json'); pairs = {r['pair_id']:r for r in a.load(a.OUT/'pairs.json')}
    fits = a.load(a.OUT/'fits.json'); predictions = a.load(a.OUT/'predictions.json')
    feature_checks(raw,features)
    for r in pairs.values():
        target = raw.vector(r['basis'],r['term'],r['target'])
        assert r['target']==str(date.fromisoformat(r['issue'])+timedelta(days=r['horizon'])) and r['target']<='2026-09-13'
        assert r['target_id']==raw.stats[r['basis'],r['term'],r['target']]['id']
        for q in a.QS: close(target[q],r['actual'][q]); close(target[q]-D(str(r['current'][q])),r['delta'][q])
    baskets = a.load(a.OUT/'baskets.json'); zero = 0
    retained = {(r['date'],int(r['term'])):r for r in csv.DictReader((a.old.PRIOR/'horizon-lag-hedges.csv').open())}
    for r in baskets:
        value,trade,slots,start = raw.hedge(r['issue'],r['term'],r['cutoff'])
        assert r['cutoff']==str(date.fromisoformat(r['issue'])-timedelta(days=r['lag']))
        assert trade==r['trade'] and start==r['start'] and len(slots)==len(r['strip'])
        if value is None: assert r['price'] is None
        else: close(value,r['price'])
        for x,y in zip(slots,r['strip']):
            for field in ('month','days','instrument'): assert x[field]==y[field]
            if x['settlement'] is None: assert y['settlement'] is None
            else: close(x['settlement'],y['settlement'])
        if r['lag']==0:
            previous = retained[r['issue'],r['term']]
            if value is None: assert not previous['price']
            else: close(value,previous['price']); assert trade==previous['trade']
            zero += 1
    fit_checks(pairs,fits)
    cohorts, identities = prediction_checks(pairs,fits,predictions)
    metric_checks(predictions,a.load(a.OUT/'metrics.json'),a.load(a.OUT/'paired-daily.json'),a.load(a.OUT/'increments.json'))
    availability = a.load(a.OUT/'availability.json')
    assert not any(r['available'] for r in availability if r['protocol']=='rolling_canonical')
    for r in availability:
        expected = [p for p in pairs.values() if p['horizon']==r['horizon'] and p['basis']==r['training_basis']
                    and p['target']<r['cutoff'] and (r['term']=='all' or p['term']==r['term'])
                    and (r['cohort']!='basket' or p['basket_change'] is not None)]
        assert r['training_rows']==len(expected) and r['training_days']==len({p['issue'] for p in expected})
        assert r['available']==(r['training_days']>=20)
    checks = synthetic(data)
    proof = dict(status='passed',raw_features=len(features),raw_pairs=len(pairs),daily_expanded_baskets=len(baskets),
        zero_lag_verified_H=zero,normal_equation_fits=len(fits),predictions=len(predictions),cohort_groups=cohorts,
        train_identity_groups=identities,metric_groups=len(a.load(a.OUT/'metrics.json')),synthetic_checks=checks,
        raw_saved_class_disagreements=sum(r['prediction_delta'] is not None and r['predicted_class']!=r['saved_delta_class'] for r in predictions),
        preserved_sources=a.sources())
    a.save(a.OUT/'verification.json',proof)
    print('Verification: '+str(proof))

if __name__=='__main__': main()
