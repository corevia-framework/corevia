"""Isolated Linux nginx/FPM recipe fixture. Never reads global site/pool configs or installs services."""
import argparse
import grp
import hashlib
import signal
import json
import os
from pathlib import Path
import pwd
import shutil
import socket
import subprocess
import tempfile
import time
import urllib.error
import urllib.request


def port():
    with socket.socket() as listener:
        listener.bind(('127.0.0.1', 0))
        return listener.getsockname()[1]


def main():
    parser = argparse.ArgumentParser()
    parser.add_argument('--recipes', required=True)
    parser.add_argument('--output-root', required=True)
    parser.add_argument('--application', help='Optional disposable source copy inside Corevia acceptance sources/storage only')
    args = parser.parse_args()
    root = Path(args.output_root).resolve(strict=True)
    # The staging host also runs unrelated live sites. Refuse all other paths.
    allowed = Path('/var/www/corevia/.acceptance')
    local_storage = Path(__file__).resolve().parents[2] / 'storage'
    if not (root.is_relative_to(allowed) or root.is_relative_to(local_storage)):
        raise SystemExit('Use only private Corevia acceptance storage.')
    recipes = Path(args.recipes).resolve(strict=True)
    source_hashes = {str(p.relative_to(recipes)): hashlib.sha256(p.read_bytes()).hexdigest() for p in sorted(recipes.rglob('*')) if p.is_file()}
    nginx = shutil.which('nginx')
    fpm = next((shutil.which(name) for name in ['php-fpm8.3', 'php-fpm8.2', 'php-fpm'] if shutil.which(name)), None)
    if not nginx or not fpm:
        raise SystemExit('Actual nginx and PHP-FPM are required; missing binaries are not a pass.')
    os.umask(0o077)
    fixture = Path(tempfile.mkdtemp(prefix='v4-hosting-', dir=root))
    application = Path(args.application).resolve(strict=True) if args.application else None
    if application is not None and not (application.is_relative_to(allowed / 'sources') or application.is_relative_to(local_storage)):
        raise ValueError('Full-app checks require a disposable Corevia acceptance copy.')
    application_hashes = {p.relative_to(application).as_posix():hashlib.sha256(p.read_bytes()).hexdigest() for p in sorted(application.rglob('*')) if p.is_file() and p.relative_to(application).parts[0] not in {'storage','.git'}} if application else {}
    public = fixture / 'app/public'
    public.mkdir(parents=True)
    (public / 'assets').mkdir()
    (public / 'assets/app.css').write_text('body { color: black; }', encoding='utf-8')
    (public / 'index.php').write_text('<?php header("Content-Type: application/json"); echo json_encode(["path"=>$_SERVER["REQUEST_URI"],"https"=>$_SERVER["HTTPS"]??null]);', encoding='utf-8')
    for name in ['other.php', 'other.PHP', '.env']:
        (public / name).write_text('PRIVATE_FIXTURE_MUST_NOT_BE_SERVED', encoding='utf-8')
    (fixture / 'app/.env').write_text('PRIVATE_FIXTURE_MUST_NOT_BE_SERVED', encoding='utf-8')
    if application is not None:
        public = application / 'public'
    fpm_port = port()
    user = pwd.getpwuid(os.getuid()).pw_name
    config = fixture / 'php-fpm.conf'
    group = grp.getgrgid(pwd.getpwuid(os.getuid()).pw_gid).gr_name
    pool = (recipes / 'vps/php-fpm.conf').read_text(encoding='utf-8')
    pool = pool.replace('user = corevia', 'user = ' + user).replace('group = corevia', 'group = ' + group)
    pool = pool.replace('listen.owner = www-data', 'listen.owner = ' + user).replace('listen.group = www-data', 'listen.group = ' + group)
    pool = pool.replace('listen = /run/php/corevia.sock', f'listen = 127.0.0.1:{fpm_port}')
    pool = pool.replace('/srv/corevia/shared/storage', str(fixture))
    pool = pool.replace('env[APP_ENV] = production', 'env[APP_ENV] = testing')
    config.write_text(f'[global]\npid = {fixture}/fpm.pid\nerror_log = {fixture}/fpm-error.log\ndaemonize = no\n' + pool + '\nphp_admin_value[cgi.fix_pathinfo] = 0\n', encoding='utf-8')
    subprocess.run([fpm, '-t', '-y', str(config)], check=True, capture_output=True)
    children = []
    handles = []
    report = {'passed': False, 'target_validated': False, 'scope': 'isolated nginx/FPM routing recipes', 'checks': []}
    try:
        log = (fixture / 'fpm.log').open('wb'); handles.append(log)
        children.append(subprocess.Popen([fpm, '-F', '-y', str(config)], stdout=log, stderr=log, start_new_session=True))
        for target in ['vps', 'azure']:
            http_port = port()
            template = (recipes / target / 'nginx.conf').read_text(encoding='utf-8')
            template = template.replace('listen 127.0.0.1:8080;', f'listen 127.0.0.1:{http_port};').replace('listen 8080;', f'listen 127.0.0.1:{http_port};')
            template = template.replace('/srv/corevia/current/public', public.as_posix()).replace('/home/site/wwwroot/public', public.as_posix())
            template = template.replace('include fastcgi_params;', 'include /etc/nginx/fastcgi_params;')
            template = template.replace('unix:/run/php/corevia.sock', f'127.0.0.1:{fpm_port}').replace('127.0.0.1:9000', f'127.0.0.1:{fpm_port}')
            nginx_config = fixture / (target + '.conf')
            nginx_config.write_text(f'pid {fixture}/{target}.pid;\nerror_log {fixture}/{target}-error.log;\nevents {{ worker_connections 64; }}\nhttp {{ include /etc/nginx/mime.types; default_type application/octet-stream; client_body_temp_path {fixture}/body-{target}; fastcgi_temp_path {fixture}/fastcgi-{target};\n' + template + '\n}\n', encoding='utf-8')
            subprocess.run([nginx, '-t', '-p', str(fixture), '-c', str(nginx_config)], check=True, capture_output=True)
            log = (fixture / (target + '.log')).open('wb'); handles.append(log)
            child = subprocess.Popen([nginx, '-p', str(fixture), '-c', str(nginx_config), '-g', 'daemon off;'], stdout=log, stderr=log, start_new_session=True)
            children.append(child)
            origin = f'http://127.0.0.1:{http_port}'

            def request(path):
                try:
                    response = urllib.request.urlopen(origin + path, timeout=3)
                except urllib.error.HTTPError as error:
                    response = error
                with response:
                    return response.status, response.read(65536)

            for attempt in range(100):
                if child.poll() is not None or children[0].poll() is not None:
                    raise AssertionError('Private fixture process exited; inspect retained fixture logs.')
                try:
                    if request('/')[0] == 200:
                        break
                except OSError:
                    pass
                time.sleep(0.05)
            else:
                raise AssertionError('Private server startup timed out.')
            status, body = request('/nested/page?key=fixture')
            if application is None:
                assert status == 200 and json.loads(body) == {'path': '/nested/page?key=fixture', 'https': 'on'}
            else:
                assert request('/')[0] == 200 and b'Corevia' in request('/')[1]
                assert request('/hello/VPS')[0] == 200
                assert request('/unknown-fixture-page')[0] == 404
            assert request('/assets/app.css')[0] == 200
            with urllib.request.urlopen(origin + '/assets/app.css', timeout=3) as asset:
                assert asset.headers.get_content_type() == 'text/css'
            for path in (['/.env', '/config/secrets.php', '/%2e%2e/.env'] if application else ['/.env', '/other.php', '/other.PHP', '/other.php/path', '/config/secrets.php', '/%2e%2e/.env']):
                status, body = request(path)
                assert status in {400, 404} and b'PRIVATE_FIXTURE' not in body, path
            report['checks'].append(target + ': actual routing/static/private-file/FPM checks passed')
        analyzer = shutil.which('systemd-analyze')
        if not analyzer:
            raise RuntimeError('systemd-analyze is required for unit syntax validation.')
        (fixture / 'app/corevia').write_text('<?php exit(0);', encoding='utf-8')
        (fixture / 'app.env').write_text('APP_ENV=production\n', encoding='utf-8')
        service = (recipes / 'vps/corevia-queue.service').read_text(encoding='utf-8')
        service = service.replace('User=corevia', 'User=' + user).replace('Group=corevia', 'Group=' + group)
        service = service.replace('/srv/corevia/current', str(fixture / 'app')).replace('/srv/corevia/shared/storage', str(fixture)).replace('/etc/corevia/app.env', str(fixture / 'app.env'))
        (fixture / 'corevia-queue.service').write_text(service, encoding='utf-8')
        (fixture / 'corevia-queue.timer').write_bytes((recipes / 'vps/corevia-queue.timer').read_bytes())
        subprocess.run([analyzer, 'verify', str(fixture / 'corevia-queue.service'), str(fixture / 'corevia-queue.timer')], check=True, capture_output=True)
        report['checks'].append('VPS FPM template exercised; systemd unit/timer syntax checked without installation/start')
        subprocess.run(['sh', '-n', str(recipes / 'azure/startup.sh')], check=True, capture_output=True)
        report['checks'].append('Azure startup shell syntax only; script was not executed')
        report['application_checked'] = application is not None
        report['source_hashes'] = source_hashes
        report['source_unchanged'] = source_hashes == {str(p.relative_to(recipes)): hashlib.sha256(p.read_bytes()).hexdigest() for p in sorted(recipes.rglob('*')) if p.is_file()}
        report['application_source_hashes'] = application_hashes
        report['application_source_unchanged'] = application is None or application_hashes == {p.relative_to(application).as_posix():hashlib.sha256(p.read_bytes()).hexdigest() for p in sorted(application.rglob('*')) if p.is_file() and p.relative_to(application).parts[0] not in {'storage','.git'}}
        report['passed'] = report['source_unchanged'] and report['application_source_unchanged']
    finally:
        for child in reversed(children):
            if child.poll() is None:
                os.killpg(child.pid, signal.SIGTERM)
            try:
                child.wait(timeout=10)
            except subprocess.TimeoutExpired:
                os.killpg(child.pid, signal.SIGKILL); child.wait(timeout=5)
        for handle in handles:
            handle.close()
        report['owned_processes_stopped'] = all(child.poll() is not None for child in children)
        (fixture / 'report.json').write_text(json.dumps(report, indent=2) + '\n', encoding='utf-8')
    print(json.dumps({'fixture': str(fixture), **{k:v for k,v in report.items() if k not in {'source_hashes','application_source_hashes'}}}))


if __name__ == '__main__':
    main()
