#!/usr/bin/env python3
"""Independent arithmetic check from raw daily evidence and output CSVs."""
import collections as C
import csv
import datetime as dt
from decimal import Decimal as D
import json
from pathlib import Path
import statistics as st

root=Path(__file__).resolve().parent
source=root.parent/'supplier-premium-coverage/export-20260913T094138Z'
def rows(name): return list(csv.DictReader((root/name).open()))
def yes(r,k): return r[k]=='True'
def diff(a,b): return abs(a-b)>D('0.0001')
raw=json.loads((source/'snapshots.json').read_text())
accepted=[r for r in raw if r['pricing_model']=='FixedPrice' and r['segment_key']=='fixed_term_'+r['fixed_time_range'][5:] and r['metering']=='General' and r['energy_price_cents_per_kwh'] is not None and D(r['energy_price_cents_per_kwh']).is_finite() and not r['includes_spot_price']]
assert len(accepted)==11608
lookup={r['id']:r for r in accepted}
panels={p:C.defaultdict(dict) for p in ('same_id','same_id_no_discount','supplier_median','dated_lineage_sensitivity')}
median_input=C.defaultdict(list)
for r in accepted:
    k=(r['company_name'],r['fixed_time_range'][5:],r['pricing_basis'],r['contract_id']); date=dt.date.fromisoformat(r['snapshot_date'])
    panels['same_id'][k][date]=D(r['energy_price_cents_per_kwh'])
    if not r['has_discount']: panels['same_id_no_discount'][k][date]=D(r['energy_price_cents_per_kwh'])
    median_input[k[:3],date].append(D(r['energy_price_cents_per_kwh']))
for (k,date),v in median_input.items(): panels['supplier_median'][k+('offered_median',)][date]=st.median(v)
# Verify every accepted mapping against raw premium period, carrier and numeric data.
premiums={r['id']:r for r in json.loads((source/'premiums.json').read_text())}
for m in rows('dated-mapping.csv'):
    if m['status']!='mapped': continue
    r=lookup[int(m['snapshot_id'])]
    assert m['contract_id']==r['contract_id'] and m['date']==r['snapshot_date']
    for pid in m['premium_ids'].split(';'):
        p=premiums[int(pid)]; md=json.loads(p['source_metadata'])
        assert p['lineage_key']==m['lineage']
        assert r['contract_id'] in md.get('period_carrier_ids',[p['contract_id']])
        assert p['first_observed_date']<=m['date']<=p['last_observed_date']
        assert not diff(D(p['retail_energy_price_cents_per_kwh']),D(r['energy_price_cents_per_kwh']))
    k=(m['supplier'],m['term'],m['basis'],m['lineage']); date=dt.date.fromisoformat(m['date'])
    if date in panels['dated_lineage_sensitivity'][k]: assert panels['dated_lineage_sensitivity'][k][date]==D(r['energy_price_cents_per_kwh'])
    panels['dated_lineage_sensitivity'][k][date]=D(r['energy_price_cents_per_kwh'])
assert sum(len(v) for v in panels['dated_lineage_sensitivity'].values())==4648
for r in rows('windows-by-series.csv'):
    k=(r['supplier'],r['term'],r['basis'],r['series']); dates=panels[r['panel']][k]; h=int(r['horizon_days'])
    pairs=eq=full=constant=returns=0
    for start,p in dates.items():
        end=start+dt.timedelta(days=h)
        if end not in dates: continue
        pairs+=1; same=not diff(p,dates[end]); eq+=same
        segment=[dates.get(start+dt.timedelta(days=i)) for i in range(h+1)]
        if None in segment: continue
        full+=1; change=any(diff(a,b) for a,b in zip(segment,segment[1:])); constant+=not change; returns+=same and change
    assert [pairs,eq,full,constant,returns]==[int(r[f]) for f in ('exact_pairs','unchanged_endpoints','complete_daily_windows','no_observed_change_complete','returned_to_start_complete')]
spells=rows('constant-spells.csv')
for panel,series in panels.items():
    subset=[r for r in spells if r['panel']==panel]
    assert sum(int(r['observed_days']) for r in subset)==sum(len(v) for v in series.values())
    for s in subset:
        k=(s['supplier'],s['term'],s['basis'],s['series']); dates=series[k]
        start=dt.date.fromisoformat(s['start']); last=dt.date.fromisoformat(s['last'])
        seq=[dates[start+dt.timedelta(days=i)] for i in range((last-start).days+1)]
        assert not any(diff(a,b) for a,b in zip(seq,seq[1:]))
        if not yes(s,'left_censored'): assert diff(dates[start-dt.timedelta(days=1)],dates[start])
        if not yes(s,'right_censored'): assert diff(dates[last],dates[last+dt.timedelta(days=1)])
        if s['completed_change_to_change_days']:
            assert not yes(s,'left_censored') and not yes(s,'right_censored')
            assert int(s['completed_change_to_change_days'])==(last-start).days+1
for s in rows('horizon-summary.csv'):
    assert int(s['unchanged_endpoints'])+int(s['changed_endpoints'])==int(s['exact_pairs'])
    assert int(s['no_observed_change_complete'])+int(s['observed_change_complete'])==int(s['complete_daily_windows'])
print('PASS: raw daily panels, dated numeric carrier mapping, every exact window, spell coverage, censor boundaries and aggregate denominators.')
