"""Optional Android lab acceptance. Uses only a disposable project and its own test application."""
import argparse
import base64
import hashlib
import http.client
from http.server import BaseHTTPRequestHandler,ThreadingHTTPServer
import json
import os
from pathlib import Path
import re
import signal
import shutil
import socket
import ssl
import subprocess
import threading
import time
import uuid

ROOT=Path(__file__).resolve().parents[2]
PACKAGE='dev.corevia.lab.corevia_mobile_lab'


def backend_sources():
    result=subprocess.run(['git','-c',f'safe.directory={ROOT.as_posix()}','ls-files','-z','--','packages','app','config','contracts','autoload.php','composer.json','composer.lock','tests/v4/sync-router.php'],cwd=ROOT,capture_output=True,check=True)
    return {name:hashlib.sha256((ROOT/name).read_bytes()).hexdigest() for name in sorted(set(result.stdout.decode('utf-8').split('\0'))-{''})}


def tls_frontend(port,certificate,key):
    class Handler(BaseHTTPRequestHandler):
        def do_GET(self):self.forward()
        def do_POST(self):self.forward()
        def payload(self):
            lengths=self.headers.get_all('Content-Length',[])
            encoding=self.headers.get('Transfer-Encoding')
            if encoding:
                if lengths or encoding.lower()!='chunked':raise ValueError('Unsupported body framing')
                chunks=[];total=0
                for _ in range(1024):
                    line=self.rfile.readline(128)
                    if not re.fullmatch(rb'[0-9a-fA-F]{1,8}\r\n',line):raise ValueError('Invalid chunk')
                    size=int(line.strip(),16)
                    if size==0:
                        if self.rfile.read(2)!=b'\r\n':raise ValueError('Trailers unsupported in fixture')
                        return b''.join(chunks)
                    total+=size
                    if total>65536:raise ValueError('Body capacity exceeded')
                    chunk=self.rfile.read(size)
                    if len(chunk)!=size or self.rfile.read(2)!=b'\r\n':raise ValueError('Truncated chunk')
                    chunks.append(chunk)
                raise ValueError('Chunk capacity exceeded')
            if len(lengths)>1:raise ValueError('Ambiguous body length')
            length=int(lengths[0])if lengths else 0
            if not 0<=length<=65536:raise ValueError('Body capacity exceeded')
            body=self.rfile.read(length)
            if len(body)!=length:raise ValueError('Truncated body')
            return body
        def forward(self):
            if not self.path.startswith('/api/v1/sync/'):
                self.send_error(404);return
            try:body=self.payload()
            except ValueError:self.send_error(400);return
            headers={name:self.headers[name]for name in ['Authorization','Content-Type','Accept']if name in self.headers}
            connection=http.client.HTTPConnection('127.0.0.1',port,timeout=5)
            try:
                connection.request(self.command,self.path,body,headers)
                response=connection.getresponse();payload=response.read(2097153)
                if len(payload)>2097152:self.send_error(502);return
                self.send_response(response.status);self.send_header('Content-Type','application/json');self.send_header('Content-Length',str(len(payload)));self.end_headers();self.wfile.write(payload)
            finally:connection.close()
        def log_message(self,*args):pass
    context=ssl.SSLContext(ssl.PROTOCOL_TLS_SERVER);context.load_cert_chain(str(certificate),str(key))
    class Server(ThreadingHTTPServer):
        daemon_threads=True
        def get_request(self):
            connection,address=self.socket.accept();connection.settimeout(5)
            try:return context.wrap_socket(connection,server_side=True),address
            except Exception:connection.close();raise
    server=Server(('127.0.0.1',0),Handler)
    thread=threading.Thread(target=server.serve_forever,daemon=True);thread.start()
    return server,thread


