"""Phase B acceptance from real packages: compose, stage an upgrade, and serve a shared-host layout."""
import hashlib
import http.cookiejar
import http.server
import functools
import importlib.util
import json
import os
from pathlib import Path
import runpy
import socket
import subprocess
import time
import threading
import urllib.error
import urllib.request

ROOT = Path(__file__).resolve().parents[2]
context = runpy.run_path(str(ROOT / 'tests/v4/packaging.py'))
base, run, php, environment = (context[name] for name in ['base', 'run', 'php', 'environment'])
runtime = base / 'one/runtime'


def digest(directory):
    return {file.relative_to(directory).as_posix(): hashlib.sha256(file.read_bytes()).hexdigest()
            for file in directory.rglob('*') if file.is_file()}


for stack in ['bootstrap', 'tailwind', 'react']:
    destination = base / ('composed-' + stack)
    arguments = [php, str(ROOT / 'corevia'), 'app:new', str(destination), '--features=ai',
                 '--stack=' + stack, '--runtime-path=' + str(runtime)]
    preview = json.loads(run(arguments + ['--dry-run']))
    assert not destination.exists() and preview['features']['order'] == ['web', 'auth', 'ai']
    installed = json.loads(run(arguments))
    assert installed['installed'] and (destination / 'routes/ai.php').is_file()
    assert 'PASS' in run([php, '-n', 'corevia', 'test'], destination)
    run(arguments, success=False)
api = base / 'api-app'
run([php, str(ROOT / 'corevia'), 'app:new', str(api), '--features=api', '--runtime-path=' + str(runtime)])
routes = json.loads(run([php, '-n', 'corevia', 'route:list'], api))
assert 'health' in json.dumps(routes) and 'contact' not in json.dumps(routes)
assert not (api / 'routes/auth.php').exists()
assert 'PASS' in run([php,'-n','corevia','test'],api)
sqlite=base/'sqlite-app'
run([php,str(ROOT/'corevia'),'app:new',str(sqlite),'--features=auth','--database=sqlite','--runtime-path='+str(runtime)])
assert 'sqlite' in run([php,'corevia','db:check','sqlite'],sqlite)
bad = base / 'invalid'
run([php, str(ROOT / 'corevia'), 'app:new', str(bad), '--features=api,auth', '--runtime-path=' + str(runtime)], success=False)
assert not bad.exists()
print('PASS single-command composition across all UI stacks, API-only profile and conflict/no-overwrite previews')

source = base / 'composed-bootstrap'
addon=base/'user-library';addon.mkdir()
(addon/'composer.json').write_text(json.dumps({'name':'fixture/user-library','version':'1.0.0','type':'library','license':'MIT'}),encoding='utf-8')
composer=json.loads((source/'composer.json').read_text())
composer['require']['fixture/user-library']='1.0.0'
composer['repositories'].append({'type':'path','url':addon.as_posix(),'options':{'symlink':False}})
composer['scripts']={'post-install-cmd':'@php -r "file_put_contents(\'HOOK_RAN\',\'bad\');"','post-update-cmd':'@php -r "file_put_contents(\'HOOK_RAN\',\'bad\');"'}
(source/'composer.json').write_text(json.dumps(composer),encoding='utf-8')
run(context['composer']+['update','--no-interaction','--no-scripts','--no-plugins'],source)
assert not (source/'HOOK_RAN').exists()
(source / 'resources/views/custom.php').write_text('application-owned customization', encoding='utf-8')
(source / 'storage/private-state.json').write_text('{"fixture":"preserve-me"}', encoding='utf-8')
(source / '.env').write_text('FIXTURE_VALUE=not-a-real-secret\n', encoding='utf-8')
(source / 'storage/config-development.json').write_text(json.dumps({'format':1,'environment':'development','values':{'old_path':str(source)}}),encoding='utf-8')
original = digest(source)
staged = base / 'staged-upgrade'
command = [php, str(ROOT / 'corevia'), 'app:upgrade', '--from=' + str(source), '--output=' + str(staged), '--runtime-path=' + str(runtime)]
assert json.loads(run(command + ['--dry-run']))['source_modified'] is False
assert not staged.exists()
run(command, success=False)
assert not staged.exists()
assert json.loads(run(command + ['--offline']))['staged']
assert not (staged / 'storage/config-development.json').exists()
assert list((staged/'storage').glob('.corevia-upgrade-*/config-development.json'))
assert digest(source) == original
for name in ['resources/views/custom.php', 'storage/private-state.json', '.env', 'routes/auth.php', 'config/auth.php']:
    assert (staged / name).read_bytes() == (source / name).read_bytes()
