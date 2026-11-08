"""Optional isolated Windows Apache/FastCGI acceptance. Never loads global httpd.conf or touches services."""
import http.cookiejar
import json
import shutil
import os
from pathlib import Path
import socket
import subprocess
import sys
import tempfile
import time
import urllib.error
import urllib.request

ROOT=Path(__file__).resolve().parents[2]
if os.name!='nt': raise SystemExit('SKIP optional Windows Apache fixture; use a dedicated target recipe on this platform.')
apache=Path(os.environ.get('COREVIA_APACHE_ROOT','C:/xampp8.2/apache'))
php=Path(os.environ.get('PHP_CGI_BINARY','C:/xampp8.2/php-8.3.35-nts-Win32-vs16-x64/php-cgi.exe'))
bundle=Path(sys.argv[1]).resolve()
if not bundle.is_relative_to((ROOT/'storage').resolve()) or not (bundle/'public_html/index.php').is_file(): raise SystemExit('Use a generated shared bundle beneath repository storage/.')
if not (apache/'bin/httpd.exe').is_file() or not php.is_file(): raise SystemExit('Apache and PHP CGI binaries are required for this optional fixture.')
def port():
    with socket.socket() as listener:
        listener.bind(('127.0.0.1',0));return listener.getsockname()[1]
http_port,fcgi_port=port(),port()
origin='http://127.0.0.1:'+str(http_port)
workspace=Path(tempfile.mkdtemp(prefix='apache-fixture-',dir=bundle.parent))
recipe=sys.argv[2] if len(sys.argv)>2 else None
if recipe is not None:
    if recipe not in {'shared','aws'}:raise SystemExit('Unknown Apache recipe.')
    copied=workspace/'bundle';shutil.copytree(bundle,copied);bundle=copied
    (bundle/'public_html/.htaccess').write_bytes((ROOT/'packages/cli/resources/hosting'/recipe/'apache.htaccess').read_bytes())
    for name in ['other.php','other.PHP','.env']:(bundle/'public_html'/name).write_text('PRIVATE_FIXTURE_MUST_NOT_BE_SERVED',encoding='utf-8')
modules=['authz_core','dir','mime','rewrite','proxy','proxy_fcgi']
configuration='\n'.join([
    'ServerRoot "'+apache.as_posix()+'"',
    'ServerName 127.0.0.1',
    'Listen 127.0.0.1:'+str(http_port),
    'PidFile "'+(workspace/'httpd.pid').as_posix()+'"',
    'ErrorLog "'+(workspace/'error.log').as_posix()+'"',
    'LogLevel warn',
    *['LoadModule '+name+'_module modules/mod_'+name+'.so' for name in modules],
    'TypesConfig "'+(apache/'conf/mime.types').as_posix()+'"',
    'ProxyFCGIBackendType GENERIC',
    'ProxyFCGISetEnvIf "true" SCRIPT_FILENAME "'+(bundle/'public_html/index.php').as_posix()+'"',
    'ProxyFCGISetEnvIf "true" SCRIPT_NAME "/index.php"',
    'DocumentRoot "'+(bundle/'public_html').as_posix()+'"',
    '<Directory />','Require all denied','</Directory>',
    '<Directory "'+(bundle/'public_html').as_posix()+'">','Require all granted','AllowOverride All','</Directory>',
    '<FilesMatch "\\.php$">','SetHandler "proxy:fcgi://127.0.0.1:'+str(fcgi_port)+'/"','</FilesMatch>',
])+'\n'
config=workspace/'httpd.conf';config.write_text(configuration,encoding='utf-8')
env=dict(os.environ)
for key in list(env):
    if key.startswith(('APP_','AUTH_','AI_','COREVIA_','MODEL_')):env.pop(key)
env.update(APP_ENV='development',APP_URL=origin,APP_HTTPS='false',COREVIA_ORIGINS=origin,AUTH_DELIVERY='disabled',PHP_FCGI_MAX_REQUESTS='0')
flags={'creationflags':subprocess.CREATE_NO_WINDOW}
subprocess.run([str(apache/'bin/httpd.exe'),'-f',str(config),'-t'],env=env,check=True,**flags)
children=[]
with (workspace/'process.log').open('wb') as log:
    try:
        children.append(subprocess.Popen([str(php),'-n','-d','cgi.fix_pathinfo=0','-b','127.0.0.1:'+str(fcgi_port)],env=env,stdout=log,stderr=log,**flags))
        for _ in range(100):
            try:
                with socket.create_connection(('127.0.0.1',fcgi_port),timeout=.1):break
            except OSError:time.sleep(.05)
        children.append(subprocess.Popen([str(apache/'bin/httpd.exe'),'-X','-f',str(config)],env=env,stdout=log,stderr=log,**flags))
        for _ in range(100):
            if children[-1].poll() is not None: raise RuntimeError('Isolated Apache exited; inspect private fixture logs.')
            try:
                with socket.create_connection(('127.0.0.1',http_port),timeout=.1):break
            except OSError:time.sleep(.05)
        client=urllib.request.build_opener(urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar()))
        def request(path,payload=None,csrf=None):
            headers={'Origin':origin}
            if payload is not None:headers['Content-Type']='application/json'
            if csrf:headers['X-CSRF-Token']=csrf
            message=urllib.request.Request(origin+path,data=None if payload is None else json.dumps(payload).encode(),headers=headers)
            try:response=client.open(message,timeout=10)
            except urllib.error.HTTPError as error:response=error
            with response:return response.status,response.read()
        for path in ['/','/hello/Apache','/login','/assets/auth.css']:
            status,body=request(path);assert status==200,(path,status,body[:160])
        for path in ['/private/corevia/config/auth.php','/vendor/autoload.php','/.env','/storage/auth/records.json']:assert request(path)[0] in {403,404},path
        if recipe:
            for path in ['/other.php','/other.PHP','/.env']:
                status,body=request(path);assert status in {403,404} and b'PRIVATE_FIXTURE' not in body,path
        session=json.loads(request('/auth/session')[1])
        payload={'name':'Apache fixture','email':'apache@example.test','password':'fixture-password-123','password_confirmation':'fixture-password-123'}
        assert request('/auth/register',payload)[0]==419
        assert request('/auth/register',payload,session['csrf'])[0] in {200,201}
        assert request('/auth/me')[0]==200
        print('PASS isolated Apache rewrite/static/private-path and PHP FastCGI CSRF/registration/session checks')
    finally:
        for child in reversed(children):
            if child.poll() is None:
                subprocess.run(['taskkill','/PID',str(child.pid),'/T','/F'],capture_output=True,**flags)
                child.wait(timeout=10)
print('Only owned fixture processes stopped; existing Apache/PHP services were not reconfigured.')
