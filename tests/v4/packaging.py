"""Exercise detached application artifacts, real Composer installation and signed-boot coverage."""
import hashlib
import http.cookiejar
import importlib.util
import json
import os
from pathlib import Path
import shutil
import socket
import subprocess
import time
import urllib.error
import urllib.request
import uuid

ROOT = Path(__file__).resolve().parents[2]
spec = importlib.util.spec_from_file_location('v4_package', ROOT / 'tools/v4-package.py')
packaging = importlib.util.module_from_spec(spec)
spec.loader.exec_module(packaging)
base = ROOT / 'storage' / ('v4-packaging-' + uuid.uuid4().hex)
php = shutil.which('php')
composer_executable = shutil.which('composer')
if not php or not composer_executable:
    raise SystemExit('PHP and Composer are required.')
phar = Path(composer_executable).parent / 'composer.phar'
composer = [php, str(phar)] if phar.exists() else [composer_executable]
environment = dict(os.environ, COMPOSER_ROOT_VERSION=packaging.VERSION)
for key in list(environment):
    if key.startswith(('APP_', 'AUTH_', 'AI_', 'COREVIA_', 'MODEL_')):
        environment.pop(key)


def run(command, cwd=ROOT, success=True):
    result = subprocess.run(command, cwd=cwd, env=environment, text=True, capture_output=True)
    if success and result.returncode != 0:
        raise AssertionError('Command failed: ' + repr(command) + '\n' + result.stdout[-4000:] + result.stderr[-2000:])
    if not success and result.returncode == 0:
        raise AssertionError('Expected failure: ' + repr(command))
    return result.stdout


def http_smoke(project):
    with socket.socket() as reservation:
        reservation.bind(('127.0.0.1', 0))
        port = reservation.getsockname()[1]
    origin = 'http://127.0.0.1:' + str(port)
    child_environment = dict(environment, APP_ENV='development', APP_URL=origin,
                             APP_HTTPS='false', AUTH_DELIVERY='disabled', COREVIA_ORIGINS=origin)
    cookies = http.cookiejar.CookieJar()
    client = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(cookies))

    def request(path, data=None, csrf=None):
        headers = {'Origin': origin}
        if data is not None:
            headers['Content-Type'] = 'application/json'
        if csrf is not None:
            headers['X-CSRF-Token'] = csrf
        message = urllib.request.Request(origin + path, headers=headers,
                                         data=None if data is None else json.dumps(data).encode())
        try:
            response = client.open(message, timeout=5)
        except urllib.error.HTTPError as error:
            response = error
        with response:
            return response.status, response.read(), response.headers

    with (project / 'storage/http-test.log').open('wb') as log:
        server = subprocess.Popen([php, '-n', '-S', '127.0.0.1:' + str(port), '-t', 'public', 'public/index.php'],
                                  cwd=project, env=child_environment, stdout=log, stderr=log)
        try:
            for _ in range(100):
                if server.poll() is not None:
                    raise AssertionError('Preview HTTP process exited during startup.')
                try:
                    with socket.create_connection(('127.0.0.1', port), timeout=0.1):
                        break
                except OSError:
                    time.sleep(0.05)
            else:
                raise AssertionError('Preview HTTP startup timed out.')
            assert request('/')[0] == 200
            for asset in ['auth.css', 'auth-account.js', 'corevia-ai.js']:
                assert request('/assets/' + asset)[0] == 200
            if (project / 'public/assets/auth.js').is_file():
                assert request('/assets/auth.js')[0] == 200
            for path in ['/vendor/autoload.php', '/config/auth.php', '/storage/auth/records.json',
                         '/.env', '/%2e%2e/composer.json']:
                assert request(path)[0] == 404, path
            assert request('/auth/me')[0] == 401
            session = json.loads(request('/auth/session')[1])
            registration = {'name': 'Preview user', 'email': 'preview@example.test',
                            'password': 'preview-secure-password', 'password_confirmation': 'preview-secure-password'}
            assert request('/auth/register', registration)[0] == 419
            assert request('/auth/register', registration, session['csrf'])[0] in {200, 201}
            assert request('/auth/me')[0] == 200
            session = json.loads(request('/auth/session')[1])
            assert any(cookie.name == 'corevia_auth' and cookie.has_nonstandard_attr('HttpOnly') for cookie in cookies)
            assert request('/auth/logout', {}, session['csrf'])[0] == 200
            assert request('/auth/me')[0] == 401
        finally:
            server.terminate()
            try:
                server.wait(timeout=5)
            except subprocess.TimeoutExpired:
                server.kill()
                server.wait(timeout=5)


preview = packaging.build(base / 'dry-run', dry_run=True)
assert preview['dry_run'] is True and not base.exists()
first = packaging.build(base / 'one')
second = packaging.build(base / 'two')
assert first == second
for name in ['runtime.zip', 'application.zip', 'manifest.json']:
    assert (base / 'one' / name).read_bytes() == (base / 'two' / name).read_bytes()
for invalid in [base / 'one', ROOT / 'app', ROOT / 'storage']:
    try:
        packaging.build(invalid)
    except ValueError:
        pass
    else:
        raise AssertionError('Unsafe or existing output accepted.')
print('PASS reproducible packages, no-write preview, output confinement and overwrite rejection')

