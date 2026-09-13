#!/usr/bin/env python3
"""Local evidence analysis only. Run with the complete export directory."""
import collections
import csv
import datetime
import hashlib
import json
import pathlib
import statistics
import sys

root = pathlib.Path(__file__).resolve().parent
path = pathlib.Path(sys.argv[1]).resolve()
assert path.parent == root
manifest = json.loads((path / 'manifest.json').read_text())
assert manifest['status'] == 'complete'
assert hashlib.sha256((path / 'manifest.json').read_bytes()).hexdigest() == (path / 'manifest.sha256').read_text().split()[0]
data = {}
for name, query in manifest['queries'].items():
    raw = (path / query['file']).read_bytes()
    assert hashlib.sha256(raw).hexdigest() == query['sha256']
    assert len(raw) == query['bytes']
    data[name] = json.loads(raw)
    assert len(data[name]) == query['row_count'] == query['expected_count']
    assert hashlib.sha256(query['sql'].encode()).hexdigest() == query['sql_sha256']
    assert hashlib.sha256(query['count_sql'].encode()).hexdigest() == query['count_sql_sha256']
assert sum(q['bytes'] for q in manifest['queries'].values()) < 104857600


def write_csv(name, rows):
    with (root / name).open('w', newline='') as file:
        writer = csv.DictWriter(file, fieldnames=list(rows[0]))
        writer.writeheader()
        writer.writerows(rows)


def counts(items):
    return json.dumps(dict(sorted(collections.Counter(items).items())), ensure_ascii=False, sort_keys=True)


def bounds(items):
    items = list(items)
    return (min(items), max(items)) if items else ('', '')


snapshots = collections.defaultdict(list)
for row in data['snapshots']:
    term = int(row['fixed_time_range'].removeprefix('Fixed'))
    assert term in (6, 12, 24)
    snapshots[row['company_name'], term].append(row)

premiums = collections.defaultdict(list)
audit = []
by_key = {r['observation_key']: r for r in data['premiums']}
assert len(by_key) == len(data['premiums'])
for row in data['premiums']:
    row = dict(row)
    metadata = json.loads(row['source_metadata'] or '{}')
    flags = json.loads(row['quality_flags'] or '[]')
    term = metadata.get('duration_months')
    assignment = 'stored_period_metadata'
    if term is None:
        # Only exact carrier rows dated within the observation period are evidence.
        dated_terms = {int(s['fixed_time_range'].removeprefix('Fixed')) for s in data['snapshots']
                       if s['contract_id'] == row['contract_id'] and s['company_name'] == row['company_name']
                       and row['first_observed_date'] <= s['snapshot_date'] <= row['last_observed_date']}
        term = next(iter(dated_terms)) if len(dated_terms) == 1 else 'unknown'
        assignment = 'dated_snapshot' if len(dated_terms) == 1 else 'missing_or_ambiguous_historical_duration'
    reasons = []
    if term not in (6, 12, 24): reasons.append('outside_requested_terms' if term != 'unknown' else 'unknown_term')
    if row['vat_basis'] not in ('included', 'excluded'): reasons.append('unknown_or_mixed_energy_vat')
    if row['retail_premium_cents_per_kwh'] is None: reasons.append('null_energy_premium')
    if row['quality'] not in ('inferred', 'exact'): reasons.append('incompatible_quality')
    if row['energy_component_type'] != 'energy_general' or row['metering'] != 'General': reasons.append('multi_rate_not_aggregate')
    for flag in ('discount_effect_unresolved', 'source_consistency_incomplete', 'source_consistency_conflicting'):
        if flag in flags: reasons.append(flag)
    if row['phase_kind'] == 'continuation': reasons.append('post_term_continuation')
    reference = metadata.get('reference') or {}
    if row['reference_price_cents_per_kwh'] is None: reasons.append('null_vat_matched_reference')
    if reference.get('missing_delivery_months'): reasons.append('incomplete_term_strip')
    if not row['reference_trade_date'] or row['reference_trade_date'] >= row['first_observed_date']:
        reasons.append('missing_or_nonprior_reference')
    seam = 'continues_prior_history_period' in flags
    seam_target = metadata.get('continued_history_observation_key')
    seam_status = 'not_seam'
    if seam:
        target = by_key.get(seam_target)
        seam_status = 'linked_in_export' if target and target['lineage_key'] == row['lineage_key'] and target['energy_component_type'] == row['energy_component_type'] else 'unresolved_target'
        reasons.append('method_seam_not_independent')
    row.update(term=term, assignment=assignment, reasons=reasons, seam=seam, seam_status=seam_status, flags=flags)
    premiums[row['company_name'], term].append(row)
    audit.append({k: row[k] for k in ['id', 'company_name', 'term', 'assignment', 'lineage_key', 'contract_id', 'observation_key', 'price_signature', 'method_version', 'first_observed_date', 'last_observed_date', 'energy_component_type', 'phase_index', 'phase_kind', 'vat_basis', 'quality', 'retail_premium_cents_per_kwh', 'seam_status']} | {
        'usable_descriptive_energy_premium': not reasons,
        'exclusion_reasons': ';'.join(reasons), 'quality_flags': ';'.join(flags)})
