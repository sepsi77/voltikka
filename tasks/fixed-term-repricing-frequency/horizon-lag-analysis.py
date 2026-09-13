#!/usr/bin/env python3
"""Offline, fixed-specification horizon and change-lag research. Standard library only."""
import bisect
import calendar
from collections import Counter, defaultdict
import csv
from datetime import date, timedelta
from decimal import Decimal, ROUND_HALF_UP
from functools import lru_cache
import hashlib
import json
import math
from pathlib import Path
import statistics

HERE = Path(__file__).resolve().parent
EXPORT = HERE.parent / 'supplier-premium-coverage/export-20260913T094138Z'
START, END = date(2026, 4, 8), date(2026, 9, 13)
TERMS = (6, 12, 24)
HORIZONS = (14, 30, 45, 60)
LAGS = (0, 7, 14, 21, 30, 45, 60)
BASES = ('observed_seller_data', 'canonical_calculation')
MIN_EVENTS = 5  # Reporting guard only, fixed before calculation; not a significance test.


def number(value):
    if value is None:
        return None
    try:
        value = float(value)
    except (TypeError, ValueError):
        return None
    return value if math.isfinite(value) else None


def days(a, b):
    return [a + timedelta(days=i) for i in range((b-a).days+1)]


def next_month(d):
    return date(d.year + (d.month == 12), d.month % 12 + 1, 1)


def output(name, rows, fields=None):
    rows = list(rows)
    with (HERE / name).open('w', newline='') as f:
        writer = csv.DictWriter(f, fieldnames=fields or list(rows[0]))
        writer.writeheader()
        writer.writerows(rows)


def verified_export():
    raw = (EXPORT / 'manifest.json').read_bytes()
    assert hashlib.sha256(raw).hexdigest() == (EXPORT / 'manifest.sha256').read_text().split()[0]
    manifest = json.loads(raw)
    assert manifest['status'] == 'complete'
    assert manifest['bounds']['from'] == str(START) and manifest['bounds']['to'] == str(END)
    data = {}
    for key, q in manifest['queries'].items():
        assert Path(q['file']).name == q['file']
        payload = (EXPORT / q['file']).read_bytes()
        assert len(payload) == q['bytes'] and hashlib.sha256(payload).hexdigest() == q['sha256']
        data[key] = json.loads(payload)
        assert len(data[key]) == q['row_count'] == q['expected_count']
        for sql in ('sql', 'count_sql'):
            assert hashlib.sha256(q[sql].encode()).hexdigest() == q[sql+'_sha256']
    return manifest, data


class Evidence:
    def __init__(self, data):
        self.audit = Counter()
        self.stats = {}
        for r in data['statistics']:
            if (r['method_version'] != 'unit_statistics_v1' or r['metric_key'] != 'energy_price'
                    or r['consumption_kwh'] is not None or r['segment_key'] not in [f'fixed_term_{t}' for t in TERMS]
                    or r['pricing_basis'] not in BASES):
                self.audit['excluded_stat_scope'] += 1
                continue
            key = (r['pricing_basis'], int(r['segment_key'].split('_')[-1]), date.fromisoformat(r['stat_date']))
            if key in self.stats:
                self.audit['duplicate_stat_ownership'] += 1
            if key not in self.stats or int(r['id']) > int(self.stats[key]['id']):
                self.stats[key] = r
        self.audit['invalid_owned_medians'] = sum(number(r['median_value']) is None for r in self.stats.values())
        self.curves = defaultdict(dict)
        for r in data['futures']:
            if r['area'] != 'FI' or r['product'] != 'Base':
                self.audit['excluded_futures_scope'] += 1
                continue
            d = date.fromisoformat(r['trade_date'])
            k = (r['maturity_type'], r['maturity'])
            assert k not in self.curves[d], 'Ambiguous curve ownership'
            self.curves[d][k] = number(r['settlement_price'])
        self.trades = sorted(self.curves)
        self.audit['invalid_futures_values'] = sum(p is None for c in self.curves.values() for p in c.values())

    def price(self, basis, term, d):
        return number(self.stats.get((basis, term, d), {}).get('median_value'))

    @lru_cache(None)
    def hedge(self, d, term):
        index = bisect.bisect_left(self.trades, d)-1
        start = next_month(d)
        if index < 0:
            return dict(price=None, trade=None, basket=start, missing='no_prior_trade', strip=[])
        trade = self.trades[index]
        curve = self.curves[trade]
        month, total, weight = start, 0., 0
        strip, missing = [], []
        for _ in range(term):
            choices = [('month', month.strftime('%Y%m')),
                       ('quarter', f'{month.year}{((month.month-1)//3)*3+1:02d}'),
                       ('year', f'{month.year}01')]
            selected = next((k for k in choices if k in curve), None)
            price = curve[selected] if selected else None
            n = calendar.monthrange(month.year, month.month)[1]
            if price is None:
                missing.append(str(month))
            else:
                total += price*n
            weight += n
            strip.append(dict(month=str(month), days=n, type=selected[0] if selected else None,
                              maturity=selected[1] if selected else None, settlement=price))
            month = next_month(month)
        return dict(price=None if missing else total/weight/10*1.255, trade=trade,
                    basket=start, missing='|'.join(missing), strip=strip)

    @lru_cache(None)
    def forecast(self, basis, term, issue):
        canonical_dates = [d for b, t, d in self.stats if b == BASES[1] and t == term and d <= issue]
        transition = min(canonical_dates) if basis == BASES[1] and canonical_dates else None
        history, ewma = [], None
        for d in days(START, issue-timedelta(days=1)):
            b = BASES[0] if basis == BASES[1] and transition and d < transition else basis
            p, h = self.price(b, term, d), self.hedge(d, term)['price']
            if p is None or h is None:
                continue
            premium = p-h
            ewma = premium if ewma is None else .25*premium+.75*ewma
            history.append((d, b))
        current, hedge = self.price(basis, term, issue), self.hedge(issue, term)
        ok = current is not None and hedge['price'] is not None and len(history) >= 10
        gap = hedge['price']+ewma-current if ok else None
        return dict(gap=gap, prediction=round(current+.30*gap, 4) if ok else None,
                    ewma=ewma, n=len(history), history=history, transition=transition)


