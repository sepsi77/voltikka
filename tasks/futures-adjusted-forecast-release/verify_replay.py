#!/usr/bin/env python3
"""No network, no imported research helpers, no writes. Compare real PHP with raw-array formula."""
import calendar
from collections import Counter
from datetime import date, timedelta
from decimal import Decimal, ROUND_HALF_UP
import hashlib
import json
import math
import os
from pathlib import Path
import statistics
import subprocess
import sys

HERE = Path(__file__).resolve().parent
ROOT = HERE.parents[1]
EXPORT = ROOT / 'tasks/supplier-premium-coverage/export-20260913T094138Z'
RESEARCH = ROOT / 'tasks/forecast-futures-increment-test'

def load(p): return json.loads(p.read_text())
def digest(p): return hashlib.sha256(p.read_bytes()).hexdigest()
def rounded(x): return float(Decimal(str(x)).quantize(Decimal('.0001'), rounding=ROUND_HALF_UP))
def near(a, b): assert abs(a-b) < 1e-9, (a, b)
def number(v): return None if v is None or not math.isfinite(float(v)) else float(v)

# Preserve every frozen research and export artifact, not just the inputs used here.
pins = {str(p.relative_to(ROOT)): digest(p) for folder in (EXPORT, RESEARCH) for p in folder.rglob('*') if p.is_file()}
args = ['php', '-d', 'memory_limit=512M', '-d', 'error_reporting=24575', '-d', 'display_errors=stderr',
        '-d', 'allow_url_fopen=0', '-d', 'disable_functions=curl_exec,curl_multi_exec,fsockopen,pfsockopen,stream_socket_client', str(HERE/'replay.php')]
env = {k: os.environ[k] for k in ('PATH', 'HOME', 'LANG') if k in os.environ}
runs = []
for _ in range(2):
    process = subprocess.run(args, cwd=ROOT, env=env, capture_output=True, text=True, check=True)
    sys.stderr.write(process.stderr)
    runs.append(json.loads(process.stdout))
assert runs[0] == runs[1], 'Replay output differs between independent memory databases'
result = runs[0]
assert result['proof']['before_schema_and_load'] and result['proof']['network_disabled']

stats = load(EXPORT/'statistics.json')
owned = {}
for r in stats:
    if r['method_version'] != 'unit_statistics_v1' or r['metric_key'] != 'energy_price' or r['consumption_kwh'] is not None:
        continue
    t = int(r['segment_key'].split('_')[-1])
    key = (t, r['pricing_basis'], date.fromisoformat(r['stat_date'][:10]))
    if key not in owned or r['id'] > owned[key]['id']:
        owned[key] = r
curves = {}
for r in sorted(load(EXPORT/'futures.json'), key=lambda r: r['id']):
    if r['area'] == 'FI' and r['product'] == 'Base':
        curves.setdefault(date.fromisoformat(r['trade_date'][:10]), {})[(r['maturity_type'], r['maturity'])] = number(r['settlement_price'])
trades = sorted(curves)

def next_month(d):
    return date(d.year + (d.month == 12), d.month % 12 + 1, 1)

def basket(endpoint, start, term):
    vintage = max((d for d in trades if d < endpoint), default=None)
    if vintage is None: return None
    curve = curves[vintage]
    weighted, days = 0, 0
    d = start
    for _ in range(term):
        keys = [('month', d.strftime('%Y%m')), ('quarter', f'{d.year}{((d.month-1)//3)*3+1:02}'), ('year', f'{d.year}01')]
        value = next((curve[k] for k in keys if k in curve), None)
        if value is None: return None
        weight = calendar.monthrange(d.year, d.month)[1]
        weighted += value * weight
        days += weight
        d = next_month(d)
    return weighted/days/10*1.255

features = {}
def feature(t, d):
    key = t, d
    if key not in features:
        c = basket(d, next_month(d), t)
        lag = basket(d-timedelta(days=7), next_month(d), t)
        features[key] = None if c is None or lag is None else c-lag
    return features[key]

