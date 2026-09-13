#!/usr/bin/env python3
"""Read-only evidence analysis. Python standard library. Writes only beside this script."""
import collections as C
import csv
import datetime as dt
from decimal import Decimal
import hashlib
import json
from pathlib import Path
import statistics as st
import sys

ROOT = Path(__file__).resolve().parent
SOURCE = Path(sys.argv[1]).resolve() if len(sys.argv) > 1 else ROOT.parent / 'supplier-premium-coverage/export-20260913T094138Z'
TOL = Decimal('0.0001')
HORIZONS = (7, 14, 30, 45, 60, 90)
START, END = dt.date(2026, 4, 8), dt.date(2026, 9, 13)

def load_verified():
    raw = (SOURCE / 'manifest.json').read_bytes()
    assert hashlib.sha256(raw).hexdigest() == (SOURCE / 'manifest.sha256').read_text().split()[0]
    m = json.loads(raw)
    assert m['status'] == 'complete'
    assert m['method_pair'] == ['retail-premium-v2', 'retail-premium-history-v2']
    assert m['bounds']['from'] == str(START) and m['bounds']['to'] == str(END)
    assert m['project_id'] == '6d8cae01-1006-409f-8108-1d51f1abc676'
    assert m['environment_id'] == '9245cef8-41d0-486e-862f-193726511dba'
    assert m['service_id'] == 'beb2ba12-4a7b-416b-b4b1-596434dc3215'
    out = {}
    for name, q in m['queries'].items():
        raw = (SOURCE / q['file']).read_bytes()
        assert hashlib.sha256(raw).hexdigest() == q['sha256']
        assert len(raw) == q['bytes']
        out[name] = json.loads(raw)
        assert len(out[name]) == q['row_count'] == q['expected_count'] <= m['limits']['rows_per_query']
        for k in ('sql', 'count_sql'):
            assert hashlib.sha256(q[k].encode()).hexdigest() == q[k + '_sha256']
    assert sum(q['bytes'] for q in m['queries'].values()) < m['limits']['total_data_bytes']
    assert {k: len(out[k]) for k in ('snapshots','premiums','futures','statistics','forecasts')} == dict(snapshots=25620,premiums=1949,futures=2239,statistics=477,forecasts=1314)
    return m, out

manifest, data = load_verified()

def num(v):
    if v is None: return None
    n = Decimal(str(v))
    return n if n.is_finite() else None

def changed(a, b): return a is not None and b is not None and abs(a-b) > TOL

def percentile(v, p):
    if not v: return None
    v = sorted(v); pos = (len(v)-1)*p; lo = int(pos)
    return float(v[lo] + (v[min(lo+1,len(v)-1)]-v[lo])*(pos-lo))

def distribution(v):
    return dict(n=len(v), p25=percentile(v,.25), median=percentile(v,.5), p75=percentile(v,.75), p90=percentile(v,.9), maximum=max(v) if v else None)

def csvout(name, rows):
    rows = list(rows)
    if not rows: return
    keys = list(dict.fromkeys(k for r in rows for k in r))
    with (ROOT / name).open('w', newline='') as f:
        w = csv.DictWriter(f, fieldnames=keys); w.writeheader(); w.writerows(rows)

def rate(a,b): return a/b if b else None

def base(k): return dict(supplier=k[0],term=k[1],basis=k[2],series=k[3])

eligible = []
exclusions = C.Counter()
for r in data['snapshots']:
    term = int(r['fixed_time_range'].replace('Fixed',''))
    if r['pricing_model'] != 'FixedPrice' or r['segment_key'] != f'fixed_term_{term}':
        exclusions['not_fixedprice_term_segment'] += 1; continue
    if r['metering'] != 'General':
        exclusions['Time_Season_separate_not_complete_components'] += 1; continue
    if r['includes_spot_price'] or num(r['energy_price_cents_per_kwh']) is None:
        exclusions['spot_or_nonfinite_energy'] += 1; continue
    eligible.append(dict(r, term=term, date=dt.date.fromisoformat(r['snapshot_date']), price=num(r['energy_price_cents_per_kwh']), fee=num(r['monthly_fee_eur'])))
