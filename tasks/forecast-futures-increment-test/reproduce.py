#!/usr/bin/env python3
"""Two deterministic runs, with source and input integrity checks."""
import sys
sys.dont_write_bytecode=True
import analyze as A
import subprocess
import json

def main():
    sources={p.name:A.S.digest(p) for p in A.HERE.glob('*.py')}
    pin=A.HERE/'source-hashes.json'
    if pin.exists(): assert json.loads(pin.read_text())==sources
    else: A.save('source-hashes.json',sources)
    before=A.hashes()
    outputs=('evidence.json','predictions.json','results.json','checks.json')
    runs=[]
    for _ in range(2):
        for script in ('analyze.py','verify.py'):
            subprocess.run([sys.executable,'-B',str(A.HERE/script)],check=True)
        runs.append({name:A.S.digest(A.HERE/name) for name in outputs})
        assert A.hashes()==before
    assert runs[0]==runs[1]
    assert sources=={p.name:A.S.digest(p) for p in A.HERE.glob('*.py')}
    A.save('reproducibility.json',dict(status='passed',runs=2,byte_identical_artifacts=runs[0],unchanged_input_hashes=before,source_hashes=sources))
    print('Two byte-identical runs; pinned inputs and sources unchanged')
if __name__=='__main__': main()
