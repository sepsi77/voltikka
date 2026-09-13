#!/usr/bin/env python3
"""Independent verification from pinned raw files, not analysis helpers."""
import sys
sys.dont_write_bytecode = True
import csv
import hashlib
import json
from collections import Counter, defaultdict
from decimal import Decimal as D, ROUND_HALF_UP
from pathlib import Path
from statistics import median

H=Path(__file__).resolve().parent
ROOT=H.parents[1]
E=ROOT/'tasks/supplier-premium-coverage/export-20260913T094138Z'
A=ROOT/'tasks/forecast-accuracy-experiments/report'
O=H/'results'
C=('down','flat','up')
K=('correct','missed_move','false_move','wrong_way')

def load(p): return json.loads(p.read_text(),parse_float=D)
def csvread(p): return list(csv.DictReader(p.open()))
def sha(p): return hashlib.sha256(p.read_bytes()).hexdigest()
def q(x): return D(x).quantize(D('.0001'),rounding=ROUND_HALF_UP)
def cls(x,t=D('.15')):
    x=D(x); t=D(t)
    assert x.is_finite() and t.is_finite()
    if abs(x)<t: return 'flat'
    return 'down' if x<0 else 'up'
def result(p,a):
    return 'correct' if p==a else 'false_move' if a=='flat' else 'missed_move' if p=='flat' else 'wrong_way'
def pred(r,t): return r['model'][7:] if r['model'].startswith('always_') else cls(r['prediction_delta'],t)
def near(x,y): assert abs(D(str(x))-D(str(y)))<D('1e-12'),(x,y)