assert len(eligible)+sum(exclusions.values()) == len(data['snapshots'])
# The prior pilot's producer-clean range removes no further eligible observation.
assert all(Decimal('0.005') <= r['price'] <= Decimal('50') for r in eligible)

panels = {}
for panel in ('same_id','same_id_no_discount'):
    series = C.defaultdict(dict)
    for r in eligible:
        if panel.endswith('no_discount') and r['has_discount']: continue
        k = (r['company_name'], r['term'],r['pricing_basis'],r['contract_id'])
        assert r['date'] not in series[k]
        series[k][r['date']] = dict(price=r['price'], fee=r['fee'], discount=bool(r['has_discount']), carriers={r['contract_id']}, row_ids={r['id']})
    panels[panel] = series

# Dated period carrier evidence only. Never map all current DAG IDs onto the past.
# Lineage keys still use the stored replacement graph (not contemporaneous graph proof).
map_candidates = C.defaultdict(list)
premium_audit = []
for r in data['premiums']:
    md = json.loads(r['source_metadata'] or '{}'); flags = json.loads(r['quality_flags'] or '[]')
    assert r['method_version'] in manifest['method_pair'] and r['reference_kind'] == 'term_strip'
    term = md.get('duration_months')
    reasons = []
    if term not in (6,12,24): reasons.append('term')
    if r['metering'] != 'General' or r['energy_component_type'] != 'energy_general': reasons.append('not_general')
    if r['pricing_model'] != 'FixedPrice': reasons.append('not_fixed')
    if r['phase_kind'] not in ('current_structured','historical_observed'): reasons.append('promotion_or_noncurrent_phase')
    if r['phase_index'] != 0: reasons.append('nonzero_phase')
    if r['quality'] not in ('inferred','exact'): reasons.append('quality')
    if r['target_group'] not in ('Household','Both',None): reasons.append('audience')
    for flag in ('source_consistency_incomplete','source_consistency_conflicting'):
        if flag in flags: reasons.append(flag)
    # Discounted fee alone does not invalidate energy cadence; a discounted energy component does.
    components = json.loads(r['energy_components'] or '[]')
    energy_discount = any(c.get('component_type') == 'energy_general' and (c.get('discount') or c.get('normal_amount') is not None) for c in components)
    if energy_discount: reasons.append('energy_discount')
    carriers = md.get('period_carrier_ids', [r['contract_id']])
    if not reasons:
        for carrier in carriers:
            map_candidates[r['company_name'],term,carrier].append((r,md))
    premium_audit.append(dict(id=r['id'],supplier=r['company_name'],term=term,lineage=r['lineage_key'],method=r['method_version'],phase=r['phase_kind'],vat=r['vat_basis'],first=r['first_observed_date'],last=r['last_observed_date'],carrier_ids=';'.join(carriers),excluded=';'.join(reasons),seam='continues_prior_history_period' in flags))
csvout('premium-mapping-audit.csv', premium_audit)

mapped = C.defaultdict(list); map_audit=[]
for r in eligible:
    matches = []
    for p, md in map_candidates[r['company_name'],r['term'],r['contract_id']]:
        if not p['first_observed_date'] <= str(r['date']) <= p['last_observed_date']: continue
        if str(r['date']) in md.get('bridged_observation_dates',[]): continue
        # Require numerical agreement on the stored scale, without inventing VAT.
        if num(p['retail_energy_price_cents_per_kwh']) is None or changed(num(p['retail_energy_price_cents_per_kwh']),r['price']): continue
        matches.append(p)
    keys = {p['lineage_key'] for p in matches}
    status = 'mapped' if len(keys)==1 else 'ambiguous' if keys else 'unmapped'
    map_audit.append(dict(snapshot_id=r['id'],date=r['date'],contract_id=r['contract_id'],supplier=r['company_name'],term=r['term'],basis=r['pricing_basis'],status=status,premium_ids=';'.join(str(p['id']) for p in matches),lineage=next(iter(keys)) if len(keys)==1 else ''))
    if len(keys)==1:
        mapped[r['company_name'],r['term'],r['pricing_basis'],next(iter(keys)),r['date']].append(r)
