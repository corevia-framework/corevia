"""Local V4 evidence runner. Passing fixtures never grants provider/hosting acceptance."""
import argparse
import hashlib
import json
import os
from pathlib import Path
import shutil
import subprocess
import sys
import time

ROOT = Path(__file__).resolve().parent.parent


def source():
    unstaged = subprocess.run(['git', '-c', f'safe.directory={ROOT.as_posix()}', 'diff', '--quiet'], cwd=ROOT)
    if unstaged.returncode:
        raise ValueError('Stage intended tracked source changes before acceptance; packages are built from the Git index.')
    result = subprocess.run(['git', '-c', f'safe.directory={ROOT.as_posix()}', 'ls-files', '-z'], cwd=ROOT, capture_output=True, check=True)
    files = {}
    for name in sorted(set(result.stdout.decode('utf-8').split('\0')) - {''}):
        path = ROOT / name
        if path.is_symlink() or not path.is_file():
            raise ValueError('Acceptance requires regular existing indexed source: ' + name)
        files[name] = hashlib.sha256(path.read_bytes()).hexdigest()
    encoded = json.dumps(files, sort_keys=True, separators=(',', ':')).encode()
    return {'sha256': hashlib.sha256(encoded).hexdigest(), 'files': files}


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--output', required=True, help='New private directory under repository storage/')
    parser.add_argument('--browser', action='store_true')
    parser.add_argument('--auth-ui', action='store_true', help='Rebuild/verify assets and test all auth stacks, locales, themes and storage modes')
    parser.add_argument('--passkeys', action='store_true', help='Install/audit locked optional test dependencies and run real WebAuthn virtual-authenticator tests')
    parser.add_argument('--services', action='store_true', help='Explicit disposable SQL service tests; never reads application credentials')
    args = parser.parse_args()
    target = Path(args.output)
    if not target.is_absolute():
        target = ROOT / target
    storage = (ROOT / 'storage').resolve()
    resolved = target.resolve()
    if resolved == storage or not resolved.is_relative_to(storage) or target.exists() or target.is_symlink():
        raise ValueError('Use a new directory strictly inside repository storage/.')
    for parent in target.parents:
        if parent.is_symlink():
            raise ValueError('Acceptance output cannot traverse symlinks.')
        if parent == ROOT:
            break
    baseline = source()
    target.mkdir(parents=True, mode=0o700)
    php = shutil.which('php')
    composer = shutil.which('composer')
    if not php or not composer:
        raise RuntimeError('PHP and Composer must be on PATH.')
    composer_phar = Path(composer).parent / 'composer.phar'
    composer_command = [php, str(composer_phar)] if composer_phar.exists() else [composer]
    env = os.environ.copy()
    count = int(env.get('GIT_CONFIG_COUNT', '0'))
    env.update({'GIT_CONFIG_COUNT': str(count + 1), f'GIT_CONFIG_KEY_{count}': 'safe.directory', f'GIT_CONFIG_VALUE_{count}': ROOT.as_posix(), 'APP_ENV': 'development', 'COREVIA_DB_TESTS': '0'})
    checks = [
        ('composer-validate', composer_command + ['validate', '--strict'], {}),
        ('php-lint', composer_command + ['lint'], {}),
        ('php-regression', composer_command + ['test'], {}),
        ('sqlite-concurrency', [sys.executable, 'tests/v4/concurrency.py'], {}),
        ('cross-runtime-budgets', [sys.executable, 'tests/v4/cross-runtime.py'], {}),
        ('outbound-http-budgets', [sys.executable, 'tests/v4/outbound-http.py'], {}),
        ('architecture', [sys.executable, 'tools/architecture.py'], {}),
        ('v3-api-compatibility', [sys.executable, 'tests/v4/compatibility.py'], {}),
        ('v3-backup-restoration', [sys.executable, 'tests/v4/persistence.py'], {}),
        ('distribution-upgrades', [sys.executable, 'tests/v4/phase-b.py'], {}),
        ('javascript-sdk', ['node', '--test', *[p.relative_to(ROOT).as_posix() for p in sorted((ROOT / 'sdk/js/test').glob('*.test.js'))]], {}),
        ('python-sdk', [sys.executable, '-m', 'unittest', 'discover', '-s', 'sdk/python/tests'], {'PYTHONPATH': 'sdk/python'}),
    ]
    if args.browser:
        browser_package = target / 'browser-distribution'
        browser_app = browser_package / 'application'
        checks.extend([
            ('workspace-package', [sys.executable, 'tools/v4-package.py', '--output=' + str(browser_package)], {}),
            ('workspace-install', composer_command + ['--working-dir=' + str(browser_app), 'install', '--no-interaction', '--no-scripts', '--no-plugins'], {'COMPOSER_ROOT_VERSION': 'dev-v4'}),
            ('workspace-browser', ['node', 'tests/v4/workspace-browser.mjs', str(browser_app)], {}),
        ])
    if args.services:
        for suite in ['service-contracts']:
            checks.append(('services-' + suite, [php, 'tests/v4/' + suite + '.php'], {'COREVIA_DB_TESTS': '1'}))
    if args.auth_ui:
        checks.append(('auth-assets', ['node', 'tools/build-auth-starters.mjs', '--check'], {}))
        for name, sql, crypto, accessibility in [('auth-json-accessibility', '0', '0', '1'), ('auth-json-identity', '0', '1', '0'), ('auth-sql-identity', '1', '1', '0')]:
            checks.append((name, ['node', 'tests/v3/auth-browser.mjs'], {'COREVIA_AUTH_STACK': '', 'COREVIA_AUTH_SQL': sql, 'COREVIA_AUTH_CRYPTO': crypto, 'COREVIA_AUTH_A11Y': accessibility}))
    if args.passkeys:
        optional = ROOT / 'tests/v4/passkey-dependencies'
        for name, command in [('passkey-dependencies', ['install', '--no-interaction', '--no-scripts', '--no-plugins', '--prefer-dist']),
                              ('passkey-metadata', ['validate', '--strict']), ('passkey-advisories', ['audit', '--locked'])]:
            checks.append((name, composer_command + ['--working-dir=' + str(optional), *command], {}))
        checks.append(('passkey-browser', ['node', 'tests/v4/passkey-browser.mjs'], {'COREVIA_PASSKEY_AUTOLOAD': str(optional / 'vendor/autoload.php')}))
    report = {'format': 1, 'source': baseline, 'checks': [], 'release_ready': False,
              'runtimes': {'python': sys.version.split()[0], 'php': subprocess.run([php, '-r', 'echo PHP_VERSION;'], capture_output=True, text=True, check=True).stdout,
                           'node': subprocess.run(['node', '--version'], capture_output=True, text=True, check=True).stdout.strip()},
              'unmet_release_gates': [
                  'Phase D full application identity/audit/data-lifecycle integration, accessibility/manual review and real provider/service/device acceptance',
                  'Phase E trace/metric export, cross-runtime resilience, full delivery operations and hosting acceptance',
                  'Phase F ingestion/vector-provider acceptance, tool isolation/evaluations and mobile SDK workflows',
                  'Required remote CI, supported services/runtimes, security review and worker soak/load acceptance',
                  'Real shared/VPS/AWS/Azure hosting acceptance and final Composer package publication',
              ]}
    for name, command, overrides in checks:
        started = time.monotonic()
        try:
            with (target / (name + '.log')).open('wb') as log:
                completed = subprocess.run(command, cwd=ROOT, env={**env, **overrides}, stdout=log, stderr=subprocess.STDOUT, timeout=600)
            entry = {'name': name, 'exit_code': completed.returncode}
        except (OSError, subprocess.TimeoutExpired) as error:
            entry = {'name': name, 'exit_code': -1, 'error_type': type(error).__name__}
        entry['seconds'] = round(time.monotonic() - started, 3)
        report['checks'].append(entry)
        print(('PASS ' if entry['exit_code'] == 0 else 'FAIL ') + name, flush=True)
    report['source_unchanged'] = source() == baseline
    report['local_passed'] = report['source_unchanged'] and all(check['exit_code'] == 0 for check in report['checks'])
    (target / 'report.json').write_text(json.dumps(report, indent=2) + '\n', encoding='utf-8')
    print('Private evidence: ' + str(target / 'report.json'), flush=True)
    print('Release acceptance remains incomplete; see unmet_release_gates.', flush=True)
    return 0 if report['local_passed'] else 1


if __name__ == '__main__':
    try:
        raise SystemExit(main())
    except (ValueError, RuntimeError, subprocess.CalledProcessError) as error:
        print(str(error), file=sys.stderr)
        raise SystemExit(1)
