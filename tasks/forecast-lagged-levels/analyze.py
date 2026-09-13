#!/usr/bin/env python3
"""Offline fixed-delivery levels. All writes stay in this task."""
import sys
sys.dont_write_bytecode = True
import bisect
import calendar
from collections import Counter, defaultdict
import csv
from datetime import date, timedelta
from decimal import Decimal as D, ROUND_HALF_UP
from functools import lru_cache
import hashlib
import importlib.util
import json
import math
from pathlib import Path

HERE = Path(__file__).resolve().parent
ROOT = HERE.parents[1]
OUT = HERE/'results'
LAGS = (0, 7, 14, 21, 30)
HORIZON = 30
spec = importlib.util.spec_from_file_location('hedge_helpers', HERE.parent/'fixed-term-repricing-frequency/horizon-lag-analysis.py')
old = importlib.util.module_from_spec(spec)
spec.loader.exec_module(old)
START, END, TERMS, BASES = old.START, old.END, old.TERMS, old.BASES


def load(p): return json.loads(p.read_text())
def digest(p): return hashlib.sha256(p.read_bytes()).hexdigest()
def save(p, data): p.write_text(json.dumps(data, indent=2, sort_keys=True, default=str, allow_nan=False)+'\n')
def sources():
    pinned = load(HERE/'inputs.json')
    for path, sha in pinned.items(): assert digest(ROOT/path) == sha, path
    # Check the retained verification chain, not just newly taken hashes.
    previous = HERE.parent/'fixed-term-repricing-frequency'
    proof = load(previous/'horizon-lag-reproducibility.json')
    assert proof['status'] == 'passed' and proof['runs'] == 2
    for path, info in proof['byte_identical_machine_artifacts'].items():
        assert digest(previous/path) == info['sha256']
    assert load(previous/'horizon-lag-php-checks.json')['status'] == 'passed'
    for path, sha in load(HERE.parent/'forecast-direction-sensitivity/inputs.json').items():
        assert digest(ROOT/path) == sha, path
    return len(pinned)

def table(name, rows):
    save(OUT/(name+'.json'), rows)
    if not rows: return
    with (OUT/(name+'.csv')).open('w', newline='') as f:
        w = csv.DictWriter(f, fieldnames=list(rows[0])); w.writeheader()
        for r in rows:
            w.writerow({k:json.dumps(v, sort_keys=True, default=str) if isinstance(v,(dict,list)) else v for k,v in r.items()})

def q(x): return float(D(str(x)).quantize(D('.0001'), rounding=ROUND_HALF_UP))
def direction(x): return 'up' if x >= .15 else 'down' if x <= -.15 else 'flat'
def saved_category(x): return {'rising':'up','falling':'down','stable':'flat','slightly_rising':'flat','slightly_falling':'flat'}[x]
def outcome(p,a):
    if p == a: return 'correct'
    if p == 'flat': return 'missed_move'
    if a == 'flat': return 'false_move'
    return 'wrong_way'


class Evidence(old.Evidence):
    @lru_cache(None)
    def laghedge(self, d, term, lag):
        cutoff = d-timedelta(days=lag)
        index = bisect.bisect_left(self.trades, cutoff)-1
        start = old.next_month(d)
        if index < 0:
            return dict(price=None, trade=None, basket=start, cutoff=cutoff, missing='no_prior_trade', strip=[])
        trade = self.trades[index]
        curve = self.curves[trade]
        month, total, weight = start, 0., 0
        strip, missing = [], []
        for _ in range(term):
            choices = [('month',month.strftime('%Y%m')),('quarter',f'{month.year}{((month.month-1)//3)*3+1:02d}'),('year',f'{month.year}01')]
            chosen = next((k for k in choices if k in curve), None)
            price = curve[chosen] if chosen else None
            n = calendar.monthrange(month.year,month.month)[1]
            if price is None: missing.append(str(month))
            else: total += price*n
            weight += n
            strip.append(dict(month=str(month), days=n, type=chosen[0] if chosen else None,
                              maturity=chosen[1] if chosen else None, settlement=price))
            month = old.next_month(month)
        return dict(price=None if missing else total/weight/10*1.255, trade=trade,
                    basket=start, cutoff=cutoff, missing='|'.join(missing), strip=strip)

    def history(self, basis, term, issue):
        boundaries = [d for b,t,d in self.stats if b == BASES[1] and t == term and d <= issue]
        boundary = min(boundaries) if basis == BASES[1] and boundaries else None
        rows = []
        for d in old.days(START, issue-timedelta(days=1)):
            b = BASES[0] if basis == BASES[1] and boundary and d < boundary else basis
            p = self.price(b,term,d)
            if p is not None:
                rows.append(dict(date=str(d), basis=b, retail=p, statistic_id=self.stats[b,term,d]['id']))
        return rows, boundary


