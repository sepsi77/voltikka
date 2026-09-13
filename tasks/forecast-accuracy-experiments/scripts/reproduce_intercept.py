#!/usr/bin/env python3
"""Repeat only the additive diagnostic; preserve all pinned original bytes."""
import sys
sys.dont_write_bytecode = True
import subprocess
import intercept_experiment as a


def state():
    paths = [p for p in a.OUT.iterdir() if p.name!='reproducibility.json']
    paths.append(a.HERE/'report/intercept-report.md')
    return {str(p.relative_to(a.HERE)):a.x.digest(p) for p in sorted(paths)}


def main():
    a.check_inputs()
    first = None
    for run in (1,2):
        for script in ('intercept_experiment.py','verify_intercept.py'):
            subprocess.run([sys.executable,'-B',str(a.HERE/'scripts'/script)],cwd=a.x.ROOT,check=True)
        current = state()
        if first is None: first = current
        else: assert current==first, 'New outputs differ between runs'
        a.check_inputs()
    a.save('reproducibility.json',dict(status='passed',runs=2,byte_identical_outputs=first,
           preserved_originals=a.load(a.HERE/'intercept-inputs.json'),
           preserved_original_sources=a.load(a.x.ART/'sources.json'),
           verification=a.load(a.OUT/'verification.json'),python_version=sys.version.split()[0]))
    print(f'PASS: two runs; {len(first)} identical additive outputs; all pinned originals and sources unchanged.')


if __name__=='__main__':
    main()