runtime = base / 'one/runtime'
application = base / 'one/application'
manifest = json.loads((base / 'one/manifest.json').read_text())
for group in ['runtime', 'application']:
    for name, digest in manifest['files'][group].items():
        assert hashlib.sha256((base / 'one' / group / name).read_bytes()).hexdigest() == digest
        assert not name.startswith(('local-v3-acceptance/', 'vendor/', '.github/', 'frontend/', 'storage/')) or name == 'storage/.gitkeep'
assert not (application / 'packages').exists()
assert not (application / 'routes/auth.php').exists()
assert not (application / 'config/auth.php').exists()
assert not (application / '.corevia-auth-starter.json').exists()
assert not (runtime / 'app').exists()
run(composer + ['validate', '--strict', '--no-check-publish'], runtime)
run(composer + ['validate', '--strict', '--no-check-publish'], application)
run(composer + ['install', '--no-interaction', '--no-progress', '--no-plugins', '--no-scripts'], application)
assert 'Corevia' in run([php, '-n', 'corevia', 'about'], application)
assert 'home' in run([php, '-n', 'corevia', 'route:list'], application)
assert 'PASS' in run([php, '-n', 'corevia', 'test'], application)
for target in ['shared', 'vps', 'aws', 'azure']:
    preset = json.loads(run([php, '-n', 'corevia', 'hosting:prepare', '--target=' + target, '--output=recipe-' + target], application))
    assert preset['written'] and not preset['target_validated']
    for name, digest in preset['files'].items():
        assert hashlib.sha256((application / 'storage' / ('recipe-' + target) / name).read_bytes()).hexdigest() == digest

poison = application / 'config/auth.php'
poison.write_text("<?php throw new RuntimeException('UNEXPECTED_CONFIG_EXECUTION');", encoding='utf-8')
try:
    hosting = json.loads(run([php, '-n', 'corevia', 'hosting:check', '--target=aws'], application))
    assert hosting['baseline_compatible'] and not hosting['target_validated'] and not hosting['production_ready']
    restricted = json.loads(run([php, '-n', '-d', 'disable_functions=flock', 'corevia', 'hosting:check'], application, success=False))
    assert restricted['blocked'] and any(row['id'] == 'function.flock' and row['status'] == 'failed' for row in restricted['checks'])
    run([php, '-n', 'corevia', 'hosting:check', '--public-path=' + str(application)], application, success=False)
    policy = application / 'storage/requirements.json'
    policy.write_text(json.dumps({'password': 'DO_NOT_DISCLOSE_THIS_VALUE'}), encoding='utf-8')
    result = subprocess.run([php, '-n', 'corevia', 'hosting:check', '--requirements=' + str(policy)], cwd=application, env=environment, text=True, capture_output=True)
    assert result.returncode != 0 and 'DO_NOT_DISCLOSE_THIS_VALUE' not in result.stdout + result.stderr
finally:
    poison.unlink()
run([php, '-n', 'corevia', 'app:compile'], application)
run([php, '-n', 'corevia', 'app:verify'], application)
vendor = application / 'vendor/corevia-framework/runtime'
source = vendor / 'packages/core/src/FrameworkResources.php'
original = source.read_bytes()
try:
    source.write_bytes(original + b'\n// changed framework dependency\n')
    run([php, '-n', 'corevia', 'app:verify'], application, success=False)
finally:
    source.write_bytes(original)
run([php, '-n', 'corevia', 'app:verify'], application)
print('PASS detached hosting CLI does not execute app config or disclose invalid policy values; signed boot rejects vendor drift')

dispatch = application / 'smoke.php'
dispatch.write_text('''<?php
require __DIR__.'/autoload.php';
$bootstrap=require __DIR__.'/config/runtime.php';$runtime=$bootstrap(__DIR__);
try {
    foreach (['/','/hello/Ada','/up'] as $path) {
        $response=$runtime['router']->dispatch(new Corevia\\Http\\Request('GET',$path));
        if ($response->status!==200) {throw new RuntimeException('Route failed: '.$path);}
    }
} finally {$runtime['app']->shutdown();}
echo 'PASS database-free request dispatch';
''', encoding='utf-8')
assert 'PASS' in run([php, '-n', 'smoke.php'], application)
for stack in ['bootstrap', 'tailwind', 'react']:
    candidate = base / 'one' / ('app-' + stack)
    shutil.copytree(application, candidate)
    assert 'installed' in run([php, '-n', 'corevia', 'starter:auth', '--stack=' + stack], candidate)
    run([php, '-n', 'corevia', 'starter:auth', '--stack=' + stack, '--check'], candidate)
    upgrade = json.loads(run([php, '-n', 'corevia', 'starter:upgrade'], candidate))
    assert not upgrade['requires_review'] and not upgrade['files_written'] and not upgrade['can_apply']
    run([php, '-n', 'corevia', 'starter:upgrade', '--apply'], candidate, success=False)
    run([php, '-n', 'corevia', 'starter:auth', '--stack=' + stack], candidate, success=False)
    run([php, '-n', 'corevia', 'starter:ai'], candidate)
    run([php, '-n', 'corevia', 'starter:ai'], candidate, success=False)
    assert (candidate / 'resources/views/ai/chat.php').exists()
    assert 'login' in run([php, '-n', 'corevia', 'route:list'], candidate)
    assert 'PASS' in run([php, '-n', 'smoke.php'], candidate)
    http_smoke(candidate)
print('PASS all three detached auth/AI starters; HTTP assets, private-file denial, CSRF, registration and logout')
print('Artifacts: ' + str(base))
