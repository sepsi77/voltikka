"""Walk-forward harness for the pre-declared falling-market study (see spec.md).

Usage: python3 -I harness.py <export_dir> [--frozen export-20261016] [--out results.json]
Standard library only. Reads CSVs; never touches a database.
"""
import argparse, calendar, csv, json, math, os
from collections import defaultdict
from datetime import date, timedelta
from decimal import Decimal, ROUND_HALF_UP

CUTOFF = date(2026, 10, 15)
PERIOD_SPLIT = date(2026, 8, 15)
THRESHOLD = 0.15
TERMS = (6, 12, 24)
MODELS = ['M0_unchanged', 'M1_current', 'M2_futures_only', 'M3_rolling60_drift', 'M4_premium_return',
          'M5_premium_return_futures', 'M6_half_gap', 'M7_blend']
RUNS = [('median', 30, 'primary'), ('median', 14, 'secondary'), ('median', 7, 'secondary'),
        ('p20', 30, 'secondary'), ('p80', 30, 'secondary')]
COLS = {'p20': 'p20_value', 'median': 'median_value', 'p80': 'p80_value'}

ap = argparse.ArgumentParser()
ap.add_argument('export')
ap.add_argument('--frozen', default=None)
ap.add_argument('--out', default=None)
args = ap.parse_args()
D = date.fromisoformat


def r4(v):
    return float(Decimal(repr(v)).quantize(Decimal('0.0001'), rounding=ROUND_HALF_UP))


def direction(c):
    return 'up' if c >= THRESHOLD else ('down' if c <= -THRESHOLD else 'flat')


def read(path):
    with open(path, newline='') as f:
        return list(csv.DictReader(f))


retail_rows = read(os.path.join(args.export, 'retail.csv'))
futures_rows = read(os.path.join(args.export, 'futures.csv'))

# ---- futures curves: newest vintage first, latest id owns duplicate keys
curves = defaultdict(dict)
for r in sorted(futures_rows, key=lambda r: int(r['id'])):
    curves[D(r['trade_date'])][f"{r['maturity_type']}|{r['maturity']}"] = float(r['settlement_price'])
trade_dates = sorted(curves, reverse=True)


def add_months(d, n):
    y, m = divmod(d.month - 1 + n, 12)
    return date(d.year + y, m + 1, 1)


basket_cache = {}


def basket(valuation, term, start):
    key = (valuation, term, start)
    if key in basket_cache:
        return basket_cache[key]
    td = next((t for t in trade_dates if t < valuation), None)
    value = None
    if td is not None:
        c = curves[td]; tot = w = 0; ok = True
        for k in range(term):
            m = add_months(start, k)
            days = calendar.monthrange(m.year, m.month)[1]
            keys = [f'month|{m:%Y%m}', f'quarter|{date(m.year, ((m.month - 1) // 3) * 3 + 1, 1):%Y%m}', f'year|{m.year}01']
            p = next((c[x] for x in keys if x in c), None)
            if p is None or not math.isfinite(p):
                ok = False; break
            tot += p * days; w += days
        value = tot / w / 10 * 1.255 if ok and w else None
    basket_cache[key] = value
    return value


def next_month(d):
    return add_months(date(d.year, d.month, 1), 1)


def feature(issue, term):
    a = basket(issue, term, next_month(issue))
    b = basket(issue - timedelta(days=7), term, next_month(issue))
    return None if a is None or b is None else a - b


def build_series(rows, quantile):
    col = COLS[quantile]; out = {}
    for term in TERMS:
        seg = [r for r in rows if r['segment_key'] == f'fixed_term_{term}']
        canon = [D(r['stat_date']) for r in seg if r['pricing_basis'] == 'canonical_calculation']
        start = min(canon) if canon else None
        owned = {}
        for r in sorted(seg, key=lambda r: -int(r['id'])):
            d = D(r['stat_date']); b = r['pricing_basis']
            keep = b == 'canonical_calculation' or (b == 'observed_seller_data' and (start is None or d < start))
            if keep and d not in owned:
                owned[d] = (b, r[col], int(r['id']))
        s = {}
        for d, (b, v, i) in owned.items():
            try:
                f = float(v)
            except (TypeError, ValueError):
                continue
            if math.isfinite(f):
                s[d] = (b, f, i)
        out[term] = s
    return out


