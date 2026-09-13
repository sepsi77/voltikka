#!/usr/bin/env python3
"""Frozen offline experiment. Standard library only; no database or network."""
import sys
sys.dont_write_bytecode = True
import bisect
import calendar
from collections import defaultdict
import csv
from datetime import date, timedelta
import hashlib
import importlib.util
import json
import math
from pathlib import Path
import statistics as st

HERE = Path(__file__).resolve().parents[1]
ROOT = HERE.parents[1]
PRIOR = HERE.parent / 'fixed-term-repricing-frequency'
ART = HERE / 'report/artifacts'
CUTOFF = '2026-07-27'
FEATURES = {'retail': ['retail_change'], 'rolling': ['retail_change', 'rolling_change'],
            'basket': ['retail_change', 'basket_change']}
MODELS = ('unchanged', 'gap', 'retail', 'rolling', 'basket', 'basket_abstain')
spec = importlib.util.spec_from_file_location('prior', PRIOR / 'horizon-lag-analysis.py')
prior = importlib.util.module_from_spec(spec)
spec.loader.exec_module(prior)


def digest(path):
    return hashlib.sha256(path.read_bytes()).hexdigest()


def save(name, value):
    ART.mkdir(parents=True, exist_ok=True)
    (ART / name).write_text(json.dumps(value, indent=2, sort_keys=True, default=str, allow_nan=False)+'\n')


def csvsave(name, rows):
    with (ART / name).open('w', newline='') as f:
        w = csv.DictWriter(f, fieldnames=list(rows[0]))
        w.writeheader()
        w.writerows(rows)


def basket(e, endpoint, start, term):
    index = bisect.bisect_left(e.trades, endpoint)-1
    if index < 0:
        return dict(price=None, trade=None, start=str(start), strip=[], missing='no_prior_trade')
    trade = e.trades[index]
    curve = e.curves[trade]
    strip, total, weight, missing = [], 0., 0, []
    month = start
    for _ in range(term):
        choices = [('month', month.strftime('%Y%m')),
                   ('quarter', f'{month.year}{((month.month-1)//3)*3+1:02d}'),
                   ('year', f'{month.year}01')]
        key = next((k for k in choices if k in curve), None)
        value = curve[key] if key else None
        n = calendar.monthrange(month.year, month.month)[1]
        strip.append(dict(month=str(month), days=n, instrument=key, settlement=value))
        if value is None:
            missing.append(str(month))
        else:
            total += n*value
        weight += n
        month = prior.next_month(month)
    return dict(price=None if missing else total/weight/10*1.255, trade=str(trade),
                start=str(start), strip=strip, missing='|'.join(missing))


def fit(rows, names, penalty=1.0):
    n = len(rows)
    means = [st.mean(r[k] for r in rows) for k in names]
    scales = [st.pstdev(r[k] for r in rows) for k in names]
    z = [[(r[k]-mu)/sd if sd else 0. for k, mu, sd in zip(names, means, scales)] for r in rows]
    intercept = st.mean(r['delta'] for r in rows)
    rhs = [sum(x[j]*(r['delta']-intercept) for x, r in zip(z, rows))/n for j in range(len(names))]
    a = [[sum(x[i]*x[j] for x in z)/n + (penalty if i == j else 0.)
          for j in range(len(names))] for i in range(len(names))]
    # At most two features. Constant columns have the defined zero coefficient.
    active = [j for j, sd in enumerate(scales) if sd]
    slopes = [0.]*len(names)
    if len(active) == 1:
        j = active[0]
        slopes[j] = rhs[j]/a[j][j]
    elif len(active) == 2:
        det = a[0][0]*a[1][1]-a[0][1]*a[1][0]
        assert det > 1e-14, 'Singular unpenalized synthetic design'
        slopes = [(rhs[0]*a[1][1]-rhs[1]*a[0][1])/det,
                  (a[0][0]*rhs[1]-a[1][0]*rhs[0])/det]
    raw = [s/sd if sd else 0. for s, sd in zip(slopes, scales)]
    return dict(features=names, means=means, scales=scales, standardized_slopes=slopes,
                raw_slopes=raw, standardized_intercept=intercept,
                raw_intercept=intercept-sum(s*m for s, m in zip(raw, means)), penalty=penalty,
                n=n, issue_days=len({r['issue'] for r in rows}),
                identities=[r['id'] for r in rows],
                max_target=max(r['target'] for r in rows))


def predict(f, row):
    return f['raw_intercept']+sum(s*row[k] for s, k in zip(f['raw_slopes'], f['features']))


def direction(delta):
    return 0 if abs(delta) < .15 else (1 if delta > 0 else -1)


