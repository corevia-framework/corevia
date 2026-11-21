"""Install the root project from Git and Composer archives using an offline repository.

Stage intended release files first: Git archives use the index, never local secrets
or application-owned untracked files. No commits, tags or network publication occur.
"""
import argparse
import hashlib
import json
import os
from pathlib import Path
import shutil
import signal
import socket
import subprocess
import time
import urllib.error
import urllib.request
from urllib.parse import unquote
import uuid
import zipfile

ROOT = Path(__file__).resolve().parents[2]
GIT = ['git', '-c', 'safe.directory=' + ROOT.as_posix()]
SENTINEL = 'COREVIA_SYNTHETIC_' + 'ARCHIVE_EXCLUSION_SENTINEL'


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--php', default=shutil.which('php'))
    args = parser.parse_args()
    php = str(Path(args.php).resolve())
    executable = shutil.which('composer')
    if not executable:
        raise RuntimeError('Composer is required.')
    phar = Path(executable).parent / 'composer.phar'
    composer = [php, str(phar)] if phar.is_file() else [executable]
    base = ROOT / 'storage' / ('composer-project-' + uuid.uuid4().hex)
    base.mkdir(parents=True)
    env = {key: value for key, value in os.environ.items()
           if not key.startswith(('APP_', 'AUTH_', 'AI_', 'COREVIA_', 'MODEL_', 'DB_', 'SMTP_', 'MAIL_', 'COMPOSER_'))}
    env.update(COMPOSER_HOME=str(base / 'composer-home'), COMPOSER_CACHE_DIR=str(base / 'composer-cache'),
               COMPOSER_DISABLE_NETWORK='1', COMPOSER_NO_INTERACTION='1',
               PATH=str(Path(php).parent) + os.pathsep + env.get('PATH', ''))
    count = int(env.get('GIT_CONFIG_COUNT', '0'))
    env.update(GIT_CONFIG_COUNT=str(count + 1))
    env['GIT_CONFIG_KEY_' + str(count)] = 'safe.directory'
    env['GIT_CONFIG_VALUE_' + str(count)] = ROOT.as_posix()
    checks = []

    def run(command, cwd=ROOT, overrides=None):
        result = subprocess.run(command, cwd=cwd, env={**env, **(overrides or {})}, capture_output=True, timeout=600)
        with (base / 'commands.log').open('ab') as log:
            log.write((repr(command) + '\n').encode() + result.stdout + result.stderr)
        if result.returncode:
            raise AssertionError('Command failed: ' + repr(command) + '\n' +
                                 (result.stdout + result.stderr).decode('utf-8', 'replace')[-6000:])
        return result.stdout.decode('utf-8', 'replace')

    tree = run(GIT + ['write-tree']).strip()
    snapshot = base / 'snapshot'
    snapshot.mkdir()
    # Export exact index contents without applying exclusions, for Composer's own archive path.
    run(GIT + ['checkout-index', '--all', '--prefix=' + snapshot.as_posix() + '/'])
    manifest = json.loads((snapshot / 'composer.json').read_text(encoding='utf-8'))
    assert manifest['name'] == 'corevia-framework/corevia' and manifest['type'] == 'project'
    assert 'version' not in manifest, 'Stable versions must be inferred from release tags.'
    version = '4.0.1'
    # Deliberately synthetic credentials/state; no real local secrets are read.
    forbidden = ['.env', '.env.production', 'auth.json', '.aws/credentials',
                 'storage/signing.secret', 'storage/cache.json', 'storage/runtime.log',
                 'local-v3-acceptance/private.json', 'vendor/fixture/file.php',
                 'tools/node_modules/fixture/index.js', 'sdk/python/__pycache__/fixture.pyc',
                 'tests/v4/passkey-dependencies/vendor/fixture/file.php',
                 'test-project/storage/fixture.json', 'app/fixture.key', 'app/nested/.env']
    for name in forbidden:
        path = snapshot / name
        path.parent.mkdir(parents=True, exist_ok=True)
        content = SENTINEL
        if name == 'auth.json':
            content = json.dumps({'http-basic': {'example.invalid': {'username': content, 'password': content}}})
        path.write_text(content, encoding='utf-8')
    archive_dir = base / 'archives'
    archive_dir.mkdir()
    run(composer + ['archive', '--format=zip', '--dir=' + str(archive_dir), '--file=composer-project'], snapshot)
    # Also prove Git export rules against synthetic tracked secrets in a separate
    # index. The real working tree and real index are never changed by this test.
    fixture_index = base / 'fixture.index'
    index_path = Path(run(GIT + ['rev-parse', '--git-path', 'index']).strip())
    if not index_path.is_absolute():
        index_path = ROOT / index_path
    shutil.copyfile(index_path, fixture_index)
    fixture_env = {'GIT_INDEX_FILE': str(fixture_index)}
    run(GIT + ['--work-tree=' + str(snapshot), 'add', '--force', '--', *forbidden], overrides=fixture_env)
    fixture_tree = run(GIT + ['write-tree'], overrides=fixture_env).strip()
    run(GIT + ['archive', '--format=zip', '--output=' + str(archive_dir / 'git-project.zip'), fixture_tree])
    required = ['composer.json', 'autoload.php', 'corevia', '.env.example', '.gitignore',
                'storage/.gitkeep', 'packages/core/src/Version.php', 'config/settings/database.php',
                'protocol/cvp-1.schema.json', 'protocol/cvp-2.schema.json', 'sdk/js/index.js', 'sdk/python/corevia/__init__.py',
                'sdk/dart/pubspec.yaml', 'starters/auth/assets/bootstrap/auth.css', 'tests/run.php']
    # Retain every tracked package/protocol/SDK/starter resource, except explicit local artifacts.
    indexed = run(GIT + ['ls-files']).splitlines()
    required += [name for name in indexed if name.startswith(('packages/', 'protocol/', 'sdk/', 'starters/'))]
    for filename in ['composer-project.zip', 'git-project.zip']:
        archive = archive_dir / filename
        with zipfile.ZipFile(archive) as incoming:
            names = set(incoming.namelist())
            for name in required:
                assert name in names, (filename, 'missing supported resource', name)
            for name in forbidden + ['.github/workflows/ci.yml', 'CODEX.md']:
                assert name not in names, (filename, 'included private/development artifact', name)
            assert all(SENTINEL.encode() not in incoming.read(name)
                       for name in names if not name.endswith('/')), filename
            if filename == 'git-project.zip':
                assert incoming.getinfo('corevia').external_attr >> 16 & 0o111, 'CLI must be executable on Unix.'
        checks.append(filename + ': inventory and exclusion checks')
        project = base / filename.removesuffix('.zip')
        # PHP's local file wrapper expects the literal path, including spaces.
        package = {**manifest, 'version': version, 'dist': {'type': 'zip', 'url': unquote(archive.as_uri()),
                   'shasum': hashlib.sha1(archive.read_bytes()).hexdigest()}}
        repository = json.dumps({'type': 'package', 'package': package}, separators=(',', ':'))
        run(composer + ['create-project', '--repository=' + repository, '--prefer-dist', '--no-progress',
                        'corevia-framework/corevia', str(project), '^4.0'])
        run(composer + ['validate', '--strict'], project)
        run(composer + ['check-platform-reqs'], project)
        run(composer + ['dump-autoload', '--optimize', '--strict-psr'], project)
        probe = project / 'storage/autoload-probe.php'
        probe.write_text("<?php\n$loader=require dirname(__DIR__).'/vendor/autoload.php';\n"
                         "$map=json_decode(file_get_contents(dirname(__DIR__).'/composer.json'),true)['autoload']['psr-4'];\n"
                         "foreach($map as $prefix=>$directory){if(!isset($loader->getPrefixesPsr4()[$prefix])){throw new RuntimeException($prefix);}}\n"
                         "if(Corevia\\Core\\Version::VERSION!=='4.0.1'||!class_exists(App\\Http\\HomeController::class)){exit(1);}\n"
                         "echo 'PASS Composer autoload and application namespace';\n", encoding='utf-8')
        assert 'PASS' in run([php, str(probe)], project)
        for command in ['about', 'help', 'doctor', 'route:list', 'db:status']:
            run([php, 'corevia', command], project)
        run([php, '-n', 'corevia', 'route:list'], project)
        diagnosis = json.loads(run([php, '-n', 'corevia', 'doctor'], project))
        assert diagnosis['healthy'] is True and diagnosis['run_store'] == 'json'
        assert (project / 'storage').is_dir() and os.access(project / 'storage', os.W_OK)
        with socket.socket() as reserved:
            reserved.bind(('127.0.0.1', 0))
            port = reserved.getsockname()[1]
        origin = 'http://127.0.0.1:' + str(port)
        # The CLI launches a child PHP server. Keep that child database-free too.
        empty_ini = base / 'http-empty.ini'
        empty_ini.write_text('; Database-free HTTP acceptance\n', encoding='utf-8')
        scan_dir = base / 'http-empty-scan'
        scan_dir.mkdir(exist_ok=True)
        server_env = {**env, 'PHPRC': str(empty_ini), 'PHP_INI_SCAN_DIR': str(scan_dir)}
        with (base / (filename + '.http.log')).open('wb') as log:
            server = subprocess.Popen([php, '-n', 'corevia', 'serve', '--host=127.0.0.1', '--port=' + str(port)],
                                      cwd=project, env=server_env, stdout=log, stderr=log,
                                      start_new_session=os.name != 'nt')
            try:
                for _ in range(150):
                    try:
                        with urllib.request.urlopen(origin + '/', timeout=1) as response:
                            assert response.status == 200 and b'Corevia' in response.read()
                        break
                    except (OSError, urllib.error.URLError):
                        if server.poll() is not None:
                            raise AssertionError('HTTP server exited during startup.')
                        time.sleep(0.1)
                else:
                    raise AssertionError('HTTP server startup timed out.')
                for path in ['/up', '/hello/Ada', '/assets/app.css', '/assets/app.js']:
                    with urllib.request.urlopen(origin + path, timeout=5) as response:
                        assert response.status == 200, path
                for path in ['/.env', '/composer.json', '/storage/web-signing.secret', '/vendor/autoload.php']:
                    try:
                        urllib.request.urlopen(origin + path, timeout=5)
                    except urllib.error.HTTPError as error:
                        assert error.code == 404, path
                    else:
                        raise AssertionError('Private path served: ' + path)
            finally:
                # serve uses passthru; terminate the Windows descendant server too.
                if os.name == 'nt':
                    subprocess.run(['taskkill', '/PID', str(server.pid), '/T', '/F'], capture_output=True)
                else:
                    try:
                        os.killpg(server.pid, signal.SIGTERM)
                    except ProcessLookupError:
                        pass
                server.wait(timeout=15)
        assert (project / 'storage/web-signing.secret').is_file()
        checks.append(filename + ': create-project ^4.0, strict autoload, config/CLI/storage and database-free HTTP')
    report = {'passed': True, 'version': version, 'source_tree': tree, 'php': run([php, '-r', 'echo PHP_VERSION;']),
              'checks': checks, 'published': False}
    (base / 'report.json').write_text(json.dumps(report, indent=2) + '\n', encoding='utf-8')
    print(json.dumps(report, indent=2))
    print('Private artifacts: ' + str(base))


if __name__ == '__main__':
    main()
