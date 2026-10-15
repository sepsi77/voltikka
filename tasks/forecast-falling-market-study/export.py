"""Extract frozen study inputs from the local SQLite copy. Usage: python3 -I export.py <sqlite> <outdir>"""
import csv, hashlib, json, os, sqlite3, sys

db = sqlite3.connect(f"file:{sys.argv[1]}?mode=ro", uri=True)
out = sys.argv[2]
queries = {
    'retail.csv': """select id, substr(stat_date,1,10) stat_date, segment_key, pricing_basis, p20_value, median_value, p80_value, contract_count
        from contract_price_daily_statistics
        where method_version='unit_statistics_v1' and metric_key='energy_price' and consumption_kwh is null
          and segment_key in ('fixed_term_6','fixed_term_12','fixed_term_24') order by id""",
    'futures.csv': """select id, substr(trade_date,1,10) trade_date, maturity_type, maturity, settlement_price
        from electricity_futures_eod_prices
        where area='FI' and product='Base' and maturity_type in ('month','quarter','year') order by id""",
}
pins = {}
for name, sql in queries.items():
    cur = db.execute(sql)
    path = os.path.join(out, name)
    with open(path, 'w', newline='') as f:
        w = csv.writer(f, lineterminator='\n')
        w.writerow([d[0] for d in cur.description])
        n = 0
        for row in cur:
            w.writerow(row); n += 1
    pins[name] = {'rows': n, 'sha256': hashlib.sha256(open(path, 'rb').read()).hexdigest()}
print(json.dumps(pins, indent=2))