def metrics(rows):
    errors = [r['prediction_delta']-r['delta'] for r in rows]
    baseline = st.mean(abs(r['delta']) for r in rows)
    mae = st.mean(abs(v) for v in errors)
    issues = sorted({r['issue'] for r in rows})
    horizon = rows[0]['horizon']
    pairs = [(a,b) for i,a in enumerate(issues) for b in issues[i+1:]]
    overlap = sum((date.fromisoformat(b)-date.fromisoformat(a)).days < horizon for a,b in pairs)
    return dict(n=len(rows), issue_days=len(issues), first_issue=issues[0], last_issue=issues[-1],
                mae=mae, rmse=math.sqrt(st.mean(v*v for v in errors)), bias=st.mean(errors),
                direction_accuracy=st.mean(direction(r['prediction_delta']) == direction(r['delta']) for r in rows),
                skill=1-mae/baseline if baseline else None,
                overlapping_issue_pairs=overlap, total_issue_pairs=len(pairs))


def main():
    manifest, data = prior.verified_export()
    proof = json.loads((PRIOR / 'horizon-lag-reproducibility.json').read_text())
    for name in ('horizon-lag-forecasts.csv', 'horizon-lag-hedges.csv', 'horizon-lag-php-checks.json'):
        assert digest(PRIOR / name) == proof['byte_identical_machine_artifacts'][name]['sha256']
    assert digest(PRIOR / 'horizon-lag-analysis.py') == proof['source_hashes']['horizon-lag-analysis.py']['sha256']
    refs = {(r['basis'], int(r['term']), int(r['horizon']), r['issue']): r
            for r in csv.DictReader((PRIOR / 'horizon-lag-forecasts.csv').open())}
    e = prior.Evidence(data)
    baskets, features, pairs, exclusions = {}, [], [], []
    for window in (7,14):
        for basis in prior.BASES:
            for term in prior.TERMS:
                for issue in prior.days(prior.START, prior.END):
                    olddate = issue-timedelta(days=window)
                    current, old = e.price(basis, term, issue), e.price(basis, term, olddate)
                    if current is None:
                        continue
                    start = prior.next_month(issue)
                    for endpoint in (issue, olddate):
                        key = f'{endpoint}|{start}|{term}'
                        if key not in baskets:
                            baskets[key] = dict(endpoint=str(endpoint), term=term, **basket(e, endpoint, start, term))
                    nowkey, oldkey = f'{issue}|{start}|{term}', f'{olddate}|{start}|{term}'
                    now, oldbasket = baskets[nowkey], baskets[oldkey]
                    rollingold = e.hedge(olddate, term)
                    reason = ('missing_retail_endpoint' if old is None else
                              'missing_current_curve' if now['price'] is None else
                              'missing_old_rolling_curve' if rollingold['price'] is None else
                              'missing_old_fixed_basket_curve' if oldbasket['price'] is None else '')
                    fid = f'{basis}|{term}|{window}|{issue}'
                    f = dict(id=fid, basis=basis, term=term, window=window, issue=str(issue), lag_date=str(olddate),
                             current=current, lag_price=old, current_stat_id=e.stats[basis,term,issue]['id'],
                             lag_stat_id=e.stats.get((basis,term,olddate),{}).get('id'),
                             basket_now=nowkey, basket_old=oldkey, old_rolling=rollingold['price'],
                             old_rolling_trade=str(rollingold['trade']) if rollingold['trade'] else None,
                             missing=reason,
                             retail_change=None if old is None else current-old,
                             rolling_change=None if now['price'] is None or rollingold['price'] is None else now['price']-rollingold['price'],
                             basket_change=None if now['price'] is None or oldbasket['price'] is None else now['price']-oldbasket['price'])
                    features.append(f)
                    for horizon in (30,14):
                        target = issue+timedelta(days=horizon)
                        actual = e.price(basis,term,target) if target <= prior.END else None
                        ref = refs.get((basis,term,horizon,str(issue)),{})
                        why = ('target_after_export' if target > prior.END else
                               'missing_same_basis_target' if actual is None else reason or
                               ('missing_gap_reference' if not ref.get('gap') else ''))
                        identity = f'{fid}|{horizon}'
                        exclusions.append(dict(id=identity, basis=basis, term=term, window=window, horizon=horizon,
                                               issue=str(issue), target=str(target), reason=why or 'eligible'))
                        if why:
                            continue
                        assert abs(float(ref['current'])-current) < 1e-12
                        assert abs(float(ref['actual'])-actual) < 1e-12
                        pairs.append(dict(**{**f, 'id':identity}, feature_id=fid, horizon=horizon,
                                          target=str(target), actual=actual, delta=actual-current,
                                          target_stat_id=e.stats[basis,term,target]['id'], gap_delta=float(ref['gap'])-current))
    predictions, fits, availability = [], {}, []
    for window in (7,14):
        for horizon in (30,14):
            group = [r for r in pairs if r['window']==window and r['horizon']==horizon]
            train = [r for r in group if r['basis']==prior.BASES[0] and r['target']<CUTOFF]
            test = [r for r in group if r['basis']==prior.BASES[1] and r['issue']>=CUTOFF]
            candidates = [('frozen_transfer', CUTOFF, train, test)]
            for basis in prior.BASES:
                same = [r for r in group if r['basis']==basis]
                for issue in sorted({r['issue'] for r in same}):
                    candidates.append(('rolling_'+basis,issue,[r for r in same if r['target']<issue],
                                       [r for r in same if r['issue']==issue]))
            for protocol, cutoff, training, testing in candidates:
                days = len({r['issue'] for r in training})
                fitid = f'{protocol}|{window}|{horizon}|{cutoff}'
                availability.append(dict(fit_id=fitid, protocol=protocol, window=window, horizon=horizon,
                                         cutoff=cutoff, training_rows=len(training), training_days=days,
                                         test_rows=len(testing), available=days>=20))
                if days < 20:
                    continue
                assert all(r['target']<cutoff for r in training)
                fits[fitid] = {name:fit(training,names) for name,names in FEATURES.items()}
                for r in testing:
                    values = dict(unchanged=0.,gap=r['gap_delta'], **{name:predict(f,r) for name,f in fits[fitid].items()})
                    values['basket_abstain'] = 0. if abs(values['basket']) < .15 else values['basket']
                    for model,value in values.items():
                        predictions.append(dict(protocol=protocol, window=window, horizon=horizon, model=model,
                                                fit_id=fitid, id=r['id'], basis=r['basis'], term=r['term'],
                                                issue=r['issue'], target=r['target'], current=r['current'],
                                                actual=r['actual'], delta=r['delta'], prediction_delta=value))
    groups = defaultdict(list)
    for r in predictions:
        for term in (r['term'],'all'):
            groups[r['protocol'],r['window'],r['horizon'],r['model'],str(term)].append(r)
    result = [dict(protocol=p,window=w,horizon=h,model=m,term=t,**metrics(rows))
              for (p,w,h,m,t),rows in sorted(groups.items())]
    common = []
    for window in (7,14):
        byh = {h:{(r['issue'],r['term']) for r in predictions if r['protocol']=='frozen_transfer'
                   and r['window']==window and r['horizon']==h} for h in (14,30)}
        keys = byh[14] & byh[30]
        for h in (14,30):
            for model in MODELS:
                rows = [r for r in predictions if r['protocol']=='frozen_transfer' and r['window']==window
                        and r['horizon']==h and r['model']==model and (r['issue'],r['term']) in keys]
                common.append(dict(window=window,horizon=h,model=model,identities=sorted(keys),**metrics(rows)))
    save('baskets.json',baskets)
    save('features.json',features)
    save('pairs.json',pairs)
    save('fits.json',fits)
    csvsave('exclusions.csv',exclusions)
    csvsave('availability.csv',availability)
    csvsave('predictions.csv',predictions)
    csvsave('metrics.csv',result)
    save('common-horizons.json',common)
    sources = [HERE/'spec.md',Path(__file__), PRIOR/'horizon-lag-analysis.py', PRIOR/'horizon-lag-forecasts.csv',
               PRIOR/'horizon-lag-hedges.csv', PRIOR/'horizon-lag-reproducibility.json',
               PRIOR/'horizon-lag-php-checks.json',
               ROOT/'laravel/app/Services/PriceForecasting/FixedTermHedgeCostService.php',
               ROOT/'laravel/app/Services/PriceForecasting/FixedTermPriceForecastService.php']
    sources += sorted((HERE/'scripts').glob('*.py'))
    sources += sorted(prior.EXPORT.glob('*'))
    save('sources.json',{str(p.relative_to(ROOT)):digest(p) for p in sources if p.is_file()})
    save('summary.json',dict(status='calculated',features=len(features),pairs=len(pairs),basket_vintages=len(baskets),
                             predictions=len(predictions),fits=len(fits),source_audit=dict(e.audit),
                             frozen_availability=[r for r in availability if r['protocol']=='frozen_transfer'],
                             canonical_rolling_max_training_days=max(r['training_days'] for r in availability if r['protocol']=='rolling_canonical_calculation')))
    print(json.dumps([r for r in result if r['protocol']=='frozen_transfer' and r['term']=='all'],indent=2))


if __name__ == '__main__':
    main()
