"""Clean Composer installation checks using a committed archive, with no package publication."""
import json
import os
from pathlib import Path
import shutil
import subprocess
import uuid
import zipfile

root = Path(__file__).resolve().parents[2]
directory = root / 'storage' / ('install-check-' + uuid.uuid4().hex)
directory.mkdir(parents=True)
php = shutil.which(os.environ.get('PHP_BINARY', 'php'))
composer_executable = shutil.which('composer')
if not php or not composer_executable:
    raise SystemExit('PHP and Composer are required for installation checks.')
phar = Path(composer_executable).parent / 'composer.phar'
composer = [php, str(phar)] if phar.exists() else [composer_executable]

def run(command, cwd=root):
    environment = dict(os.environ)
    index = int(environment.get('GIT_CONFIG_COUNT', '0'))
    environment['GIT_CONFIG_COUNT'] = str(index + 1)
    environment['GIT_CONFIG_KEY_' + str(index)] = 'safe.directory'
    environment['GIT_CONFIG_VALUE_' + str(index)] = root.as_posix()
    environment['COMPOSER_ROOT_VERSION'] = '2.0.0'
    subprocess.run(command, cwd=cwd, check=True, env=environment)

archive = directory / 'source.zip'
run(['git', '-c', 'safe.directory=' + root.as_posix(), 'archive', '--format=zip', '-o', str(archive), 'HEAD'])
source = directory / 'source'
with zipfile.ZipFile(archive) as files:
    names = set(files.namelist())
    required = {'corevia', 'autoload.php', 'composer.json', 'composer.lock', '.gitignore', '.env.example', 'LICENSE', 'README.md', 'CONTRIBUTING.md', 'SECURITY.md', 'storage/.gitkeep', 'packages/core/src/Corevia.php', 'config/web.php', 'routes/web.php', 'resources/views/welcome.php', 'tests/features/web.php'}
    missing = required - names
    if missing:
        raise SystemExit('Distribution is missing required files: ' + ', '.join(sorted(missing)))
    excluded = {'CODEX.md', 'docs/implementation.md', 'docs/validation.md', 'docs/v2/phases.md', 'docs/v2/release.md'}
    if names & excluded or any(name.startswith('.github/') or name.endswith('.secret') for name in names):
        raise SystemExit('Distribution includes local-only or repository-only files.')
    files.extractall(source)
print('PASS archive keeps the runnable framework and excludes internal/automation files')
repository = {'type': 'path', 'url': str(source), 'options': {'symlink': False, 'versions': {'corevia-framework/corevia': '2.0.0'}}}
application = directory / 'application'
run(composer + ['create-project', '--no-interaction', '--no-progress', '--no-scripts', '--repository=' + json.dumps(repository), 'corevia-framework/corevia', str(application), '2.0.0'])
run([php, '-n', 'corevia', 'about'], application)
run([php, '-n', 'test-project/tests/run.php'], application)
run([php, '-n', 'tests/features/web.php'], application)
core_project = directory / 'core-only'
core_project.mkdir()
manifest = {'name': 'corevia-install-test/core-only', 'require': {'corevia-framework/core': '2.0.0'}, 'repositories': [{'type': 'path', 'url': str(source / 'packages/core'), 'options': {'symlink': False, 'versions': {'corevia-framework/core': '2.0.0'}}}]}
(core_project / 'composer.json').write_text(json.dumps(manifest), encoding='utf-8')
run(composer + ['install', '--no-interaction', '--no-progress', '--no-scripts'], core_project)
(core_project / 'smoke.php').write_text("<?php require __DIR__.'/vendor/autoload.php'; $app=Corevia\\Core\\Corevia::create(__DIR__); $app->boot(); if ($app->kernel->state()->value!=='ready') {exit(1);} $app->shutdown(); echo 'PASS standalone core without PDO'.PHP_EOL;", encoding='utf-8')
run([php, '-n', 'smoke.php'], core_project)
print('PASS clean Composer create-project and standalone core installation; artifacts: ' + str(directory))
