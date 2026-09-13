#!/usr/bin/env python3
"""Offline, fixed-coefficient snapshot-index diagnostic. Standard library only."""
import calendar
import csv
import hashlib
import json
import math
import sys
from collections import Counter, defaultdict
from datetime import date, timedelta
from functools import lru_cache
from pathlib import Path
from statistics import mean, median

OUT = Path(__file__).resolve().parent
SOURCE = OUT.parent / 'supplier-premium-coverage/export-20260913T094138Z'
ALPHA, LAMBDA, MIN_HISTORY, VAT = 0.25, 0.30, 10, 1.255
TERMS = (6, 12, 24)
CANONICAL, OBSERVED = 'canonical_calculation', 'observed_seller_data'
START, END = date(2026, 4, 8), date(2026, 9, 13)
ISSUES = [date(2026, 7, 27) + timedelta(days=i) for i in range(19)]
MODELS = ('supplier', 'unchanged', 'pooled')


def digest(data):
    return hashlib.sha256(data).hexdigest()


def save_json(name, value):
    (OUT / name).write_text(json.dumps(value, indent=2, ensure_ascii=False, allow_nan=False) + '\n')


def save_csv(name, rows):
    assert rows, name
    with (OUT / name).open('w', newline='') as stream:
        writer = csv.DictWriter(stream, fieldnames=list(rows[0]))
        writer.writeheader()
        writer.writerows(rows)


def valid_price(value):
    if value is None:
        return False
    try:
        value = float(value)
        return math.isfinite(value) and 0.005 <= value <= 50.0
    except (TypeError, ValueError):
        return False


def ewma(values):
    result = values[0]
    for value in values[1:]:
        result = ALPHA * value + (1 - ALPHA) * result
    # Independent closed-form weight check; no fitted or variable weights.
    n = len(values)
    check = values[0] * 0.75 ** (n - 1) + sum(
        0.25 * value * 0.75 ** (n - 1 - i) for i, value in enumerate(values[1:], 1))
    assert math.isclose(result, check, abs_tol=1e-12)
    return result


