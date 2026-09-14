#!/usr/bin/env python3
"""Independent arithmetic and adversarial input checks; standard library only."""
import sys
sys.dont_write_bytecode=True
import analyze as A
from datetime import date,timedelta
from decimal import Decimal, ROUND_HALF_UP
import calendar
import copy
import json
import math

D=lambda x:Decimal(str(x))
def near(a,b): assert abs(float(a)-float(b))<1e-10,(a,b)
def main():
    A.hashes()
    ev=json.loads((A.HERE/'evidence.json').read_text())
    ps=json.loads((A.HERE/'predictions.json').read_text())
    pairs={r['pair_id']:r for r in ev['pairs']}
    _,data=A.P.verified_export()
    e=A.S.Evidence(data)
    # Raw latest-ID reconstruction, independent of Evidence.price and pair().
    owned={}
    for r in data['statistics']:
        if r['method_version']!='unit_statistics_v1' or r['metric_key']!='energy_price' or r['consumption_kwh'] is not None: continue
        k=(r['pricing_basis'],int(r['segment_key'].split('_')[-1]),r['stat_date'])
        if k not in owned or int(r['id'])>int(owned[k]['id']): owned[k]=r
    for r in pairs.values():
        assert date.fromisoformat(r['target'])-date.fromisoformat(r['issue'])==timedelta(days=r['horizon'])
        assert (r['issue']<ev['seams'][str(r['term'])])==(r['target']<ev['seams'][str(r['term'])])
        c=owned[r['basis'],r['term'],r['issue']]; a=owned[r['basis'],r['term'],r['target']]
        assert (c['id'],a['id'])==(r['current_id'],r['target_id'])
        near(D(a['median_value'])-D(c['median_value']),r['delta']['median'])
    baskets=0
    for feature in ev['features'].values():
        assert date.fromisoformat(feature['issue'])-date.fromisoformat(feature['lag'])==timedelta(days=7)
        bs=feature['baskets']
        assert [(s['month'],s['days']) for s in bs['current']['strip']]==[(s['month'],s['days']) for s in bs['fixed_lag']['strip']] or not bs['fixed_lag']['strip']
        for name,b in bs.items():
            endpoint=feature['issue'] if name=='current' else feature['lag']
            trades=sorted({r['trade_date'] for r in data['futures'] if r['area']=='FI' and r['product']=='Base' and r['trade_date']<endpoint})
            assert b['trade']==(trades[-1] if trades else None)
            if not trades: assert b['price'] is None; continue
            raw={(r['maturity_type'],r['maturity']):r for r in data['futures'] if r['trade_date']==b['trade'] and r['area']=='FI' and r['product']=='Base'}
            daily=[]
            for s in b['strip']:
                m=date.fromisoformat(s['month']); n=calendar.monthrange(m.year,m.month)[1]
                assert n==s['days']
                choices=[('month',m.strftime('%Y%m')),('quarter',f'{m.year}{((m.month-1)//3)*3+1:02d}'),('year',f'{m.year}01')]
                k=next((k for k in choices if k in raw),None)
                assert (list(k) if k else None)==s['instrument']
                if k: near(raw[k]['settlement_price'],s['settlement']); daily.extend([D(raw[k]['settlement_price'])]*n)
            if b['price'] is not None: near(sum(daily)/len(daily)/10*D('1.255'),b['price'])
            baskets+=1
    for fid,f in ev['fits'].items():
        full=[r for r in pairs.values() if r['term']==f['term'] and r['horizon']==f['horizon'] and r['target']<f['issue']]
        local=[r for r in full if ev['features'][f'{r["term"]}|{r["issue"]}']['x'][f['cohort']] is not None]
        assert [r['pair_id'] for r in full]==f['production_train_ids']
        assert [r['pair_id'] for r in local]==f['train_ids']
        assert len({r['issue'] for r in full})>=20 and len({r['issue'] for r in local})>=20
        assert f['max_target']<f['issue'] and f['production_max_target']<f['issue']
        near(sum(D(r['delta']['median']) for r in full)/len(full),f['production_mean'])
    selected=[]
    for t in A.P.TERMS:
        ids=[k for k,f in ev['fits'].items() if f['term']==t and f['horizon']==30 and f['cohort']=='fixed']
        ids.sort(key=lambda k:ev['fits'][k]['issue'])
        for fid in (ids[0],ids[-1]):
            f=ev['fits'][fid]; rs=[pairs[k] for k in f['train_ids']]; n=D(len(rs))
            xs=[D(ev['features'][f'{r["term"]}|{r["issue"]}']['x']['fixed']) for r in rs]
            ys=[D(r['delta']['median']) for r in rs]
            mx=sum(xs)/n; my=sum(ys)/n; sd=(sum((x-mx)**2 for x in xs)/n).sqrt()
            zs=[(x-mx)/sd if sd else D(0) for x in xs]
            slope=sum(z*(y-my) for z,y in zip(zs,ys))/(sum(z*z for z in zs)+n)
            near(mx,f['means'][0]); near(sd,f['scales'][0]); near(my,f['intercept']); near(slope,f['slopes'][0])
            selected.append(dict(fit_id=fid,n=int(n),independent_mean=str(my),independent_slope=str(slope)))
    for r in ps:
        assert set(r['models'])==set(A.MODELS)
        p=pairs[r['pair_id']]; f=ev['fits'][r['fit_id']]
        z=(r['x']-f['means'][0])/f['scales'][0] if f['scales'][0] else 0
        raws=[0,f['production_mean'],f['intercept'],f['intercept']+z*f['slopes'][0],f['production_mean']+z*f['slopes'][0]]
        for name,raw in zip(A.MODELS,raws):
            m=r['models'][name]; near(raw,m['raw_delta'])
            delta=D(raw).quantize(D('.0001'),rounding=ROUND_HALF_UP)
            forecast=(D(p['current'])+delta).quantize(D('.0001'),rounding=ROUND_HALF_UP)
            near(delta,m['saved_delta']); near(forecast,m['forecast']); near(forecast-D(p['actual']),m['error'])
    # Synthetic tests: missing retail lag or p20/p80 cannot gate futures or median.
    t=6; d=date(2026,6,10); h=30
    synthetic=copy.deepcopy(data)
    synthetic['statistics']=[r for r in synthetic['statistics'] if r['stat_date']!=str(d-timedelta(days=7))]
    for r in synthetic['statistics']: r['p20_value']=None; r['p80_value']='NaN'
    altered=A.S.Evidence(synthetic)
    assert A.features(altered,t,d)==A.features(e,t,d)
    assert A.pair(altered,t,d,14)[0] is not None
    # Invalid latest owns date, including canonical presence; no observed fallback.
    sample=next(r for r in data['statistics'] if r['segment_key']=='fixed_term_6' and r['stat_date']==str(d))
    bad=dict(sample,id=99999999,median_value=None)
    synthetic=copy.deepcopy(data); synthetic['statistics'].append(bad)
    assert A.pair(A.S.Evidence(synthetic),6,d,14)[1]=='missing_invalid_current'
    bad=dict(bad,pricing_basis=A.P.BASES[1])
    synthetic=copy.deepcopy(data); synthetic['statistics'].append(bad)
    altered=A.S.Evidence(synthetic)
    assert A.seam(altered,6)==d
    assert A.pair(altered,6,d,14)[1]=='missing_invalid_current'
    assert A.pair(altered,6,d-timedelta(days=1),14)[1]=='cross_seam'
    assert A.pair(altered,6,d+timedelta(days=1),14)[1]=='missing_invalid_current'
    assert not any(r['target']==str(d) for r in A.train(list(pairs.values()),dict(term=6,horizon=14,issue=str(d))))
    for x,label in [(.14994,'flat'),(.14995,'up'),(-.14995,'down'),(-.14994,'flat'),(.15,'up'),(-.15,'down')]:
        assert A.S.direction(A.S.rounded(x))==label
    assert A.features(e,6,A.P.START)['x']['fixed'] is None
    # Constant across trade dates, seasonal across deliveries: rolling changes, fixed does not.
    toy=copy.deepcopy(e); toy.trades=[date(2026,5,20),date(2026,5,31)]
    curve={('year','202601'):1.,('year','202701'):100.}
    toy.curves={day:curve.copy() for day in toy.trades}
    f=A.features(toy,12,date(2026,6,1))
    near(f['x']['fixed'],0); assert abs(f['x']['rolling'])>0
    constant=[dict(x=1,delta={'median':2},issue=str(i),target=str(i),pair_id=str(i)) for i in range(20)]
    assert A.S.fit(constant,['x'],'median')['slopes']==[0.]
    assert {r['term'] for r in ps}=={6,12,24}
    # Independently recompute every summary group from saved errors.
    results=json.loads((A.HERE/'results.json').read_text())
    for m in results['metrics']:
        rs=[r for r in ps if r['horizon']==m['horizon'] and r['cohort']==m['cohort'] and (m['term']=='all' or str(r['term'])==m['term']) and (m['basis']=='all' or r['basis']==m['basis']) and (m['actual_class']=='all' or r['actual_class']==m['actual_class'])]
        errors=[D(r['models'][m['model']]['error']) for r in rs]
        assert len(rs)==m['n']; near(sum(abs(x) for x in errors)/len(rs),m['mae']); near(sum(errors)/len(rs),m['bias'])
        for c,v in m['paired_improvement'].items(): near(sum(abs(D(r['models'][c]['error']))-abs(D(r['models'][m['model']]['error'])) for r in rs)/len(rs),v['mean'])
    A.save('checks.json',dict(status='passed',pairs=len(pairs),fits=len(ev['fits']),predictions=len(ps),baskets=baskets,independent_fits=selected,
        synthetic_checks=['missing retail lag','missing p20/p80','invalid latest','invalid canonical seam','no observed fallback','strict label cutoff','signed saved threshold','missing futures lag','month roll','constant predictor','all terms','matched five-model cohorts']))
    A.hashes(); print('Independent checks passed')
if __name__=='__main__': main()
