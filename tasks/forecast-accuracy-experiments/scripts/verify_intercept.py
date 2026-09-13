#!/usr/bin/env python3
"""Independent raw-price means and paired metrics; original verifier cannot write."""
import sys
sys.dont_write_bytecode = True
from collections import Counter, defaultdict
from datetime import date
from decimal import Decimal
import math
import intercept_experiment as a
import verify as original


def near(got, expected):
    assert abs(float(got)-float(expected))<=1e-10, (got,expected)


def main():
    a.check_inputs()
    for path,sha in a.load(a.OUT/'sources.json').items():
        assert a.x.digest(a.x.ROOT/path)==sha, path
    # This replaces the only writer used by original verification. No old file is written.
    def no_write(name, result):
        assert name=='verification.json'
        assert result==a.load(a.x.ART/name)
    original.x.save = no_write
    original.main()
    _, data = a.x.prior.verified_export()
    stats = {}
    for r in data['statistics']:
        if (r['metric_key'],r['method_version'],r['consumption_kwh'])!=('energy_price','unit_statistics_v1',None):
            continue
        if r['segment_key'] not in ('fixed_term_6','fixed_term_12','fixed_term_24'):
            continue
        key = r['pricing_basis'],int(r['segment_key'].rsplit('_',1)[1]),r['stat_date']
        if key not in stats or int(r['id'])>int(stats[key]['id']): stats[key]=r
    pairs = {r['id']:r for r in a.load(a.x.ART/'pairs.json')}
    deltas = {}
    for pid,r in pairs.items():
        assert pid==f"{r['basis']}|{r['term']}|{r['window']}|{r['issue']}|{r['horizon']}"
        assert (date.fromisoformat(r['target'])-date.fromisoformat(r['issue'])).days==r['horizon']
        values = []
        for endpoint,field in (('issue','current'),('target','actual')):
            raw = stats[r['basis'],r['term'],r[endpoint]]
            assert raw['id']==r['current_stat_id' if endpoint=='issue' else 'target_stat_id']
            value = Decimal(str(raw['median_value']))
            assert value.is_finite()
            near(r[field],value)
            values.append(value)
        deltas[pid] = values[1]-values[0]
        near(r['delta'],deltas[pid])
    fits = a.load(a.x.ART/'fits.json')
    means = a.load(a.OUT/'means.json')
    assert set(means)==set(fits) and len(means)==136
    availability = a.readcsv(a.x.ART/'availability.csv')
    expected_tests = {}
    for row in availability:
        fid,p,w,h,cutoff = row['fit_id'],row['protocol'],int(row['window']),int(row['horizon']),row['cutoff']
        basis = 'observed_seller_data' if p=='frozen_transfer' else p.removeprefix('rolling_')
        assert cutoff==('2026-07-27' if p=='frozen_transfer' else fid.rsplit('|',1)[1])
        training = [r for r in pairs.values() if r['basis']==basis and r['window']==w and r['horizon']==h and r['target']<cutoff]
        days = {r['issue'] for r in training}
        assert len(training)==int(row['training_rows']) and len(days)==int(row['training_days'])
        assert (fid in means)==(len(days)>=20)==(row['available']=='True')
        tests = {r['id'] for r in pairs.values() if r['window']==w and r['horizon']==h and
                 (r['basis']=='canonical_calculation' and r['issue']>=cutoff if p=='frozen_transfer'
                  else r['basis']==basis and r['issue']==cutoff)}
        assert len(tests)==int(row['test_rows'])
        expected_tests[fid] = tests if fid in means else set()
        if fid not in means: continue
        ids = {r['id'] for r in training}
        m = means[fid]
        assert set(m['identities'])==ids and len(m['identities'])==len(ids)==m['rows']
        assert m['issue_days']==len(days)>=20 and m['max_target']==max(r['target'] for r in training)<cutoff
        assert m['cutoff']==cutoff
        for fit in fits[fid].values():
            assert fit['identities']==m['identities']
        byday = defaultdict(list)
        for r in training: byday[r['issue']].append(r)
        assert all(Counter(r['term'] for r in g)==Counter({6:1,12:1,24:1}) for g in byday.values())
        mean = sum((deltas[i] for i in ids),Decimal(0))/len(ids)
        issue_mean = sum((sum((deltas[r['id']] for r in g),Decimal(0))/len(g) for g in byday.values()),Decimal(0))/len(byday)
        near(mean,issue_mean)
        near(m['mean_delta'],mean)
        for fit in fits[fid].values(): near(fit['standardized_intercept'],mean)
    old = a.readcsv(a.x.ART/'predictions.csv')
    new = a.readcsv(a.OUT/'predictions.csv')
    identity = lambda r:(r['fit_id'],r['id'],r['model'])
    oldmap = {identity(r):r for r in old}
    newmap = {identity(r):r for r in new}
    assert len(oldmap)==len(old)==3564
    assert len(newmap)==len(new)==4158
    assert {k for k in newmap if k[2]!='intercept'}==set(oldmap)
    for key,r in oldmap.items():
        for field,value in r.items(): assert newmap[key][field]==value, (key,field)
    for fid,tests in expected_tests.items():
        for model in a.MODELS:
            assert {r['id'] for r in new if r['fit_id']==fid and r['model']==model}==tests
    for r in new:
        pair = pairs[r['id']]
        for field in ('issue','target','basis'): assert r[field]==pair[field]
        for field in ('term','window','horizon'): assert int(r[field])==pair[field]
        for field in ('current','actual','delta'): near(r[field],pair[field])
        cutoff = means[r['fit_id']]['cutoff']
        assert cutoff<=r['issue']
        if r['protocol']!='frozen_transfer': assert cutoff==r['issue']
        mean = means[r['fit_id']]['mean_delta']
        near(r['intercept_delta'],mean)
        if r['model']=='intercept': near(r['prediction_delta'],mean)
        near(r['prediction_price'],float(r['current'])+float(r['prediction_delta']))
        near(r['absolute_error_increment'],abs(float(r['prediction_delta'])-float(deltas[r['id']]))-abs(mean-float(deltas[r['id']])))
    assert not any(r['protocol']=='rolling_canonical_calculation' for r in new)
    primary = [r for r in new if (r['protocol'],r['window'],r['horizon'],r['model'])==('frozen_transfer','7','30','intercept')]
    assert len(primary)==36 and len({r['issue'] for r in primary})==12
    assert min(r['issue'] for r in primary)=='2026-08-03' and max(r['issue'] for r in primary)=='2026-08-14'

    def verify_metrics(rows, metrics, compare_original=False):
        groups = defaultdict(list)
        for r in rows:
            for term in (r['term'],'all'):
                groups[r['protocol'],r['window'],r['horizon'],r['model'],term].append(r)
        oldmetrics = {(r['protocol'],r['window'],r['horizon'],r['model'],r['term']):r for r in a.readcsv(a.x.ART/'metrics.csv')}
        keys = set()
        for m in metrics:
            key = m['protocol'],m['window'],m['horizon'],m['model'],m['term']
            assert key not in keys
            keys.add(key)
            g=groups[key]; n=len(g)
            dates=sorted({r['issue'] for r in g})
            assert n==int(m['n']) and len(dates)==int(m['issue_days'])
            assert m['first_issue']==dates[0] and m['last_issue']==dates[-1]
            for day in dates:
                terms = Counter(r['term'] for r in g if r['issue']==day)
                assert terms==Counter({'6':1,'12':1,'24':1}) if m['term']=='all' else terms==Counter({m['term']:1})
            errors = [float(r['prediction_delta'])-float(deltas[r['id']]) for r in g]
            mae = sum(map(abs,errors))/n
            baseline = sum(abs(float(deltas[r['id']])) for r in g)/n
            inter = sum(abs(means[r['fit_id']]['mean_delta']-float(deltas[r['id']])) for r in g)/n
            cat=lambda v:0 if -.15<v<.15 else (1 if v>0 else -1)
            adjustment=[float(r['prediction_delta'])-means[r['fit_id']]['mean_delta'] for r in g]
            values=dict(mae=mae,rmse=math.sqrt(sum(e*e for e in errors)/n),bias=sum(errors)/n,
                        skill=1-mae/baseline, direction_accuracy=sum(cat(float(r['prediction_delta']))==cat(float(deltas[r['id']])) for r in g)/n,
                        mae_minus_intercept=mae-inter,relative_mae_minus_intercept=mae/inter-1,
                        mean_absolute_adjustment=sum(map(abs,adjustment))/n,
                        minimum_adjustment=min(adjustment),maximum_adjustment=max(adjustment))
            for field,value in values.items(): near(m[field],value)
            near(m['mae_minus_intercept'],sum(float(r['absolute_error_increment']) for r in g)/n)
            overlap=sum((date.fromisoformat(b)-date.fromisoformat(d)).days<int(m['horizon']) for i,d in enumerate(dates) for b in dates[i+1:])
            assert overlap==int(m['overlapping_issue_pairs']) and len(dates)*(len(dates)-1)//2==int(m['total_issue_pairs'])
            if compare_original and m['model']!='intercept':
                for field in oldmetrics[key]:
                    if field in ('protocol','window','horizon','model','term','first_issue','last_issue'): assert m[field]==oldmetrics[key][field]
                    else: near(m[field],oldmetrics[key][field])
        assert keys==set(groups)
    metrics = a.readcsv(a.OUT/'metrics.csv')
    verify_metrics(new,metrics,True)
    commonrows=[]
    common=a.load(a.OUT/'common-identities.json')
    for window in ('7','14'):
        ids=set.intersection(*[{(r['issue'],int(r['term'])) for r in old if r['protocol']=='frozen_transfer' and r['window']==window and r['horizon']==h} for h in ('14','30')])
        assert common[window]==[list(k) for k in sorted(ids)]
        commonrows += [r for r in new if r['protocol']=='frozen_transfer' and r['window']==window and (r['issue'],int(r['term'])) in ids]
    verify_metrics(commonrows,a.readcsv(a.OUT/'common-metrics.csv'))
    for oldcommon in a.load(a.x.ART/'common-horizons.json'):
        match=next(r for r in a.readcsv(a.OUT/'common-metrics.csv') if r['term']=='all' and r['model']==oldcommon['model'] and int(r['window'])==oldcommon['window'] and int(r['horizon'])==oldcommon['horizon'])
        for k in ('mae','rmse','bias','skill','direction_accuracy','n','issue_days','overlapping_issue_pairs','total_issue_pairs'): near(match[k],oldcommon[k])

    # Constant changes at different price levels must not learn those levels.
    constant=[dict(current=v,actual=v+2.5) for v in (1.,10.,100.)]
    near(a.training_mean(constant),2.5)
    # Issue-specific historical membership must update the mean, not retain a frozen mean.
    fixture=[dict(current=10.,actual=11.,target='2026-01-01'),dict(current=100.,actual=105.,target='2026-01-02')]
    near(a.training_mean([r for r in fixture if r['target']<'2026-01-02']),1.)
    near(a.training_mean([r for r in fixture if r['target']<'2026-01-03']),3.)
    assert len({m['mean_delta'] for fid,m in means.items() if fid.startswith('rolling_observed_seller_data|7|30|')})>1
    a.check_inputs()
    result=dict(status='passed',raw_pairs=len(pairs),fit_means=len(means),original_predictions=len(old),
                intercept_predictions=len(new)-len(old),metric_groups=len(metrics),common_metric_groups=len(a.readcsv(a.OUT/'common-metrics.csv')),
                checks=['original verifier with writes disabled','pinned original/source hashes','raw statistic ownership and IDs',
                        'strict label cutoffs and no future fit','exact balanced training/test identities and 20 issue days',
                        'Decimal delta means equal all standardized intercepts within 1e-10','all original prediction fields unchanged',
                        'independent metrics and feature adjustments','common cohorts and original metrics reproduced',
                        'constant delta across price levels','changing historical training cohort mean'])
    a.save('verification.json',result)
    print(result)


if __name__=='__main__':
    main()