write_csv('coverage-period-audit.csv', audit)

companies = sorted({k[0] for k in snapshots} | {k[0] for k in premiums})
coverage = []
transition_audit = []
for company in companies:
    for term in (6, 12, 24):
        ss = snapshots[company, term]
        pp = premiums[company, term]
        usable = [r for r in pp if not r['reasons']]
        first_s, last_s = bounds(r['snapshot_date'] for r in ss)
        first_p, _ = bounds(r['first_observed_date'] for r in pp)
        _, last_p = bounds(r['last_observed_date'] for r in pp)
        independent = [r for r in pp if not r['seam']]
        # A stored key is evidence, not automatically a repricing. Check consecutive
        # same-method, same-role, same-phase, same-VAT series. Never bridge a method seam.
        series = collections.defaultdict(list)
        for r in independent:
            series[r['lineage_key'], r['method_version'], r['energy_component_type'], r['phase_index'], r['vat_basis']].append(r)
        transitions = collections.Counter()
        for values in series.values():
            values.sort(key=lambda r: (r['first_observed_date'], r['id']))
            for before, after in zip(values, values[1:]):
                if after['first_observed_date'] <= before['last_observed_date']:
                    status = 'overlapping_period_pair_ambiguous'
                elif before['price_signature'] == after['price_signature']:
                    status = 'same_signature_new_key_not_proven_repricing'
                elif (before['retail_energy_price_cents_per_kwh'], before['monthly_fee_eur']) == (after['retail_energy_price_cents_per_kwh'], after['monthly_fee_eur']):
                    status = 'signature_change_without_energy_fee_change'
                else:
                    status = 'dated_energy_or_fee_change_component_pair'
                transitions[status] += 1
                transition_audit.append({'company_name': company, 'term_months': term,
                    'before_id': before['id'], 'after_id': after['id'],
                    'lineage_key': before['lineage_key'], 'method_version': before['method_version'],
                    'energy_component_type': before['energy_component_type'],
                    'phase_index': before['phase_index'], 'vat_basis': before['vat_basis'],
                    'before_last_observed': before['last_observed_date'],
                    'after_first_observed': after['first_observed_date'], 'status': status,
                    'both_rows_numerically_compatible': not before['reasons'] and not after['reasons']})
        row = {
            'company_name': company, 'term_months': term,
            'snapshot_rows': len(ss), 'snapshot_dates': len({r['snapshot_date'] for r in ss}),
            'snapshot_contract_ids_not_lineages': len({r['contract_id'] for r in ss}),
            'snapshot_first': first_s, 'snapshot_last': last_s,
            'snapshot_age_days_at_2026_09_13': (datetime.date(2026, 9, 13) - datetime.date.fromisoformat(last_s)).days if last_s else '',
            'snapshot_nonnull_energy_rows': sum(r['energy_price_cents_per_kwh'] is not None for r in ss),
            'snapshot_basis_counts': counts(r['pricing_basis'] for r in ss),
            'snapshot_segment_counts': counts(r['segment_key'] for r in ss),
            'snapshot_pricing_model_counts': counts(r['pricing_model'] or 'null' for r in ss),
            'snapshot_metering_counts': counts(r['metering'] for r in ss),
            'premium_rows_one_reference': len(pp), 'premium_lineages': len({r['lineage_key'] for r in pp}),
            'premium_first_observed': first_p, 'premium_last_observed': last_p,
            'premium_age_days_at_2026_09_13': (datetime.date(2026, 9, 13) - datetime.date.fromisoformat(last_p)).days if last_p else '',
            'stored_component_period_keys_excluding_seams': len(independent),
            'unique_lineage_price_signatures_not_repricing_count': len({(r['lineage_key'], r['method_version'], r['price_signature']) for r in independent}),
            'seam_rows': sum(r['seam'] for r in pp),
            'unresolved_seam_targets': sum(r['seam_status'] == 'unresolved_target' for r in pp),
            'chronology_pair_counts': json.dumps(dict(transitions), sort_keys=True),
            'method_counts': counts(r['method_version'] for r in pp),
            'vat_counts': counts(r['vat_basis'] for r in pp),
            'quality_counts': counts(r['quality'] for r in pp),
            'quality_flag_counts': counts(f for r in pp for f in r['flags']),
            'nonnull_known_vat_rows_before_compatibility_filter': sum(r['vat_basis'] in ('included', 'excluded') and r['retail_premium_cents_per_kwh'] is not None for r in pp),
            'usable_general_premium_rows': len(usable),
            'usable_general_lineages': len({r['lineage_key'] for r in usable}),
            'exclusion_reason_counts_nonexclusive': counts(reason for r in pp for reason in r['reasons']),
        }
        for vat in ('included', 'excluded'):
            vv = [r for r in usable if r['vat_basis'] == vat]
            row['usable_' + vat + '_rows'] = len(vv)
            row['descriptive_' + vat + '_median_cents_per_kwh'] = statistics.median(float(r['retail_premium_cents_per_kwh']) for r in vv) if vv else ''
        row['descriptive_status'] = 'VAT-separated sample description only; representativeness not established' if usable else 'No compatible known-VAT General premium baseline'
        row['supplier_forecast_status'] = 'Not validated; no independent holdout comparison performed'
        coverage.append(row)
