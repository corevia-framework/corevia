"""Opt-in smoke tests of installed, local Ollama models. No downloads, cloud calls or user conversations."""
import argparse
import hashlib
import json
import os
from pathlib import Path
import shutil
import subprocess
import urllib.request

ROOT = Path(__file__).resolve().parents[2]


def get(path):
    opener = urllib.request.build_opener(urllib.request.ProxyHandler({}))
    with opener.open('http://127.0.0.1:11434' + path, timeout=5) as response:
        wire = response.read(1048577)
        if len(wire) > 1048576:raise ValueError('Local model metadata exceeds bound.')
        return json.loads(wire)


def main():
    parser=argparse.ArgumentParser()
    parser.add_argument('--output',required=True)
    parser.add_argument('--stream-only',action='store_true',help='Run only bounded streaming; lifecycle remains a separate explicit check')
    parser.add_argument('--max-tokens',type=int,default=8)
    parser.add_argument('--models',nargs='+',required=True)
    args=parser.parse_args()
    output=Path(args.output)
    if not output.is_absolute():output=ROOT/output
    storage=(ROOT/'storage').resolve()
    if output.exists() or output.is_symlink() or output.resolve()==storage or not output.resolve().is_relative_to(storage):raise ValueError('Choose a new directory inside private storage.')
    for parent in output.parents:
        if parent.is_symlink():raise ValueError('Output may not traverse symlinks.')
        if parent==ROOT:break
    if not 1<=args.max_tokens<=64:raise ValueError('Choose 1..64 smoke-test tokens.')
    tags=get('/api/tags');installed={x['name']:x for x in tags['models']}
    for name in args.models:
        if name not in installed or name.endswith((':cloud','-cloud')) or installed[name].get('remote_host') or installed[name].get('remote_model') or installed[name].get('size',0)<1048576:raise ValueError('Select only an already-installed local model.')
    if len(args.models)>8 or len(set(args.models))!=len(args.models):raise ValueError('Select 1..8 distinct local models.')
    php=shutil.which('php')
    if not php:raise RuntimeError('PHP is required.')
    os.umask(0o077);output.mkdir(mode=0o700,parents=True)
    paths=[ROOT/'autoload.php',*sorted((ROOT/'packages/ai/src').glob('*.php')),*sorted((ROOT/'packages/http/src').glob('*.php')),*sorted((ROOT/'packages/core/src').glob('*.php')),*sorted((ROOT/'packages/protocol/src').glob('*.php')),Path(__file__),Path(__file__).with_name('local-model.php')]
    hashes={p.relative_to(ROOT).as_posix():hashlib.sha256(p.read_bytes()).hexdigest() for p in paths}
    report={'passed':False,'ollama':get('/api/version'),'checks':[],'source_hashes':hashes,'release_ready':False,'scope':'installed local model interoperability; application-specific model quality remains user-owned'}
    for index,name in enumerate(args.models):
        command=[php,str(ROOT/'tests/v4/local-model.php'),name]
        command += ['--lifecycle' if index==0 and not args.stream_only else '--stream',str(args.max_tokens)]
        try:
            result=subprocess.run(command,cwd=ROOT,capture_output=True,text=True,timeout=390)
            (output/f'model-{index}.log').write_text(result.stdout+result.stderr,encoding='utf-8')
            entry={'model':name,'digest':installed[name].get('digest'),'exit_code':result.returncode}
            if result.stdout.strip():
                try:entry['result']=json.loads(result.stdout)
                except ValueError:entry['result']={'passed':False,'failure':'INVALID_FIXTURE_RESULT'}
        except subprocess.TimeoutExpired:
            entry={'model':name,'digest':installed[name].get('digest'),'exit_code':-1,'result':{'passed':False,'failure':'FIXTURE_PROCESS_TIMEOUT'}}
            (output/f'model-{index}.log').write_text('Bounded fixture process timed out.\n',encoding='utf-8')
        entry['passed']=entry['exit_code']==0 and isinstance(entry.get('result'),dict) and entry['result'].get('passed') is True
        report['checks'].append(entry)
        print(('PASS 'if entry['passed']else 'FAIL ')+name,flush=True)
        (output/'report.json').write_text(json.dumps(report,indent=2)+'\n',encoding='utf-8')
    report['source_unchanged']=hashes=={name:hashlib.sha256((ROOT/name).read_bytes()).hexdigest() for name in hashes}
    report['passed']=report['source_unchanged'] and all(x['passed']for x in report['checks'])
    (output/'report.json').write_text(json.dumps(report,indent=2)+'\n',encoding='utf-8')
    raise SystemExit(0 if report['passed']else 1)


if __name__=='__main__':main()
