import json, sys, os, collections, statistics

# Cause classification of historical-v3 vs stored current-writer v3 differences.
TOL = 0.01
d = sys.argv[1]
exclude = set(sys.argv[2].split(',')) if len(sys.argv) > 2 and sys.argv[2] else set()
detail = len(sys.argv) > 3
dates = sorted(f[:-5] for f in os.listdir(d) if f.endswith('.json') and f[:-5] not in exclude)


def cause(r):
    s_av, h_av = r['s_cost'] is not None, r['h_cost'] is not None
    if not s_av and not h_av:
        return None
    if s_av and h_av and r['s_segment'] == r['h_segment'] and r['s_method'] == r['h_method'] and abs(r['s_cost'] - r['h_cost']) <= TOL:
        return 'equal'
    hf = set(r['h_flags'] or [])
    if r['h_interp'] is None and r['s_interp'] is not None:
        if 'historical_temporal_source_validation_unavailable' in hf:
            return 'A_target_validation_rejected:date_guard'
        if 'canonical_interpretation_rejected_by_exact_target_validation' in hf:
            return 'A_target_validation_rejected:validator'
        return 'A_no_selected_interpretation:' + str(r['h_reason'] or 'available')
    if r['h_interp'] != r['s_interp']:
        return 'B_different_interpretation'
    if not s_av:
        return 'C_new_in_history:' + str(r['h_method'])
    if not h_av:
        return 'C_lost_in_history:' + str(r['h_reason'])
    if r['s_segment'] != r['h_segment']:
        return 'D_segment'
    if r['s_start'] != r['h_start']:
        return 'E_anchor_start:%s->%s' % (r['s_method'], r['h_method'])
    if r['s_method'] != r['h_method']:
        return 'F_method_same_anchor:%s->%s' % (r['s_method'], r['h_method'])
    return 'G_amount_same_method_same_anchor:%s' % r['s_method']


counts = collections.Counter()
seg_counts = collections.defaultdict(collections.Counter)
examples = collections.defaultdict(list)
contracts = collections.defaultdict(set)
for date in dates:
    for r in json.load(open(os.path.join(d, date + '.json'))):
        c = cause(r)
        if c is None:
            continue
        counts[c] += 1
        seg_counts[c][r['s_segment'] or r['h_segment']] += 1
        contracts[c].add(r['contract'])
        if len(examples[c]) < 3 and r['kwh'] == 5000:
            examples[c].append((date, r['contract'], r['s_cost'], r['h_cost'], r['s_start'], r['h_start']))

print('dates=%d excluded=%s' % (len(dates), sorted(exclude)))
for c, n in sorted(counts.items(), key=lambda x: -x[1]):
    print('%6d pairs %4d contracts  %s  %s' % (n, len(contracts[c]), c, dict(seg_counts[c])))
    if detail:
        for e in examples[c]:
            print('           ', e)
        if c != 'equal' and len(contracts[c]) <= 25:
            print('            contracts:', sorted(x[:40] for x in contracts[c]))