def fit(e, basis, term, issue, lag, history, boundary, support):
    accepted = [r for r in history if e.laghedge(date.fromisoformat(r['date']),term,lag)['price'] is not None]
    n = None
    for r in accepted:
        premium = r['retail']-e.laghedge(date.fromisoformat(r['date']),term,lag)['price']
        n = premium if n is None else .25*premium+.75*n
    h = e.laghedge(issue,term,lag)
    retail = e.price(basis,term,issue)
    reason = 'missing_current_retail' if retail is None else 'missing_current_H' if h['price'] is None else 'history_warmup' if len(accepted)<10 else ''
    gap = h['price']+n-retail if not reason else None
    delta = .30*gap if not reason else None
    target = issue+timedelta(days=HORIZON)
    actual = e.price(basis,term,target) if target<=END else None
    actual_delta = q(D(str(actual))-D(str(retail))) if actual is not None and retail is not None else None
    return dict(id=f'{issue}|{term}|median', issue=str(issue), target=str(target), term=term, quantile='median',
                horizon=HORIZON, basis=basis, method='unit_statistics_v1', lag=lag, support=support,
                current=retail, actual=actual, current_statistic_id=e.stats.get((basis,term,issue),{}).get('id'),
                target_statistic_id=e.stats.get((basis,term,target),{}).get('id'),
                hedge=h['price'], trade=str(h['trade']) if h['trade'] else None, basket=str(h['basket']),
                normal_premium=n, gap=gap, expected_change_raw=delta, expected_change=q(delta) if delta is not None else None,
                forecast=q(retail+delta) if delta is not None else None,
                prediction_class=direction(delta) if delta is not None else None,
                stored_delta_class=direction(q(delta)) if delta is not None else None,
                actual_delta=actual_delta, actual_class=direction(actual_delta) if actual_delta is not None else None,
                eligible=not reason, reason=reason, transition=str(boundary) if boundary else None,
                history_count=len(accepted), history_start=accepted[0]['date'] if accepted else None,
                history_end=accepted[-1]['date'] if accepted else None,
                history_basis_counts=dict(Counter(r['basis'] for r in accepted)), history_dates='|'.join(r['date'] for r in accepted))


def metrics(rs):
    n = len(rs)
    counts = Counter(outcome(r['prediction_class'],r['actual_class']) for r in rs)
    errors = [r['forecast']-r['actual'] for r in rs if r['forecast'] is not None]
    return dict(n=n, issue_days=len({r['issue'] for r in rs}), first_issue=min((r['issue'] for r in rs),default=None),
                last_issue=max((r['issue'] for r in rs),default=None),
                mae=sum(abs(x) for x in errors)/n if len(errors)==n and n else None,
                rmse=math.sqrt(sum(x*x for x in errors)/n) if len(errors)==n and n else None,
                bias=sum(errors)/n if len(errors)==n and n else None,
                **{o:counts[o] for o in ('correct','wrong_way','missed_move','false_move')},
                **{'actual_'+c:sum(r['actual_class']==c for r in rs) for c in ('up','flat','down')},
                **{'predicted_'+c:sum(r['prediction_class']==c for r in rs) for c in ('up','flat','down')})


