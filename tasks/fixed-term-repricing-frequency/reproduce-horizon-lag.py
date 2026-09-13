#!/usr/bin/env python3
"""Run both independent checks twice and retain byte-reproducibility proof here."""
import hashlib
import json
from pathlib import Path
import subprocess
import sys

HERE = Path(__file__).resolve().parent
ROOT = HERE.parent.parent

def digest(path):
    raw = path.read_bytes()
    return dict(bytes=len(raw), sha256=hashlib.sha256(raw).hexdigest())

def run(args, log):
    with (HERE/log).open('w') as f:
        result = subprocess.run(args, cwd=ROOT, stdout=f, stderr=subprocess.STDOUT)
    assert result.returncode == 0, f'{args}: see {log}'

def machine_results():
    return {p.name:digest(p) for p in sorted(HERE.glob('horizon-lag-*'))
            if p.suffix in ('.csv','.json') and p.name!='horizon-lag-reproducibility.json'}

run(['php','-l',str(HERE/'verify-horizon-lag-php.php')], 'horizon-lag-php-lint.log')
first = None
for _ in range(2):
    run([sys.executable,str(HERE/'horizon-lag-analysis.py')], 'horizon-lag-analysis.log')
    run([sys.executable,str(HERE/'verify-horizon-lag.py')], 'horizon-lag-independent.log')
    run(['php','-d','memory_limit=512M',str(HERE/'verify-horizon-lag-php.php')], 'horizon-lag-php.log')
    current = machine_results()
    if first is not None:
        assert first == current, 'Non-reproducible machine artifact'
    first = current
run([sys.executable,str(HERE/'verify.py')], 'horizon-lag-cadence-check.log')
original = json.loads((HERE/'artifact-manifest.json').read_text())
preserved = {}
for name, expected in original.items():
    # Task tracking and reports have authorized continuation updates; cadence science does not.
    if name in ('spec.md','decisions.md','tasks.json','report.md'):
        continue
    actual = digest(HERE/name)
    assert actual == expected, f'Changed cadence artifact: {name}'
    preserved[name] = actual
sources = {name:digest(HERE/name) for name in ('horizon-lag-analysis.py','verify-horizon-lag.py',
           'verify-horizon-lag-php.php','reproduce-horizon-lag.py','horizon-lag-report.md')}
for name in ('replay.php','replay-results.json','replay-results.csv'):
    sources['../fix-forecast-learning-evaluation/'+name] = digest(HERE.parent/'fix-forecast-learning-evaluation'/name)
result = dict(status='passed',runs=2,byte_identical_machine_artifacts=first,
              preserved_cadence_artifacts=preserved,source_hashes=sources,
              actual_checks=json.loads((HERE/'horizon-lag-php-checks.json').read_text()),
              independent_checks=json.loads((HERE/'horizon-lag-independent-checks.json').read_text()))
(HERE/'horizon-lag-reproducibility.json').write_text(json.dumps(result,indent=2)+'\n')
print(f'PASS: two complete runs; {len(first)} byte-identical machine artifacts; {len(preserved)} unchanged cadence artifacts; original cadence verifier passed.')