def main():
    pins=load(H/'inputs.json')
    for p,s in pins.items(): assert sha(ROOT/p)==s,p
    assert sha(H/'spec.md')==(H/'spec.sha256').read_text().split()[0]
    manifest=load(E/'manifest.json')
    for entry in manifest['queries'].values():
        p=E/entry['file']
        assert sha(p)==entry['sha256'] and p.stat().st_size==entry['bytes']
        assert len(load(p))==entry['row_count']==entry['expected_count']
    stats={}
    byid={}
    for r in load(E/'statistics.json'):
        byid[str(r['id'])]=r
        if (r['metric_key'],r['method_version'],r['consumption_kwh'])!=('energy_price','unit_statistics_v1',None): continue
        if r['segment_key'] not in ('fixed_term_6','fixed_term_12','fixed_term_24'): continue
        key=(r['pricing_basis'],r['stat_date'],r['segment_key'].split('_')[-1])
        if key not in stats or r['id']>stats[key]['id']: stats[key]=r
    snapshots=defaultdict(list)
    for r in load(E/'snapshots.json'):
        if r['energy_price_cents_per_kwh'] is not None:
            snapshots[r['pricing_basis'],r['snapshot_date'],r['segment_key'].split('_')[-1]].append(D(r['energy_price_cents_per_kwh']))
    snapshot_differences=[]
    for key,s in stats.items():
        values=snapshots[key]
        assert values,key
        if q(median(values))!=D(s['median_value']) or len(values)!=s['contract_count']:
            snapshot_differences.append(dict(key=key,statistic_id=s['id'],snapshot_rows=len(values),statistic_count=s['contract_count'],snapshot_median=str(q(median(values))),statistic_median=s['median_value']))
    pairs={r['id']:r for r in load(A/'artifacts/pairs.json')}
    fits=load(A/'artifacts/fits.json')
    original={(r['fit_id'],r['id'],r['model']):r for r in csvread(A/'intercept-artifacts/predictions.csv')}
    means=load(A/'intercept-artifacts/means.json')
    replay=load(ROOT/'tasks/fix-forecast-learning-evaluation/replay-results.json')['runs']['continuity']['generated']
    stored={f"{r['forecast_date'][:10]}|{r['duration_months']}|{r['target_quantile']}":r for r in load(E/'forecasts.json') if r['model_version']=='fixed_term_ewma_gap_v2'}
    replay_csv={f"{r['forecast_date']}|{r['duration_months']}|{r['quantile']}":r for r in csvread(ROOT/'tasks/fix-forecast-learning-evaluation/replay-results.csv')}
    for name in ('rows','metrics','transitions','saved-baselines','precision-audit','misses'):
        csvrows=csvread(O/(name+'.csv')); jsonrows=load(O/(name+'.json'))
        assert len(csvrows)==len(jsonrows)
        for cr,jr in zip(csvrows,jsonrows):
            assert set(cr)==set(jr)
            for field,value in jr.items():
                if value is None: assert cr[field]==''
                elif isinstance(value,D): near(cr[field],value)
                else: assert cr[field]==str(value),(name,field)
    rows=csvread(O/'rows.csv'); maps=defaultdict(set); fit_ids=set(); source_checks=0
    for r in rows:
        key=(r['basis'],r['issue'],r['term']); target=(r['basis'],r['target'],r['term'])
        assert D(r['current'])==D(stats[key]['median_value'])
        assert D(r['actual'])==D(stats[target]['median_value'])
        assert D(r['actual_delta'])==q(D(stats[target]['median_value'])-D(stats[key]['median_value']))
        assert r['actual_class']==cls(r['actual_delta'])
        maps[r['cohort'],r['model']].add(r['id'])
        if r['fit_id']:
            fit_ids.add(r['fit_id'])
            pair=pairs[r['id']]
            assert pair['current_stat_id']==stats[key]['id'] and pair['target_stat_id']==stats[target]['id']
            if r['model'].startswith('always_'):
                assert r['prediction_price']==r['prediction_delta']==''
                continue
            old=original[r['fit_id'],r['id'],r['model']]
            for field in ('current','actual','prediction_price'): assert D(r[field])==D(old[field])
            expected=D(old['prediction_price'])-D(old['current'])
            near(expected,old['prediction_delta'])
            if r['model']=='gap':
                expected=D(replay[f"{r['issue']}|{r['term']}|median"]['expected_change_cents_per_kwh']) if r['cohort']=='primary36' else q(expected)
            assert D(r['prediction_delta'])==expected
        else:
            raw=replay[r['id']] if r['model']=='v3' else stored[r['id']] if r['model']=='v2' else None
            if raw:
                assert D(r['prediction_delta'])==D(raw['expected_change_cents_per_kwh'])
                assert D(r['prediction_price'])==D(raw['forecast_price_cents_per_kwh'])
                assert r['saved_direction']==raw['direction']
                assert abs(D(r['prediction_delta'])-D(r['prediction_price'])+D(r['current']))<=D('.0001')
            else: assert D(r['prediction_delta'])==0 and D(r['prediction_price'])==D(r['current'])
        if r['cohort']=='matched48' and r['model'] in ('v2','v3'):
            prior=replay_csv[r['id']]
            assert D(prior[r['model']+'_forecast'])==D(r['prediction_price'])
            assert D(prior['current_price'])==D(r['current']) and D(prior['actual'])==D(r['actual'])
            assert prior[r['model']+'_direction']==r['saved_direction']
        source_checks+=1
    for cohort,n in [('primary36',36),('older57',57),('secondary84',84),('matched48',48),('full57',57)]:
        sets=[v for (c,m),v in maps.items() if c==cohort]
        assert all(s==sets[0] and len(s)==n for s in sets)
    for protocol,horizon,cohort in [('frozen_transfer','30','primary36'),('rolling_observed_seller_data','30','older57'),('frozen_transfer','14','secondary84')]:
        for model in ('unchanged','intercept','gap','retail','rolling','basket'):
            ids={r['id'] for r in original.values() if r['protocol']==protocol and r['window']=='7' and r['horizon']==horizon and r['model']==model}
            assert maps[cohort,model]==ids
    for fid in fit_ids:
        mean=means[fid]; cutoff=mean['cutoff']; ids=mean['identities']
        assert len({pairs[i]['issue'] for i in ids})>=20
        assert all(pairs[i]['target']<cutoff for i in ids)
        deltas=[]
        for i in ids:
            p=pairs[i]
            cur=byid[str(p['current_stat_id'])]; target=byid[str(p['target_stat_id'])]
            assert cur['stat_date']==p['issue'] and target['stat_date']==p['target']
            assert cur['pricing_basis']==target['pricing_basis']==p['basis']
            deltas.append(D(target['median_value'])-D(cur['median_value']))
        near(sum(deltas)/len(deltas),mean['mean_delta'])
        for fit in fits[fid].values():
            assert fit['identities']==ids
            near(fit['standardized_intercept'],mean['mean_delta'])
    for m in csvread(O/'metrics.csv'):
        rs=[r for r in rows if r['cohort']==m['cohort'] and r['model']==m['model'] and (m['term']=='all' or r['term']==m['term'])]
        n=len(rs); assert n==int(m['n'])
        assert len({r['issue'] for r in rs})==int(m['issue_days'])
        matrix=Counter((r['actual_class'],pred(r,m['threshold'])) for r in rs)
        counts=Counter(result(p,a) for (a,p),count in matrix.items() for _ in range(count))
        assert sum(counts.values())==n
        for k in K:
            assert int(m[k])==counts[k];near(m[k+'_rate'],D(counts[k])/n)
        recalls=[]
        for a in C:
            actual=sum(matrix[a,p] for p in C)
            assert int(m['actual_'+a])==actual
            for p in C: assert int(m[a+'__'+p])==matrix[a,p]
            for name,denom in [('recall',actual),('precision',sum(matrix[p,a] for p in C))]:
                assert int(m[a+'_'+name+'_numerator'])==matrix[a,a]
                assert int(m[a+'_'+name+'_denominator'])==denom
                if denom: near(m[a+'_'+name],D(matrix[a,a])/denom)
                else: assert m[a+'_'+name]==''
            if actual: recalls.append(D(matrix[a,a])/actual)
        if len(recalls)==3: near(m['balanced_accuracy'],sum(recalls)/3)
        else: assert m['balanced_accuracy']==''
    for tr in csvread(O/'transitions.csv'):
        rs=[r for r in rows if r['cohort']==tr['cohort'] and r['model']==tr['model'] and (tr['term']=='all' or r['term']==tr['term'])]
        oc=Counter((result(pred(r,'.15'),r['actual_class']),result(pred(r,tr['threshold']),r['actual_class'])) for r in rs)
        pc=Counter((pred(r,'.15'),pred(r,tr['threshold'])) for r in rs)
        assert sum(oc.values())==sum(pc.values())==int(tr['n'])
        for a in K:
            for b in K: assert int(tr[a+'__'+b])==oc[a,b]
        for a in C:
            for b in C: assert int(tr['prediction_'+a+'__'+b])==pc[a,b]
        assert int(tr['rescued_misses'])==oc['missed_move','correct']
        assert int(tr['correct_lost'])==sum(oc['correct',b] for b in K if b!='correct')
        for field,k in [('new_false_moves','false_move'),('new_wrong_ways','wrong_way')]:
            assert int(tr[field])==sum(oc[a,k] for a in K if a!=k)
    import analyze
    edge_checks=0
    for t in (D('.05'),D('.10'),D('.15')):
        for x,want in [(t,'up'),(-t,'down'),(t-D('.0001'),'flat'),(-t+D('.0001'),'flat'),(D(0),'flat')]:
            assert cls(x,t)==analyze.direction(x,t)==want; edge_checks+=1
    for x,want in [('0.14995','up'),('-0.14995','down'),('0.14994','flat'),('-0.14994','flat')]:
        assert cls(q(x))==analyze.direction(analyze.rounded(x))==want; edge_checks+=1
    for x in ('NaN','Infinity','-Infinity'):
        try: analyze.direction(x)
        except AssertionError: edge_checks+=1
        else: raise AssertionError(x)
    labels={'rising':'up','falling':'down','slightly_rising':'flat','slightly_falling':'flat','stable':'flat'}
    for m in csvread(O/'saved-baselines.csv'):
        rs=[r for r in rows if r['cohort']=='matched48' and r['model']==m['model'] and (m['term']=='all' or r['term']==m['term'])]
        c=Counter(result(labels[r['saved_direction']],cls(D(r['actual'])-D(r['current']))) for r in rs)
        assert all(int(m[k])==c[k] for k in K)
    misses=csvread(O/'misses.csv')
    expected={r['id'] for r in rows if r['cohort']=='matched48' and r['model']=='v3' and result(labels[r['saved_direction']],cls(D(r['actual'])-D(r['current'])))=='missed_move'}
    assert len(misses)==len(expected)==33 and {r['id'] for r in misses}==expected
    assert Counter(r['sign'] for r in misses)==Counter(positive=6,negative=27)
    for audit in csvread(O/'precision-audit.csv'):
        r=next(r for r in rows if (r['cohort'],r['model'],r['id'])==(audit['cohort'],audit['model'],audit['id']))
        assert D(audit['rounding_difference'])==D(r['prediction_delta'])-D(r['prediction_price'])+D(r['current'])
        assert audit['saved_category']==labels[r['saved_direction']]
        assert audit['numeric_category']==pred(r,'.15')
        assert audit['disagreement']==str(audit['saved_category']!=audit['numeric_category'])
    for r in csvread(A/'artifacts/metrics.csv'):
        if r['protocol']=='frozen_transfer' and r['window']=='7' and r['horizon']=='30' and r['term']=='all' and r['model'] in ('retail','rolling','basket'):
            assert D(r['direction_accuracy'])==D('.75')
    for p,s in pins.items(): assert sha(ROOT/p)==s,p
    evidence=dict(status='passed',failed=0,missing_selected=0,preserved_files=len(pins),raw_statistic_checks=len(rows)*2,
                  numeric_source_checks=source_checks,exact_fit_sets=len(fit_ids),synthetic_checks=edge_checks,
                  metric_groups=len(csvread(O/'metrics.csv')),transition_groups=len(csvread(O/'transitions.csv')),
                  snapshot_groups=len(stats),snapshot_statistic_differences=snapshot_differences,
                  original_fit_coefficients='Preserved by hash; no refit. Training IDs, raw target means and strict chronology rechecked.',
                  skipped_new_php_replay='Use pinned prior actual-PHP verification; no database access.')
    (O/'verification.json').write_text(json.dumps(evidence,indent=2,sort_keys=True,default=str)+'\n')
    print(json.dumps(evidence,indent=2,default=str))

if __name__=='__main__': main()