def main():
    parser=argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--project',required=True,help='Generated lab root inside repository storage')
    parser.add_argument('--flutter',required=True)
    parser.add_argument('--android-sdk',required=True)
    parser.add_argument('--device',help='Explicit authorized ADB serial; omission runs build-machine checks only')
    parser.add_argument('--adb-port',type=int,default=5037)
    parser.add_argument('--target-platform',choices=['android-arm','android-arm64','android-x64'])
    args=parser.parse_args()
    project=Path(args.project);resolved=project.resolve(strict=True);storage=(ROOT/'storage').resolve()
    if not resolved.is_relative_to(storage)or resolved==storage:raise ValueError('Use only a generated private lab inside storage.')
    manifest=json.loads((project/'manifest.json').read_text(encoding='utf-8'))
    if manifest.get('fixture_only')is not True:raise ValueError('Expected a synthetic generated lab, not a user application.')
    if manifest.get('generator_sha256')!=hashlib.sha256((ROOT/'tools/v4-mobile-lab.py').read_bytes()).hexdigest():raise ValueError('Regenerate after generator changes.')
    for name,digest in manifest['source_hashes'].items():
        if hashlib.sha256((ROOT/name).read_bytes()).hexdigest()!=digest:raise ValueError('Regenerate the lab after source changes: '+name)
    app=project/'app';sdk=Path(args.android_sdk);flutter=Path(args.flutter)
    adb=sdk/'platform-tools'/('adb.exe'if os.name=='nt'else'adb')
    if not 1024<=args.adb_port<=65535:raise ValueError('Invalid ADB server port.')
    env=dict(os.environ,CI='true',FLUTTER_SUPPRESS_ANALYTICS='true',ANDROID_HOME=str(sdk),ANDROID_USER_HOME=str((project.parent/'android-user').resolve()),PUB_CACHE=str(project.parent/'pub-cache'),GRADLE_USER_HOME=str(project.parent/'gradle-cache'),ADB_SERVER_SOCKET=f'tcp:localhost:{args.adb_port}',ANDROID_ADB_SERVER_PORT=str(args.adb_port))
    count=int(env.get('GIT_CONFIG_COUNT','0'))
    for value in [flutter.parent.parent.as_posix(),flutter.parent.parent.resolve().as_posix()]:
        env[f'GIT_CONFIG_KEY_{count}']='safe.directory';env[f'GIT_CONFIG_VALUE_{count}']=value;count+=1
    env['GIT_CONFIG_COUNT']=str(count)
    logs=project/'acceptance';logs.mkdir(mode=0o700,exist_ok=False)
    driver_hash=hashlib.sha256(Path(__file__).read_bytes()).hexdigest()
    report={'passed':False,'release_ready':False,'native_device_tested':False,'source_hashes':manifest['source_hashes'],'driver_sha256':driver_hash,'checks':[],'scope':'synthetic Flutter Android lab only; not React Native/iOS, production auth, encryption or OEM/background certification'}
    report['generator_sha256']=manifest['generator_sha256']
    report['android_renderer']=manifest.get('android_renderer','flutter-default')
    report['backend_source_hashes']=backend_sources()
    server=None;server_log=None;reverse=None;tls=None;tls_thread=None

    def run(name,command,timeout=900,cwd=app,variables=env):
        start=time.monotonic()
        with (logs/(name+'.log')).open('wb')as log:
            process=subprocess.Popen([str(x)for x in command],cwd=cwd,env=variables,stdout=log,stderr=subprocess.STDOUT,start_new_session=os.name!='nt')
            try:code=process.wait(timeout=timeout)
            except subprocess.TimeoutExpired:
                # Windows batch wrappers otherwise leave the analyzer/Gradle child
                # alive. Terminate only this still-owned process tree.
                if os.name=='nt':
                    subprocess.run(['taskkill','/PID',str(process.pid),'/T','/F'],stdout=log,stderr=subprocess.STDOUT,timeout=30,check=True)
                else:os.killpg(process.pid,signal.SIGKILL)
                process.wait(timeout=30)
                report['checks'].append({'name':name,'timed_out':True,'seconds':round(time.monotonic()-start,3)})
                raise
        report['checks'].append({'name':name,'exit_code':code,'seconds':round(time.monotonic()-start,3)})
        print(('PASS 'if code==0 else'FAIL ')+name,flush=True)
        if code:raise RuntimeError('Inspect private lab log: '+name)
        return (logs/(name+'.log')).read_text(encoding='utf-8',errors='replace')

    def device(*command,timeout=20,allow_failure=False):
        return subprocess.run([str(adb),'-P',str(args.adb_port),'-s',args.device,*command],env=env,capture_output=True,text=True,timeout=timeout,check=not allow_failure).stdout

    try:
        target=args.target_platform or 'android-arm64'
        if args.device:
            if device('get-state').strip()!='device':raise RuntimeError('Authorized device is not connected.')
            abi=device('shell','getprop','ro.product.cpu.abi').strip()
            target=args.target_platform or {'arm64-v8a':'android-arm64','armeabi-v7a':'android-arm','x86_64':'android-x64'}.get(abi)
            if target is None:raise RuntimeError('Unsupported fixture device ABI.')
            report['abi']=abi
        report['target_platform']=target
        run('flutter-version',[flutter,'--version'])
        run('pub-get',[flutter,'pub','get'])
        run('analysis',[flutter,'analyze'],timeout=180)
        run('widget',[flutter,'test','test/widget_test.dart'])
        dart=flutter.parent/'cache/dart-sdk/bin'/('dart.exe'if os.name=='nt'else'dart')
        run('file-contract',[dart,'run','test/storage_contract.dart'])
        run('build-debug',[flutter,'build','apk','--debug','--target=lib/main.dart','--target-platform='+target,'--no-pub'])
        apk=app/'build/app/outputs/flutter-apk/app-debug.apk'
        if not apk.is_file():raise RuntimeError('Expected the generated lab debug APK.')
        report['apk_sha256']=hashlib.sha256(apk.read_bytes()).hexdigest()
        if args.device:
            if device('get-state').strip()!='device':raise RuntimeError('Authorized device is not connected.')
            lookup=subprocess.run([str(adb),'-P',str(args.adb_port),'-s',args.device,'shell','pm','path',PACKAGE],env=env,capture_output=True,text=True,timeout=20)
            existing=lookup.stdout.strip()
            # Android returns 1 with empty output when this package is absent.
            if lookup.stderr.strip()or lookup.returncode not in (0,1)or lookup.returncode==1 and existing or existing and not existing.startswith('package:'):
                raise RuntimeError('Selected device package lookup failed; refusing installation.')
            owner=project.parent/'corevia-lab-install.json'
            if existing and not owner.is_file():raise RuntimeError('Refusing to replace an existing app without a prior lab install receipt.')
            if owner.is_file():
                previous=json.loads(owner.read_text())
                if previous.get('package')!=PACKAGE or previous.get('device')!=args.device:raise RuntimeError('Existing lab receipt belongs to a different target; review ownership first.')
            php=shutil.which('php')
            if not php:raise RuntimeError('Native HTTP acceptance requires PHP/SQLite.')
            fixture=storage/('sync-http-mobile-'+uuid.uuid4().hex);fixture.mkdir(mode=0o700)
            with socket.socket()as listener:listener.bind(('127.0.0.1',0));port=listener.getsockname()[1]
            backend_env=os.environ.copy()
            for key in list(backend_env):
                if key.startswith(('APP_','AUTH_','AI_','COREVIA_','MODEL_','RUN_','DB_','DATABASE_','QUEUE_','SMTP_','OAUTH_')):backend_env.pop(key)
            backend_env.update(APP_ENV='testing',AUTH_DELIVERY='disabled',RUN_STORE='json',DB_CONNECTION='sqlite',COREVIA_STORAGE=str(fixture/'runtime'),COREVIA_TEST_SYNC_DIRECTORY=str(fixture),COREVIA_TEST_SYNC_HTTP='1',COREVIA_DB_TESTS='0')
            run('backend-prepare',[php,'tests/v4/sync-router.php','prepare',fixture],cwd=ROOT,variables=backend_env)
            run('backend-version',[php,'--version'],cwd=ROOT,variables=backend_env)
            server_log=(logs/'backend-server.log').open('wb')
            server=subprocess.Popen([php,'-S',f'127.0.0.1:{port}',str(ROOT/'tests/v4/sync-router.php')],cwd=ROOT,env=backend_env,stdout=server_log,stderr=server_log)
            for attempt in range(100):
                if server.poll()is not None:raise RuntimeError('Private backend exited.')
                try:
                    with socket.create_connection(('127.0.0.1',port),timeout=0.1):break
                except OSError:time.sleep(0.05)
            else:raise RuntimeError('Private backend startup timed out.')
            openssl=shutil.which('openssl')
            fallback=Path('C:/xampp8.2/apache/bin/openssl.exe')
            if not openssl and fallback.is_file():openssl=str(fallback)
            if not openssl:raise RuntimeError('Native TLS acceptance needs OpenSSL on the build machine.')
            certificate=logs/'fixture-ca.pem';key=logs/'fixture-ca.key'
            command=[openssl,'req','-x509','-newkey','rsa:2048','-nodes','-days','1','-subj','/CN=127.0.0.1','-addext','subjectAltName=IP:127.0.0.1','-keyout',key,'-out',certificate]
            configuration=Path('C:/xampp8.2/apache/conf/openssl.cnf')
            if os.name=='nt'and configuration.is_file():command+=['-config',configuration]
            run('test-certificate',command)
            tls,tls_thread=tls_frontend(port,certificate,key);tls_port=tls.server_address[1]
            device('reverse','--no-rebind',f'tcp:{tls_port}',f'tcp:{tls_port}');reverse=tls_port
            # Receipt authorizes only this fixture ID and explicitly selected phone, never another app.
            owner.write_text(json.dumps({'package':PACKAGE,'device':args.device,'fixture_project':str(project)},indent=2)+'\n',encoding='utf-8')
            ca=base64.b64encode(certificate.read_bytes()).decode('ascii')
            renderer=['--no-enable-impeller']if report['android_renderer']=='legacy-opengl'else[]
            output=run('device-contract',[flutter,'test','integration_test/device_test.dart','-d',args.device,*renderer,'--dart-define=COREVIA_SYNC_URL=https://127.0.0.1:'+str(tls_port),'--dart-define=COREVIA_TEST_CA='+ca],timeout=600)
            match=re.search(r'COREVIA_RESTART_PROBE_ID=(restart-[0-9]+)',output)
            if not match:raise RuntimeError('No native restart probe was produced.')
            probe=match[1]
            # Integration-test APKs are not the standalone lab. Rebuild the ordinary entrypoint.
            run('build-standalone',[flutter,'build','apk','--debug','--target=lib/main.dart','--target-platform='+target,'--no-pub'])
            run('install-standalone',[adb,'-P',str(args.adb_port),'-s',args.device,'install','-r',apk],timeout=180)
            # The ordinary app must relaunch without the PC fixture backend or reverse tunnel.
            device('reverse','--remove',f'tcp:{reverse}');reverse=None
            tls.shutdown();tls.server_close();tls_thread.join(timeout=10);tls=None
            server.terminate();server.wait(timeout=10)
            previous_launch=None
            for iteration in range(2):
                device('shell','am','force-stop',PACKAGE)
                old_marker=device('shell','run-as',PACKAGE,'cat','files/corevia-lab-v1/launch.json',allow_failure=True)
                if old_marker.strip():previous_launch=json.loads(old_marker).get('launch_id')
                device('shell','am','start','-W','-n',PACKAGE+'/.MainActivity')
                for attempt in range(100):
                    # A process ID does not mean Dart has finished starting.
                    # Wait for this entrypoint's fresh, flushed launch marker.
                    try:
                        launch=json.loads(device('shell','run-as',PACKAGE,'cat','files/corevia-lab-v1/launch.json',allow_failure=True))
                        if launch.get('standalone')is True and launch.get('launch_id')!=previous_launch:break
                    except (ValueError,TypeError):pass
                    time.sleep(0.1)
                else:raise RuntimeError('Standalone lab failed to launch.')
                saved=device('shell','run-as',PACKAGE,'cat','files/corevia-lab-v1/client.json')
                envelope=json.loads(saved);state=json.loads(envelope['payload'])
                if not any(item['id']==probe for item in state['pending']):raise RuntimeError('Native queued probe did not survive relaunch.')
                launch=json.loads(device('shell','run-as',PACKAGE,'cat','files/corevia-lab-v1/launch.json'))
                if launch.get('standalone')is not True or launch.get('launch_id')==previous_launch:raise RuntimeError('Ordinary standalone entrypoint did not run.')
                previous_launch=launch['launch_id']
            report['native_device_tested']=True
            report['process_restart_probe_retained']=True
            report['standalone_relaunched_without_backend']=True
            report['native_tls_and_untrusted_ca_wrong_host_rejection']=True
            report['apk_sha256']=hashlib.sha256(apk.read_bytes()).hexdigest()
            report['android_version']=device('shell','getprop','ro.build.version.release').strip()
        report['source_unchanged']=all(hashlib.sha256((ROOT/name).read_bytes()).hexdigest()==digest for name,digest in manifest['source_hashes'].items()) and hashlib.sha256(Path(__file__).read_bytes()).hexdigest()==driver_hash and backend_sources()==report['backend_source_hashes'] and hashlib.sha256((ROOT/'tools/v4-mobile-lab.py').read_bytes()).hexdigest()==manifest['generator_sha256']
        report['build_input_hashes']={name:hashlib.sha256((app/name).read_bytes()).hexdigest() for name in ['pubspec.lock','.metadata','android/app/src/main/AndroidManifest.xml','android/settings.gradle.kts','android/app/build.gradle.kts','android/gradle.properties','android/gradle/wrapper/gradle-wrapper.properties']}
        report['passed']=report['source_unchanged']
    finally:
        if reverse is not None:
            try:device('reverse','--remove',f'tcp:{reverse}')
            except Exception:report['reverse_cleanup_failed']=True
        if tls is not None:
            tls.shutdown();tls.server_close();tls_thread.join(timeout=10)
        if server is not None and server.poll()is None:
            server.terminate()
            try:server.wait(timeout=10)
            except subprocess.TimeoutExpired:server.kill();server.wait(timeout=5)
        if server_log:server_log.close()
        report['owned_backend_stopped']=server is None or server.poll()is not None
        report['passed']=report['passed'] and report['owned_backend_stopped'] and not report.get('reverse_cleanup_failed',False)
        (logs/'report.json').write_text(json.dumps(report,indent=2)+'\n',encoding='utf-8')
    print(json.dumps({key:value for key,value in report.items()if key not in {'source_hashes','backend_source_hashes','build_input_hashes'}}))


if __name__=='__main__':main()