assert 'PASS' in run([php, '-n', 'corevia', 'test'], staged)
assert not (staged/'HOOK_RAN').exists()
locked=json.loads((staged/'composer.lock').read_text())
assert any(item['name']=='fixture/user-library' and item['version']=='1.0.0' for item in locked['packages'])
print('PASS staged dependency upgrade preserves application edits/private state and leaves original rollback copy byte-identical')
custom_vendor=source/'vendor/corevia-framework/runtime/packages/core/src/Version.php'
vendor_bytes=custom_vendor.read_bytes()
try:
    custom_vendor.write_bytes(vendor_bytes+b'\n// customized vendor dependency\n')
    rejected_vendor=base/'rejected-vendor'
    run([php,str(ROOT/'corevia'),'app:upgrade','--from='+str(source),'--output='+str(rejected_vendor),'--runtime-path='+str(runtime),'--offline'],success=False)
    assert not rejected_vendor.exists()
finally:custom_vendor.write_bytes(vendor_bytes)

# A real V3 source checkout is the legacy migration fixture; no user project is used.
legacy = base / 'legacy-v3'
archive = base / 'legacy.zip'
run(['git', '-c', 'safe.directory=' + ROOT.as_posix(), 'archive', '--format=zip', '-o', str(archive), 'd7429ed14356de39aaf8e98fa0993fc5c8f38337'])
import zipfile
with zipfile.ZipFile(archive) as files:
    files.extractall(legacy)
legacy_original = digest(legacy)
replacement = base / 'v3-migrated'
run([php, str(ROOT / 'corevia'), 'app:upgrade', '--from=' + str(legacy), '--output=' + str(replacement), '--runtime-path=' + str(runtime), '--offline'])
assert digest(legacy) == legacy_original
assert not (replacement / 'packages').exists()
assert (replacement / 'app/Http/HomeController.php').read_bytes() == (legacy / 'app/Http/HomeController.php').read_bytes()
assert 'home' in run([php, '-n', 'corevia', 'route:list'], replacement)
fork = legacy / 'packages/core/src/Version.php'
fork.write_bytes(fork.read_bytes() + b'\n// local fork\n')
refused = base / 'refused-fork'
run([php, str(ROOT / 'corevia'), 'app:upgrade', '--from=' + str(legacy), '--output=' + str(refused), '--runtime-path=' + str(runtime), '--offline'], success=False)
assert not refused.exists()
print('PASS actual V3-to-detached migration and customized framework fork refusal')

spec = importlib.util.spec_from_file_location('shared', ROOT / 'tools/v4-shared.py')
shared = importlib.util.module_from_spec(spec)
spec.loader.exec_module(shared)
bundle = base / 'shared'
shared.build(source, bundle)
assert not (bundle / 'private/corevia/.env').exists()
assert not (bundle / 'private/corevia/storage/private-state.json').exists()
assert (bundle / 'private/corevia/vendor/autoload.php').is_file()
with socket.socket() as reserved:
    reserved.bind(('127.0.0.1', 0)); port = reserved.getsockname()[1]
