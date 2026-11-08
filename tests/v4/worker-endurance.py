"""Optional bounded local soak and actual killed-worker lease recovery; private fixture DB only."""
import argparse
import hashlib
import json
import os
from pathlib import Path
import shutil
import subprocess
import time

ROOT = Path(__file__).resolve().parents[2]


def main():
    parser = argparse.ArgumentParser()
    parser.add_argument('--output', required=True)
    parser.add_argument('--seconds', type=int, default=60)
    args = parser.parse_args()
    if not 10 <= args.seconds <= 1800:
        raise ValueError('Choose 10..1800 soak seconds.')
    output = Path(args.output)
    if not output.is_absolute(): output = ROOT / output
    storage = (ROOT / 'storage').resolve()
    if output.exists() or output.is_symlink() or output.resolve() == storage or not output.resolve().is_relative_to(storage):
        raise ValueError('Use a new private directory inside storage.')
    for parent in output.parents:
        if parent.is_symlink(): raise ValueError('Output may not traverse symlinks.')
        if parent == ROOT: break
    os.umask(0o077);output.mkdir(mode=0o700, parents=True)
    php = shutil.which('php')
    if not php: raise RuntimeError('Actual PHP with SQLite is required.')
    files = [ROOT / 'autoload.php', *sorted((ROOT/'packages/core/src').glob('*.php')), *sorted((ROOT/'packages/persistence-pdo/src').glob('*.php')), ROOT/'packages/cli/src/WorkerLoop.php', *sorted((ROOT/'tests/v4').glob('worker-*'))]
    hashes = {str(p.relative_to(ROOT)):hashlib.sha256(p.read_bytes()).hexdigest() for p in files if p.is_file()}
    env = os.environ.copy()
    for key in list(env):
        if key.startswith(('APP_', 'AUTH_', 'AI_', 'COREVIA_', 'MODEL_')):env.pop(key)
    report = {'passed':False,'source_hashes':hashes,'release_ready':False}
    victim = None

    def run(script, arguments, timeout=30):
        result = subprocess.run([php, str(ROOT/'tests/v4'/script), *arguments], cwd=ROOT, env=env, capture_output=True, text=True, timeout=timeout)
        if result.returncode:raise RuntimeError('Fixture failed: '+result.stderr[-2000:])
        return json.loads(result.stdout)

    try:
        report['soak'] = run('worker-soak.php', [str(args.seconds)], args.seconds+60)
        crash = output/'crash';crash.mkdir(mode=0o700)
        setup = run('worker-crash.php', [str(crash), 'setup'])
        with (output/'victim.log').open('wb') as log:
            victim = subprocess.Popen([php,str(ROOT/'tests/v4/worker-crash.php'),str(crash),'victim'],cwd=ROOT,env=env,stdout=log,stderr=log)
            marker = crash/'claim.json'
            for attempt in range(100):
                if victim.poll() is not None:raise RuntimeError('Victim exited before test kill.')
                if marker.exists():break
                time.sleep(0.05)
            else:raise RuntimeError('Victim claim timed out.')
            claim = json.loads(marker.read_text())
            assert claim['id'] == setup['id'] and claim['attempt'] == 1
            victim.kill();victim.wait(timeout=5)
        # Wait for the real DB-clock lease; no timestamp edits or simulated expiration.
        wait = claim['expires'] - time.time() + 0.2
        if not -1 <= wait <= 11:raise RuntimeError('Unexpected fixture lease clock.')
        if wait > 0:time.sleep(wait)
        recovered = run('worker-crash.php', [str(crash),'recover'])
        assert recovered['result']['status']=='completed' and recovered['result']['id']==setup['id']
        assert recovered['effects']==1 and recovered['queue']=={'pending':0,'running':0,'done':1,'dead':0}
        assert not recovered['victim_cleanup_ran'] and recovered['replacement_cleanup_ran']
        report['crash_recovery']={**recovered,'real_lease_expiration':True,'killed_owned_process':True}
        report['source_unchanged']=hashes=={name:hashlib.sha256((ROOT/name).read_bytes()).hexdigest() for name in hashes}
        report['passed']=report['source_unchanged'] and report['soak']['passed']
    finally:
        if victim is not None and victim.poll() is None:victim.kill();victim.wait(timeout=5)
        report['owned_process_stopped']=victim is None or victim.poll() is not None
        (output/'report.json').write_text(json.dumps(report,indent=2)+'\n',encoding='utf-8')
    print(json.dumps({k:v for k,v in report.items() if k!='source_hashes'}))


if __name__=='__main__':main()
