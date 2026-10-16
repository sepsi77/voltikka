import json, sys, os, collections, statistics

# Older-history impact: new historical v3 vs stored v3 (old historical path) per date.
d = sys.argv[1]
exclude = set(sys.argv[2].split(',')) if len(sys.argv) > 2 and sys.argv[2] else set()
dates = sorted(f[:-5] for f in os.listdir(d) if f.endswith('.json') and f[:-5] not in exclude)
FAM = {'supplier_adjusted_forward_curve_shift': 'supplier_forward', 'supplier_adjusted_forward_premium': 'supplier_forward',
       'recurring_forward_curve_shift': 'recurring_forward', 'recurring_forward_premium': 'recurring_forward'}
TOL = 0.01
tot = collections.Counter()
seg_tot = collections.defaultdict(collections.Counter)
method_moves = collections.Counter()
delta = collections.defaultdict(list)      # (seg,kwh) -> median deltas
big = []
dom = {'s': collections.defaultdict(dict), 'h': collections.defaultdict(dict)}
changed_contracts = set()
for date in dates:
    rows = json.load(open(os.path.join(d, date + '.json')))
    med = collections.defaultdict(lambda: {'s': [], 'h': []})
    meth = {'s': collections.defaultdict(collections.Counter), 'h': collections.defaultdict(collections.Counter)}
    for r in rows:
        s_av, h_av = r['s_cost'] is not None, r['h_cost'] is not None
        if s_av:
            med[(r['s_segment'], r['kwh'])]['s'].append(r['s_cost'])
            meth['s'][(r['s_segment'], r['kwh'])][FAM.get(r['s_method'], r['s_method'])] += 1
        if h_av:
            med[(r['h_segment'], r['kwh'])]['h'].append(r['h_cost'])
            meth['h'][(r['h_segment'], r['kwh'])][FAM.get(r['h_method'], r['h_method'])] += 1
        if not s_av and not h_av:
            continue
        seg = r['s_segment'] or r['h_segment']
        if s_av and h_av and r['s_method'] == r['h_method'] and abs(r['s_cost'] - r['h_cost']) <= TOL and r['s_segment'] == r['h_segment']:
            k = 'equal'
        elif s_av and not h_av:
            k = 'lost'
        elif h_av and not s_av:
            k = 'new'
        else:
            k = 'changed'
            if r['s_method'] != r['h_method']:
                method_moves['%s -> %s' % (r['s_method'], r['h_method'])] += 1
        if k != 'equal':
            changed_contracts.add(r['contract'])
        tot[k] += 1
        seg_tot[seg][k] += 1
    for key, v in med.items():
        if v['s'] and v['h']:
            x = statistics.median(v['h']) - statistics.median(v['s'])
            delta[key].append(x)
            if abs(x) >= 25 and key[1] == 5000:
                big.append((date, key[0], round(statistics.median(v['s']), 2), round(statistics.median(v['h']), 2), round(x, 2)))
    for side in ('s', 'h'):
        for key, c in meth[side].items():
            m = max(c.values())
            dom[side][key][date] = tuple(sorted(k for k, n in c.items() if n == m))

print('dates=%d (%s..%s)' % (len(dates), dates[0], dates[-1]))
print('pairs', dict(tot), 'contracts with any change', len(changed_contracts))
print('per segment:')
for seg in sorted(seg_tot):
    print('  %-18s %s' % (seg, dict(seg_tot[seg])))
print('method moves (top):')
for k, n in method_moves.most_common(15):
    print('  %6d %s' % (n, k))
print('segment median delta new-stored: n, median, p10, p90, min, max, |delta|>=1 days')
for key in sorted(delta):
    xs = sorted(delta[key])
    p = lambda q: xs[min(len(xs) - 1, int(q * len(xs)))]
    print('  %-18s %5d n=%3d med=%+8.2f p10=%+8.2f p90=%+8.2f min=%+8.2f max=%+8.2f days|d|>=1:%d' % (key[0], key[1], len(xs), statistics.median(xs), p(0.1), p(0.9), min(xs), max(xs), sum(1 for x in xs if abs(x) >= 1)))
print('transitions of dominant family (stored -> new), after 2026-05-01 for open_ended/hybrid/quarterly/market_reset/spot:')
for key in sorted(set(dom['s']) | set(dom['h'])):
    if key[0] not in ('open_ended', 'hybrid', 'quarterly', 'market_reset', 'spot'):
        continue
    out = []
    for side in ('s', 'h'):
        seq = [(dt, dom[side][key][dt]) for dt in sorted(dom[side][key]) if dt >= '2026-05-01']
        tr = [seq[i][0] for i in range(1, len(seq)) if seq[i][1] != seq[i - 1][1]]
        out.append(tr)
    print('  %-14s %5d stored=%d %s' % (key[0], key[1], len(out[0]), out[0]))
    print('  %-14s %5s new   =%d %s' % ('', '', len(out[1]), out[1]))
print('5000 kWh median deltas >= 25 EUR:', len(big))
for b in big[:40]:
    print('  ', b)
