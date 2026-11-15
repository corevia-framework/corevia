"""Explicit optional acceptance against an installed official Collector binary; no services installed."""
import argparse
import hashlib
import json
import os
from pathlib import Path
import signal
import socket
import subprocess
import tempfile
import time


def port():
    with socket.socket() as listener:
        listener.bind(('127.0.0.1', 0))
        return listener.getsockname()[1]


def main():
    if os.name != 'posix':
        raise SystemExit('Use a private Linux acceptance host for this process-group fixture.')
    parser = argparse.ArgumentParser()
    parser.add_argument('--binary', required=True)
    parser.add_argument('--source', default=str(Path(__file__).resolve().parents[2]))
    parser.add_argument('--output-root', required=True)
    parser.add_argument('--php', default='php')
    args = parser.parse_args()
    source = Path(args.source).resolve(strict=True)
    root = Path(args.output_root).resolve(strict=True)
    if not (root.is_relative_to(source / 'storage') or root.is_relative_to(Path('/var/www/corevia/.acceptance'))):
        raise ValueError('Use private Corevia acceptance storage.')
    binary = Path(args.binary).resolve(strict=True)
    version = subprocess.check_output([str(binary), '--version'], text=True, timeout=10).strip()
    os.umask(0o077)
    fixture = Path(tempfile.mkdtemp(prefix='collector-fixture-', dir=root))
    source_files = [source / 'autoload.php', *sorted((source / 'packages/core/src').glob('*.php')), *sorted((source / 'packages/http/src').glob('*.php'))]
    hashes = {str(p.relative_to(source)): hashlib.sha256(p.read_bytes()).hexdigest() for p in source_files}
    http_port = port()
    config = fixture / 'collector.json'
    config.write_text(json.dumps({
        'receivers': {'otlp': {'protocols': {'http': {'endpoint': f'127.0.0.1:{http_port}'}}}},
        'processors': {'memory_limiter': {'check_interval': '1s', 'limit_mib': 64, 'spike_limit_mib': 16}},
        'exporters': {'debug': {'verbosity': 'detailed'}},
        'service': {
            'telemetry': {'logs': {'level': 'info', 'encoding': 'json'}, 'metrics': {'level': 'none', 'readers': []}},
            'pipelines': {kind: {'receivers': ['otlp'], 'processors': ['memory_limiter'], 'exporters': ['debug']} for kind in ['traces', 'metrics']},
        },
    }), encoding='utf-8')
    env = dict(os.environ, GOMEMLIMIT='96MiB', GOMAXPROCS='1')
    for key in list(env):
        if key.startswith(('APP_', 'AUTH_', 'AI_', 'COREVIA_', 'MODEL_')):
            env.pop(key)
    subprocess.run([str(binary), 'validate', '--config=' + str(config)], env=env, check=True, capture_output=True, timeout=15)
    report = {'passed': False, 'collector_version': version, 'binary_sha256': hashlib.sha256(binary.read_bytes()).hexdigest(), 'source_hashes': hashes, 'release_ready': False}
    child = None
    try:
        with (fixture / 'collector.log').open('wb') as log:
            child = subprocess.Popen([str(binary), '--config=' + str(config)], env=env, stdout=log, stderr=log, start_new_session=True)
            for attempt in range(100):
                if child.poll() is not None:
                    raise AssertionError('Collector exited; inspect private fixture log.')
                try:
                    with socket.create_connection(('127.0.0.1', http_port), timeout=0.1):
                        break
                except OSError:
                    time.sleep(0.05)
            else:
                raise AssertionError('Collector startup timed out.')
            command = [args.php, str(Path(__file__).with_name('collector-export.php')), str(source), f'http://127.0.0.1:{http_port}']
            export = subprocess.run(command, env=env, capture_output=True, text=True, timeout=10)
            if export.returncode != 0:
                raise AssertionError('Exporter failed: ' + export.stderr[-2000:])
            report['export'] = json.loads(export.stdout)
            for attempt in range(50):
                contents = (fixture / 'collector.log').read_text(encoding='utf-8')
                if all(name in contents for name in ['fixture.request', 'fixture.query', 'fixture.failure', 'corevia.operations', 'corevia.operation.duration']):
                    break
                time.sleep(0.05)
            else:
                raise AssertionError('Actual Collector did not expose all expected spans and metrics.')
            assert 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa' in contents
            assert 'PRIVATE_TELEMETRY_SENTINEL' not in contents
            os.killpg(child.pid, signal.SIGTERM)
            child.wait(timeout=10)
            outage = subprocess.run(command + ['outage'], env=env, capture_output=True, text=True, timeout=10)
            if outage.returncode != 0:
                raise AssertionError('Outage contract failed: ' + outage.stderr[-2000:])
            report['outage'] = json.loads(outage.stdout)
            report['source_unchanged'] = hashes == {str(p.relative_to(source)): hashlib.sha256(p.read_bytes()).hexdigest() for p in source_files}
            report['passed'] = report['source_unchanged']
    finally:
        if child is not None and child.poll() is None:
            os.killpg(child.pid, signal.SIGTERM)
            try:
                child.wait(timeout=10)
            except subprocess.TimeoutExpired:
                os.killpg(child.pid, signal.SIGKILL); child.wait(timeout=5)
        report['owned_process_stopped'] = child is None or child.poll() is not None
        report['test_sha256'] = {p.name: hashlib.sha256(p.read_bytes()).hexdigest() for p in [Path(__file__), Path(__file__).with_name('collector-export.php')]}
        (fixture / 'report.json').write_text(json.dumps(report, indent=2) + '\n', encoding='utf-8')
    print(json.dumps({'fixture': str(fixture), **report}))


if __name__ == '__main__':
    main()
