#!/usr/bin/env python3
"""Run only new writers twice; verify deterministic bytes and preserved sources."""
import sys
sys.dont_write_bytecode = True
import subprocess
import analyze as a


def run():
    for script in ('analyze.py','verify.py','report.py'):
        subprocess.run([sys.executable,str(a.HERE/script)],cwd=a.ROOT,check=True)
    paths=sorted(p for p in a.OUT.iterdir() if p.name!='reproducibility.json')+[a.HERE/'report.md']
    return {str(p.relative_to(a.HERE)):a.digest(p) for p in paths}


def main():
    before=a.check_sources()
    first=run();second=run()
    assert first==second
    assert a.check_sources()==before
    a.save(a.OUT/'reproducibility.json',dict(status='passed',runs=2,failed=0,identical_outputs=len(first),
           preserved_files=before,outputs=first,spec_sha256=a.digest(a.HERE/'spec.md'),
           script_hashes={p:a.digest(a.HERE/p) for p in ('analyze.py','verify.py','report.py','reproduce.py')}))
    print(f'PASS: two runs; {len(first)} identical outputs; {before} preserved files.')

if __name__=='__main__': main()