def errors(rows):
    result = dict(n=len(rows), issue_days=len({r['issue'] for r in rows}))
    for m in ('unchanged', 'gap', 'momentum'):
        valid = [abs(r[m]-r['actual']) for r in rows if r[m] is not None]
        result[m+'_mae'] = statistics.mean(valid) if len(valid) == len(rows) and valid else None
    for m in ('gap', 'momentum'):
        baseline, mae = result['unchanged_mae'], result[m+'_mae']
        result[m+'_improvement_fraction'] = 1-mae/baseline if baseline and mae is not None else None
    return result


def horizons(e):
    rows, coverage = [], []
    for basis in BASES:
        for term in TERMS:
            for horizon in HORIZONS:
                counts = Counter()
                for issue in days(START, END):
                    counts['calendar_starts'] += 1
                    target = issue+timedelta(days=horizon)
                    if target > END:
                        counts['target_after_export'] += 1
                        continue
                    current, actual = e.price(basis, term, issue), e.price(basis, term, target)
                    if current is None:
                        counts['missing_invalid_current'] += 1
                        continue
                    if actual is None:
                        counts['missing_invalid_same_basis_target'] += 1
                        continue
                    counts['exact_pairs'] += 1
                    f = e.forecast(basis, term, issue)
                    lagdate = issue-timedelta(days=14)
                    old = e.price(basis, term, lagdate)
                    if f['prediction'] is None:
                        counts['gap_unavailable'] += 1
                    if old is None:
                        counts['momentum_unavailable'] += 1
                    matched = f['prediction'] is not None and old is not None
                    counts['gap_pairs'] += f['prediction'] is not None
                    counts['all_model_pairs'] += matched
                    rows.append(dict(basis=basis, term=term, horizon=horizon, issue=str(issue), target=str(target),
                                     current=current, actual=actual, unchanged=current, gap=f['prediction'],
                                     momentum=float((Decimal(str(current))+(Decimal(str(current))-Decimal(str(old)))*horizon/14)
                                                    .quantize(Decimal('.0001'), rounding=ROUND_HALF_UP)) if old is not None else None,
                                     momentum_lag_date=str(lagdate), momentum_lag_value=old,
                                     history_count=f['n'], history_start=str(f['history'][0][0]) if f['history'] else '',
                                     history_end=str(f['history'][-1][0]) if f['history'] else '',
                                     transition=str(f['transition']) if f['transition'] else '', normal_premium=f['ewma'],
                                     hedge=e.hedge(issue, term)['price'], gap_unrounded=f['gap'], matched=int(matched)))
                names = ('calendar_starts', 'target_after_export', 'missing_invalid_current',
                         'missing_invalid_same_basis_target', 'exact_pairs', 'gap_unavailable',
                         'momentum_unavailable', 'gap_pairs', 'all_model_pairs')
                coverage.append(dict(basis=basis, term=term, horizon=horizon, **{k:counts[k] for k in names}))
    summary = []
    for basis in BASES:
        matched = [r for r in rows if r['basis'] == basis and r['matched']]
        common = set.intersection(*[{(r['issue'], r['term']) for r in matched if r['horizon'] == h} for h in (14,30,45)])
        for term in (*TERMS, 'all'):
            for horizon in HORIZONS:
                available = [r for r in rows if r['basis'] == basis and r['horizon'] == horizon and (term == 'all' or r['term'] == term)]
                for cohort, selected in (
                    ('all_models', [r for r in available if r['matched']]),
                    ('gap_available_only', [r for r in available if r['gap'] is not None]),
                    ('common_14_30_45_all_models', [r for r in available if r['matched'] and (r['issue'],r['term']) in common] if horizon != 60 else [])):
                    summary.append(dict(basis=basis, term=term, horizon=horizon, cohort=cohort, **errors(selected)))
    output('horizon-lag-forecasts.csv', rows)
    output('horizon-lag-coverage.csv', coverage)
    output('horizon-lag-metrics.csv', summary)
    return rows, coverage, summary


