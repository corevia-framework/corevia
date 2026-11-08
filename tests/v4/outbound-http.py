"""Exercise actual bounded reads/redirect rejection against a literal loopback HTTP fixture."""
import json
import os
from pathlib import Path
import shutil
import subprocess
import sys
import threading
import time
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer

ROOT=Path(__file__).resolve().parents[2]
sys.path.insert(0,str(ROOT/'sdk/python'))
from corevia import Client, CoreviaError, Deadline, WorkBudgetError, work_context
leaks=[]

class Handler(BaseHTTPRequestHandler):
    def log_message(self,*args):
        pass
    def do_GET(self):
        if self.path=='/leak':
            leaks.append(True)
        if self.path=='/redirect':
            self.send_response(302);self.send_header('Location','/leak');self.end_headers();return
        if self.path=='/trickle':
            self.trickle();return
        body=json.dumps({'traceparent':self.headers.get('traceparent')}).encode()
        self.send_response(200);self.send_header('Content-Length',str(len(body)));self.end_headers();self.wfile.write(body)
    def do_POST(self):
        value=json.loads(self.rfile.read(int(self.headers['Content-Length'])))
        if value['target']=='redirect':
            self.send_response(302);self.send_header('Location','/leak');self.end_headers();return
        self.trickle()
    def trickle(self):
        self.send_response(200);self.send_header('Content-Length','1000');self.end_headers()
        try:
            for _ in range(100):
                self.wfile.write(b'x');self.wfile.flush();time.sleep(.02)
        except (OSError,ConnectionError):
            pass

server=ThreadingHTTPServer(('127.0.0.1',0),Handler)
thread=threading.Thread(target=server.serve_forever);thread.start()
origin='http://127.0.0.1:'+str(server.server_address[1])
try:
    result=subprocess.run([shutil.which('php'),str(ROOT/'tests/v4/outbound-http.php'),origin],cwd=ROOT,capture_output=True,text=True,timeout=10)
    assert result.returncode==0,result.stderr
    client=Client(origin,timeout=2,version='2.0')
    try:
        client.call('redirect',{})
    except CoreviaError:
        pass
    else:
        raise AssertionError('Python client followed redirect.')
    started=time.monotonic()
    try:
        with work_context(Deadline(150)):
            client.call('trickle',{})
    except (CoreviaError,WorkBudgetError):
        pass
    else:
        raise AssertionError('Python slow response exceeded work budget.')
    assert time.monotonic()-started<1.5
    assert not leaks,'A redirect leaked an outbound request.'
finally:
    server.shutdown();server.server_close();thread.join(timeout=5)
print('PASS actual PHP/Python slow-stream total budgets, trace propagation and redirect rejection')