def main():
    preserved = sources()
    manifest, data = old.verified_export()
    e = Evidence(data)
    OUT.mkdir(exist_ok=True)
    hedges = [dict(date=str(d),term=t,lag=l,**e.laghedge(d,t,l)) for d in old.days(START,END) for t in TERMS for l in LAGS]
    rows = []
    for basis in BASES:
        for term in TERMS:
            for issue in old.days(START,END):
                history, boundary = e.history(basis,term,issue)
                common = [r for r in history if all(e.laghedge(date.fromisoformat(r['date']),term,l)['price'] is not None for l in LAGS)]
                for support, hist in [('native',history),('common',common)]:
                    rows.extend(fit(e,basis,term,issue,l,hist,boundary,support) for l in LAGS)
    # Actual PHP service replay: every generated median, including immature rows.
    replay = load(HERE.parent/'fix-forecast-learning-evaluation/replay-results.json')
    assert replay['manifest_sha256'] == digest(old.EXPORT/'manifest.json')
    generated = replay['runs']['continuity']['generated']
    checks = []
    fields = {'current':'current_price_cents_per_kwh','forecast':'forecast_price_cents_per_kwh',
              'expected_change':'expected_change_cents_per_kwh','hedge':'hedge_cost_cents_per_kwh',
              'normal_premium':'normal_retail_premium_cents_per_kwh'}
    for r in rows:
        if r['basis']!=BASES[1] or r['support']!='native' or r['lag']!=0 or r['id'] not in generated: continue
        g = generated[r['id']]
        diffs = {k:abs(q(r[k])-float(g[v])) for k,v in fields.items()}
        assert max(diffs.values()) < 1e-8, (r['id'],diffs)
        assert r['prediction_class'] == saved_category(g['direction'])
        assert r['history_count'] == g['source_metadata']['history_observations']
        checks.append(dict(id=r['id'],max_difference=max(diffs.values()),history_count=r['history_count']))
    assert len(checks)==147
    stored = {f"{r['forecast_date'][:10]}|{r['duration_months']}|median":r for r in data['forecasts']
              if r['model_version']=='fixed_term_ewma_gap_v2' and r['target_quantile']=='median' and r['horizon_days']==30}
    coverage, metric_rows, selected = [], [], []
    for basis in BASES:
        for support in ('native','common'):
            pool = [r for r in rows if r['basis']==basis and r['support']==support]
            available = {l:{r['id'] for r in pool if r['lag']==l and r['eligible'] and r['actual'] is not None} for l in LAGS}
            intersection = set.intersection(*available.values())
            for l in LAGS:
                for t in (*TERMS,'all'):
                    local = [r for r in pool if r['lag']==l and (t=='all' or r['term']==t)]
                    pairs = [r for r in local if r['current'] is not None and r['actual'] is not None]
                    coverage.append(dict(basis=basis,support=support,lag=l,term=t,calendar_rows=len(local),
                        current_retail_rows=sum(r['current'] is not None for r in local),
                        eligible_all_dates=sum(r['eligible'] for r in local),exact_mature_pairs=len(pairs),
                        missing_current_H=sum(r['reason']=='missing_current_H' for r in pairs),
                        history_warmup=sum(r['reason']=='history_warmup' for r in pairs),
                        missing_current_H_all_dates=sum(r['reason']=='missing_current_H' for r in local),
                        history_warmup_all_dates=sum(r['reason']=='history_warmup' for r in local),
                        native_mature_eligible=sum(r['eligible'] for r in pairs),intersection_rows=sum(r['id'] in intersection for r in local)))
            for cohort in ('native_eligible','intersection','matched_v2'):
                if cohort=='matched_v2' and basis!=BASES[1]: continue
                for l in LAGS:
                    rs = [r for r in pool if r['lag']==l and r['id'] in (available[l] if cohort=='native_eligible' else intersection)
                          and (cohort!='matched_v2' or r['id'] in stored)]
                    selected.extend(dict(cohort=cohort,model=f'lag_{l}',**r) for r in rs)
                    models = [(f'lag_{l}',rs)]
                    if l==0:
                        for control in ('unchanged','always_up','always_down'):
                            models.append((control,[dict(r,forecast=r['current'] if control=='unchanged' else None,
                                                       prediction_class='flat' if control=='unchanged' else control[7:]) for r in rs]))
                        if cohort=='matched_v2':
                            models.append(('saved_v2',[dict(r,forecast=float(stored[r['id']]['forecast_price_cents_per_kwh']),
                                prediction_class=saved_category(stored[r['id']]['direction'])) for r in rs]))
                    for model, group in models:
                        for t in (*TERMS,'all'):
                            subset = [r for r in group if t=='all' or r['term']==t]
                            metric_rows.append(dict(basis=basis,support=support,cohort=cohort,model=model,term=t,**metrics(subset)))
    indexed = {(r['basis'],r['support'],r['id'],r['lag']):r for r in rows}
    diagnostics = []
    for r in selected:
        if r['cohort']!='intersection': continue
        zero = indexed[r['basis'],r['support'],r['id'],0]
        dh, dn = r['hedge']-zero['hedge'], r['normal_premium']-zero['normal_premium']
        diagnostics.append({k:r[k] for k in ('id','basis','support','lag','term','issue','target','current','actual','hedge','normal_premium','gap','expected_change_raw','prediction_class')} |
            dict(latest_hedge=zero['hedge'],latest_normal_premium=zero['normal_premium'],delta_H=dh,delta_N=dn,
                 delta_forecast_raw=.30*(dh+dn),lagged_H_above_latest=dh>1e-12,
                 gap_sign_change=r['gap']*zero['gap']<0,
                 prior_strong_fall_case=r['term']==6 and r['issue'] in ('2026-08-08','2026-08-09')))
    # Full rows retain all history dates; retail values and IDs come from the pinned unit export.
    table('forecasts',rows); table('hedges',hedges); table('coverage',coverage); table('metrics',metric_rows)
    table('cohorts',[{k:v for k,v in r.items() if k!='history_dates'} for r in selected])
    table('diagnostics',diagnostics); table('php-replay-checks',checks)
    table('precision-audit',[{k:r[k] for k in ('id','basis','support','lag','expected_change_raw','expected_change','prediction_class','stored_delta_class')}
          | dict(disagreement=r['prediction_class']!=r['stored_delta_class']) for r in rows if r['eligible']])
    save(OUT/'summary.json',dict(preserved_files=preserved,php_replay_rows=len(checks),forecast_candidates=len(rows),hedge_rows=len(hedges),
                               precision_mismatches=sum(r['eligible'] and r['prediction_class']!=r['stored_delta_class'] for r in rows),
                               export_status=manifest['status'],horizon=HORIZON,lags=LAGS))
    sources()
    print(json.dumps(load(OUT/'summary.json'),indent=2))

if __name__=='__main__': main()
