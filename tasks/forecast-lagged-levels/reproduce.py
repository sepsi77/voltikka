#!/usr/bin/env python3
"""Run only this task twice and check input/output hashes."""
import sys
sys.dont_write_bytecode = True
import subprocess
import analyze as a


def snapshot():
    paths=sorted(a.OUT.glob('*'))+[a.HERE/'report.md']
    return {str(p.relative_to(a.HERE)):dict(sha256=a.digest(p),bytes=p.stat().st_size) for p in paths if p.name!='reproducibility.json'}


def main():
    before={p:a.digest(a.ROOT/p) for p in a.load(a.HERE/'inputs.json')}
    a.sources()
    runs=[]
    for _ in range(2):
        for script in ('analyze.py','verify.py','report.py'):
            subprocess.run([sys.executable,str(a.HERE/script)],check=True,cwd=a.ROOT)
        runs.append(snapshot())
    assert runs[0]==runs[1]
    after={p:a.digest(a.ROOT/p) for p in before}
    assert before==after
    a.sources()
    result=dict(status='passed',runs=2,preserved_files=len(before),source_hashes_before=before,source_hashes_after=after,
                byte_identical_artifacts=runs[0],database='not used')
    a.save(a.OUT/'reproducibility.json',result)
    print(f"PASS: {len(runs[0])} byte-identical artifacts; {len(before)} pinned sources unchanged.")

if __name__=='__main__': main()