csvout('dated-mapping.csv', map_audit)
lineage = C.defaultdict(dict); conflicts=[]
for kd, rows in mapped.items():
    if changed(min(r['price'] for r in rows),max(r['price'] for r in rows)):
        conflicts.append(dict(supplier=kd[0],term=kd[1],basis=kd[2],lineage=kd[3],date=kd[4],ids=';'.join(r['contract_id'] for r in rows))); continue
    lineage[kd[:4]][kd[4]]=dict(price=rows[0]['price'],fee=None,discount=any(r['has_discount'] for r in rows),carriers={r['contract_id'] for r in rows},row_ids={r['id'] for r in rows})
panels['dated_lineage_sensitivity']=lineage
csvout('lineage-conflicts.csv',conflicts)

# Supplier median uses exactly the same eligible General offer cohort, equal weight per ID.
supplier_days=C.defaultdict(list)
for r in eligible: supplier_days[r['company_name'],r['term'],r['pricing_basis'],r['date']].append(r)
medians=C.defaultdict(dict); median_rows=[]
for kd, rows in supplier_days.items():
    value=st.median(r['price'] for r in rows)
    medians[kd[:3]+('offered_median',)][kd[3]]=dict(price=value,fee=None,discount=any(r['has_discount'] for r in rows),carriers={r['contract_id'] for r in rows},row_ids={r['id'] for r in rows})
    median_rows.append(dict(supplier=kd[0],term=kd[1],basis=kd[2],date=kd[3],median=value,offers=len(rows),discount_offers=sum(bool(r['has_discount']) for r in rows),ids=';'.join(sorted(r['contract_id'] for r in rows))))
panels['supplier_median']=medians
csvout('supplier-daily-medians.csv',median_rows)

transitions=[]; spells=[]; series_rows=[]; windows=[]; aggregate=[]
for panel, series in panels.items():
    for k, dates in sorted(series.items()):
        ordered=sorted(dates); changes=0; adjacent_changes=0; gap_changes=0
        # Gaps terminate observation runs; never carry a price through missing dates.
        run_start=ordered[0]; spell_start=ordered[0]; left_censored=True
        for prev, now in zip(ordered, ordered[1:]):
            a,b=dates[prev],dates[now]; gap=(now-prev).days; ch=changed(a['price'],b['price'])
            changes+=ch; adjacent_changes+=ch and gap==1; gap_changes+=ch and gap>1
            transitions.append(dict(panel=panel,**base(k),before=prev,after=now,interval_bound_days=gap,energy_changed=ch,fee_comparable=a['fee'] is not None and b['fee'] is not None,fee_changed=changed(a['fee'],b['fee']),discount_flag_changed=a['discount'] != b['discount'],discount_at_either=a['discount'] or b['discount'],carrier_set_changed=a['carriers']!=b['carriers'],disjoint_carriers=not bool(a['carriers'] & b['carriers']),before_ids=';'.join(sorted(a['carriers'])),after_ids=';'.join(sorted(b['carriers'])),before_price=a['price'],after_price=b['price']))
            if gap>1 or ch:
                spells.append(dict(panel=panel,**base(k),start=spell_start,last=prev,observed_days=(prev-spell_start).days+1,elapsed_lower_bound_days=(prev-spell_start).days,left_censored=left_censored,right_censored=gap>1,ends_in_adjacent_change=gap==1 and ch,completed_change_to_change_days=(now-spell_start).days if gap==1 and ch and not left_censored else None,end_reason='gap' if gap>1 else 'numeric_change'))
                spell_start=now; left_censored=gap>1
        spells.append(dict(panel=panel,**base(k),start=spell_start,last=ordered[-1],observed_days=(ordered[-1]-spell_start).days+1,elapsed_lower_bound_days=(ordered[-1]-spell_start).days,left_censored=left_censored,right_censored=True,ends_in_adjacent_change=False,completed_change_to_change_days=None,end_reason='series_or_study_end'))
        series_rows.append(dict(panel=panel,**base(k),first=ordered[0],last=ordered[-1],observed_dates=len(ordered),span_days=(ordered[-1]-ordered[0]).days,changes_between_observations=changes,adjacent_day_changes=adjacent_changes,gap_bounded_changes=gap_changes,no_observed_energy_change=changes==0))
        for h in HORIZONS:
            potential=sum(d+dt.timedelta(days=h)<=END for d in ordered)
            paired=equal=full=full_constant=return_to_start=0
            for d in ordered:
                target=d+dt.timedelta(days=h)
                if target not in dates: continue
                paired+=1; same=not changed(dates[d]['price'],dates[target]['price']); equal+=same
                inner=[d+dt.timedelta(days=i) for i in range(h+1)]
                if all(i in dates for i in inner):
                    full+=1
                    any_change=any(changed(dates[a]['price'],dates[b]['price']) for a,b in zip(inner,inner[1:]))
                    full_constant+=not any_change
                    return_to_start+=same and any_change
            assert full_constant<=equal<=paired<=potential and full_constant<=full<=paired
            windows.append(dict(panel=panel,**base(k),horizon_days=h,start_dates_with_target_in_study=potential,exact_pairs=paired,unchanged_endpoints=equal,changed_endpoints=paired-equal,complete_daily_windows=full,no_observed_change_complete=full_constant,observed_change_complete=full-full_constant,returned_to_start_complete=return_to_start))

