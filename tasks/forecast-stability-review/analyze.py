#!/usr/bin/env python3
"""Rebuild local review artifacts from the one complete export. Python stdlib only."""
import calendar
import csv
import hashlib
import json
from collections import Counter, defaultdict
from datetime import date, timedelta
from pathlib import Path
from statistics import mean

ROOT = Path(__file__).resolve().parent
SOURCE = ROOT / 'export-20260913T073334Z'
START, END = date(2026, 6, 13), date(2026, 9, 13)
DURATIONS = (6, 12, 24)
CHANGE = 'expected_change_cents_per_kwh'
HEDGE = 'hedge_cost_cents_per_kwh'
PREMIUM = 'normal_retail_premium_cents_per_kwh'
RETAIL = 'current_price_cents_per_kwh'
SIGNALS = {'neutral': 'neutral', 'lock_sooner': 'lock', 'wait_if_flexible': 'wait'}


def number(row, key):
    return float(row[key])


def csv_file(name, rows):
    with (ROOT / name).open('w', newline='', encoding='utf-8') as stream:
        writer = csv.DictWriter(stream, fieldnames=list(rows[0]))
        writer.writeheader()
        writer.writerows(rows)


def month_add(value, offset):
    index = value.year * 12 + value.month - 1 + offset
    return date(index // 12, index % 12 + 1, 1)


def table(headers, rows):
    return '\n'.join(['| ' + ' | '.join(headers) + ' |', '| ' + ' | '.join(['---'] * len(headers)) + ' |'] + ['| ' + ' | '.join(map(str, row)) + ' |' for row in rows])


def main():
    manifest_bytes = (SOURCE / 'manifest.json').read_bytes()
    assert hashlib.sha256(manifest_bytes).hexdigest() == (SOURCE / 'manifest.sha256').read_text().split()[0]
    manifest = json.loads(manifest_bytes)
    assert manifest['status'] == 'complete'
    data, verified = {}, {}
    for key, entry in manifest['queries'].items():
        raw = (SOURCE / entry['file']).read_bytes()
        assert len(raw) == entry['bytes'], key
        assert hashlib.sha256(raw).hexdigest() == entry['sha256'], key
        data[key] = json.loads(raw)
        assert len(data[key]) == entry['row_count'], key
        verified[key] = {'rows': len(data[key]), 'sha256': entry['sha256'], 'bytes': len(raw)}
    forecasts, futures = data['forecasts'], data['futures']
    assert len(forecasts) == 810 and len(futures) == 1499
    metadata = {r['id']: json.loads(r['source_metadata']) for r in forecasts}
    assert all(r['horizon_days'] == 30 and r['confidence'] == 'low' for r in forecasts)
    assert all(m['ewma_alpha'] == .25 and m['gap_closure_lambda'] == .3 and m['direction_threshold_cents_per_kwh'] == .15 for m in metadata.values())
    medians = [r for r in forecasts if r['target_quantile'] == 'median']
    headline = sorted([r for r in medians if r['model_version'].endswith('v1' if r['forecast_date'] < '2026-07-27' else 'v2')], key=lambda r: (r['forecast_date'], r['duration_months']))
    days = sorted(set(r['forecast_date'] for r in headline))
    missing = [(START + timedelta(days=i)).isoformat() for i in range((END - START).days + 1) if (START + timedelta(days=i)).isoformat() not in days]
    assert len(days) == 89 and missing == ['2026-08-02', '2026-08-03', '2026-08-04', '2026-08-22']
    date_counts = Counter(r['forecast_date'] for r in forecasts)
    assert all(n == (18 if d == '2026-07-27' else 9) for d, n in date_counts.items())
    csv_file('all-original-forecasts.csv', forecasts)
    csv_file('daily-headline.csv', headline)
    dual = [r for r in medians if r['forecast_date'] == '2026-07-27']
    csv_file('july27-dual-models.csv', dual)
    series = {duration: [r for r in headline if r['duration_months'] == duration] for duration in DURATIONS}
    stability, revisions, rolls = {}, [], []
    curves = defaultdict(dict)
    for r in futures:
        key = (r['maturity_type'], r['maturity'])
        assert key not in curves[r['trade_date']]
        curves[r['trade_date']][key] = float(r['settlement_price'])
    trade_dates = sorted(curves)

    def hedge(start, duration, trade):
        weighted, weights = 0, 0
        for offset in range(duration):
            month = month_add(start, offset)
            keys = [('month', month.strftime('%Y%m')), ('quarter', f'{month.year}{((month.month-1)//3)*3+1:02}'), ('year', f'{month.year}01')]
            price = next((curves[trade][k] for k in keys if k in curves[trade]), None)
            assert price is not None, (start, duration, trade, month)
            weight = calendar.monthrange(month.year, month.month)[1]
            weighted += weight * price
            weights += weight
        return weighted / weights * .1255

    errors = []
    for r in medians:
        dt = date.fromisoformat(r['forecast_date'])
        trade = max(t for t in trade_dates if t < r['forecast_date'])
        assert trade == r['futures_trade_date']
        errors.append(abs(hedge(month_add(dt, 1), r['duration_months'], trade) - number(r, HEDGE)))
    assert max(errors) <= .000051
    for duration, rows in series.items():
        transitions = []
        for old, new in zip(rows, rows[1:]):
            if old['consumer_signal'] != new['consumer_signal']:
                transitions.append({'date': new['forecast_date'], 'previous_date': old['forecast_date'], 'from': SIGNALS[old['consumer_signal']], 'to': SIGNALS[new['consumer_signal']]})
            rev = {'duration_months': duration, 'from_date': old['forecast_date'], 'to_date': new['forecast_date'], 'calendar_days': (date.fromisoformat(new['forecast_date']) - date.fromisoformat(old['forecast_date'])).days, 'model_boundary': old['model_version'] != new['model_version'], 'change_revision': number(new, CHANGE) - number(old, CHANGE), 'hedge_contribution': .3 * (number(new, HEDGE) - number(old, HEDGE)), 'premium_contribution': .3 * (number(new, PREMIUM) - number(old, PREMIUM)), 'retail_contribution': -.3 * (number(new, RETAIL) - number(old, RETAIL))}
            rev['rounding_residual'] = rev['change_revision'] - sum(rev[k] for k in ['hedge_contribution', 'premium_contribution', 'retail_contribution'])
            assert abs(rev['rounding_residual']) <= .0002
            revisions.append(rev)
            if new['forecast_date'].endswith('-01'):
                trade = new['futures_trade_date']
                old_start = month_add(date.fromisoformat(old['forecast_date']), 1)
                new_start = month_add(date.fromisoformat(new['forecast_date']), 1)
                old_on_new = hedge(old_start, duration, trade)
                new_on_new = hedge(new_start, duration, trade)
                roll = new_on_new - old_on_new
                rolls.append(dict(rev, trade_date=trade, old_delivery_start=old_start.isoformat(), new_delivery_start=new_start.isoformat(), old_window_on_new_curve=old_on_new, new_window_on_new_curve=new_on_new, delivery_roll_hedge=roll, delivery_roll_forecast=.3*roll, old_window_curve_and_selection_forecast=.3*(old_on_new-hedge(old_start, duration, old['futures_trade_date']))))
        stability[duration] = {'signal_counts': dict(Counter(SIGNALS[r['consumer_signal']] for r in rows)), 'signal_changes': len(transitions), 'transitions': transitions, 'raw_sign_reversals': sum(number(a, CHANGE)*number(b, CHANGE) < 0 for a,b in zip(rows,rows[1:])), 'direct_wait_lock_transitions': sum({t['from'],t['to']} == {'wait','lock'} for t in transitions)}
        assert stability[duration]['signal_changes'] == {6:7,12:3,24:4}[duration]
        assert stability[duration]['raw_sign_reversals'] == {6:9,12:4,24:14}[duration]
    threshold_sensitivity = {}
    for threshold in (.15, .20, .25):
        labels = ['lock' if number(r, CHANGE) >= threshold else 'wait' if number(r, CHANGE) <= -threshold else 'neutral' for r in series[6]]
        threshold_sensitivity[str(threshold)] = sum(a != b for a, b in zip(labels, labels[1:]))
        if threshold == .15:
            assert labels == [SIGNALS[r['consumer_signal']] for r in series[6]]
    assert list(threshold_sensitivity.values()) == [7, 8, 12]
    assert {r['forecast_date']: r['contract_count'] for r in series[6] if r['forecast_date'] in ['2026-06-13', '2026-07-31', '2026-08-01', '2026-09-10', '2026-09-13']} == {'2026-06-13':20, '2026-07-31':9, '2026-08-01':14, '2026-09-10':9, '2026-09-13':10}
    old_curve_roll = .3 * (hedge(date(2026,9,1), 6, '2026-07-30') - hedge(date(2026,8,1), 6, '2026-07-30'))
    assert abs(old_curve_roll - .365518) < .000001
    csv_file('daily-revisions.csv', revisions)
    csv_file('month-boundary-decomposition.csv', rolls)
    v2 = [r for r in medians if r['model_version'].endswith('v2')]
    normal = {d: sorted(set(number(r, PREMIUM) for r in v2 if r['duration_months'] == d)) for d in DURATIONS}
    assert normal == {6:[1.3692],12:[1.7866],24:[2.1343]}
    assert all(metadata[r['id']]['historical_retail_source_end_date'] == '2026-07-26' and metadata[r['id']]['history_observations'] == 109 for r in v2)
    weekly_dates = {}
    for day in days:
        iso = date.fromisoformat(day).isocalendar()
        weekly_dates[f'{iso.year}-W{iso.week:02}'] = day
    by_day_duration = {(r['forecast_date'],r['duration_months']): r for r in headline}
    weekly = []
    for week, day in weekly_dates.items():
        row = {'iso_week':week, 'forecast_date':day}
        for d in DURATIONS:
            r = by_day_duration[day,d]
            row[f'{d}m_change'] = number(r,CHANGE)
            row[f'{d}m_signal'] = SIGNALS[r['consumer_signal']]
        weekly.append(row)
    csv_file('weekly-headline.csv', weekly)
    fixed_stats, fixed_rows = {}, []
    for name, key in [('Q4 2026',('quarter','202610')), ('Q1 2027',('quarter','202701')), ('Calendar 2027',('year','202701'))]:
        rows = sorted([r for r in futures if (r['maturity_type'],r['maturity']) == key and r['trade_date'] >= '2026-06-12'], key=lambda r:r['trade_date'])
        prices = {r['trade_date']:float(r['settlement_price']) for r in rows}
        adjacent, seven = [], []
        for i, r in enumerate(rows):
            old_date = (date.fromisoformat(r['trade_date'])-timedelta(days=7)).isoformat()
            prior = rows[i-1] if i else None
            adj = (float(r['settlement_price'])/float(prior['settlement_price'])-1)*100 if prior else None
            ret7 = (float(r['settlement_price'])/prices[old_date]-1)*100 if old_date in prices else None
            if adj is not None: adjacent.append(adj)
            if ret7 is not None: seven.append(ret7)
            fixed_rows.append(dict(r, analysis_instrument=name, previous_available_trade_date=prior['trade_date'] if prior else None, adjacent_return_percent=adj, exact_7day_from=old_date if ret7 is not None else None, exact_7day_return_percent=ret7))
        fixed_stats[name] = {'first_date':rows[0]['trade_date'], 'last_date':rows[-1]['trade_date'], 'first_price':float(rows[0]['settlement_price']), 'last_price':float(rows[-1]['settlement_price']), 'period_return_percent':(float(rows[-1]['settlement_price'])/float(rows[0]['settlement_price'])-1)*100, 'observations':len(rows), 'adjacent_pairs':len(adjacent), 'adjacent_mean_absolute_percent':mean(map(abs,adjacent)), 'adjacent_max_absolute_percent':max(map(abs,adjacent)), 'exact_7day_pairs':len(seven), 'exact_7day_unmatched_endpoints':len(rows)-len(seven), 'exact_7day_mean_absolute_percent':mean(map(abs,seven)), 'exact_7day_max_absolute_percent':max(map(abs,seven))}
    csv_file('fixed-maturity-futures.csv', fixed_rows)
    volume_counts = {'null': sum(r['volume'] is None for r in futures), 'present': sum(r['volume'] is not None for r in futures)}
    assert volume_counts == {'null': 1475, 'present': 24}
    accuracy, matured = {}, []
    for duration in DURATIONS:
        evaluated = [r for r in medians if r['duration_months']==duration and r['actual_price_cents_per_kwh'] is not None]
        assert len(evaluated)==15 and all(r['model_version'].endswith('v1') for r in evaluated)
        accuracy[duration] = {'count':len(evaluated), 'forecast_mae':mean(number(r,'absolute_error_cents_per_kwh') for r in evaluated), 'unchanged_price_mae':mean(abs(number(r,'actual_price_cents_per_kwh')-number(r,RETAIL)) for r in evaluated)}
        for version in sorted(set(r['model_version'] for r in forecasts)):
            selected = [r for r in forecasts if r['duration_months']==duration and r['model_version']==version and r['target_date']<=END.isoformat() and r['actual_price_cents_per_kwh'] is None]
            matured.append({'model_version':version,'duration_months':duration,'all_quantiles':len(selected),'median':sum(r['target_quantile']=='median' for r in selected)})
    summary = {'source':SOURCE.name,'manifest_sha256':hashlib.sha256(manifest_bytes).hexdigest(),'verified_files':verified,'window':{'start':START.isoformat(),'end':END.isoformat(),'calendar_days':93,'observed_dates':len(days),'missing_dates':missing,'headline_rows':len(headline),'all_forecast_rows':len(forecasts)},'stability':stability,'six_month_threshold_sensitivity':threshold_sensitivity,'august_6m_old_curve_roll_contribution':old_curve_roll,'v2_normal_premiums':normal,'hedge_reconstruction':{'rows':len(medians),'maximum_absolute_error':max(errors),'tolerance':.000051},'month_boundary_decomposition':rolls,'fixed_maturities':fixed_stats,'futures_volume_counts':volume_counts,'accuracy':accuracy,'matured_unevaluated':matured,'largest_6m_revision':max((r for r in revisions if r['duration_months']==6),key=lambda r:abs(r['change_revision']))}
    (ROOT/'analysis-summary.json').write_text(json.dumps(summary,indent=2,sort_keys=True)+'\n')
    make_svg(series)
    make_report(summary, weekly, dual)
    print(f'PASS: manifest and 6 file hashes/counts; {len(forecasts)} forecasts; {len(headline)} headline rows; {len(medians)} hedge reconstructions (max error {max(errors):.10f}); signal, premium, and decomposition assertions.')


def make_svg(series):
    width, height = 1200, 610
    left, right, top, bottom = 95, 1110, 105, 485
    def x(day): return left+(date.fromisoformat(day)-START).days/(END-START).days*(right-left)
    values = [number(r,CHANGE) for rows in series.values() for r in rows]
    low = min(-.3, (int(min(values)*10)-1)/10)
    high = max(.3, (int(max(values)*10)+1)/10)
    def y(value): return bottom-(value-low)/(high-low)*(bottom-top)
    svg = [f'<svg xmlns="http://www.w3.org/2000/svg" width="{width}" height="{height}" viewBox="0 0 {width} {height}" role="img" aria-labelledby="title desc">', '<title id="title">Stored 30-day median retail forecast change, 13 June to 13 September 2026</title>', '<desc id="desc">Three contract terms. All forecasts have low confidence. Lines stop at missing dates and the 27 July model boundary. Dashed horizontal lines mark plus and minus 0.15 c/kWh signal thresholds. The CSV and report give exact values.</desc>', '<rect width="1200" height="610" fill="white"/>', '<g font-family="Arial, sans-serif" font-size="14" fill="#242424">', '<text x="95" y="32" font-size="23" font-weight="bold">Stored forecasts: expected median price change in 30 days</text>', '<text x="95" y="57">c/kWh including VAT · Energy only · All rows: low confidence · Not HTML impression history</text>']
    for value in sorted(set([round(low+i*.1,2) for i in range(round((high-low)/.1)+1)]+[-.15,.15,0])):
        threshold = abs(value)==.15
        svg.append(f'<line x1="{left}" x2="{right}" y1="{y(value):.2f}" y2="{y(value):.2f}" stroke="{"#555" if threshold or value==0 else "#ddd"}" stroke-dasharray="{"5 4" if threshold else "none"}"/>')
        svg.append(f'<text x="83" y="{y(value)+5:.2f}" text-anchor="end">{value:+.2f}</text>')
    for day in ['2026-06-13','2026-07-01','2026-07-15','2026-08-01','2026-08-15','2026-09-01','2026-09-13']:
        svg.append(f'<text x="{x(day):.2f}" y="512" text-anchor="middle">{date.fromisoformat(day).strftime("%d %b")}</text>')
    boundary = x('2026-07-27')
    svg += [f'<line x1="{boundary:.2f}" x2="{boundary:.2f}" y1="85" y2="485" stroke="#555" stroke-dasharray="2 5"/>', f'<text x="{boundary+7:.2f}" y="95">27 Jul: v2 starts</text>']
    for index, (duration, color, dash) in enumerate([(6,'#005b96','none'),(12,'#a44200','8 3'),(24,'#70409b','2 4')]):
        commands = []
        previous = None
        for r in series[duration]:
            connected = previous is not None and (date.fromisoformat(r['forecast_date'])-date.fromisoformat(previous['forecast_date'])).days==1 and r['model_version']==previous['model_version']
            commands.append(f'{"L" if connected else "M"}{x(r["forecast_date"]):.2f},{y(number(r,CHANGE)):.2f}')
            svg.append(f'<circle cx="{x(r["forecast_date"]):.2f}" cy="{y(number(r,CHANGE)):.2f}" r="1.8" fill="{color}"/>')
            previous=r
        svg.append(f'<path d="{" ".join(commands)}" fill="none" stroke="{color}" stroke-width="2.3" stroke-dasharray="{dash}"/>')
        lx=95+index*250
        svg.append(f'<line x1="{lx}" x2="{lx+35}" y1="547" y2="547" stroke="{color}" stroke-width="3" stroke-dasharray="{dash}"/><text x="{lx+45}" y="552">{duration}-month contract</text>')
    svg += ['<text x="95" y="583">Gaps: 2–4 Aug and 22 Aug. Above +0.15: lock; below −0.15: wait; between: neutral.</text>','</g></svg>']
    (ROOT/'forecast-timeline.svg').write_text('\n'.join(svg)+'\n')


def make_report(s, weekly, dual):
    weekly_table = table(['ISO week','Last stored date','6m change / signal','12m change / signal','24m change / signal'], [[r['iso_week'],r['forecast_date']]+[f"{r[f'{d}m_change']:+.4f} / {r[f'{d}m_signal']}" for d in DURATIONS] for r in weekly])
    stability_table = table(['Term','Neutral','Wait','Lock','Signal changes','Raw sign reversals'], [[d,s['stability'][d]['signal_counts'].get('neutral',0),s['stability'][d]['signal_counts'].get('wait',0),s['stability'][d]['signal_counts'].get('lock',0),s['stability'][d]['signal_changes'],s['stability'][d]['raw_sign_reversals']] for d in DURATIONS])
    roll_table = table(['Date','Term','Revision','0.3 ΔH','0.3 ΔN','−0.3 ΔR','Roll effect on forecast','Old-window curve / selection'], [[r['to_date'],r['duration_months']]+[f'{r[k]:+.4f}' for k in ['change_revision','hedge_contribution','premium_contribution','retail_contribution','delivery_roll_forecast','old_window_curve_and_selection_forecast']] for r in s['month_boundary_decomposition']])
    futures_table = table(['Fixed delivery','12 Jun','11 Sep','Change','Adjacent pairs / mean abs / max abs','7-day pairs / mean abs / max abs'], [[name,f"{v['first_price']:.2f}",f"{v['last_price']:.2f}",f"{v['period_return_percent']:+.2f}%",f"{v['adjacent_pairs']} / {v['adjacent_mean_absolute_percent']:.2f}% / {v['adjacent_max_absolute_percent']:.2f}%",f"{v['exact_7day_pairs']} / {v['exact_7day_mean_absolute_percent']:.2f}% / {v['exact_7day_max_absolute_percent']:.2f}%"] for name,v in s['fixed_maturities'].items()])
    accuracy_table = table(['Term','Evaluations','Forecast MAE','Unchanged-price MAE'], [[d,v['count'],f"{v['forecast_mae']:.7f}",f"{v['unchanged_price_mae']:.7f}"] for d,v in s['accuracy'].items()])
    matured_table = table(['Model','Term','Median rows','All quantiles'], [[r['model_version'],r['duration_months'],r['median'],r['all_quantiles']] for r in s['matured_unevaluated']])
    dual_table = table(['Model','Term','Current','Forecast','Expected change','Signal'], [[r['model_version'],r['duration_months'],r[RETAIL],r['forecast_price_cents_per_kwh'],r[CHANGE],SIGNALS[r['consumer_signal']]] for r in dual])
    transitions = '\n'.join(f"- **{d}m:** " + '; '.join(f"{t['date']}: {t['from']} → {t['to']}" for t in s['stability'][d]['transitions']) + '.' for d in DURATIONS)
    report = f'''# Three-month forecast stability review

## Main finding

The stored 6-month-contract outlook changes enough to need clearer uncertainty language. But the evidence does **not** show repeated direct switches from strong wait advice to strong lock advice on consecutive stored observations. The signals pass through neutral. Some large changes come from the monthly delivery-window roll, not noise in one fixed futures instrument. Other changes are real same-delivery futures price moves. These effects must be tested separately.

On 13 September, the 6m expected change is **+0.1545 c/kWh**, only **0.0045** above the lock cutoff. The 12m value is **−0.2318** (wait), and 24m is **−0.0267** (neutral). This is not a broad market consensus. Every exported forecast has **low confidence**. Current code derives confidence from history count, not a calibrated forecast-error probability.

The v2 normal-premium input does not update in this sample. Its historical evidence ends on 26 July at 109 observations. Validate that learning path and comparable evaluation before trying to make the signal smoother.

## Evidence and scope

- Dates: **13 June–13 September 2026**, inclusive; 93 calendar days, 89 stored forecast dates.
- The complete source is `{SOURCE.name}` only. Failed export attempts are not inputs.
- Verified: manifest SHA-256, complete status, all six data-file hashes, byte counts, and row counts. There are 810 forecast rows, 1,499 FI Base futures rows, 315 retail statistic rows, and 80 schema rows. The database clock was 13 September 2026, 07:33:36 UTC; the complete export ended at 07:33:42 UTC.
- Nine forecast rows per date, except 27 July with 18: both model versions exist. Missing dates: **2, 3, 4 and 22 August**. We do not fill gaps or infer failed run causes.
- Headline selection: median, 30-day horizon; v1 through 26 July and v2 from 27 July. This gives 267 rows, 89 per term. All other original rows remain available.
- This is **stored forecast history, not proven HTML impression history**. Stored rows do not prove which value a visitor saw, when caches refreshed, or what historical page eligibility rules allowed.
- Prices and forecast changes below are **c/kWh including VAT**, energy only. Futures prices are **EUR/MWh excluding VAT**. Monthly fees are excluded. A 6m, 12m or 24m label means **contract term**, not forecast horizon. Every forecast is a 30-day outlook. This is not a whole-customer optimum recommendation.

## Timeline

![Stored median forecast changes](forecast-timeline.svg)

The chart uses actual dates and stops lines at missing days and the 27 July model boundary. It does not connect over absent data. Zero and ±0.15 thresholds are shown. Exact daily current and forecast prices are in `daily-headline.csv`.

### Weekly view

Each row uses the **last available stored date inside the ISO week**, not an average and not a carried-forward Sunday value. The first week is partial. In the week ending 2 August, the correct date is **1 August**.

{weekly_table}

### Signal stability

{stability_table}

{transitions}

A signal change compares adjacent **available observations** (88 pairs per term), including gap-spanning pairs and the model boundary. It is not necessarily a next-calendar-day comparison. `daily-revisions.csv` gives the day gap and model-boundary flag for each pair. A raw sign reversal means the product of two signed expected changes is negative; zero does not count. This measure can count tiny neutral moves. It is not the same as advice switching. No adjacent pair switches directly between wait and lock in either direction.

Lock means expected change ≥ +0.15; wait means ≤ −0.15; otherwise neutral. Equality belongs to the strong category. Stored rounded values are used for this review; service labels use the unrounded calculation. At the current 0.15 threshold, the rounded 6m classification matches every stored signal. As a simple sensitivity check, 6m switch counts are **7 at 0.15, 8 at 0.20, and 12 at 0.25** across the same 89 observations. A higher cutoff alone does not guarantee more stable labels; this is not a backtest of decision quality.

### Preserve the 27 July boundary

{dual_table}

The two models are separate records, not duplicate observations to average. V2 records canonical current retail inputs and observed-seller historical inputs separately. A continuous headline selection is useful for review, but it does not make the two bases or model versions equivalent. Full source metadata remains in the CSV and JSON export.\n\nThe 6m median contract count falls from **20 on 13 June to 10 on 13 September** (9 on 10 September). It changes from **9 on 31 July to 14 on 1 August**. Median input movements can combine seller repricing and changes in the eligible product mix. This aggregate export cannot separate these causes. It does not show that each same contract rose. This also limits current-to-forecast level comparisons across the 27 July basis change.

## What moves the forecast?

Let R be current median retail price, H futures-implied hedge cost, and N the normal retail premium. The stored coefficients are EWMA alpha **0.25**, gap closure lambda **0.30**, and direction threshold **0.15 c/kWh**.

`expected change = 0.3 × (H + N − R)`

`revision in expected change = 0.3 ΔH + 0.3 ΔN − 0.3 ΔR`

Here, “revision” means a change between successive **30-day-outlook vintages**. The target date advances with each forecast date; this is not a revision for one fixed target date, nor a realized customer price change. The output CSV retains the small residual from four-decimal stored rounding. Throughout v2 the median N values remain **1.3692 / 1.7866 / 2.1343** for 6/12/24m. V2 metadata always ends its observed history on **26 July**, with **109 observations**. Thus v2 daily revisions come from H and R, not renewed premium learning. The old observed-history selection and new canonical current basis explain the data-path concern; this review does not change either basis.

### Reconstructed hedge costs and calendar rolls

The local reconstruction follows `FixedTermHedgeCostService.php`: next full calendar month; calendar-day weights; one latest trade date strictly before the forecast date; month → quarter → year fallback; conversion factor **0.1255**. All **{s['hedge_reconstruction']['rows']} stored median hedge costs**, including both 27 July models, reconstruct within **{s['hedge_reconstruction']['maximum_absolute_error']:.10f} c/kWh** (asserted limit 0.000051).

At a month boundary, calculate both the old and new delivery windows on the **same new trade-date curve**. Their difference is the delivery-roll effect, including fallback choices for those windows. Separately, recalculate the old window across the old and new curves. That remainder can include **instrument selection changes as well as instrument price changes**. It is not pure same-instrument movement. This is an accounting decomposition, not proof of economic cause.

{roll_table}

All contributions in this table are c/kWh. Four-decimal rounding can prevent displayed columns from summing exactly. `month-boundary-decomposition.csv` retains more precision, trade dates, old/new start months and same-curve costs for all three terms.

The 6m hedge-cost roll is **+1.0980 on 1 July, +1.3134 on 1 August, and +0.3643 on 1 September**. The corresponding forecast effects are **+0.3294 / +0.3940 / +0.1093**. The largest adjacent 6m revision is 31 July → 1 August: **+0.4298**, with hedge contribution **+0.4605**, retail contribution **−0.0306**, and premium contribution **zero**. The same-curve roll contributes about **92% under this new-curve decomposition** (31 July settlements; the 31 July forecast used 30 July settlements). This is not a unique causal share: holding the old 30 July curve fixed gives a roll forecast contribution of **+0.3655**, about **85%**. The order of decomposition changes the attribution. The roll replaces an earlier delivery month with a later one, increasing winter exposure; it is not noise in one unchanged instrument. For example, the July-to-August forecast boundary shifts delivery from August–January to September–February.

### Fixed-delivery futures: real down and up moves

{futures_table}

The start is **12 June**, the last session before the review begins; the end is **11 September**, the last exported session. Returns are `100 × (new settlement / old settlement − 1)`. Adjacent-session metrics compare successive available observations **of the same instrument**. Seven-day metrics require a row exactly seven calendar days earlier for that same instrument inside this comparison sample. No carry forward, interpolation, cross-instrument substitution, or seven-session approximation is used. Mean/max absolute percentages describe return size, not signed direction. Each instrument has {next(iter(s['fixed_maturities'].values()))['observations']} observations; there are **5 unmatched seven-day endpoints per instrument**, also listed in `analysis-summary.json` (including early endpoints without an in-sample predecessor).

Q4 2026 specifically falls from **92.74 on 31 July to 83.27 on 28 August**, then rises to **102.37 on 11 September**. Thus some down/up movement is real for fixed delivery, not just a rolling basket. This export contains settlement values; **1,475 of 1,499 raw volume fields are null; 24 are present**. Sparse volume evidence does not establish market-wide liquidity, trading activity, market depth, or a news cause. No news explanation is claimed.

## Accuracy: not enough evidence for v2

{accuracy_table}

MAE is mean absolute forecast price error in c/kWh. The unchanged-price baseline predicts that the target-date retail price equals the forecast-date current price, using the **same evaluated rows and stored evaluation facts**, not a new join to current statistic rows. Current statistics differ from stored actuals on 3/2/1 evaluated rows for 6/12/24m; later table contents must not replace the frozen evaluation evidence. All 15 median evaluations per term are v1, from **13–27 June**, for target dates **13–27 July**. All three forecast MAEs are worse than the unchanged-price baseline in this small sample. Daily 30-day forecasts overlap, so these are not 15 independent trials.

No v2 median row has an evaluation. Matured but unevaluated exported rows as of **13 September**, with target date ≤ that date:

{matured_table}

These counts include only this exported forecast window, not the entire forecast table. The evaluator requires **observed_seller_data** on the exact target date. The exported statistics now use **canonical_calculation**. Do not fill absent evaluations with canonical values and call them comparable actuals. V2 starts from canonical retail while the current evaluator seeks observed targets; same-basis validation is required.

`direction_correct` is **thresholded direction-category accuracy**, not raw numerical sign accuracy. `directionCategory()` keeps rising and falling, but maps both slightly_rising and slightly_falling (and flat) to flat. No direction-accuracy percentage is reported here. This tiny, old-model, overlapping sample is insufficient to establish v2 accuracy or useful consumer timing advice.

## Proposals — no application changes made

1. **Repair and validate premium learning and same-basis evaluation first.** Establish which historical basis belongs to the model, and test correct continuation after 26 July. Preserve old forecast evidence. Do not silently relabel canonical values as observed actuals.
2. **Backtest comparable start and delivery windows.** Separate delivery rolls, source selection, fixed-instrument movement and retail changes. Measure forecast error and unchanged-price baselines on enough out-of-sample dates and market conditions.
3. **Use softer, uncertainty-aware copy.** Say “6-month contract: 30-day price outlook.” Explain that p20/p80 are market price quantiles, **not confidence bounds**. Avoid strong low-confidence lock advice near a threshold. Do not imply that an energy-price direction alone selects the best customer contract.
4. **Then test persistence or hysteresis**, with explicit trade-offs against responsiveness. Compare reduced signal changes with delayed useful warnings and forecast error. Do not hide real market movement merely to produce a smooth line.

## Reproduce and audit

Run from the repository root:

```sh
python3 tasks/forecast-stability-review/analyze.py
git diff --check
```

Python standard library only. No production access, Laravel boot, database access, dependency install, or app change is needed. The script reads only the complete local export and writes artifacts in this task directory. It verifies hashes/counts before generation and asserts key findings and hedge reconstruction. Outputs are deterministic; there is no run timestamp.

Artifacts:
- `all-original-forecasts.csv`: every original forecast field and all 810 rows; metadata stays a JSON string. CSV blanks represent JSON nulls; use original JSON for authoritative types.
- `daily-headline.csv`: full selected median current/forecast rows, 267 rows.
- `july27-dual-models.csv`: six median rows that preserve both model versions.
- `weekly-headline.csv`: signed weekly changes and signals, with actual chosen dates.
- `daily-revisions.csv`: all 264 adjacent-observation decompositions and gap flags.
- `month-boundary-decomposition.csv`: nine same-curve delivery-roll decompositions.
- `fixed-maturity-futures.csv`: original fields for the three fixed-delivery instruments plus adjacent and exact-seven-day returns.
- `analysis-summary.json`: verified provenance, counts, transitions, metrics, reconstruction error and evaluation coverage.
- `forecast-timeline.svg`: standalone chart with a text alternative and distinct line patterns.

### Source code references

These are local repository code references used to interpret the stored evidence, not proof of the exact deployed code on every past date:
- `laravel/app/Services/PriceForecasting/FixedTermHedgeCostService.php`: `calculate`, `latestTradeDateBefore`, `maturityForMonth`, `loadCurve`.
- `laravel/app/Services/PriceForecasting/FixedTermPriceForecastService.php`: forecast gap calculation, `historyPremiumEvidence`, `directionLabel`, `directionCategory`, `consumerSignal`, `confidenceLabel`.
- `laravel/app/Services/PriceForecasting/FixedTermForecastEvaluationService.php`: `evaluateMatured`, observed-target selection and category comparison.
- `laravel/app/Livewire/FixedContractPriceForecast.php` and `laravel/resources/views/livewire/fixed-contract-price-forecast.blade.php`: current page selection and display; the historical retail chart is not stored forecast-run history.
- `laravel/config/price_forecasting.php`: default model settings; stored metadata supplies the coefficients used in this analysis.

No recommendation above is implemented by this review. No production mutation was made.
'''
    (ROOT/'report.md').write_text(report)


if __name__ == '__main__':
    main()
