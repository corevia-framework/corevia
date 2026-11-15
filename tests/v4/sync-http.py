"""Native JS/Dart clients against an isolated PHP/SQLite HTTP API; no live accounts or databases."""
import os
from pathlib import Path
import shutil
import socket
import subprocess
import sys
import tempfile
import time
import urllib.request

ROOT=Path(__file__).resolve().parents[2]
directory=Path(tempfile.mkdtemp(prefix='sync-http-',dir=ROOT/'storage'))
php=shutil.which('php');dart=os.environ.get('COREVIA_DART') or shutil.which('dart')
if not dart:
    cached=ROOT/'storage/dart-tools/dart-sdk/bin'/('dart.exe' if os.name=='nt' else 'dart')
    if cached.is_file():dart=str(cached)
if not php or not dart:raise RuntimeError('Native sync acceptance requires PHP PDO SQLite and a Dart SDK; missing runtimes are not a pass.')
subprocess.run([php,str(ROOT/'tests/v4/sync-router.php'),'prepare',str(directory)],cwd=ROOT,check=True,capture_output=True)
with socket.socket() as probe:probe.bind(('127.0.0.1',0));port=probe.getsockname()[1]
origin='http://127.0.0.1:'+str(port)
env=dict(os.environ,COREVIA_TEST_SYNC_HTTP='1',COREVIA_TEST_SYNC_DIRECTORY=str(directory),APP_ENV='development')
with (directory/'server.log').open('w') as log:
    child=subprocess.Popen([php,'-S','127.0.0.1:'+str(port),str(ROOT/'tests/v4/sync-router.php')],cwd=ROOT,env=env,stdout=log,stderr=subprocess.STDOUT)
    try:
        for _ in range(100):
            try:
                with urllib.request.urlopen(urllib.request.Request(origin+'/api/v1/sync/changes',headers={'Authorization':'Bearer alpha-test'}),timeout=.2):break
            except OSError:time.sleep(.05)
        else:raise RuntimeError('Isolated sync HTTP server failed to start.')
        for phase in ['lost','resume']:
            subprocess.run(['node',str(ROOT/'tests/v4/sync-js.mjs'),origin,str(directory),phase],cwd=ROOT,check=True,timeout=30)
        subprocess.run([dart,'run',str(ROOT/'sdk/dart/test/sync_http.dart'),origin,str(directory)],cwd=ROOT,check=True,timeout=45)
        subprocess.run(['node',str(ROOT/'tests/v4/sync-js.mjs'),origin,str(directory),'inspect-dart'],cwd=ROOT,check=True,timeout=30)
    finally:
        child.terminate()
        try:child.wait(timeout=5)
        except subprocess.TimeoutExpired:child.kill();child.wait(timeout=5)
print('PASS native JS/Dart offline workflows against actual PHP API; owned HTTP server stopped; private artifacts: '+str(directory))