def main():
    raw = (SOURCE / 'manifest.json').read_bytes()
    assert digest(raw) == (SOURCE / 'manifest.sha256').read_text().split()[0]
    manifest = json.loads(raw)
    assert manifest['status'] == 'complete'
    data = {}
    for key, query in manifest['queries'].items():
        blob = (SOURCE / query['file']).read_bytes()
        assert digest(blob) == query['sha256'] and len(blob) == query['bytes']
        for field in ('sql', 'count_sql'):
            assert digest(query[field].encode()) == query[field + '_sha256']
        data[key] = json.loads(blob)
        assert len(data[key]) == query['row_count'] == query['expected_count']
    assert manifest['method_pair'] == ['retail-premium-v2', 'retail-premium-history-v2']
    assert (ALPHA, LAMBDA, MIN_HISTORY, VAT) == (0.25, 0.30, 10, 1.255)
    assert [valid_price(x) for x in (None, -1, 0, 0.0049, 0.005, 50, 50.001, float('nan'), float('inf'))] == [False]*4 + [True, True] + [False]*3

    boundaries = {}
    for term in TERMS:
        boundaries[term] = min(date.fromisoformat(r['stat_date']) for r in data['statistics']
                               if r['segment_key'] == f'fixed_term_{term}' and r['pricing_basis'] == CANONICAL
                               and r['method_version'] == 'unit_statistics_v1')
    def basis_on(term, day):
        return OBSERVED if day < boundaries[term] else CANONICAL

    curves = defaultdict(dict)
    for row in data['futures']:
        assert row['area'] == 'FI' and row['product'] == 'Base'
        day = date.fromisoformat(row['trade_date'])
        key = row['maturity_type'], str(row['maturity'])
        assert key not in curves[day], 'ambiguous curve key'
        curves[day][key] = float(row['settlement_price'])
    trades = sorted(curves)
    hedge_audit = []

    @lru_cache(None)
    def hedge(day, term):
        prior = [t for t in trades if t < day]
        trade = max(prior) if prior else None
        chosen, missing = [], []
        for offset in range(term):
            month_index = day.year * 12 + day.month + offset
            year, month0 = divmod(month_index, 12)
            month = month0 + 1
            weight = calendar.monthrange(year, month)[1]
            keys = [('month', f'{year}{month:02d}'),
                    ('quarter', f'{year}{((month - 1) // 3) * 3 + 1:02d}'),
                    ('year', f'{year}01')]
            selected = next((key for key in keys if trade and key in curves[trade]), None)
            if selected is None:
                missing.append(f'{year}-{month:02d}')
            else:
                settlement = curves[trade][selected]
                assert math.isfinite(settlement)
                chosen.append((weight, settlement, selected[0]))
        value = None if missing else sum(w*p for w, p, _ in chosen) / sum(w for w, _, _ in chosen) / 10 * VAT
        if value is not None:
            # Independent daily expansion checks the calendar-day strip weights.
            daily = [p for w, p, _ in chosen for _ in range(w)]
            assert math.isclose(value, mean(daily) * 0.1255, abs_tol=1e-12)
            assert len(chosen) == term and trade < day
            assert not any(trade < t < day for t in trades)
        hedge_audit.append(dict(date=str(day), term=term, trade_date=str(trade) if trade else '',
                                hedge=value, missing_months=';'.join(missing),
                                month_count=sum(t == 'month' for _, _, t in chosen),
                                quarter_count=sum(t == 'quarter' for _, _, t in chosen),
                                year_count=sum(t == 'year' for _, _, t in chosen)))
        return value, trade

    supplier_offers, market_offers = defaultdict(list), defaultdict(list)
    exclusions, reason_counts = [], Counter()
    companies = sorted({r['company_name'] for r in data['snapshots']})
    identities = set()
    accepted = []
    for row in data['snapshots']:
        day = date.fromisoformat(row['snapshot_date'])
        term = int(row['fixed_time_range'].replace('Fixed', ''))
        reasons = []
        if row['segment_key'] != f'fixed_term_{term}': reasons.append('not_exact_fixed_segment')
        if row['pricing_model'] != 'FixedPrice': reasons.append('not_FixedPrice')
        if row['metering'] != 'General': reasons.append('not_General')
        if not valid_price(row['energy_price_cents_per_kwh']): reasons.append('producer_price_filter_or_nonfinite')
        if row['pricing_basis'] != basis_on(term, day): reasons.append('wrong_history_basis')
        if not row['company_name']: reasons.append('missing_company')
        if reasons:
            reason_counts.update(reasons)
            exclusions.append(dict(snapshot_id=row['id'], company=row['company_name'], term=term,
                                   date=str(day), reasons=';'.join(reasons)))
            continue
        identity = (row['contract_id'], day)
        assert identity not in identities, 'duplicate same-date contract row'
        identities.add(identity)
        price = float(row['energy_price_cents_per_kwh'])
        supplier_offers[row['company_name'], term, day].append(price)
        market_offers[term, day].append(price)
        accepted.append(row)
    supplier = {k: median(v) for k, v in supplier_offers.items()}
    market = {k: median(v) for k, v in market_offers.items()}
    series = defaultdict(dict)
    for (company, term, day), price in supplier.items(): series[company, term][day] = price
    for (term, day), price in market.items(): series['__market__', term][day] = price
    daily_rows = []
    for (company, term), prices in sorted(series.items()):
        for day, price in sorted(prices.items()):
            h, trade = hedge(day, term)
            count = len(market_offers[term, day]) if company == '__market__' else len(supplier_offers[company, term, day])
            daily_rows.append(dict(company=company, term=term, date=str(day), basis=basis_on(term, day),
                                   median=price, offers=count, hedge=h, trade_date=str(trade) if trade else '',
                                   usable_history=h is not None))

    def history(company, term, issue):
        prices = series.get((company, term), {})
        before = [(d, p) for d, p in sorted(prices.items()) if d < issue]
        usable = [(d, p, hedge(d, term)[0]) for d, p in before if hedge(d, term)[0] is not None]
        assert all(d < issue and hedge(d, term)[1] < d for d, _, _ in usable)
        stats = dict(history_n=len(usable), history_observed=sum(d < boundaries[term] for d, _, _ in usable),
                     history_canonical=sum(d >= boundaries[term] for d, _, _ in usable),
                     history_unique_levels=len({p for _, p, _ in usable}),
                     history_median_changes=sum(a[1] != b[1] for a, b in zip(usable, usable[1:])),
                     history_curve_failures=len(before)-len(usable),
                     history_missing_dates=(issue-START).days-len(before),
                     history_last_date=str(usable[-1][0]) if usable else '')
        premium = ewma([p-h for _, p, h in usable]) if usable else None
        return stats, premium

    pairs, attempts = [], []
    for company in companies:
        for term in TERMS:
            for issue in ISSUES:
                target = issue + timedelta(days=30)
                current, actual = supplier.get((company, term, issue)), supplier.get((company, term, target))
                m = market.get((term, issue))
                h, trade = hedge(issue, term)
                sh, ns = history(company, term, issue)
                mh, nm = history('__market__', term, issue)
                reasons = []
                if current is None: reasons.append('no_exact_current')
                if actual is None: reasons.append('no_exact_target')
                if m is None: reasons.append('no_exact_market_current')
                if basis_on(term, issue) != CANONICAL or basis_on(term, target) != CANONICAL: reasons.append('not_canonical_pair')
                if h is None: reasons.append('incomplete_issue_curve')
                if sh['history_n'] < MIN_HISTORY: reasons.append('supplier_history_below_10')
                if mh['history_n'] < MIN_HISTORY: reasons.append('market_history_below_10')
                row = dict(company=company, term=term, issue_date=str(issue), target_date=str(target),
                           current=current, actual=actual, market_current=m, hedge=h, trade_date=str(trade),
                           **{'supplier_'+k:v for k,v in sh.items()}, **{'market_'+k:v for k,v in mh.items()},
                           reasons=';'.join(reasons))
                attempts.append(row)
                if reasons: continue
                forecasts = dict(supplier=current + LAMBDA*(h+ns-current), unchanged=current,
                                 pooled=current + LAMBDA*(h+nm-m))
                pair = dict(row, supplier_premium=ns, market_premium=nm,
                            **{k+'_forecast':v for k,v in forecasts.items()},
                            **{k+'_ae':abs(v-actual) for k,v in forecasts.items()})
                assert target == date.fromisoformat(pair['issue_date']) + timedelta(days=30)
                # Independently reconstruct all three predictions from their stated equations.
                assert math.isclose(pair['supplier_forecast']-current, 0.30*(h+ns-current), abs_tol=1e-12)
                assert math.isclose(pair['pooled_forecast']-current, 0.30*(h+nm-m), abs_tol=1e-12)
                assert pair['unchanged_forecast'] == current
                pairs.append(pair)
    assert pairs
    keys = lambda rows: {(r['company'], r['term'], r['issue_date'], r['target_date']) for r in rows}
    assert len(keys(pairs)) == len(pairs)
    assert keys(pairs) == keys([r for r in attempts if not r['reasons']])
    denominators = [keys([r for r in pairs if math.isfinite(r[m+'_ae'])]) for m in MODELS]
    assert denominators[0] == denominators[1] == denominators[2]

    def metrics(rows):
        result = {'n':len(rows)}
        for model in MODELS: result[model+'_mae'] = mean(r[model+'_ae'] for r in rows) if rows else None
        for baseline in ('unchanged', 'pooled'):
            base, model = result[baseline+'_mae'], result['supplier_mae']
            result['improvement_vs_'+baseline] = base-model if rows else None
            result['improvement_pct_vs_'+baseline] = 100*(base-model)/base if rows and base else None
        return result
    cells = []
    for company in companies:
        for term in TERMS:
            rows = [r for r in pairs if (r['company'],r['term']) == (company,term)]
            trials = [r for r in attempts if (r['company'],r['term']) == (company,term)]
            prices = series.get((company,term), {})
            values = [v for _,v in sorted(prices.items())]
            reasons = Counter(reason for r in trials for reason in r['reasons'].split(';') if reason)
            cells.append(dict(company=company, term=term, **metrics(rows), attempted_n=len(trials),
                              exact_date_pair_n=sum(r['current'] is not None and r['actual'] is not None for r in trials),
                              excluded_n=len(trials)-len(rows), exclusions=json.dumps(dict(reasons),sort_keys=True),
                              first_date=str(min(prices)) if prices else '', latest_date=str(max(prices)) if prices else '',
                              daily_n=len(prices), unique_levels=len(set(values)),
                              median_changes=sum(a != b for a,b in zip(values,values[1:])),
                              history_min=min((r['supplier_history_n'] for r in rows), default=None),
                              history_max=max((r['supplier_history_n'] for r in rows), default=None),
                              history_unique_levels_min=min((r['supplier_history_unique_levels'] for r in rows), default=None),
                              history_unique_levels_max=max((r['supplier_history_unique_levels'] for r in rows), default=None),
                              history_changes_min=min((r['supplier_history_median_changes'] for r in rows), default=None),
                              history_changes_max=max((r['supplier_history_median_changes'] for r in rows), default=None)))
    aggregates = []
    for term in ('all', *TERMS):
        selected = [r for r in pairs if term == 'all' or r['term'] == term]
        used_cells = [r for r in cells if r['n'] and (term == 'all' or r['term'] == term)]
        aggregates.append(dict(term=term, weighting='pooled_forecast_pair', cells=len(used_cells), **metrics(selected)))
        # Each supplier-term cell receives one vote, irrespective of available pair count.
        pseudo = [{m+'_ae':c[m+'_mae'] for m in MODELS} for c in used_cells]
        equal = metrics(pseudo)
        equal['n'] = len(selected)
        aggregates.append(dict(term=term, weighting='equal_supplier_term_cell', cells=len(used_cells), **equal))
    date_pairs = []
    for issue in ISSUES:
        for term in TERMS:
            trials = [r for r in attempts if r['issue_date'] == str(issue) and r['term'] == term]
            date_pairs.append(dict(issue_date=str(issue), target_date=str(issue+timedelta(days=30)), term=term,
                                   attempted=len(trials), exact_pairs=sum(r['current'] is not None and r['actual'] is not None for r in trials),
                                   included=sum(not r['reasons'] for r in trials), excluded=sum(bool(r['reasons']) for r in trials)))
    # Every calendar date is explicit; zero supply dates remain gaps.
    gaps = []
    for offset in range((END-START).days+1):
        day = START+timedelta(days=offset)
        for term in TERMS:
            gaps.append(dict(date=str(day), term=term, expected_basis=basis_on(term,day),
                             eligible_offers=len(market_offers.get((term,day),[])),
                             supplier_count=sum(t == term and d == day for _,t,d in supplier),
                             has_statistic=any(r['stat_date'] == str(day) and r['segment_key'] == f'fixed_term_{term}'
                                               and r['pricing_basis'] == basis_on(term,day) for r in data['statistics'])))
    trial_reasons = Counter(reason for r in attempts for reason in r['reasons'].split(';') if reason)
    summary = dict(manifest_sha256=digest(raw), parameters=dict(alpha=ALPHA, lambda_=LAMBDA, min_history=MIN_HISTORY, vat=VAT),
                   canonical_boundaries={str(k):str(v) for k,v in boundaries.items()},
                   snapshot_rows=len(data['snapshots']), eligible_snapshot_rows=len(accepted), excluded_snapshot_rows=len(exclusions),
                   snapshot_exclusions_nonexclusive=dict(reason_counts), suppliers=len(companies),
                   eligible_suppliers=len({r['company_name'] for r in accepted}),
                   candidate_pairs=len(attempts), accepted_pairs=len(pairs), excluded_pairs=len(attempts)-len(pairs),
                   pair_exclusions_nonexclusive=dict(trial_reasons), evaluated_cells=sum(bool(c['n']) for c in cells),
                   evaluated_suppliers=len({r['company'] for r in pairs}),
                   distinct_issue_dates=len({r['issue_date'] for r in pairs}),
                   distinct_target_dates=len({r['target_date'] for r in pairs}),
                   missing_market_term_dates=sum(r['eligible_offers'] == 0 for r in gaps),
                   hedge_date_term_queries=len(hedge_audit), hedge_failed_date_terms=sum(r['hedge'] is None for r in hedge_audit),
                   aggregates=aggregates,
                   checks={'manifest_sql_file_hashes_counts_bytes':'passed', 'fixed_parameters_and_price_edges':'passed',
                           'strict_prior_history_and_latest_curve':'passed', 'daily_strip_weight_reconstruction':'passed',
                           'closed_form_ewma_weights':'passed', 'exact_30_day_canonical_targets':'passed',
                           'identical_three_model_denominators':'passed', 'duplicate_contract_date_guard':'passed'})
    save_csv('snapshot-exclusions.csv', exclusions)
    save_csv('daily-indices.csv', daily_rows)
    save_csv('curve-audit.csv', sorted(hedge_audit,key=lambda r:(r['date'],r['term'])))
    save_csv('pair-audit.csv', attempts)
    save_csv('forecast-pairs.csv', pairs)
    save_csv('supplier-term-results.csv', cells)
    save_csv('aggregate-results.csv', aggregates)
    save_csv('available-date-pairs.csv', date_pairs)
    save_csv('date-gap-audit.csv', gaps)
    save_json('summary.json', summary)
    print(json.dumps(summary,indent=2,ensure_ascii=False))


