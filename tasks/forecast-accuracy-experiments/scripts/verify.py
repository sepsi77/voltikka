#!/usr/bin/env python3
"""Independent daily-expanded prices, normal-equation and identity checks."""
import sys
sys.dont_write_bytecode = True
from collections import Counter, defaultdict
import csv
from datetime import date, timedelta
from decimal import Decimal
from functools import lru_cache
import json
import math
import experiment as x


def load(name):
    return json.loads((x.ART/name).read_text())


def readcsv(name):
    return list(csv.DictReader((x.ART/name).open()))


def near(a,b):
    assert math.isclose(float(a),float(b),rel_tol=1e-10,abs_tol=1e-10), (a,b)


def main():
    assert x.digest(x.HERE/'spec.md') == (x.HERE/'spec.sha256').read_text().split()[0]
    sources = load('sources.json')
    for path,sha in sources.items():
        assert x.digest(x.ROOT/path)==sha, path
    manifest,data = x.prior.verified_export()
    # Select owned raw medians independently of the imported Evidence class.
    stats = {}
    for r in data['statistics']:
        if r['metric_key']!='energy_price' or r['method_version']!='unit_statistics_v1' or r['consumption_kwh'] is not None:
            continue
        if r['segment_key'] not in ('fixed_term_6','fixed_term_12','fixed_term_24'):
            continue
        k = r['pricing_basis'],int(r['segment_key'].rsplit('_',1)[1]),r['stat_date']
        if k not in stats or int(r['id'])>int(stats[k]['id']):
            stats[k]=r
    curves = defaultdict(dict)
    for r in data['futures']:
        if r['area']=='FI' and r['product']=='Base':
            curves[r['trade_date']][r['maturity_type'],r['maturity']]=Decimal(str(r['settlement_price']))

    @lru_cache(None)
    def daily(endpoint,start,term):
        earlier = [d for d in curves if d<endpoint]
        if not earlier:
            return None,None,[]
        trade=max(earlier)
        first=date.fromisoformat(start)
        months=first.year*12+first.month-1+term
        stop=date(months//12,months%12+1,1)
        d=first
        total=Decimal(0)
        weights=Counter()
        while d<stop:
            options=[('month',f'{d.year}{d.month:02d}'),
                     ('quarter',f'{d.year}{1+3*((d.month-1)//3):02d}'),('year',f'{d.year}01')]
            k=next((k for k in options if k in curves[trade]),None)
            if k is None:
                return None,trade,[]
            total+=curves[trade][k]
            weights[d.replace(day=1).isoformat()]+=1
            d+=timedelta(days=1)
        return float(total/Decimal((stop-first).days)*Decimal('.1255')),trade,sorted(weights.items())

    baskets=load('baskets.json')
    samevintage=0
    priorhedges={(r['date'],int(r['term'])):r for r in csv.DictReader((x.PRIOR/'horizon-lag-hedges.csv').open())}
    for key,b in baskets.items():
        v,trade,weights=daily(b['endpoint'],b['start'],b['term'])
        assert (v is None)==(b['price'] is None),key
        assert trade==b['trade']
        if v is None:
            assert b['missing']
            continue
        assert trade<b['endpoint'] and not b['missing']
        near(v,b['price'])
        assert weights==[(s['month'],s['days']) for s in b['strip']]
        for slot in b['strip']:
            d=date.fromisoformat(slot['month'])
            choices=[('month',f'{d.year}{d.month:02d}'),
                     ('quarter',f'{d.year}{1+3*((d.month-1)//3):02d}'),('year',f'{d.year}01')]
            selected=next(k for k in choices if k in curves[trade])
            assert tuple(slot['instrument'])==selected
            near(slot['settlement'],curves[trade][selected])
        if b['start']==str(x.prior.next_month(date.fromisoformat(b['endpoint']))) and (b['endpoint'],b['term']) in priorhedges:
            r=priorhedges[b['endpoint'],b['term']]
            near(v,r['price'])
            samevintage+=1
    features={r['id']:r for r in load('features.json')}
    for r in features.values():
        basis,term,issue=r['basis'],r['term'],r['issue']
        assert date.fromisoformat(issue)-date.fromisoformat(r['lag_date'])==timedelta(days=r['window'])
        nowstat=stats[basis,term,issue]
        near(r['current'],nowstat['median_value'])
        assert r['current_stat_id']==nowstat['id']
        old=stats.get((basis,term,r['lag_date']))
        assert r['lag_stat_id']==(old['id'] if old else None)
        if old:
            near(r['lag_price'],old['median_value'])
            near(r['retail_change'],Decimal(str(nowstat['median_value']))-Decimal(str(old['median_value'])))
        else:
            assert r['lag_price'] is None and r['retail_change'] is None and r['missing']
        a,b=baskets[r['basket_now']],baskets[r['basket_old']]
        assert a['endpoint']==issue and b['endpoint']==r['lag_date'] and a['start']==b['start']
        assert a['start']==str(x.prior.next_month(date.fromisoformat(issue)))
        if a['price'] is not None and b['price'] is not None:
            assert [(s['month'],s['days']) for s in a['strip']]==[(s['month'],s['days']) for s in b['strip']]
            near(r['basket_change'],a['price']-b['price'])
        rolling,trade,_=daily(r['lag_date'],str(x.prior.next_month(date.fromisoformat(r['lag_date']))),term)
        assert trade==r['old_rolling_trade']
        if rolling is not None:
            near(rolling,r['old_rolling'])
            if a['price'] is not None:
                near(r['rolling_change'],a['price']-rolling)
                if a['start']==str(x.prior.next_month(date.fromisoformat(r['lag_date']))):
                    near(r['rolling_change'],r['basket_change'])
        assert bool(r['missing']) == any(v is None for v in (r['lag_price'],a['price'],b['price'],rolling))

    pairs={r['id']:r for r in load('pairs.json')}
    refs={(r['basis'],int(r['term']),int(r['horizon']),r['issue']):r for r in csv.DictReader((x.PRIOR/'horizon-lag-forecasts.csv').open())}
    exclusions=readcsv('exclusions.csv')
    assert len(exclusions)==2*len(features)
    assert {r['id'] for r in exclusions if r['reason']=='eligible'}==set(pairs)
    for r in exclusions:
        f=features[r['id'].rsplit('|',1)[0]]
        target=str(date.fromisoformat(r['issue'])+timedelta(days=int(r['horizon'])))
        assert target==r['target']
        actual=stats.get((r['basis'],int(r['term']),target))
        ref=refs.get((r['basis'],int(r['term']),int(r['horizon']),r['issue']),{})
        expected=('target_after_export' if target>str(x.prior.END) else
                  'missing_same_basis_target' if actual is None else f['missing'] or
                  ('missing_gap_reference' if not ref.get('gap') else 'eligible'))
        assert expected==r['reason']
    for r in pairs.values():
        assert not features[r['feature_id']]['missing']
        target=stats[r['basis'],r['term'],r['target']]
        near(r['actual'],target['median_value'])
        assert r['target_stat_id']==target['id']
        near(r['delta'],r['actual']-r['current'])
        near(r['gap_delta'],float(refs[r['basis'],r['term'],r['horizon'],r['issue']]['gap'])-r['current'])

    fits=load('fits.json')
    available={r['fit_id']:r for r in readcsv('availability.csv')}
    for fitid,a in available.items():
        expected=[r for r in pairs.values() if r['window']==int(a['window']) and r['horizon']==int(a['horizon'])
                  and r['target']<a['cutoff'] and r['basis']==('observed_seller_data' if a['protocol']=='frozen_transfer' else a['protocol'].removeprefix('rolling_'))]
        testing=[r for r in pairs.values() if r['window']==int(a['window']) and r['horizon']==int(a['horizon'])
                 and (r['basis']=='canonical_calculation' and r['issue']>=x.CUTOFF if a['protocol']=='frozen_transfer'
                      else r['basis']==a['protocol'].removeprefix('rolling_') and r['issue']==a['cutoff'])]
        assert len(testing)==int(a['test_rows'])
        assert len(expected)==int(a['training_rows'])
        assert len({r['issue'] for r in expected})==int(a['training_days'])
        assert (fitid in fits)==(int(a['training_days'])>=20)
        if fitid not in fits:
            continue
        for model,f in fits[fitid].items():
            assert set(f['identities'])=={r['id'] for r in expected}
            assert len(f['identities'])==len(expected)==f['n']
            assert f['issue_days']>=20 and f['max_target']<a['cutoff']
            assert f['penalty']==1 and f['features']==x.FEATURES[model]
            n=len(expected)
            ymean=sum(r['delta'] for r in expected)/n
            near(f['standardized_intercept'],ymean)
            z=[]
            for j,k in enumerate(f['features']):
                mu=sum(r[k] for r in expected)/n
                sd=math.sqrt(sum((r[k]-mu)**2 for r in expected)/n)
                near(mu,f['means'][j]); near(sd,f['scales'][j])
            for r in expected:
                z.append([(r[k]-mu)/sd if sd else 0. for k,mu,sd in zip(f['features'],f['means'],f['scales'])])
            residual=[ymean+sum(v*s for v,s in zip(row,f['standardized_slopes']))-r['delta'] for row,r in zip(z,expected)]
            near(sum(residual)/n,0)
            for j,s in enumerate(f['standardized_slopes']):
                near(sum(row[j]*err for row,err in zip(z,residual))/n+s,0)
                near(f['raw_slopes'][j],s/f['scales'][j] if f['scales'][j] else 0)
            near(f['raw_intercept'],ymean-sum(s*m for s,m in zip(f['raw_slopes'],f['means'])))

    preds=readcsv('predictions.csv')
    groups=defaultdict(list)
    identities=defaultdict(set)
    for r in preds:
        p=pairs[r['id']]
        for k in ('delta','actual','current'):
            near(r[k],p[k]); r[k]=float(r[k])
        for k in ('term','window','horizon'):
            r[k]=int(r[k]); assert r[k]==p[k]
        assert r['issue']==p['issue'] and r['target']==p['target'] and r['basis']==p['basis']
        a=available[r['fit_id']]
        assert int(a['training_days'])>=20
        if r['protocol']=='frozen_transfer':
            assert r['basis']=='canonical_calculation' and r['issue']>=x.CUTOFF
        else:
            assert r['basis']==r['protocol'].removeprefix('rolling_') and a['cutoff']==r['issue']
        model=r['model']
        if model=='unchanged': expected=0.
        elif model=='gap': expected=p['gap_delta']
        else:
            f=fits[r['fit_id']]['basket' if model=='basket_abstain' else model]
            expected=f['standardized_intercept']+sum(s*(p[k]-mu)/sd if sd else 0.
                     for k,mu,sd,s in zip(f['features'],f['means'],f['scales'],f['standardized_slopes']))
            if model=='basket_abstain' and abs(expected)<.15: expected=0.
        near(r['prediction_delta'],expected)
        r['prediction_delta']=float(r['prediction_delta'])
        identities[r['protocol'],r['window'],r['horizon'],model].add(r['id'])
        for term in (str(r['term']),'all'):
            groups[r['protocol'],r['window'],r['horizon'],model,term].append(r)
    for fitid,a in available.items():
        got=[r for r in preds if r['fit_id']==fitid]
        assert len(got)==(int(a['test_rows'])*len(x.MODELS) if fitid in fits else 0)
        assert len({(r['id'],r['model']) for r in got})==len(got)
    for key,ids in identities.items():
        for model in x.MODELS:
            assert ids==identities[*key[:3],model]
    for m in readcsv('metrics.csv'):
        g=groups[m['protocol'],int(m['window']),int(m['horizon']),m['model'],m['term']]
        n=len(g)
        assert n==int(m['n']) and len({r['issue'] for r in g})==int(m['issue_days'])
        errors=[r['prediction_delta']-r['delta'] for r in g]
        mae=sum(abs(e) for e in errors)/n
        near(m['mae'],mae); near(m['rmse'],math.sqrt(sum(e*e for e in errors)/n)); near(m['bias'],sum(errors)/n)
        near(m['skill'],1-mae/(sum(abs(r['delta']) for r in g)/n))
        cat=lambda d: 0 if -.15<d<.15 else (1 if d>0 else -1)
        near(m['direction_accuracy'],sum(cat(r['prediction_delta'])==cat(r['delta']) for r in g)/n)
        dates=sorted({r['issue'] for r in g})
        overlap=sum((date.fromisoformat(b)-date.fromisoformat(a)).days<int(m['horizon']) for i,a in enumerate(dates) for b in dates[i+1:])
        assert overlap==int(m['overlapping_issue_pairs'])
        assert len(dates)*(len(dates)-1)//2==int(m['total_issue_pairs'])
    for c in load('common-horizons.json'):
        expected=set.intersection(*[{(r['issue'],r['term']) for r in preds if r['protocol']=='frozen_transfer'
                                    and r['window']==c['window'] and r['horizon']==h} for h in (14,30)])
        assert {tuple(v) for v in c['identities']}==expected
        assert c['n']==len(expected)
        g=[r for r in preds if r['protocol']=='frozen_transfer' and r['window']==c['window'] and r['horizon']==c['horizon']
           and r['model']==c['model'] and (r['issue'],r['term']) in expected]
        near(c['mae'],sum(abs(r['prediction_delta']-r['delta']) for r in g)/len(g))
    for window,expected in ((7,57),(14,57)):
        raw=[r for r in exclusions if r['basis']=='canonical_calculation' and r['window']==str(window)
             and r['horizon']=='30' and r['reason']!='target_after_export']
        assert len(raw)==expected and len({r['issue'] for r in raw})==19
    assert len(identities['frozen_transfer',7,30,'unchanged'])==36
    assert len(identities['frozen_transfer',14,30,'unchanged'])==15
    assert not any(r['protocol']=='rolling_canonical_calculation' for r in preds)

    # Orthogonal unit-variance known solution: ridge halves slopes when lambda=1.
    synthetic=[dict(id=str(i),issue=str(i),target='0',a=a,b=b,constant=7.,delta=5+2*a-3*b)
               for i,(a,b) in enumerate([(-1,-1),(-1,1),(1,-1),(1,1)])]
    for penalty,slopes in ((0,[2,-3]),(1,[1,-1.5])):
        f=x.fit(synthetic,['a','b'],penalty)
        near(f['raw_intercept'],5)
        for got,want in zip(f['raw_slopes'],slopes): near(got,want)
    f=x.fit(synthetic,['constant','a'])
    assert f['scales'][0]==0 and f['raw_slopes'][0]==0
    near(f['raw_slopes'][1],1)
    f=x.fit(synthetic,['constant'],0)
    near(f['raw_intercept'],5); assert f['raw_slopes']==[0]
    empty=x.prior.Evidence({'statistics':[],'futures':[]})
    assert x.basket(empty,date(2026,5,1),date(2026,6,1),6)['missing']=='no_prior_trade'
    fixture=x.prior.Evidence({'statistics':[],'futures':[dict(area='FI',product='Base',trade_date='2026-04-30',maturity_type='month',maturity='202606',settlement_price=0)]})
    assert x.basket(fixture,date(2026,4,30),date(2026,6,1),1)['price'] is None
    near(x.basket(fixture,date(2026,5,1),date(2026,6,1),1)['price'],0)
    assert x.basket(fixture,date(2026,5,1),date(2026,6,1),2)['missing']=='2026-07-01'
    fixture.curves[date(2026,4,30)]['year','202601']=50.
    fixture.curves[date(2026,4,30)]['month','202606']=None
    assert x.basket(fixture,date(2026,5,1),date(2026,6,1),1)['price'] is None
    result=dict(status='passed',basket_vintages=len(baskets),same_vintage_verified_php_hedges=samevintage,
                features=len(features),pairs=len(pairs),fits=len(fits),predictions=len(preds),
                synthetic_checks=['lambda zero known solution','lambda one known solution','zero-scale column',
                                  'all constant columns','missing trade','strict trade endpoint','zero valid',
                                  'partial strip rejected','invalid owned month blocks fallback'])
    x.save('verification.json',result)
    print(json.dumps(result,indent=2))


if __name__=='__main__': main()