def association(rows):
    xs, ys = [r['hedge_change'] for r in rows], [r['retail_change'] for r in rows]
    events = {v for r in rows for v in r['events'].split('|') if v}
    corr = statistics.correlation(xs, ys) if len(rows)>1 and len(set(xs))>1 and len(set(ys))>1 else None
    nonzero = [(x,y) for x,y in zip(xs,ys) if abs(x)>1e-10 and abs(y)>1e-4]
    return dict(sample_days=len(rows), distinct_retail_change_events=len(events),
                nonzero_pairs=len(nonzero), correlation=corr,
                sign_agreement=sum(x*y>0 for x,y in nonzero)/len(nonzero) if nonzero else None,
                reportable=int(len(events)>=MIN_EVENTS), first_date=min((r['date'] for r in rows), default=''),
                last_date=max((r['date'] for r in rows), default=''))


def lags(e, data):
    panels = defaultdict(dict)
    for (basis,term,d), r in e.stats.items():
        p = number(r['median_value'])
        if p is not None:
            panels[('market', 'public_market', term, basis)][d] = p
    supplier = defaultdict(list)
    for r in data['snapshots']:
        term = int(r['fixed_time_range'].replace('Fixed',''))
        p = number(r['energy_price_cents_per_kwh'])
        if (term in TERMS and r['pricing_model'] == 'FixedPrice' and r['segment_key'] == f'fixed_term_{term}'
                and r['metering'] == 'General' and not r['includes_spot_price'] and p is not None):
            supplier[(r['company_name'], term, r['pricing_basis'], date.fromisoformat(r['snapshot_date']))].append(p)
    for (company,term,basis,d), values in supplier.items():
        panels[('supplier_general', company, term, basis)][d] = statistics.median(values)
    allrows, counts, summaries = [], [], []
    for (panel, company, term, basis), prices in sorted(panels.items()):
        base = dict(panel=panel, company=company, term=term, basis=basis)
        lagrows = defaultdict(list)
        for lag in LAGS:
            count = Counter()
            for d in sorted(prices):
                count['retail_dates'] += 1
                window = days(d-timedelta(days=7), d)
                if not all(w in prices for w in window):
                    count['missing_retail_window'] += 1
                    continue
                count['complete_retail_windows'] += 1
                end, start = d-timedelta(days=lag), d-timedelta(days=lag+7)
                h0, h1 = e.hedge(start,term), e.hedge(end,term)
                if h0['price'] is None or h1['price'] is None:
                    count['missing_hedge_endpoints'] += 1
                    continue
                count['unscreened_pairs'] += 1
                # Check every calendar basket, not only endpoints. No future price adjustment.
                baskets = {e.hedge(w,term)['basket'] for w in days(start,end)}
                roll = len(baskets) != 1
                assert roll == (h0['basket'] != h1['basket'])
                count['removed_rolls'] += roll
                count['screened_pairs'] += not roll
                events = [str(w) for w in window[1:] if abs(prices[w]-prices[w-timedelta(days=1)])>1e-4]
                row = dict(**base, lag=lag, date=str(d), retail_start=str(window[0]),
                           retail_change=prices[d]-prices[window[0]], hedge_start=str(start), hedge_end=str(end),
                           hedge_change=h1['price']-h0['price'], trade_start=str(h0['trade']), trade_end=str(h1['trade']),
                           basket_start=str(h0['basket']), basket_end=str(h1['basket']), roll=int(roll), events='|'.join(events))
                lagrows[lag].append(row)
                allrows.append(row)
            counts.append(dict(**base, lag=lag, **{k:count[k] for k in ('retail_dates','missing_retail_window',
                          'complete_retail_windows','missing_hedge_endpoints','unscreened_pairs','removed_rolls','screened_pairs')}))
        for screened in (False,True):
            selected = {lag:[r for r in lagrows[lag] if not screened or not r['roll']] for lag in LAGS}
            common_all = set.intersection(*[{r['date'] for r in selected[l]} for l in LAGS])
            common_short = set.intersection(*[{r['date'] for r in selected[l]} for l in (0,7,14)])
            for lag in LAGS:
                for cohort, rows in [('individual',selected[lag]),
                                     ('common_all_lags',[r for r in selected[lag] if r['date'] in common_all]),
                                     ('common_0_7_14',[r for r in selected[lag] if r['date'] in common_short] if lag in (0,7,14) else [])]:
                    summaries.append(dict(**base, lag=lag, screened=int(screened), cohort=cohort, **association(rows)))
    output('horizon-lag-changes.csv', allrows)
    output('horizon-lag-exclusions.csv', counts)
    output('horizon-lag-associations.csv', summaries)
    return counts, summaries