def ridge(train, x):
    """train: list of (y, feature). Ridge 1 on population-standardized x, unpenalized intercept."""
    n = len(train)
    xm = sum(f for _, f in train) / n; ym = sum(y for y, _ in train) / n
    sd = math.sqrt(sum((f - xm) ** 2 for _, f in train) / n)
    if sd == 0:
        return 0.0
    cov = sum(((f - xm) / sd) * (y - ym) for y, f in train) / n
    var = sum(((f - xm) / sd) ** 2 for _, f in train) / n
    return cov / (var + 1) * ((x - xm) / sd)


def run(quantile, h):
    series = build_series(retail_rows, quantile)
    rows = []
    for term in TERMS:
        s = series[term]
        dates = sorted(s)
        prem = {d: s[d][1] - basket(d, term, next_month(d)) for d in dates if basket(d, term, next_month(d)) is not None}
        gap_cache = {}

        def gap(d):
            if d not in gap_cache:
                past = [prem[x] for x in dates if x < d and x in prem]
                gap_cache[d] = (sum(past) / len(past) - prem[d]) if d in prem and len(past) >= 20 else None
            return gap_cache[d]

        all_pairs = []
        for d in dates:
            t = d + timedelta(days=h)
            if t in s and s[t][0] == s[d][0]:
                all_pairs.append((d, t, s[t][1] - s[d][1]))
        for issue in dates:
            if issue > CUTOFF:
                continue
            pr = [p for p in all_pairs if p[1] < issue]
            if len(pr) < 20:
                continue
            x = feature(issue, term); g = gap(issue)
            ftr = [(y, feature(d, term)) for d, _, y in pr if feature(d, term) is not None]
            gtr = [(gap(d), y, d) for d, _, y in pr if gap(d) is not None]
            if x is None or g is None or len(ftr) < 20 or len(gtr) < 20:
                continue
            mean = sum(y for *_, y in pr) / len(pr)
            fut = ridge(ftr, x)
            roll = [y for *_, y in pr[-60:]]
            sxx = sum(a * a for a, _, _ in gtr)
            b = max(0.0, sum(a * y for a, y, _ in gtr) / sxx) if sxx else 0.0
            rtr = [(y - b * a, feature(d, term)) for a, y, d in gtr if feature(d, term) is not None]
            if len(rtr) < 20:
                continue
            m4 = b * g
            preds = {
                'M0_unchanged': 0.0,
                'M1_current': mean + fut,
                'M2_futures_only': fut,
                'M3_rolling60_drift': sum(roll) / len(roll) + fut,
                'M4_premium_return': m4,
                'M5_premium_return_futures': m4 + ridge(rtr, x),
                'M6_half_gap': 0.5 * g,
                'M7_blend': (fut + m4) / 2,
            }
            target = issue + timedelta(days=h)
            scoreable = target in s and s[target][0] == s[issue][0]
            rows.append({
                'term': term, 'issue': issue.isoformat(), 'target': target.isoformat(),
                'window': 'retrospective' if target <= CUTOFF else 'holdout',
                'current': s[issue][1], 'current_id': s[issue][2],
                'actual': r4(s[target][1] - s[issue][1]) if scoreable else None,
                'target_id': s[target][2] if scoreable else None,
                'train_pairs': len(pr), 'train_label_max': max(p[1] for p in pr).isoformat(),
                'diag': {'mean': mean, 'futures': fut, 'feature': x, 'gap': g, 'b': b},
                'pred': {m: r4(v) for m, v in preds.items()},
            })
    return rows


def summarize(rows, select):
    sel = [r for r in rows if r['actual'] is not None and select(r)]
    if not sel:
        return None
    acts = [direction(r['actual']) for r in sel]
    out = {'n': len(sel), 'unique_issue_days': len({r['issue'] for r in sel}),
           'issue_min': min(r['issue'] for r in sel), 'issue_max': max(r['issue'] for r in sel),
           'actual_up_flat_down': [acts.count('up'), acts.count('flat'), acts.count('down')], 'models': {}}
    err = {m: [abs(r['pred'][m] - r['actual']) for r in sel] for m in MODELS}
    for m in MODELS:
        c = {'correct': 0, 'missed': 0, 'false': 0, 'wrong_way': 0}
        for r in sel:
            p, a = direction(r['pred'][m]), direction(r['actual'])
            c['correct' if p == a else 'missed' if p == 'flat' else 'false' if a == 'flat' else 'wrong_way'] += 1
        n = len(sel)
        out['models'][m] = {
            'mae': round(sum(err[m]) / n, 6), 'bias': round(sum(r['pred'][m] - r['actual'] for r in sel) / n, 6), **c,
            'gain_vs_M0': round(sum(a - b for a, b in zip(err['M0_unchanged'], err[m])) / n, 6),
            'gain_vs_M1': round(sum(a - b for a, b in zip(err['M1_current'], err[m])) / n, 6),
        }
    return out