def independent(row):
    t, q, h = row['duration_months'], row['target_quantile'], row['horizon_days']
    issue = date.fromisoformat(row['forecast_date'])
    boundary = min((d for term, b, d in owned if term == t and b == 'canonical_calculation' and d <= issue), default=date.max)
    use_canonical = row['source_metadata']['current_retail_pricing_basis'] == 'canonical_calculation'
    def basis(d): return 'canonical_calculation' if use_canonical and d >= boundary else 'observed_seller_data'
    def price(d): return number(owned.get((t, basis(d), d), {}).get(q+'_value'))
    pairs = []
    for d in sorted({d for term, b, d in owned if term == t and d < issue}):
        target = d + timedelta(days=h)
        if target >= issue or basis(d) != basis(target): continue
        c, a = price(d), price(target)
        if c is not None and a is not None:
            pairs.append((d, a-c, feature(t, d)))
    full = [p[1] for p in pairs]
    local = [p for p in pairs if p[2] is not None]
    assert len(full) >= 20 and len(local) >= 20
    mean = statistics.mean(full)
    xm = statistics.mean(p[2] for p in local)
    ym = statistics.mean(p[1] for p in local)
    std = statistics.pstdev(p[2] for p in local)
    z = [(p[2]-xm)/std if std else 0 for p in local]
    slope = statistics.mean(v*(p[1]-ym) for v, p in zip(z, local))/(statistics.mean(v*v for v in z)+1)
    contribution = slope*((feature(t, issue)-xm)/std) if std else 0
    m = row['source_metadata']
    assert len(full) == m['pair_count'] and len(local) == m['feature_pair_count']
    assert dict(Counter(basis(p[0]) for p in pairs)) == m['historical_retail_pricing_basis_counts']
    assert dict(Counter(basis(p[0]) for p in local)) == m['feature_pricing_basis_counts']
    for key, value in [('mean_change_cents_per_kwh', mean), ('feature_mean', xm), ('feature_population_std', std),
                       ('feature_delta_mean', ym), ('feature_standardized_slope', slope), ('futures_contribution_cents_per_kwh', contribution)]:
        near(m[key], value)
    for prefix, pool in [('', pairs), ('feature_', local)]:
        assert m[prefix+'pair_start_min'] == str(pool[0][0])
        assert m[prefix+'pair_start_max'] == str(pool[-1][0])
        assert m[prefix+'pair_target_min'] == str(pool[0][0]+timedelta(days=h))
        assert m[prefix+'pair_target_max'] == str(pool[-1][0]+timedelta(days=h))
    near(row['expected_change_cents_per_kwh'], rounded(mean+contribution))
    near(row['forecast_price_cents_per_kwh'], rounded(price(issue)+rounded(mean+contribution)))
    return {'term': t, 'quantile': q, 'full_n': len(full), 'feature_n': len(local), 'mean': mean, 'feature_mean': xm,
            'feature_std': std, 'slope': slope, 'contribution': contribution,
            'change': row['expected_change_cents_per_kwh'], 'forecast': row['forecast_price_cents_per_kwh']}

frozen = {p['pair_id']: p for p in load(RESEARCH/'predictions.json') if p['cohort']=='fixed' and p['horizon']==30}
assert {p['pair_id'] for p in result['matched']} == set(frozen)
for p in result['matched']:
    independent(p['row'])
    expected = frozen[p['pair_id']]['models']['production_futures']
    near(p['row']['expected_change_cents_per_kwh'], expected['saved_delta'])
    near(p['row']['forecast_price_cents_per_kwh'], expected['forecast'])
latest = [independent(row) for row in result['latest']]
assert len(latest) == 9
for name, sha in pins.items(): assert digest(ROOT/name) == sha, name
print(json.dumps({'status': 'passed', 'runs': 2, 'proof': result['proof'], 'primary_median_predictions': len(frozen),
                  'primary_counts_by_term': dict(Counter(p['term'] for p in frozen.values())),
                  'primary_issue_days': len({p['issue'] for p in frozen.values()}),
                  'evaluated_new_rows': result['evaluated'], 'old_rows_preserved': result['original_forecast_count'],
                  'latest_nine_independent_formula': latest, 'frozen_artifact_hashes': pins,
                  'limits': ['Export starts April 8; production has January 21 onward history, so production values differ.',
                             'Quantile formula verification is not measured quantile prediction accuracy.',
                             'Frozen median research is not an untouched future holdout.']}, indent=2, sort_keys=True))