def main():
    manifest, data = verified_export()
    e = Evidence(data)
    hedges = []
    for d in days(START-timedelta(days=67), END):
        for term in TERMS:
            h = e.hedge(d, term)
            hedges.append(dict(date=str(d), term=term, price=h['price'], trade=str(h['trade']) if h['trade'] else '',
                               basket=str(h['basket']), missing=h['missing'], strip=json.dumps(h['strip'],separators=(',',':'))))
    output('horizon-lag-hedges.csv', hedges)
    rows, coverage, metrics = horizons(e)
    exclusions, associations = lags(e, data)
    replay = json.loads((HERE.parent/'fix-forecast-learning-evaluation/replay-results.json').read_text())
    checks = 0
    for old in replay['runs']['continuity']['generated'].values():
        if old['target_quantile'] != 'median':
            continue
        d, term = date.fromisoformat(old['forecast_date']), old['duration_months']
        f, h = e.forecast(BASES[1],term,d), e.hedge(d,term)
        assert abs(f['prediction']-float(old['forecast_price_cents_per_kwh'])) < 1e-9
        assert abs(h['price']-float(old['hedge_cost_cents_per_kwh'])) <= .000051
        assert f['n'] == old['source_metadata']['history_observations']
        checks += 1
    previous = []
    with (HERE.parent/'fix-forecast-learning-evaluation/replay-results.csv').open() as f:
        for old in csv.DictReader(f):
            if old['quantile'] == 'median' and old['actual']:
                r = next(r for r in rows if r['basis']==BASES[1] and r['term']==int(old['duration_months'])
                         and r['issue']==old['forecast_date'] and r['horizon']==30)
                assert abs(r['gap']-float(old['v3_forecast'])) < 1e-9
                previous.append(r)
    summary = dict(export_manifest_sha256=hashlib.sha256((EXPORT/'manifest.json').read_bytes()).hexdigest(),
                   constants=dict(horizons=HORIZONS,lags=LAGS,alpha=.25,lambda_fixed=.30,min_history=10,momentum_lag=14,
                                  vat=1.255,min_reporting_change_events=MIN_EVENTS),
                   optional_tuning='Not fitted. Fixed baseline experiment only; no target-dependent selection.',
                   audit=dict(e.audit), php_replay_median_rows_checked=checks,
                   previous_30day_matched=errors(previous), coverage=coverage, metrics=metrics)
    (HERE/'horizon-lag-summary.json').write_text(json.dumps(summary,indent=2,allow_nan=False)+'\n')
    print(json.dumps(dict(php_replay_median_rows_checked=checks,previous_30day_matched=errors(previous),
                         canonical_metrics=[m for m in metrics if m['basis']==BASES[1] and m['term']=='all']),indent=2))


if __name__ == '__main__':
    main()
