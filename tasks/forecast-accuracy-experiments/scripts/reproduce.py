#!/usr/bin/env python3
"""Two complete offline runs; reject changed source files or output bytes."""
import sys
sys.dont_write_bytecode = True
import hashlib
import json
from pathlib import Path
import subprocess

HERE=Path(__file__).resolve().parents[1]
ROOT=HERE.parents[1]
ART=HERE/'report/artifacts'


def sha(p):
    return hashlib.sha256(p.read_bytes()).hexdigest()


def state():
    paths=sorted(p for p in ART.iterdir() if p.name!='reproducibility.json')
    paths.append(HERE/'report/report.md')
    return {str(p.relative_to(HERE)):dict(sha256=sha(p),bytes=p.stat().st_size) for p in paths}


def main():
    original={str(p.relative_to(ROOT)):sha(p) for p in (HERE.parent/'supplier-premium-coverage/export-20260913T094138Z').iterdir() if p.is_file()}
    first=None
    logs=[]
    for run in (1,2):
        for script in ('experiment.py','verify.py','report.py'):
            path=HERE/'scripts'/script
            result=subprocess.run([sys.executable,str(path)],cwd=ROOT,text=True,capture_output=True)
            logs.append(f'Run {run}: {path.relative_to(ROOT)} => exit {result.returncode}\n')
            if script=='verify.py' or result.returncode:
                logs.append(result.stdout+result.stderr)
            if result.returncode:
                (HERE/'report/verification.log').write_text('\n'.join(logs))
                raise SystemExit(result.returncode)
        if first is None:
            first=state()
        else:
            assert first==state(), 'Non-repeatable outputs'
        for p,h in original.items():
            assert sha(ROOT/p)==h, 'Original export changed'
    result=dict(status='passed',runs=2,byte_identical_outputs=first,preserved_export_hashes=original,
                verification=json.loads((ART/'verification.json').read_text()),
                python_version=sys.version.split()[0],
                git_head=subprocess.check_output(['git','rev-parse','HEAD'],cwd=ROOT,text=True).strip())
    (ART/'reproducibility.json').write_text(json.dumps(result,indent=2,sort_keys=True)+'\n')
    (HERE/'report/verification.log').write_text('\n'.join(logs))
    print(f'PASS: two runs; {len(first)} byte-identical outputs; {len(original)} unchanged export files.')


if __name__=='__main__': main()