csvout('transitions.csv',transitions); csvout('constant-spells.csv',spells); csvout('series.csv',series_rows); csvout('windows-by-series.csv',windows)
# Pooled + supplier/term/basis cells, with exact counts and selection denominators.
for panel in panels:
    subsets={('ALL','ALL','ALL'): [r for r in series_rows if r['panel']==panel]}
    for r in series_rows:
        if r['panel']==panel: subsets.setdefault((r['supplier'],r['term'],r['basis']),[]).append(r)
    for cell, ss in subsets.items():
        ids={(r['supplier'],r['term'],r['basis'],r['series']) for r in ss}
        sp=[r for r in spells if r['panel']==panel and (r['supplier'],r['term'],r['basis'],r['series']) in ids]
        tt=[r for r in transitions if r['panel']==panel and (r['supplier'],r['term'],r['basis'],r['series']) in ids]
        intervals=[r['completed_change_to_change_days'] for r in sp if r['completed_change_to_change_days'] is not None]
        row=dict(panel=panel,supplier=cell[0],term=cell[1],basis=cell[2],series=len(ss),series_at_least_two_dates=sum(r['observed_dates']>=2 for r in ss),series_no_observed_change=sum(r['no_observed_energy_change'] for r in ss),no_change_multi_date_series=sum(r['no_observed_energy_change'] and r['observed_dates']>=2 for r in ss),observed_dates=sum(r['observed_dates'] for r in ss),adjacent_pairs=sum(r['interval_bound_days']==1 for r in tt),adjacent_energy_changes=sum(r['energy_changed'] and r['interval_bound_days']==1 for r in tt),gap_bounded_energy_changes=sum(r['energy_changed'] and r['interval_bound_days']>1 for r in tt),adjacent_fee_comparable_pairs=sum(r['fee_comparable'] and r['interval_bound_days']==1 for r in tt),adjacent_fee_changes=sum(r['fee_changed'] and r['interval_bound_days']==1 for r in tt),adjacent_discount_flag_changes=sum(r['discount_flag_changed'] and r['interval_bound_days']==1 for r in tt),energy_changes_discount_at_either=sum(r['energy_changed'] and r['discount_at_either'] and r['interval_bound_days']==1 for r in tt),spells=len(sp),left_censored=sum(r['left_censored'] for r in sp),right_censored=sum(r['right_censored'] for r in sp),completed_intervals=len(intervals),interval_p25=percentile(intervals,.25),interval_median=percentile(intervals,.5),interval_p75=percentile(intervals,.75),interval_p90=percentile(intervals,.9),observed_constant_days_median=percentile([r['observed_days'] for r in sp],.5))
        aggregate.append(row)
