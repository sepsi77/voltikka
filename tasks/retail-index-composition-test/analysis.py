#!/usr/bin/env python3
"""Offline composition targets. Standard library only; no fitted models."""
import csv
import hashlib
import json
import math
from collections import Counter, defaultdict
from datetime import date, timedelta
from pathlib import Path
from statistics import mean, median

OUT = Path(__file__).resolve().parent
SOURCE = OUT.parent / 'supplier-premium-coverage/export-20260913T094138Z'
PIN = '6b7dd7093cafab8f91bb8417becba321747da4bc4334a19924374cc6be05efb6'
START, BOUNDARY, END = date(2026, 4, 8), date(2026, 7, 27), date(2026, 9, 13)
TERMS, HORIZONS = (6, 12, 24), (14, 30, 45, 60)
OBS, CAN = 'observed_seller_data', 'canonical_calculation'
TOL = .0001


def digest(blob):
    return hashlib.sha256(blob).hexdigest()


def js(value):
    return json.dumps(value, ensure_ascii=False, sort_keys=True, allow_nan=False)


def save_json(name, value):
    (OUT / name).write_text(json.dumps(value, ensure_ascii=False, indent=2, sort_keys=True, allow_nan=False) + '\n')


def save_csv(name, rows):
    with (OUT / name).open('w', newline='') as stream:
        writer = csv.DictWriter(stream, fieldnames=list(rows[0]))
        writer.writeheader()
        writer.writerows(rows)


def load_verified():
    blob = (SOURCE / 'manifest.json').read_bytes()
    assert digest(blob) == PIN == (SOURCE / 'manifest.sha256').read_text().split()[0]
    manifest = json.loads(blob)
    assert manifest['status'] == 'complete'
    assert manifest['method_pair'] == ['retail-premium-v2', 'retail-premium-history-v2']
    assert manifest['bounds']['from'] == str(START) and manifest['bounds']['to'] == str(END)
    assert manifest['project_id'] == '6d8cae01-1006-409f-8108-1d51f1abc676'
    assert manifest['environment_id'] == '9245cef8-41d0-486e-862f-193726511dba'
    assert manifest['service_id'] == 'beb2ba12-4a7b-416b-b4b1-596434dc3215'
    data = {}
    for name, query in manifest['queries'].items():
        raw = (SOURCE / query['file']).read_bytes()
        assert digest(raw) == query['sha256'] and len(raw) == query['bytes']
        data[name] = json.loads(raw)
        assert len(data[name]) == query['row_count'] == query['expected_count'] <= manifest['limits']['rows_per_query']
        for key in ('sql', 'count_sql'):
            assert digest(query[key].encode()) == query[key + '_sha256']
    assert sum(q['bytes'] for q in manifest['queries'].values()) < manifest['limits']['total_data_bytes']
    assert {k: len(v) for k, v in data.items()} == dict(schemas=145, version_metadata=89, futures=2239, statistics=477, snapshots=25620, premiums=1949, forecasts=1314)
    return data


def select_cohort(issue_supplier_medians):
    """Pure issue-only selection. Input must contain just one date/term/basis."""
    assert issue_supplier_medians and all(math.isfinite(v) for v in issue_supplier_medians.values())
    n = len(issue_supplier_medians)
    return {supplier: 1 / n for supplier in sorted(issue_supplier_medians)}


def fixed_value(weights, date_supplier_medians):
    """Never renormalize or replace a missing member. Return missing identities."""
    missing = sorted(set(weights) - set(date_supplier_medians))
    if missing:
        return None, missing
    assert math.isclose(sum(weights.values()), 1, abs_tol=1e-12)
    assert all(math.isfinite(date_supplier_medians[s]) for s in weights)
    return sum(weights[s] * date_supplier_medians[s] for s in weights), []


def sign(value):
    return 0 if abs(value) <= TOL else (1 if value > 0 else -1)


