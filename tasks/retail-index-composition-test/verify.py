#!/usr/bin/env python3
"""Independent raw Decimal rebuild and saved-output checks. No output elsewhere."""
import csv
import hashlib
import json
import math
from collections import Counter, defaultdict
from datetime import date, timedelta
from decimal import Decimal as D
from pathlib import Path

OUT = Path(__file__).resolve().parent
SOURCE = OUT.parent / 'supplier-premium-coverage/export-20260913T094138Z'
CAN, OBS = 'canonical_calculation', 'observed_seller_data'
BOUNDARY = '2026-07-27'


def read(name, root=OUT):
    return list(csv.DictReader((root / name).open()))


def close(actual, expected):
    if expected is None:
        assert actual == '', actual
    else:
        assert actual != '' and math.isclose(float(actual), float(expected), abs_tol=1e-10), (actual, expected)


def middle(values):
    x = sorted(values)
    return (x[(len(x)-1)//2] + x[len(x)//2]) / 2


def avg(values):
    return sum(values) / D(len(values))


def direction(value):
    return 0 if abs(value) <= D('.0001') else (1 if value > 0 else -1)


def main():
    manifest_blob = (SOURCE / 'manifest.json').read_bytes()
    assert hashlib.sha256(manifest_blob).hexdigest() == '6b7dd7093cafab8f91bb8417becba321747da4bc4334a19924374cc6be05efb6'
    manifest = json.loads(manifest_blob)
    for q in manifest['queries'].values():
        b = (SOURCE / q['file']).read_bytes()
        assert len(b) == q['bytes'] and hashlib.sha256(b).hexdigest() == q['sha256']
        assert len(json.loads(b)) == q['row_count'] == q['expected_count']
        for key in ('sql', 'count_sql'):
            assert hashlib.sha256(q[key].encode()).hexdigest() == q[key + '_sha256']
    raw = json.loads((SOURCE / 'snapshots.json').read_text())
    groups, market = defaultdict(list), defaultdict(list)
    contract_sets, snapshot_sets = defaultdict(set), defaultdict(set)
    id_series = defaultdict(dict)
    for r in raw:
        if r['fixed_time_range'] not in ('Fixed6', 'Fixed12', 'Fixed24'):
            continue
        term = int(r['fixed_time_range'][5:])
        if r['pricing_model'] != 'FixedPrice' or r['segment_key'] != f'fixed_term_{term}' or r['metering'] != 'General' or r['includes_spot_price']:
            continue
        p = D(r['energy_price_cents_per_kwh']) if r['energy_price_cents_per_kwh'] is not None else D('NaN')
        if not p.is_finite() or not D('.005') <= p <= D(50):
            continue
        basis, day, supplier = r['pricing_basis'], r['snapshot_date'], r['company_name']
        assert basis == (CAN if day >= BOUNDARY else OBS)
        key = basis, term, day, supplier
        assert r['contract_id'] not in contract_sets[key]
        groups[key].append(p)
        contract_sets[key].add(r['contract_id'])
        snapshot_sets[key].add(r['id'])
        market[basis, term, day].append(p)
        id_series[basis, term, supplier, r['contract_id']][day] = p
    assert sum(map(len, groups.values())) == 11608 and len(groups) == 9094
    medians = {k: middle(v) for k, v in groups.items()}
    daily = defaultdict(dict)
    for (b, t, d, s), value in medians.items():
        daily[b, t, d][s] = value
    for r in read('supplier-medians.csv'):
        k = r['basis'], int(r['term']), r['date'], r['supplier']
        close(r['median'], medians[k])
        assert json.loads(r['contract_ids']) == sorted(contract_sets[k])
        assert json.loads(r['snapshot_ids']) == sorted(snapshot_sets[k])
        assert int(r['offers']) == len(groups[k])
    # Match earlier saved panels, without running or changing their scripts.
    prior = OUT.parent / 'fixed-term-repricing-frequency'
    old_hashes = json.loads((prior / 'artifact-manifest.json').read_text())
    old_hashes = {k: v for k, v in old_hashes.items() if k not in ('spec.md', 'decisions.md', 'tasks.json', 'report.md')}
    for name, record in old_hashes.items():
        blob = (prior / name).read_bytes()
        assert len(blob) == record['bytes'] and hashlib.sha256(blob).hexdigest() == record['sha256'], name
    for r in read('supplier-daily-medians.csv', prior):
        k = r['basis'], int(r['term']), r['date'], r['supplier']
        close(r['median'], medians[k])
        assert set(r['ids'].split(';')) == contract_sets[k]
    pilot = read('daily-indices.csv', OUT.parent / 'supplier-forecast-pilot')
    for r in pilot:
        k = r['basis'], int(r['term']), r['date']
        if r['company'] == '__market__':
            close(r['median'], middle(market[k]))
        else:
            close(r['median'], medians[(*k, r['company'])])
    adjacent, changes, changed_sets, disjoint = 0, 0, 0, 0
    for (b, t, d, s), value in medians.items():
        previous = str(date.fromisoformat(d) - timedelta(days=1))
        pk = b, t, previous, s
        if pk in medians:
            adjacent += 1
            if abs(value-medians[pk]) > D('.0001'):
                changes += 1
                changed_sets += contract_sets[b, t, d, s] != contract_sets[pk]
                disjoint += not bool(contract_sets[b, t, d, s] & contract_sets[pk])
    assert (adjacent, changes, changed_sets, disjoint) == (8913, 473, 472, 430)
    same_id_changes = sum(abs(v-prev[str(date.fromisoformat(day)-timedelta(days=1))]) > D('.0001') for prev in id_series.values() for day, v in prev.items() if str(date.fromisoformat(day)-timedelta(days=1)) in prev)
    assert same_id_changes == 1
    supplier_30 = sum((b, t, str(date.fromisoformat(d)+timedelta(days=30)), s) in medians for b, t, d, s in medians if b == CAN)
    assert supplier_30 == 891
    for r in read('daily-indices.csv'):
        k = r['basis'], int(r['term']), r['date']
        close(r['contract_median'], middle(market[k]))
        close(r['supplier_mean'], avg(daily[k].values()))
        assert json.loads(r['supplier_ids']) == sorted(daily[k])
        previous = (k[0], k[1], str(date.fromisoformat(k[2])-timedelta(days=1)))
        assert int(r['adjacent_prior_available']) == int(previous in daily)
        if previous in daily:
            assert json.loads(r['supplier_entries']) == sorted(set(daily[k])-set(daily[previous]))
            assert json.loads(r['supplier_exits']) == sorted(set(daily[previous])-set(daily[k]))
            current_ids = set().union(*(contract_sets[(*k, s)] for s in daily[k]))
            prior_ids = set().union(*(contract_sets[(*previous, s)] for s in daily[previous]))
            assert json.loads(r['contract_entries']) == sorted(current_ids-prior_ids)
            assert json.loads(r['contract_exits']) == sorted(prior_ids-current_ids)
    pairs = read('pair-audit.csv')
    assert len(pairs) == 477 * 4
    assert len({r['pair_id'] for r in pairs}) == len(pairs)
    members = defaultdict(list)
    for r in read('cohort-members.csv'):
        members[r['pair_id']].append(r)
    for r in pairs:
        b, t, issue = r['basis'], int(r['term']), date.fromisoformat(r['issue_date'])
        horizon = int(r['horizon'])
        assert horizon in (14, 30, 45, 60)
        assert r['target_date'] == str(issue+timedelta(days=horizon))
        assert r['lag_date'] == str(issue-timedelta(days=7))
        current = daily[b, t, str(issue)]
        # Expected membership comes ONLY from the issue date, before outcome access.
        names = sorted(current)
        weights = json.loads(r['cohort_weights'])
        assert sorted(weights) == names and int(r['cohort_size']) == len(names)
        assert all(math.isclose(w, 1/len(names), abs_tol=1e-14) for w in weights.values())
        assert math.isclose(sum(weights.values()), 1, abs_tol=1e-12)
        values = {}
        for label, field in [('current', 'issue_date'), ('lag7', 'lag_date'), ('target', 'target_date')]:
            day = r[field]
            v = daily.get((b, t, day), {})
            missing = sorted(set(names)-set(v))
            value = None if missing else avg([v[s] for s in names])
            values[label] = value
            close(r['fixed_' + label], value)
            if label != 'current':
                assert json.loads(r['missing_' + ('lag' if label == 'lag7' else label) + '_members']) == missing
        target_present = (b, t, r['target_date']) in daily
        assert int(r['target_in_basis']) == int(target_present)
        target_missing = json.loads(r['missing_target_members'])
        lag_missing = json.loads(r['missing_lag_members'])
        target_reason = 'target_after_export' if r['target_date'] > '2026-09-13' else ('target_basis_seam' if b == OBS and r['target_date'] >= BOUNDARY else ('missing_target_members' if target_missing else ''))
        lag_reason = 'lag_before_export' if r['lag_date'] < '2026-04-08' else ('lag_basis_seam' if b == CAN and r['lag_date'] < BOUNDARY else ('missing_lag_members' if lag_missing else ''))
        assert r['target_failure'] == target_reason and r['lag_failure'] == lag_reason
        if target_present:
            assert json.loads(r['target_supplier_entries']) == sorted(set(daily[b, t, r['target_date']])-set(names))
            assert json.loads(r['target_supplier_exits']) == target_missing
        else:
            assert r['target_supplier_entries'] == r['target_supplier_exits'] == ''
        complete = values['target'] is not None
        feature = complete and values['lag7'] is not None
        assert int(r['diagnostic_available']) == int(complete)
        assert int(r['feature_available']) == int(feature)
        close(r['fixed_delta7'], values['current']-values['lag7'] if values['lag7'] is not None else None)
        close(r['fixed_delta'], values['target']-values['current'] if complete else None)
        assert sorted(m['supplier'] for m in members[r['pair_id']]) == names
        for m in members[r['pair_id']]:
            assert m['basis'] == b and int(m['term']) == t and m['issue_date'] == r['issue_date'] and m['lag_date'] == r['lag_date'] and m['target_date'] == r['target_date']
            assert int(m['weight_numerator']) == 1 and int(m['weight_denominator']) == len(names)
            close(m['weight'], D(1)/len(names))
            for label, field in [('current', 'issue_date'), ('lag7', 'lag_date'), ('target', 'target_date')]:
                expected = daily.get((b, t, r[field]), {}).get(m['supplier'])
                close(m[label], expected)
                if label != 'current':
                    assert int(m['missing_' + label]) == int(expected is None)
        for name, function in [('contract_median', lambda k: middle(market[k])), ('supplier_mean', lambda k: avg(daily[k].values()))]:
            levels = {}
            for label, field in [('current', 'issue_date'), ('lag7', 'lag_date'), ('target', 'target_date')]:
                key = b, t, r[field]
                levels[label] = function(key) if key in daily else None
                close(r[name + '_' + label], levels[label])
            close(r[name + '_delta'], levels['target']-levels['current'] if complete else None)
        if complete:
            close(r['membership_component'], D(r['supplier_mean_delta'])-D(r['fixed_delta']))
            close(r['aggregation_difference'], D(r['contract_median_delta'])-D(r['supplier_mean_delta']))
        else:
            assert r['membership_component'] == r['aggregation_difference'] == ''
    assert read('feature-target-rows.csv') == [r for r in pairs if r['feature_available'] == '1']
    for r in read('metrics.csv'):
        candidates = [p for p in pairs if p['basis'] == r['basis'] and (r['term'] == 'ALL' or p['term'] == r['term']) and p['horizon'] == r['horizon'] and (r['size'] == 'all' or (r['size'] == 'one' and int(p['cohort_size']) == 1) or (r['size'] == 'two' and int(p['cohort_size']) == 2) or (r['size'] == 'at_least_three' and int(p['cohort_size']) >= 3))]
        used = [p for p in candidates if p[r['scope'] + '_available'] == '1']
        inside = [p for p in candidates if p['target_in_basis'] == '1']
        for field, value in dict(candidate_issues=len(candidates), same_basis_endpoint_candidates=len(inside), available=len(used), unavailable_total=len(candidates)-len(used), unavailable_inside_basis=len(inside)-len(used), issue_days=len({p['issue_date'] for p in used}), missing_target_members_inside_basis=sum(bool(json.loads(p['missing_target_members'])) for p in inside), feature_missing_lag_after_target_pass=sum(p['diagnostic_available']=='1' and p['feature_available']=='0' for p in candidates)).items():
            assert int(r[field]) == value, (field, r, value)
        for field in ('contract_median_delta', 'supplier_mean_delta', 'fixed_delta', 'membership_component', 'aggregation_difference'):
            x = sorted(D(p[field]) for p in used)
            assert int(r[field + '_n']) == len(x)
            expected = dict(mean=avg(x), mean_absolute=avg(list(map(abs, x))), minimum=x[0], maximum=x[-1]) if x else {}
            for name, q in [('p10', '.1'), ('p25', '.25'), ('median', '.5'), ('p75', '.75'), ('p90', '.9')]:
                if x:
                    pos = D(len(x)-1)*D(q)
                    a = int(pos)
                    expected[name] = x[a]+(x[min(a+1,len(x)-1)]-x[a])*(pos-a)
            for name in ('mean', 'mean_absolute', 'minimum', 'maximum', 'p10', 'p25', 'median', 'p75', 'p90'):
                close(r[field+'_'+name], expected.get(name))
        for name in ('contract_median', 'supplier_mean'):
            signs = [(direction(D(p[name+'_delta'])), direction(D(p['fixed_delta']))) for p in used]
            both = [(a,b) for a,b in signs if a and b]
            different, opposite = sum(a != b for a,b in signs), sum(a != b for a,b in both)
            assert int(r[name+'_sign_differences']) == different
            assert int(r[name+'_both_nonzero']) == len(both)
            assert int(r[name+'_opposite_signs']) == opposite
            close(r[name+'_sign_difference_share'], D(different)/len(signs) if signs else None)
            close(r[name+'_opposite_share'], D(opposite)/len(both) if both else None)
        assert int(r['nonzero_membership_pairs']) == sum(abs(D(p['membership_component'])) > D('.0001') for p in used)
    for r in read('turnover-summary.csv'):
        cell = [x for x in read('daily-indices.csv') if x['basis'] == r['basis'] and x['term'] == r['term'] and x['adjacent_prior_available'] == '1']
        assert int(r['adjacent_days']) == len(cell)
        for field in ('supplier_entries', 'supplier_exits', 'contract_entries', 'contract_exits'):
            assert int(r[field+'_count']) == sum(len(json.loads(x[field])) for x in cell)
            assert int(r[field+'_days']) == sum(bool(json.loads(x[field])) for x in cell)
    for r in read('july27-index.csv'):
        t = int(r['term'])
        names = sorted(daily[CAN, t, BOUNDARY])
        launch_weights = json.loads(r['cohort_weights'])
        assert sorted(launch_weights) == names and int(r['cohort_size']) == len(names)
        assert all(math.isclose(w, 1/len(names), abs_tol=1e-14) for w in launch_weights.values())
        assert math.isclose(sum(launch_weights.values()), 1, abs_tol=1e-12)
        assert r['basis'] == CAN and r['launch_date'] == BOUNDARY
        available = daily.get((CAN, t, r['date']), {})
        missing = sorted(set(names)-set(available))
        assert json.loads(r['missing_members']) == missing
        assert int(r['available']) == int(not missing)
        close(r['value'], None if missing else avg([available[s] for s in names]))
    # Pure function edge checks: new future member cannot change issue selection;
    # a missing old member rejects even when many new suppliers exist.
    from analysis import select_cohort, fixed_value
    weights = select_cohort({'a': 0.0, 'b': 2.0})
    assert weights == {'a': .5, 'b': .5}
    assert fixed_value(weights, {'a': 4.0, 'b': 6.0, 'future': 100.0}) == (5.0, [])
    assert fixed_value(weights, {'a': 4.0, 'future': 100.0}) == (None, ['b'])
    assert fixed_value(select_cohort({'one': 0.0}), {'one': 0.0}) == (0.0, [])
    checks = dict(status='PASS', eligible=11608, supplier_medians=9094, prior_pilot_rows=len(pilot), prior_cadence_artifacts=len(old_hashes), adjacent_supplier_pairs=adjacent, adjacent_supplier_changes=changes, same_id_changes=same_id_changes, canonical_supplier_30_pairs=supplier_30, pair_checks=len(pairs), member_checks=sum(map(len, members.values())), metric_checks=len(read('metrics.csv')), july27_checks=147, synthetic_checks=4)
    (OUT/'independent-checks.json').write_text(json.dumps(checks, indent=2, sort_keys=True)+'\n')
    print(json.dumps(checks, sort_keys=True))


if __name__ == '__main__':
    main()
