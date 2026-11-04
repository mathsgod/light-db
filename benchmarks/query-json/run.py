#!/usr/bin/env python3
"""Compare committed v1 with v2 User::Query()->toArray() -> JSON."""
import hashlib
import json
import os
from pathlib import Path
import random
import statistics
import subprocess
import sys
import tempfile

bench=Path(__file__).resolve().parent
root=bench.parent.parent
old=Path(os.environ.get('LIGHT_DB_OLD_ROOT',root.parent/'light-db')).resolve()
rounds=int(sys.argv[1]) if len(sys.argv)>1 else 30
rows=int(sys.argv[2]) if len(sys.argv)>2 else 10000
old_ref=sys.argv[3] if len(sys.argv)>3 else 'main'
baseline_ref=sys.argv[4] if len(sys.argv)>4 else None
variants=['old','baseline','new'] if baseline_ref else ['old','new']
if rounds<3 or not 1<=rows<=1000000: raise SystemExit('Use >=3 rounds and 1..1000000 rows')
env=dict(os.environ,BENCH_ROWS=str(rows),LIGHT_DB_OLD_ROOT=str(old))
env.setdefault('LIGHT_DB_QUERY_BENCH_PORT','33391')
for vendor in [old/'vendor/autoload.php',root/'vendor/autoload.php']:
    if not vendor.exists(): raise SystemExit(f'Install dependencies first: {vendor}')
compose=['docker','compose','-p','light-db-query-json-bench','-f',str(bench/'compose.yaml')]
samples=[]
def run(command,**kwargs): return subprocess.check_output(command,text=True,env=env,**kwargs)
try:
    subprocess.run(compose+['up','-d','--wait','--wait-timeout','150'],check=True,env=env)
    seed=json.loads(run(['php',str(bench/'benchmark.php'),'seed']))
    print(json.dumps(seed),flush=True)
    with tempfile.TemporaryDirectory(prefix='light-db-v1-source-') as tmp:
        source=Path(tmp)
        for path in run(['git','ls-tree','-r','--name-only',old_ref,'--','src'],cwd=old).splitlines():
            target=source/Path(path).relative_to('src')
            target.parent.mkdir(parents=True,exist_ok=True)
            target.write_text(run(['git','show',f'{old_ref}:{path}'],cwd=old))
        env['LIGHT_DB_OLD_SOURCE']=str(source)
        if baseline_ref:
            baseline=source/'v2-baseline'
            for path in run(['git','ls-tree','-r','--name-only',baseline_ref,'--','src'],cwd=root).splitlines():
                target=baseline/Path(path).relative_to('src')
                target.parent.mkdir(parents=True,exist_ok=True)
                target.write_text(run(['git','show',f'{baseline_ref}:{path}'],cwd=root))
            env['LIGHT_DB_BASELINE_SOURCE']=str(baseline)
        rng=random.Random(20261104)
        for i in range(rounds):
            cases=[(v,m,w) for v in variants for m in ['cold','warm'] for w in ['plain','json']]
            rng.shuffle(cases)
            for v,m,w in cases:
                sample=json.loads(run(['php','-d','opcache.enable_cli=0',str(bench/'benchmark.php'),'worker',v,m,w]))
                sample['round']=i; samples.append(sample)
            if (i+1)%5==0: print(f'Completed {i+1}/{rounds} rounds',flush=True)
    for workload in ['plain','json']:
        if len({s['checksum'] for s in samples if s['workload']==workload})!=1:
            raise RuntimeError(f'JSON output mismatch: {workload}')
    summary=[]
    for workload in ['plain','json']:
        for mode in ['cold','warm']:
            for variant in variants:
                group=[s for s in samples if (s['workload'],s['mode'],s['variant'])==(workload,mode,variant)]
                row=dict(workload=workload,mode=mode,variant=variant,
                    median_ms=statistics.median(s['total_ms'] for s in group),
                    median_fetch_ms=statistics.median(s['fetch_ms'] for s in group),
                    median_json_ms=statistics.median(s['json_ms'] for s in group),
                    retained_mib=statistics.median(s['retained_bytes'] for s in group)/1048576,
                    peak_mib=statistics.median(s['peak_bytes'] for s in group)/1048576,
                    sql_calls=statistics.median(s['sql_calls'] for s in group))
                summary.append(row); print(json.dumps(row),flush=True)
    result=dict(rows=rows,rounds=rounds,php=run(['php','-r','echo PHP_VERSION;']).strip(),mysql=seed['mysql'],
        baseline_ref=run(['git','rev-parse',baseline_ref],cwd=root).strip() if baseline_ref else None,
        old_ref=run(['git','rev-parse',old_ref],cwd=old).strip(),new_ref=run(['git','rev-parse','HEAD'],cwd=root).strip(),
        new_source_sha256={p.name:hashlib.sha256(p.read_bytes()).hexdigest() for p in sorted((root/'src').glob('*.php'))},
        notes='Query()->toArray() then json_encode Models. Plain and JSON-column fixtures. Connection/autoload excluded. Fresh processes, CLI OPcache off; warm fully queries/serializes once before timing. v1 source archived from Git, no production checkout modifications. SQL counters include metadata. No writes timed.',
        summary=summary,samples=samples)
    output=bench/(f'results-ab-{rows}.json' if baseline_ref else f'results-{rows}.json')
    output.write_text(json.dumps(result,indent=2)+'\n')
    print(f'Results: {output}',flush=True)
finally:
    subprocess.run(compose+['down','--volumes'],check=True,env=env)