origin = 'http://127.0.0.1:' + str(port)
env = dict(environment, APP_ENV='development', APP_URL=origin, APP_HTTPS='false', COREVIA_ORIGINS=origin, AUTH_DELIVERY='disabled')
cookies = http.cookiejar.CookieJar()
client = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(cookies))
with (base / 'shared-http.log').open('wb') as log:
    server = subprocess.Popen([php, '-n', '-S', '127.0.0.1:' + str(port), '-t', 'public_html', 'public_html/index.php'], cwd=bundle, env=env, stdout=log, stderr=log)
    try:
        for _ in range(100):
            try:
                with socket.create_connection(('127.0.0.1', port), timeout=0.1): break
            except OSError: time.sleep(0.05)
        def request(path, payload=None, csrf=None):
            headers={'Origin':origin}
            if payload is not None: headers['Content-Type']='application/json'
            if csrf: headers['X-CSRF-Token']=csrf
            message=urllib.request.Request(origin+path,data=None if payload is None else json.dumps(payload).encode(),headers=headers)
            try: response=client.open(message,timeout=5)
            except urllib.error.HTTPError as error: response=error
            with response: return response.status,response.read()
        for path in ['/', '/assets/app.css', '/assets/auth.css', '/login']: assert request(path)[0]==200,path
        for path in ['/private/corevia/config/auth.php','/vendor/autoload.php','/config/auth.php','/.env','/storage/auth/records.json']:
            assert request(path)[0]==404,path
        session=json.loads(request('/auth/session')[1])
        registration={'name':'Upload fixture','email':'upload@example.test','password':'fixture-password-123','password_confirmation':'fixture-password-123'}
        assert request('/auth/register',registration)[0]==419
        assert request('/auth/register',registration,session['csrf'])[0] in {200,201}
        assert request('/auth/me')[0]==200
    finally:
        server.terminate()
        try: server.wait(timeout=5)
        except subprocess.TimeoutExpired: server.kill();server.wait(timeout=5)
print('PASS prebuilt shared-host bundle serves assets/auth with private paths denied and no target Composer/Node/Python')
unsafe=source/'public/backdoor.php';unsafe.write_text('<?php echo "unexpected";',encoding='utf-8')
try:
    try: shared.build(source,base/'refused-shared',True)
    except ValueError: pass
    else: raise AssertionError('Additional executable public PHP was accepted.')
finally: unsafe.unlink()
print('Phase B artifacts: '+str(base))

spec=importlib.util.spec_from_file_location('publication',ROOT/'tools/v4-publication.py')
publication=importlib.util.module_from_spec(spec);spec.loader.exec_module(publication)
prepared=base/'publication'
assert publication.prepare(base/'one',prepared,'https://example.invalid/corevia-v4')['uploaded'] is False
index=json.loads((prepared/'packages.json').read_text())
runtime_package=index['packages']['corevia-framework/runtime']['dev-v4']
local_repository=base/'local-repository';local_repository.mkdir()
consumer=base/'registry-consumer';consumer.mkdir()
class RepositoryHandler(http.server.SimpleHTTPRequestHandler):
    def log_message(self,*args):pass
repository=http.server.ThreadingHTTPServer(('127.0.0.1',0),functools.partial(RepositoryHandler,directory=str(local_repository)))
repository_origin='http://127.0.0.1:'+str(repository.server_address[1])
for name,filename in [('runtime','runtime.zip'),('application','application.zip')]:
    index['packages']['corevia-framework/'+name]['dev-v4']['dist']['url']=repository_origin+'/'+filename
    import shutil
    shutil.copyfile(prepared/filename,local_repository/filename)
(local_repository/'packages.json').write_text(json.dumps(index),encoding='utf-8')
worker=threading.Thread(target=repository.serve_forever,daemon=True);worker.start()
try:
    (consumer/'composer.json').write_text(json.dumps({'name':'fixture/registry-consumer','require':{'corevia-framework/runtime':'dev-v4'},
        'repositories':{'fixture':{'type':'composer','url':repository_origin},'packagist.org':False},
        'config':{'secure-http':False}}),encoding='utf-8')
    run(context['composer']+['install','--no-interaction','--no-scripts','--no-plugins'],consumer)
finally:
    repository.shutdown();repository.server_close();worker.join(timeout=5)
assert (consumer/'vendor/corevia-framework/runtime/packages/core/src/Corevia.php').is_file()
standalone=base/'standalone-created'
run([php,str(consumer/'vendor/corevia-framework/runtime/bin/corevia-new'),str(standalone),'--features=auth','--stack=tailwind'])
assert 'PASS' in run([php,'-n','corevia','test'],standalone)
print('PASS prepared Composer publication metadata and ZIP-based consumer install; nothing uploaded')
