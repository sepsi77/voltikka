#!/usr/bin/env python3
"""Run only this task's writers twice and check every output byte."""
import sys
sys.dont_write_bytecode = True
import os
import subprocess
import analyze as a


def main():
    before = a.sources()
    runs = []
    for _ in range(2):
        for script in ('analyze.py','verify.py','report.py'):
            subprocess.run([sys.executable,str(a.HERE/script)],cwd=a.ROOT,env=dict(os.environ,PYTHONDONTWRITEBYTECODE='1'),check=True)
        paths = sorted(a.OUT.glob('*'))+[a.HERE/'report.md']
        runs.append({str(p.relative_to(a.HERE)):a.digest(p) for p in paths if p.is_file()})
        a.sources()
    assert runs[0]==runs[1], 'Output bytes differ'
    a.save(a.HERE/'reproducibility.json',dict(status='passed',runs=2,artifacts=runs[0],preserved_inputs=before,
        spec_sha256=a.digest(a.HERE/'spec.md'),source_hashes=a.load(a.HERE/'source-hashes.json')))
    print(f'Reproduction passed: {len(runs[0])} byte-identical artifacts, {before} pinned inputs unchanged')

if __name__=='__main__': main()