csvout('supplier-term-frequency.csv',aggregate)
window_cells=C.defaultdict(C.Counter)
for r in windows:
    for cell in ((r['panel'],r['supplier'],r['term'],r['basis'],r['horizon_days']),(r['panel'],'ALL','ALL','ALL',r['horizon_days'])):
        for field in ('start_dates_with_target_in_study','exact_pairs','unchanged_endpoints','changed_endpoints','complete_daily_windows','no_observed_change_complete','observed_change_complete','returned_to_start_complete'):
            window_cells[cell][field]+=r[field]
        window_cells[cell]['series_with_pairs']+=r['exact_pairs']>0
        window_cells[cell]['series_with_complete_windows']+=r['complete_daily_windows']>0
window_summary=[]
for k,c in window_cells.items():
    assert c['unchanged_endpoints']+c['changed_endpoints']==c['exact_pairs']
    assert c['no_observed_change_complete']+c['observed_change_complete']==c['complete_daily_windows']
    window_summary.append(dict(panel=k[0],supplier=k[1],term=k[2],basis=k[3],horizon_days=k[4],**c,p_unchanged_endpoints=rate(c['unchanged_endpoints'],c['exact_pairs']),p_no_observed_change_complete=rate(c['no_observed_change_complete'],c['complete_daily_windows']),exact_pair_selection_rate=rate(c['exact_pairs'],c['start_dates_with_target_in_study'])))
csvout('horizon-summary.csv',window_summary)

# Follow-up after a witnessed adjacent-day change. Initial prevalent spells are
# excluded because their true start is unknown. Report censored cases explicitly,
# not as unchanged observations. This is not a population survival estimator.
followups=[]
for panel in panels:
    fresh=[s for s in spells if s['panel']==panel and not s['left_censored']]
    for h in HORIZONS:
        events=sum(s['completed_change_to_change_days'] is not None and s['completed_change_to_change_days']<=h for s in fresh)
        survived=sum((s['completed_change_to_change_days'] is not None and s['completed_change_to_change_days']>h) or (s['right_censored'] and s['elapsed_lower_bound_days']>=h) for s in fresh)
        censored=len(fresh)-events-survived
        assert censored>=0
        followups.append(dict(panel=panel,horizon_days=h,fresh_spells=len(fresh),next_change_by_horizon=events,no_change_through_horizon=survived,censored_before_horizon=censored))
csvout('fresh-change-followup.csv',followups)

# Aggregate by basis to expose long-horizon dependence on older observed evidence.
basis_horizons=C.defaultdict(C.Counter)
for r in windows:
    c=basis_horizons[r['panel'],r['basis'],r['horizon_days']]
    for f in ('start_dates_with_target_in_study','exact_pairs','unchanged_endpoints','changed_endpoints','complete_daily_windows','no_observed_change_complete','observed_change_complete'):
        c[f]+=r[f]
csvout('horizons-by-basis.csv', [dict(panel=k[0],basis=k[1],horizon_days=k[2],**v) for k,v in sorted(basis_horizons.items())])

# Starts/ends are appearances in this eligible panel, not verified launches/withdrawals.
ids=C.defaultdict(list)
for r in eligible: ids[r['company_name'],r['term'],r['contract_id']].append(r)
entries=[]
for k,rs in sorted(ids.items()):
    lo=min(r['date'] for r in rs); hi=max(r['date'] for r in rs)
    entries.append(dict(supplier=k[0],term=k[1],contract_id=k[2],first=lo,last=hi,left_study_boundary=lo==START,right_study_boundary=hi==END,observed_dates=len(rs),price_values=len({r['price'] for r in rs})))
