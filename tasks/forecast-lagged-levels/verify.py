#!/usr/bin/env python3
"""Independent Decimal/day-expanded checks; no database or prior artifact writes."""
import sys
sys.dont_write_bytecode = True
from collections import Counter, defaultdict
from datetime import date, timedelta
from decimal import Decimal as D, ROUND_HALF_UP
from functools import lru_cache
import json
import csv
import math
import analyze as a


def close(x,y,tol='0.000000001'):
    assert abs(D(str(x))-D(str(y))) <= D(tol), (x,y)

def rounded(x): return x.quantize(D('.0001'),rounding=ROUND_HALF_UP)
def cat(x): return 'up' if x>=D('.15') else 'down' if x<=D('-.15') else 'flat'
def result(p,t):
    return 'correct' if p==t else 'missed_move' if p=='flat' else 'false_move' if t=='flat' else 'wrong_way'


class Independent:
    def __init__(self,data):
        self.curves=defaultdict(dict)
        for r in data['futures']:
            if r['area']=='FI' and r['product']=='Base':
                key=(r['maturity_type'],r['maturity'])
                assert key not in self.curves[r['trade_date']]
                self.curves[r['trade_date']][key]=D(str(r['settlement_price'])) if r['settlement_price'] is not None else None
        self.stats={}
        for r in sorted(data['statistics'],key=lambda r:int(r['id'])):
            if r['method_version']=='unit_statistics_v1' and r['metric_key']=='energy_price' and r['consumption_kwh'] is None and r['segment_key'] in ['fixed_term_'+str(t) for t in a.TERMS] and r['pricing_basis'] in a.BASES:
                self.stats[r['pricing_basis'],int(r['segment_key'].split('_')[-1]),r['stat_date']]=r

    def price(self,b,t,d):
        v=self.stats.get((b,t,d),{}).get('median_value')
        return D(str(v)) if v is not None and D(str(v)).is_finite() else None

    @lru_cache(None)
    def hedge(self,day,term,lag):
        cutoff=str(date.fromisoformat(day)-timedelta(days=lag))
        prior=[t for t in self.curves if t<cutoff]
        issue=date.fromisoformat(day)
        start=(issue.replace(day=28)+timedelta(days=4)).replace(day=1)
        if not prior: return None,None,[],str(start)
        trade=max(prior); curve=self.curves[trade]
        end_index=start.year*12+start.month-1+term
        end=date(end_index//12,end_index%12+1,1)
        values=[]; slots={}; missing=False
        d=start
        while d<end:
            keys=(('month',f'{d.year}{d.month:02}'),('quarter',f'{d.year}{(d.month-1)//3*3+1:02}'),('year',f'{d.year}01'))
            key=next((k for k in keys if k in curve),None)
            p=curve[key] if key else None
            if p is None: missing=True
            else: values.append(p)
            month=str(d.replace(day=1))
            if month not in slots: slots[month]=dict(month=month,days=0,type=key[0] if key else None,maturity=key[1] if key else None,settlement=p)
            slots[month]['days']+=1
            d+=timedelta(days=1)
        price=None if missing else sum(values)/D(len(values))/10*D('1.255')
        return price,trade,list(slots.values()),str(start)

    def history(self,b,t,issue,lag,common=False):
        boundaries=[d for bb,tt,d in self.stats if bb==a.BASES[1] and tt==t and d<=issue]
        boundary=min(boundaries) if b==a.BASES[1] and boundaries else None
        accepted=[]
        for d in sorted({dd for _,tt,dd in self.stats if tt==t and dd<issue}):
            basis=a.BASES[0] if b==a.BASES[1] and boundary and d<boundary else b
            retail=self.price(basis,t,d)
            if retail is None: continue
            if any(self.hedge(d,t,l)[0] is None for l in (a.LAGS if common else (lag,))): continue
            accepted.append((d,basis,retail-self.hedge(d,t,lag)[0]))
        return accepted,boundary


def synthetic():
    def future(d,typ,mat,p): return dict(trade_date=d,area='FI',product='Base',maturity_type=typ,maturity=mat,settlement_price=p)
    curves=[future('2028-01-01','month','202802','0'),future('2028-01-01','quarter','202801','20'),future('2028-01-01','year','202801','30')]
    e=a.Evidence(dict(statistics=[],futures=curves)); independent=Independent(dict(statistics=[],futures=curves))
    h=e.laghedge(date(2028,1,31),6,7)
    close(h['price'],independent.hedge('2028-01-31',6,7)[0])
    assert h['strip'][0]['days']==29 and h['strip'][0]['settlement']==0
    assert [s['type'] for s in h['strip']][:3]==['month','quarter','year']
    assert h['basket']==date(2028,2,1)
    assert e.laghedge(date(2028,2,29),6,30)['basket']==date(2028,3,1)
    assert e.laghedge(date(2028,1,1),6,0)['price'] is None
    assert e.laghedge(date(2028,1,31),6,30)['price'] is None
    newer=curves+[future('2028-01-30','month','202802','99')]
    assert a.Evidence(dict(statistics=[],futures=newer)).laghedge(date(2028,1,31),6,0)['price'] is None
    invalid=curves+[future('2028-01-01','month','202803',None)]
    assert a.Evidence(dict(statistics=[],futures=invalid)).laghedge(date(2028,1,31),6,0)['price'] is None
    # Latest invalid ownership and boundary presence, with observed data on every date.
    def stat(i,d,b,p,method='unit_statistics_v1'):
        return dict(id=i,stat_date=d,pricing_basis=b,median_value=p,method_version=method,metric_key='energy_price',consumption_kwh=None,segment_key='fixed_term_6')
    stats=[stat(i,str(d),a.BASES[0],10) for i,d in enumerate(a.old.days(date(2026,4,8),date(2026,4,20)),1)]
    stats += [stat(100,'2026-04-12',a.BASES[1],12),stat(101,'2026-04-12',a.BASES[1],None),stat(102,'2026-04-14',a.BASES[1],0),stat(103,'2026-04-10',a.BASES[1],50,'annual_cost_as_of_v2')]
    e=a.Evidence(dict(statistics=stats,futures=[]))
    assert e.price(a.BASES[1],6,date(2026,4,12)) is None
    hist,b=e.history(a.BASES[1],6,date(2026,4,15))
    assert str(b)=='2026-04-12'
    assert [r['date'] for r in hist]==['2026-04-08','2026-04-09','2026-04-10','2026-04-11','2026-04-14']
    assert hist[-1]['retail']==0
    hist,b=e.history(a.BASES[1],6,date(2026,4,11))
    assert b is None and hist==[]  # Future canonical dates cannot enable an observed prefix.
    assert e.price(a.BASES[1],6,date(2026,4,13)) is None
    before=e.history(a.BASES[1],6,date(2026,4,15))
    e.stats[a.BASES[1],6,date(2026,4,20)]=stat(200,'2026-04-20',a.BASES[1],999)
    assert e.history(a.BASES[1],6,date(2026,4,15))==before
    assert cat(D('.14996'))=='flat' and cat(rounded(D('.14996')))=='up'
    return ['leap_month_end','fixed_basket_across_roll','month_quarter_year','zero_valid','strict_earliest_trade',
            'missing_newest_no_vintage_fallback','invalid_slot_no_tenor_fallback','invalid_latest_ownership',
            'presence_boundary','no_observed_postcanonical_fallback','gap_dates','future_boundary_excluded',
            'annual_method_excluded','future_targets_not_learning','four_decimal_boundary_detected']


def main():
    preserved=a.sources()
    _,data=a.old.verified_export()
    i=Independent(data)
    hedges=a.load(a.OUT/'hedges.json')
    for h in hedges:
        price,trade,strip,basket=i.hedge(h['date'],h['term'],h['lag'])
        assert trade==h['trade'] and basket==h['basket']
        assert str(date.fromisoformat(h['date'])-timedelta(days=h['lag']))==h['cutoff']
        assert (price is None)==(h['price'] is None)
        if price is not None: close(price,h['price'])
        assert len(strip)==len(h['strip'])
        for x,y in zip(strip,h['strip']):
            for k in ('month','days','type','maturity'): assert x[k]==y[k]
            if x['settlement'] is None: assert y['settlement'] is None
            else: close(x['settlement'],y['settlement'])
        if h['lag']==0:
            standard=a.Evidence(data).hedge(date.fromisoformat(h['date']),h['term'])
            assert standard['price']==h['price']
    rows=a.load(a.OUT/'forecasts.json'); boundaries=[]; indexed={}
    precision=0; eligible=0
    for r in rows:
        b,t,d,l=r['basis'],r['term'],r['issue'],r['lag']
        current=i.price(b,t,d); target=str(date.fromisoformat(d)+timedelta(days=30))
        actual=i.price(b,t,target) if target<=str(a.END) else None
        assert target==r['target'] and r['method']=='unit_statistics_v1' and r['quantile']=='median'
        assert (current is None)==(r['current'] is None) and (actual is None)==(r['actual'] is None)
        if current is not None: close(current,r['current'])
        if actual is not None: close(actual,r['actual'])
        for field,day in [('current_statistic_id',d),('target_statistic_id',target)]:
            assert r[field]==i.stats.get((b,t,day),{}).get('id')
        hist,boundary=i.history(b,t,d,l,r['support']=='common')
        assert r['transition']==boundary
        assert r['history_dates']=='|'.join(x[0] for x in hist)
        assert r['history_count']==len(hist)
        assert r['history_start']==(hist[0][0] if hist else None) and r['history_end']==(hist[-1][0] if hist else None)
        assert r['history_basis_counts']==dict(Counter(x[1] for x in hist))
        n=len(hist)
        # Closed-form weights give the first observation the full residual weight.
        normal=sum((D('.75')**(n-1-j)*(D(1) if j==0 else D('.25')))*x[2] for j,x in enumerate(hist)) if hist else None
        if normal is not None: close(normal,r['normal_premium'])
        hedge=i.hedge(d,t,l)[0]
        reason='missing_current_retail' if current is None else 'missing_current_H' if hedge is None else 'history_warmup' if n<10 else ''
        assert r['reason']==reason and r['eligible']==(not reason)
        if not reason:
            eligible+=1
            delta=D('.30')*(hedge+normal-current)
            close(delta,r['expected_change_raw'])
            close(rounded(delta),r['expected_change'],'.0001')
            close(rounded(current+delta),r['forecast'],'.0001')
            assert cat(delta)==r['prediction_class']
            assert cat(D(str(r['expected_change'])))==r['stored_delta_class']
            precision+=cat(D(str(r['expected_change'])))!=cat(delta)
            if actual is not None:
                close(rounded(actual-current),r['actual_delta'])
                assert cat(rounded(actual-current))==r['actual_class']
        indexed[b,r['support'],r['id'],l]=r
    # Retained actual-PHP checks cover these earlier helper rows in both bases.
    prior_checks=0
    with (a.HERE.parent/'fixed-term-repricing-frequency/horizon-lag-forecasts.csv').open() as f:
        for p in csv.DictReader(f):
            if p['horizon']!='30': continue
            r=indexed[p['basis'],'native',f"{p['issue']}|{p['term']}|median",0]
            assert (p['gap']!='')==r['eligible']
            close(p['current'],r['current']); close(p['actual'],r['actual'])
            if r['eligible']:
                close(p['gap'],r['forecast'])
                close(p['normal_premium'],r['normal_premium'])
                assert int(p['history_count'])==r['history_count']
            prior_checks+=1
    # Reconstruct every cohort independently from valid source forecasts.
    stored={f"{r['forecast_date'][:10]}|{r['duration_months']}|median":r for r in data['forecasts'] if r['model_version']=='fixed_term_ewma_gap_v2' and r['target_quantile']=='median' and r['horizon_days']==30}
    cohorts=a.load(a.OUT/'cohorts.json'); sets=defaultdict(set)
    for r in cohorts:
        key=(r['basis'],r['support'],r['cohort'],r['model'])
        assert r['id'] not in sets[key]; sets[key].add(r['id'])
        original=indexed[r['basis'],r['support'],r['id'],r['lag']]
        for k,v in r.items():
            if k not in ('cohort','model'): assert v==original[k]
    for b in a.BASES:
        for s in ('native','common'):
            available={l:{r['id'] for r in rows if r['basis']==b and r['support']==s and r['lag']==l and r['eligible'] and r['actual'] is not None} for l in a.LAGS}
            common=set.intersection(*available.values())
            for l in a.LAGS:
                assert sets[b,s,'native_eligible',f'lag_{l}']==available[l]
                assert sets[b,s,'intersection',f'lag_{l}']==common
                if b==a.BASES[1]: assert sets[b,s,'matched_v2',f'lag_{l}']==common & stored.keys()
            # All five common-support fits learn from exactly the same dates.
            for identity in common:
                assert len({indexed[b,'common',identity,l]['history_dates'] for l in a.LAGS})==1
    metrics=a.load(a.OUT/'metrics.json')
    for m in metrics:
        model=m['model']; l=int(model[4:]) if model.startswith('lag_') else 0
        identities=sets[m['basis'],m['support'],m['cohort'],f'lag_{l}']
        group=[indexed[m['basis'],m['support'],x,l] for x in sorted(identities) if m['term']=='all' or indexed[m['basis'],m['support'],x,l]['term']==m['term']]
        assert m['n']==len(group) and m['issue_days']==len({r['issue'] for r in group})
        counts=Counter(); errors=[]; predictions=Counter(); actuals=Counter()
        for r in group:
            pred=r['prediction_class']; price=r['forecast']
            if model=='unchanged': pred,price='flat',r['current']
            elif model.startswith('always_'): pred,price=model[7:],None
            elif model=='saved_v2':
                original=stored[r['id']]
                metadata=json.loads(original['source_metadata'])
                assert metadata['current_retail_pricing_basis']==r['basis']
                assert metadata.get('current_retail_method_version','unit_statistics_v1')==r['method']
                assert metadata['current_retail_source_date']==r['issue']
                assert original['target_date'][:10]==r['target']
                close(metadata['direction_threshold_cents_per_kwh'],'.15')
                close(original['current_price_cents_per_kwh'],r['current'])
                pred,price=a.saved_category(original['direction']),original['forecast_price_cents_per_kwh']
            counts[result(pred,r['actual_class'])]+=1; predictions[pred]+=1; actuals[r['actual_class']]+=1
            if price is not None: errors.append(D(str(price))-D(str(r['actual'])))
        for k in ('correct','wrong_way','missed_move','false_move'): assert counts[k]==m[k]
        assert sum(counts.values())==m['n']
        for c in ('up','flat','down'): assert m['actual_'+c]==actuals[c] and m['predicted_'+c]==predictions[c]
        if errors:
            close(sum(abs(x) for x in errors)/len(errors),m['mae'])
            close(sum(errors)/len(errors),m['bias'])
            close((sum(x*x for x in errors)/len(errors)).sqrt(),m['rmse'])
        else: assert m['mae'] is None and m['rmse'] is None and m['bias'] is None
    for c in a.load(a.OUT/'coverage.json'):
        rs=[r for r in rows if r['basis']==c['basis'] and r['support']==c['support'] and r['lag']==c['lag'] and (c['term']=='all' or r['term']==c['term'])]
        pairs=[r for r in rs if r['current'] is not None and r['actual'] is not None]
        assert c['calendar_rows']==len(rs) and c['exact_mature_pairs']==len(pairs)
        assert c['native_mature_eligible']==sum(r['eligible'] for r in pairs)
        assert c['missing_current_H']==sum(r['reason']=='missing_current_H' for r in pairs)
        assert c['history_warmup']==sum(r['reason']=='history_warmup' for r in pairs)
        assert len(pairs)==c['native_mature_eligible']+c['missing_current_H']+c['history_warmup']
    for r in a.load(a.OUT/'diagnostics.json'):
        z=indexed[r['basis'],r['support'],r['id'],0]
        close(r['delta_H'],D(str(r['hedge']))-D(str(z['hedge'])))
        close(r['delta_N'],D(str(r['normal_premium']))-D(str(z['normal_premium'])))
        close(r['delta_forecast_raw'],D('.30')*(D(str(r['delta_H']))+D(str(r['delta_N']))))
        close(r['delta_forecast_raw'],D(str(r['expected_change_raw']))-D(str(z['expected_change_raw'])))
    sensitivity=[]
    for b in a.BASES:
        identities=sets[b,'native','intersection','lag_0'] & sets[b,'common','intersection','lag_0']
        for identity in sorted(identities):
            for lag in a.LAGS:
                native=indexed[b,'native',identity,lag]; common=indexed[b,'common',identity,lag]
                sensitivity.append(dict(basis=b,id=identity,lag=lag,native_history_count=native['history_count'],
                    common_history_count=common['history_count'],normal_premium_difference=common['normal_premium']-native['normal_premium'],
                    raw_change_difference=common['expected_change_raw']-native['expected_change_raw'],
                    stored_forecast_difference=common['forecast']-native['forecast'],
                    direction_changed=common['prediction_class']!=native['prediction_class']))
    a.table('history-sensitivity',sensitivity)
    checks=synthetic()
    a.sources()
    summary=dict(status='passed',day_expanded_hedges=len(hedges),closed_form_history_forecasts=len(rows),eligible_forecasts=eligible,
                 cohort_rows=len(cohorts),metric_groups=len(metrics),precision_mismatches=precision,synthetic_checks=checks,preserved_files=preserved,
                 php_replay_rows=len(a.load(a.OUT/'php-replay-checks.json')),prior_php_verified_both_basis_pairs=prior_checks,database='not used; retained verified actual-PHP evidence')
    a.save(a.OUT/'verification.json',summary)
    print(json.dumps(summary,indent=2))

if __name__=='__main__': main()