def distribution(values):
    values = sorted(values)
    if not values:
        return dict(n=0, mean=None, mean_absolute=None, minimum=None, p10=None, p25=None, median=None, p75=None, p90=None, maximum=None)
    def quantile(p):
        index = (len(values) - 1) * p
        low = int(index)
        return values[low] + (values[min(low + 1, len(values) - 1)] - values[low]) * (index - low)
    return dict(n=len(values), mean=mean(values), mean_absolute=mean(map(abs, values)), minimum=values[0], p10=quantile(.1), p25=quantile(.25), median=median(values), p75=quantile(.75), p90=quantile(.9), maximum=values[-1])


def main():
    data = load_verified()
    offers = defaultdict(list)
    excluded = Counter()
    ids = set()
    eligible = []
    for r in data['snapshots']:
        term = int(r['fixed_time_range'].replace('Fixed', ''))
        if term not in TERMS or r['pricing_model'] != 'FixedPrice' or r['segment_key'] != f'fixed_term_{term}':
            excluded['not_fixedprice_term_segment'] += 1
            continue
        if r['metering'] != 'General':
            excluded['not_general'] += 1
            continue
        price = float(r['energy_price_cents_per_kwh']) if r['energy_price_cents_per_kwh'] is not None else math.nan
        if r['includes_spot_price'] or not math.isfinite(price) or not .005 <= price <= 50:
            excluded['invalid_or_spot_price'] += 1
            continue
        day = date.fromisoformat(r['snapshot_date'])
        basis = CAN if day >= BOUNDARY else OBS
        assert r['pricing_basis'] == basis
        key = (basis, term, day, r['company_name'])
        identity = (day, r['contract_id'], basis)
        assert identity not in ids
        ids.add(identity)
        offers[key].append((r['contract_id'], price, r['id']))
        eligible.append(r)
    assert len(eligible) == 11608
    assert Counter(r['fixed_time_range'] for r in eligible) == {'Fixed6': 1989, 'Fixed12': 4858, 'Fixed24': 4761}
    suppliers, market_offers, daily_ids = defaultdict(dict), defaultdict(list), defaultdict(set)
    supplier_rows = []
    for (basis, term, day, supplier), rows in sorted(offers.items()):
        value = median(r[1] for r in rows)
        suppliers[basis, term, day][supplier] = value
        market_offers[basis, term, day].extend(r[1] for r in rows)
        daily_ids[basis, term, day].update(r[0] for r in rows)
        supplier_rows.append(dict(basis=basis, term=term, date=str(day), supplier=supplier, median=value, offers=len(rows), contract_ids=js(sorted(r[0] for r in rows)), snapshot_ids=js(sorted(r[2] for r in rows))))
    assert len(supplier_rows) == 9094
    save_csv('supplier-medians.csv', supplier_rows)
    indices, daily_rows = {}, []
    for key, values in sorted(suppliers.items()):
        basis, term, day = key
        indices[key] = dict(contract_median=median(market_offers[key]), supplier_mean=mean(values.values()))
        prior = (basis, term, day - timedelta(days=1))
        before = suppliers.get(prior)
        daily_rows.append(dict(basis=basis, term=term, date=str(day), **indices[key], suppliers=len(values), offers=len(market_offers[key]), supplier_ids=js(sorted(values)), adjacent_prior_available=int(before is not None), supplier_entries=js(sorted(set(values) - set(before))) if before else '', supplier_exits=js(sorted(set(before) - set(values))) if before else '', contract_entries=js(sorted(daily_ids[key] - daily_ids[prior])) if before else '', contract_exits=js(sorted(daily_ids[prior] - daily_ids[key])) if before else ''))
    assert len(daily_rows) == 477
    save_csv('daily-indices.csv', daily_rows)
    pairs, member_rows = [], []
    for (basis, term, issue), issue_values in sorted(suppliers.items()):
        weights = select_cohort(issue_values)
        for horizon in HORIZONS:
            target, lag = issue + timedelta(days=horizon), issue - timedelta(days=7)
            pair_id = f'{basis}:{term}:{issue}:{horizon}'
            target_values, lag_values = suppliers.get((basis, term, target), {}), suppliers.get((basis, term, lag), {})
            fixed_current, _ = fixed_value(weights, issue_values)
            fixed_target, missing_target = fixed_value(weights, target_values)
            fixed_lag, missing_lag = fixed_value(weights, lag_values)
            current_index = indices[basis, term, issue]
            target_index, lag_index = indices.get((basis, term, target)), indices.get((basis, term, lag))
            for supplier, weight in weights.items():
                member_rows.append(dict(pair_id=pair_id, supplier=supplier, weight=weight, weight_numerator=1, weight_denominator=len(weights), issue_date=str(issue), lag_date=str(lag), target_date=str(target), basis=basis, term=term, current=issue_values[supplier], lag7=lag_values.get(supplier), target=target_values.get(supplier), missing_lag7=int(supplier not in lag_values), missing_target=int(supplier not in target_values)))
            reason = 'target_after_export' if target > END else ('target_basis_seam' if basis == OBS and target >= BOUNDARY else ('missing_target_members' if missing_target else ''))
            lag_reason = 'lag_before_export' if lag < START else ('lag_basis_seam' if basis == CAN and lag < BOUNDARY else ('missing_lag_members' if missing_lag else ''))
            complete = fixed_target is not None
            row = dict(pair_id=pair_id, basis=basis, term=term, issue_date=str(issue), target_date=str(target), lag_date=str(lag), horizon=horizon, cohort_size=len(weights), cohort_weights=js(weights), target_in_basis=int(target_index is not None), diagnostic_available=int(complete), feature_available=int(complete and fixed_lag is not None), target_failure=reason, lag_failure=lag_reason, missing_target_members=js(missing_target), missing_lag_members=js(missing_lag), target_supplier_entries=js(sorted(set(target_values) - set(weights))) if target_index else '', target_supplier_exits=js(missing_target) if target_index else '', fixed_current=fixed_current, fixed_lag7=fixed_lag, fixed_target=fixed_target, fixed_delta7=fixed_current-fixed_lag if fixed_lag is not None else None)
            for name in ('contract_median', 'supplier_mean'):
                row[name + '_current'] = current_index[name]
                row[name + '_lag7'] = lag_index[name] if lag_index else None
                row[name + '_target'] = target_index[name] if target_index else None
                row[name + '_delta'] = target_index[name] - current_index[name] if complete else None
            row['fixed_delta'] = fixed_target - fixed_current if complete else None
            row['membership_component'] = row['supplier_mean_delta'] - row['fixed_delta'] if complete else None
            row['aggregation_difference'] = row['contract_median_delta'] - row['supplier_mean_delta'] if complete else None
            pairs.append(row)
    save_csv('pair-audit.csv', pairs)
    save_csv('cohort-members.csv', member_rows)
    save_csv('feature-target-rows.csv', [r for r in pairs if r['feature_available']])
    metrics = []
    for basis in (OBS, CAN):
        for term in (*TERMS, 'ALL'):
            for horizon in HORIZONS:
                for scope in ('diagnostic', 'feature'):
                    for size in ('all', 'one', 'two', 'at_least_three'):
                        candidates = [r for r in pairs if r['basis'] == basis and (term == 'ALL' or r['term'] == term) and r['horizon'] == horizon and (size == 'all' or (size == 'one' and r['cohort_size'] == 1) or (size == 'two' and r['cohort_size'] == 2) or (size == 'at_least_three' and r['cohort_size'] >= 3))]
                        inside = [r for r in candidates if r['target_in_basis']]
                        used = [r for r in candidates if r[scope + '_available']]
                        row = dict(basis=basis, term=term, horizon=horizon, scope=scope, size=size, candidate_issues=len(candidates), same_basis_endpoint_candidates=len(inside), available=len(used), unavailable_total=len(candidates)-len(used), unavailable_inside_basis=len(inside)-len(used), issue_days=len({r['issue_date'] for r in used}), missing_target_members_inside_basis=sum(bool(json.loads(r['missing_target_members'])) for r in inside), feature_missing_lag_after_target_pass=sum(r['diagnostic_available'] and not r['feature_available'] for r in candidates))
                        for field in ('contract_median_delta', 'supplier_mean_delta', 'fixed_delta', 'membership_component', 'aggregation_difference'):
                            row.update({field + '_' + k: v for k, v in distribution([r[field] for r in used]).items()})
                        for name in ('contract_median', 'supplier_mean'):
                            x = [(sign(r[name + '_delta']), sign(r['fixed_delta'])) for r in used]
                            row[name + '_sign_differences'] = sum(a != b for a, b in x)
                            row[name + '_sign_difference_share'] = sum(a != b for a, b in x) / len(x) if x else None
                            nonzero = [(a, b) for a, b in x if a and b]
                            row[name + '_both_nonzero'] = len(nonzero)
                            row[name + '_opposite_signs'] = sum(a != b for a, b in nonzero)
                            row[name + '_opposite_share'] = sum(a != b for a, b in nonzero) / len(nonzero) if nonzero else None
                        row['nonzero_membership_pairs'] = sum(abs(r['membership_component']) > TOL for r in used)
                        metrics.append(row)
    save_csv('metrics.csv', metrics)
    launch_rows, launch_summary = [], []
    for term in TERMS:
        weights = select_cohort(suppliers[CAN, term, BOUNDARY])
        for offset in range((END-BOUNDARY).days + 1):
            day = BOUNDARY + timedelta(days=offset)
            value, missing = fixed_value(weights, suppliers.get((CAN, term, day), {}))
            launch_rows.append(dict(term=term, basis=CAN, date=str(day), launch_date=str(BOUNDARY), cohort_weights=js(weights), cohort_size=len(weights), available=int(value is not None), value=value, missing_members=js(missing)))
        cell = [r for r in launch_rows if r['term'] == term]
        launch_summary.append(dict(term=term, cohort_size=len(weights), dates=len(cell), available=sum(r['available'] for r in cell), unavailable=sum(not r['available'] for r in cell)))
    save_csv('july27-index.csv', launch_rows)
    turnover = []
    for basis in (OBS, CAN):
        for term in TERMS:
            cell = [r for r in daily_rows if r['basis'] == basis and r['term'] == term and r['adjacent_prior_available']]
            row = dict(basis=basis, term=term, adjacent_days=len(cell))
            for field in ('supplier_entries', 'supplier_exits', 'contract_entries', 'contract_exits'):
                row[field + '_count'] = sum(len(json.loads(r[field])) for r in cell)
                row[field + '_days'] = sum(bool(json.loads(r[field])) for r in cell)
            turnover.append(row)
    save_csv('turnover-summary.csv', turnover)
    save_json('summary.json', dict(manifest_sha256=PIN, input_counts={k: len(v) for k, v in data.items()}, eligible_snapshot_days=len(eligible), exclusions=dict(excluded), supplier_dates=len(supplier_rows), market_dates=len(daily_rows), suppliers=len({r['company_name'] for r in eligible}), contract_ids=len({r['contract_id'] for r in eligible}), pairs=len(pairs), member_rows=len(member_rows), feature_rows=sum(r['feature_available'] for r in pairs), cohort_size_distribution=dict(sorted(Counter(r['suppliers'] for r in daily_rows).items())), july27=launch_summary))
    print(js(dict(status='PASS', eligible=11608, pairs=len(pairs), feature_rows=sum(r['feature_available'] for r in pairs), july27=launch_summary)))


if __name__ == '__main__':
    main()
