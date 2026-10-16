import json, sys, os, collections, statistics

TOL = 0.01
d = sys.argv[1]
dates = sorted(f[:-5] for f in os.listdir(d) if f.endswith('.json'))
exclude = set(sys.argv[2].split(',')) if len(sys.argv) > 2 and sys.argv[2] else set()

KEY_FLAGS = [
    'canonical_interpretation_rejected_by_exact_target_validation',
    'historical_temporal_source_validation_unavailable',
    'historical_exact_contract_only',
    'missing_own_reference_using_comparable_premium',
    'price_episode_left_censored',
    'prior_energy_evidence_changed_unknown_or_conflicting',
]

def classify(r):
    s_av = r['s_cost'] is not None
    h_av = r['h_cost'] is not None
    if not s_av and not h_av:
        return None
    if s_av and not h_av:
        return 'lost:' + str(r['h_reason'])
    if h_av and not s_av:
        return 'new:' + str(r['h_method'])
    parts = []
    if r['s_segment'] != r['h_segment']:
        parts.append('segment')
    if r['s_method'] != r['h_method']:
        parts.append('method:%s->%s' % (r['s_method'], r['h_method']))
    if abs(r['s_cost'] - r['h_cost']) > TOL:
        parts.append('cost')
    return 'changed:' + ','.join(parts) if parts else 'equal'

totals = collections.Counter()
causes = collections.Counter()
examples = {}
per_segment = collections.defaultdict(list)
med = collections.defaultdict(lambda: {'s': [], 'h': []})
for date in dates:
    if date in exclude:
        continue
    rows = json.load(open(os.path.join(d, date + '.json')))
    for r in rows:
        c = classify(r)
        if c is None:
            continue
        totals[c.split(':')[0]] += 1
        if c != 'equal':
            causes[c] += 1
            examples.setdefault(c, []).append((date, r['contract'], r['kwh'], r['s_cost'], r['h_cost'], r['s_method'], r['h_method']))
        if r['s_cost'] is not None:
            med[(date, r['s_segment'], r['kwh'])]['s'].append(r['s_cost'])
        if r['h_cost'] is not None:
            med[(date, r['h_segment'], r['kwh'])]['h'].append(r['h_cost'])

print('dates', len([x for x in dates if x not in exclude]), 'excluded', sorted(exclude & set(dates)))
print('totals', dict(totals))
for c, n in causes.most_common(40):
    print('%6d %s' % (n, c))
    for e in examples[c][:2]:
        print('        ', e)
seg = collections.defaultdict(list)
for (date, s, k), v in med.items():
    if v['s'] and v['h']:
        seg[(s, k)].append(statistics.median(v['h']) - statistics.median(v['s']))
print('segment median delta (hist - stored): n, median, min, max')
for key in sorted(seg):
    xs = seg[key]
    print('  %-22s %5d n=%2d med=%+8.2f min=%+8.2f max=%+8.2f' % (key[0], key[1], len(xs), statistics.median(xs), min(xs), max(xs)))
