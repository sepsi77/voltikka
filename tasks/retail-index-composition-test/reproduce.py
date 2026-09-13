#!/usr/bin/env python3
"""Repeat analysis and independent checks without writing to another task."""
import hashlib
import json
from pathlib import Path
import subprocess
import sys

HERE = Path(__file__).resolve().parent
ROOT = HERE.parent.parent


def hashes():
    return {p.name: dict(bytes=p.stat().st_size, sha256=hashlib.sha256(p.read_bytes()).hexdigest())
            for p in sorted(HERE.iterdir()) if p.suffix in ('.csv', '.json') and p.name not in ('reproducibility.json', 'tasks.json')}


first = None
for iteration in range(2):
    for script in ('analysis.py', 'verify.py'):
        result = subprocess.run([sys.executable, '-B', str(HERE/script)], cwd=ROOT, capture_output=True, text=True)
        (HERE/(script[:-3] + '.log')).write_text(result.stdout + result.stderr)
        assert result.returncode == 0, result.stdout + result.stderr
    current = hashes()
    if first is not None:
        assert current == first, 'Changed output on identical inputs'
    first = current
result = dict(status='PASS', runs=2, artifacts=first, scripts={p.name: hashlib.sha256(p.read_bytes()).hexdigest() for p in sorted(HERE.glob('*.py'))})
(HERE/'reproducibility.json').write_text(json.dumps(result, indent=2, sort_keys=True)+'\n')
print(f'PASS: two runs; {len(first)} byte-identical artifacts; independent checks passed twice.')