def independent_check():
    """Re-read saved artifacts and raw evidence; do not call the model helpers."""
    snapshots = json.loads((SOURCE / 'snapshots.json').read_text())
    statistics = json.loads((SOURCE / 'statistics.json').read_text())
    futures = json.loads((SOURCE / 'futures.json').read_text())
    raw_groups = defaultdict(list)
    boundaries = {t: min(r['stat_date'] for r in statistics if r['segment_key'] == f'fixed_term_{t}'
                         and r['pricing_basis'] == 'canonical_calculation') for t in (6, 12, 24)}
    for r in snapshots:
        term = int(r['fixed_time_range'][5:])
        expected = 'observed_seller_data' if r['snapshot_date'] < boundaries[term] else 'canonical_calculation'
        p = r['energy_price_cents_per_kwh']
        if (r['segment_key'] == 'fixed_term_'+str(term) and r['metering'] == 'General'
                and r['pricing_model'] == 'FixedPrice' and p is not None
                and math.isfinite(float(p)) and 0.005 <= float(p) <= 50
                and r['pricing_basis'] == expected and r['company_name']):
            raw_groups[r['company_name'], term, r['snapshot_date']].append(float(p))
            raw_groups['__market__', term, r['snapshot_date']].append(float(p))
    raw_medians = {}
    for key, values in raw_groups.items():
        ordered = sorted(values)
        raw_medians[key] = (ordered[(len(ordered)-1)//2] + ordered[len(ordered)//2])/2
    daily = list(csv.DictReader((OUT / 'daily-indices.csv').open()))
    assert len(daily) == len(raw_medians)
    for r in daily:
        key = r['company'], int(r['term']), r['date']
        assert float(r['median']) == raw_medians[key]
        assert int(r['offers']) == len(raw_groups[key])
    curves = list(csv.DictReader((OUT / 'curve-audit.csv').open()))
    checked_h = {}
    for r in curves:
        issue = date.fromisoformat(r['date'])
        term = int(r['term'])
        prior = [f['trade_date'] for f in futures if f['area'] == 'FI' and f['product'] == 'Base' and f['trade_date'] < r['date']]
        trade = max(prior) if prior else ''
        assert r['trade_date'] == trade
        available = {(f['maturity_type'], str(f['maturity'])): float(f['settlement_price']) for f in futures
                     if f['trade_date'] == trade and f['area'] == 'FI' and f['product'] == 'Base'}
        first = (issue.replace(day=28) + timedelta(days=4)).replace(day=1)
        end = first
        for _ in range(term): end = (end.replace(day=28)+timedelta(days=4)).replace(day=1)
        day, values, missing = first, [], set()
        while day < end:
            choices = [('month', day.strftime('%Y%m')),
                       ('quarter', f'{day.year}{1 + 3*((day.month-1)//3):02d}'), ('year', f'{day.year}01')]
            chosen = next((k for k in choices if k in available), None)
            if chosen is None: missing.add(day.strftime('%Y-%m'))
            else: values.append(available[chosen])
            day += timedelta(days=1)
        assert r['missing_months'] == ';'.join(sorted(missing))
        h = None if missing else sum(values)/len(values)*0.1255
        assert (h is None) == (r['hedge'] == '')
        if h is not None: assert math.isclose(h, float(r['hedge']), abs_tol=1e-10)
        checked_h[r['date'], term] = h
    pairs = list(csv.DictReader((OUT / 'forecast-pairs.csv').open()))
    attempts = list(csv.DictReader((OUT / 'pair-audit.csv').open()))
    expected_keys = set()
    for r in attempts:
        c, t, d, target = r['company'], int(r['term']), r['issue_date'], r['target_date']
        assert date.fromisoformat(target)-date.fromisoformat(d) == timedelta(days=30)
        history = {who: [(day,p) for (company,term,day),p in sorted(raw_medians.items())
                         if company == who and term == t and day < d and checked_h[day,t] is not None]
                   for who in (c, '__market__')}
        good = ((c,t,d) in raw_medians and (c,t,target) in raw_medians and ('__market__',t,d) in raw_medians
                and d >= boundaries[t] and target >= boundaries[t] and checked_h[d,t] is not None
                and all(len(h) >= 10 for h in history.values()))
        assert good == (not r['reasons'])
        if good: expected_keys.add((c,t,d,target))
    assert {(r['company'],int(r['term']),r['issue_date'],r['target_date']) for r in pairs} == expected_keys
    for r in pairs:
        c, t, d, target = r['company'], int(r['term']), r['issue_date'], r['target_date']
        assert float(r['current']) == raw_medians[c,t,d] and float(r['actual']) == raw_medians[c,t,target]
        for who, prefix in ((c,'supplier'), ('__market__','market')):
            history = sorted((day,p) for (company,term,day),p in raw_medians.items()
                             if company == who and term == t and day < d and checked_h[day,t] is not None)
            n = len(history)
            assert n == int(r[prefix+'_history_n'])
            weights = [0.75**(n-1)] + [0.25*0.75**(n-1-i) for i in range(1,n)]
            assert math.isclose(sum(weights),1,abs_tol=1e-12)
            premium = sum(w*(p-checked_h[day,t]) for w,(day,p) in zip(weights,history))
            assert math.isclose(premium,float(r[prefix+'_premium']),abs_tol=1e-10)
        for model in ('supplier','unchanged','pooled'):
            assert math.isclose(float(r[model+'_ae']),abs(float(r[model+'_forecast'])-float(r['actual'])),abs_tol=1e-12)
    aggregates = list(csv.DictReader((OUT / 'aggregate-results.csv').open()))
    for r in aggregates:
        rows = [p for p in pairs if r['term'] == 'all' or p['term'] == r['term']]
        assert int(r['n']) == len(rows)
        for model in ('supplier','unchanged','pooled'):
            grouped = defaultdict(list)
            for p in rows: grouped[p['company'],p['term']].append(float(p[model+'_ae']))
            values = [sum(v)/len(v) for v in grouped.values()] if r['weighting'] == 'equal_supplier_term_cell' else [float(p[model+'_ae']) for p in rows]
            assert math.isclose(sum(values)/len(values),float(r[model+'_mae']),abs_tol=1e-12)
    print(f'Independent checks passed: {len(daily)} daily indices, {len(curves)} curves, '
          f'{len(attempts)} eligibility decisions, {len(pairs)} matched pairs, {len(aggregates)} weighted aggregates.')


if __name__ == '__main__':
    if sys.argv[1:] == ['--check']:
        independent_check()
    elif not sys.argv[1:]:
        main()
    else:
        raise SystemExit('Usage: analysis.py [--check]')