csvout('id-appearances.csv',entries)
seams=[]
for k, rs in ids.items():
    rs.sort(key=lambda r:r['date'])
    for a,b in zip(rs,rs[1:]):
        if a['pricing_basis']!=b['pricing_basis']:
            seams.append(dict(supplier=k[0],term=k[1],contract_id=k[2],before=a['date'],after=b['date'],before_basis=a['pricing_basis'],after_basis=b['pricing_basis'],numeric_difference=changed(a['price'],b['price']),before_price=a['price'],after_price=b['price']))
csvout('excluded-basis-seams.csv',seams)
replacement=[r for r in transitions if r['panel']=='dated_lineage_sensitivity' and r['disjoint_carriers']]
csvout('lineage-carrier-switches.csv',replacement)
# Cluster analysis uses supplier-term-date events, not variant-weighted counts.
clusters=C.defaultdict(set); exposure=C.defaultdict(set)
for r in transitions:
    if r['panel']=='same_id' and r['interval_bound_days']==1:
        exposure[r['after']].add((r['supplier'],r['term']))
        if r['energy_changed']: clusters[r['after']].add((r['supplier'],r['term']))
cluster_rows=[dict(date=d,weekday=d.strftime('%A'),day_of_month=d.day,supplier_term_changes=len(clusters[d]),supplier_term_exposure=len(exposure[d])) for d in sorted(exposure)]
csvout('date-clusters.csv',cluster_rows)
other_clusters=[]
for panel in ('dated_lineage_sensitivity','supplier_median'):
    ce=C.defaultdict(set); cx=C.defaultdict(set)
    for r in transitions:
        if r['panel']==panel and r['interval_bound_days']==1:
            cx[r['after']].add((r['supplier'],r['term']))
            if r['energy_changed']: ce[r['after']].add((r['supplier'],r['term']))
    for d in sorted(cx):
        other_clusters.append(dict(panel=panel,date=d,weekday=d.strftime('%A'),day_of_month=d.day,supplier_term_changes=len(ce[d]),supplier_term_exposure=len(cx[d])))
csvout('supplier-lineage-date-clusters.csv',other_clusters)
summary=dict(source=str(SOURCE.relative_to(ROOT.parent)),manifest_sha256=hashlib.sha256((SOURCE/'manifest.json').read_bytes()).hexdigest(),verified_rows={k:len(v) for k,v in data.items()},tolerance=str(TOL),date_bounds=[str(START),str(END)],eligible_general_rows=len(eligible),unique_general_ids=len(ids),exclusions=dict(exclusions),panels=[r for r in aggregate if r['supplier']=='ALL'],horizons=[r for r in window_summary if r['supplier']=='ALL'],mapping=dict(C.Counter(r['status'] for r in map_audit)),conflicting_lineage_days=len(conflicts),id_appearances_after_start=sum(not r['left_study_boundary'] for r in entries),id_last_appearances_before_end=sum(not r['right_study_boundary'] for r in entries),lineage_carrier_switches=len(replacement),adjacent_disjoint_carrier_switches=sum(r['interval_bound_days']==1 for r in replacement),adjacent_disjoint_carrier_price_changes=sum(r['interval_bound_days']==1 and r['energy_changed'] for r in replacement),gap_disjoint_carrier_price_changes=sum(r['interval_bound_days']>1 and r['energy_changed'] for r in replacement),verified_seller_replacement_decisions=None,cluster_weekdays={day:dict(changes=sum(r['supplier_term_changes'] for r in cluster_rows if r['weekday']==day),exposure=sum(r['supplier_term_exposure'] for r in cluster_rows if r['weekday']==day)) for day in ('Monday','Tuesday','Wednesday','Thursday','Friday','Saturday','Sunday')})
(ROOT/'summary.json').write_text(json.dumps(summary,indent=2,ensure_ascii=False)+'\n')
print(json.dumps(summary,indent=2,ensure_ascii=False))
