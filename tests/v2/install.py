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
    environment['COMPOSER_ROOT_VERSION'] = '2.0.0-alpha.2'
    subprocess.run(command, cwd=cwd, check=True, env=environment)

archive = directory / 'source.zip'
run(['git', '-c', 'safe.directory=' + root.as_posix(), 'archive', '--format=zip', '-o', str(archive), 'HEAD'])
source = directory / 'source'
with zipfile.ZipFile(archive) as files:
    files.extractall(source)
repository = {'type': 'path', 'url': str(source), 'options': {'symlink': False, 'versions': {'corevia-framework/corevia': '2.0.0-alpha.2'}}}
application = directory / 'application'
run(composer + ['create-project', '--no-interaction', '--no-progress', '--no-scripts', '--repository=' + json.dumps(repository), 'corevia-framework/corevia', str(application), '2.0.0-alpha.2'])
run([php, '-n', 'corevia', 'about'], application)
run([php, '-n', 'test-project/tests/run.php'], application)
core_project = directory / 'core-only'
core_project.mkdir()
manifest = {'name': 'corevia-install-test/core-only', 'require': {'corevia-framework/core': '2.0.0-alpha.2'}, 'repositories': [{'type': 'path', 'url': str(source / 'packages/core'), 'options': {'symlink': False, 'versions': {'corevia-framework/core': '2.0.0-alpha.2'}}}]}
(core_project / 'composer.json').write_text(json.dumps(manifest), encoding='utf-8')
run(composer + ['install', '--no-interaction', '--no-progress', '--no-scripts'], core_project)
(core_project / 'smoke.php').write_text("<?php require __DIR__.'/vendor/autoload.php'; $app=Corevia\\Core\\Corevia::create(__DIR__); $app->boot(); if ($app->kernel->state()->value!=='ready') {exit(1);} $app->shutdown(); echo 'PASS standalone core without PDO'.PHP_EOL;", encoding='utf-8')
run([php, '-n', 'smoke.php'], core_project)
print('PASS clean Composer create-project and standalone core installation; artifacts: ' + str(directory))