write_csv('coverage-by-supplier-term.csv', coverage)
write_csv('coverage-transition-audit.csv', transition_audit)
summary = {
    'export_directory': path.name, 'export_rows': {k: len(v) for k, v in data.items()},
    'total_data_bytes': sum(q['bytes'] for q in manifest['queries'].values()),
    'suppliers': len(companies), 'supplier_term_cells': len(coverage),
    'premium_requested_term_rows': sum(r['premium_rows_one_reference'] for r in coverage),
    'out_of_term_premium_rows': sum(len(v) for k, v in premiums.items() if k[1] not in (6, 12, 24)),
    'unknown_duration_rows': len([r for r in audit if r['term'] == 'unknown']),
    'usable_general_premium_rows': sum(r['usable_general_premium_rows'] for r in coverage),
    'cells_with_usable_general_premiums': sum(r['usable_general_premium_rows'] > 0 for r in coverage),
    'usable_supplier_count': len({r['company_name'] for r in coverage if r['usable_general_premium_rows']}),
    'seam_rows_requested_terms': sum(r['seam_rows'] for r in coverage),
    'unresolved_seam_targets': sum(r['unresolved_seam_targets'] for r in coverage),
    'term_totals': {str(t): {k: sum(r[k] for r in coverage if r['term_months'] == t) for k in ['snapshot_rows', 'premium_rows_one_reference', 'premium_lineages', 'stored_component_period_keys_excluding_seams', 'usable_general_premium_rows']} for t in (6, 12, 24)},
    'data_bounds': {name: bounds(r[field] for r in data[name]) for name, field in [('futures','trade_date'),('statistics','stat_date'),('forecasts','forecast_date'),('snapshots','snapshot_date')]},
    'forecast_models': dict(collections.Counter(r['model_version'] for r in data['forecasts'])),
    'premium_vat_counts_requested_terms': dict(collections.Counter(r['vat_basis'] for r in audit if r['term'] in (6, 12, 24))),
}
(root / 'coverage-summary.json').write_text(json.dumps(summary, ensure_ascii=False, indent=2) + '\n')
print(json.dumps(summary, ensure_ascii=False, indent=2))
print('All export hashes, query hashes, row counts, byte counts and snapshot terms verified.')