def decide(holdout, retro):
    if holdout is None or retro is None:
        return {'status': 'holdout_not_scoreable_yet'}
    hm, rm = holdout['models'], retro['models']
    order = ['M2_futures_only', 'M6_half_gap', 'M4_premium_return', 'M7_blend', 'M5_premium_return_futures', 'M3_rolling60_drift']
    q = [m for m in order if hm[m]['mae'] < hm['M1_current']['mae'] and hm[m]['mae'] <= hm['M0_unchanged']['mae']
         and hm[m]['wrong_way'] <= hm['M1_current']['wrong_way'] and rm[m]['mae'] <= rm['M0_unchanged']['mae'] + 0.10]
    if q:
        best = min(q, key=lambda m: (hm[m]['mae'], order.index(m)))
        return {'status': 'candidate', 'recommend': best, 'qualifying': q}
    if hm['M1_current']['mae'] <= hm['M0_unchanged']['mae']:
        return {'status': 'keep_current', 'qualifying': []}
    return {'status': 'publish_no_direction', 'qualifying': []}


def revisions():
    if not args.frozen:
        return None
    old = {r['id']: r for r in read(os.path.join(args.frozen, 'retail.csv'))}
    new = {r['id']: r for r in retail_rows}
    changed = [i for i, r in old.items() if i in new and new[i] != r]
    removed = [i for i in old if i not in new]
    added_old_dates = [i for i, r in new.items() if i not in old and D(r['stat_date']) <= CUTOFF]
    return {'changed_ids': changed, 'removed_ids': removed, 'new_ids_dated_on_or_before_cutoff': added_old_dates}


results = {'export': os.path.abspath(args.export), 'cutoff': CUTOFF.isoformat(), 'runs': {}, 'revisions': revisions()}
for quantile, h, role in RUNS:
    rows = run(quantile, h)
    key = f'{quantile}_h{h}'
    groups = {
        'retrospective_all': lambda r: r['window'] == 'retrospective',
        'retrospective_before_split': lambda r: r['window'] == 'retrospective' and D(r['issue']) < PERIOD_SPLIT,
        'retrospective_from_split': lambda r: r['window'] == 'retrospective' and D(r['issue']) >= PERIOD_SPLIT,
        'holdout_all': lambda r: r['window'] == 'holdout',
    }
    for w in ('retrospective', 'holdout'):
        for t in TERMS:
            groups[f'{w}_term{t}'] = lambda r, w=w, t=t: r['window'] == w and r['term'] == t
        for a in ('up', 'flat', 'down'):
            groups[f'{w}_actual_{a}'] = lambda r, w=w, a=a: r['window'] == w and r['actual'] is not None and direction(r['actual']) == a
    summary = {g: summarize(rows, f) for g, f in groups.items()}
    results['runs'][key] = {
        'role': role,
        'holdout_rows_total': sum(r['window'] == 'holdout' for r in rows),
        'holdout_rows_scoreable': sum(r['window'] == 'holdout' and r['actual'] is not None for r in rows),
        'summary': summary,
        'decision': decide(summary['holdout_all'], summary['retrospective_all']) if role == 'primary' else None,
        'rows': rows,
    }

text = json.dumps(results, indent=1, sort_keys=True)
if args.out:
    open(args.out, 'w').write(text + '\n')


def table(title, s):
    print(f'\n{title}')
    if s is None:
        print('  (no scoreable rows)'); return
    print(f"  n={s['n']} issue days={s['unique_issue_days']} {s['issue_min']}..{s['issue_max']} actual up/flat/down={s['actual_up_flat_down']}")
    for m, v in s['models'].items():
        print(f"  {m:27} MAE {v['mae']:.3f} bias {v['bias']:+.3f}  ok/miss/false/wrong {v['correct']}/{v['missed']}/{v['false']}/{v['wrong_way']}")


for key, run_res in results['runs'].items():
    print(f"\n===== {key} ({run_res['role']}) holdout rows {run_res['holdout_rows_scoreable']}/{run_res['holdout_rows_total']} scoreable")
    for g in ('retrospective_all', 'retrospective_before_split', 'retrospective_from_split', 'retrospective_actual_down', 'holdout_all'):
        table(g, run_res['summary'][g])
    if run_res['decision']:
        print('\n  DECISION:', run_res['decision'])
