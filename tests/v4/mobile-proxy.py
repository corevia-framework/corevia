"""Optional loopback TLS/framing regression for the Android lab; no device or account needed."""
import argparse
import hashlib
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer
import http.client
import importlib.util
import json
import os
from pathlib import Path
import shutil
import ssl
import subprocess
import threading

ROOT=Path(__file__).resolve().parents[2]


def main():
    parser=argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--output',required=True,help='New private directory under storage/')
    args=parser.parse_args()
    output=Path(args.output)
    if not output.is_absolute():output=ROOT/output
    storage=(ROOT/'storage').resolve()
    if output.exists()or output.is_symlink()or output.resolve()==storage or not output.resolve().is_relative_to(storage):raise ValueError('Use a new private directory under storage.')
    output.mkdir(parents=True,mode=0o700)
    spec=importlib.util.spec_from_file_location('mobile_lab',ROOT/'tests/v4/mobile-lab.py')
    lab=importlib.util.module_from_spec(spec);spec.loader.exec_module(lab)
    openssl=shutil.which('openssl')
    if not openssl and Path('C:/xampp8.2/apache/bin/openssl.exe').is_file():openssl='C:/xampp8.2/apache/bin/openssl.exe'
    if not openssl:raise RuntimeError('Install OpenSSL for this optional TLS fixture.')
    cert=output/'certificate.pem';key=output/'certificate.key'
    command=[openssl,'req','-x509','-newkey','rsa:2048','-nodes','-days','1','-subj','/CN=127.0.0.1','-addext','subjectAltName=IP:127.0.0.1','-keyout',str(key),'-out',str(cert)]
    config=Path('C:/xampp8.2/apache/conf/openssl.cnf')
    if os.name=='nt'and config.is_file():command+=['-config',str(config)]
    subprocess.run(command,check=True,capture_output=True,timeout=30)

    class Echo(BaseHTTPRequestHandler):
        def do_POST(self):
            data=self.rfile.read(int(self.headers['Content-Length']))
            body=json.dumps({'sha256':hashlib.sha256(data).hexdigest()}).encode()
            self.send_response(200);self.send_header('Content-Length',str(len(body)));self.end_headers();self.wfile.write(body)
        def log_message(self,*args):pass
    upstream=ThreadingHTTPServer(('127.0.0.1',0),Echo)
    worker=threading.Thread(target=upstream.serve_forever,daemon=True);worker.start()
    frontend=None;checks=[];passed=False
    try:
        frontend,thread=lab.tls_frontend(upstream.server_port,cert,key)
        context=ssl.create_default_context(cafile=str(cert))
        def connect():return http.client.HTTPSConnection('127.0.0.1',frontend.server_port,context=context,timeout=5)
        data=b'{"a":1}'
        for body,chunked in [(data,False),(iter([data[:3],data[3:]]),True)]:
            connection=connect()
            try:
                connection.request('POST','/api/v1/sync/mutations',body,encode_chunked=chunked)
                response=connection.getresponse();value=json.loads(response.read())
                assert response.status==200 and value['sha256']==hashlib.sha256(data).hexdigest()
                checks.append('chunked payload intact'if chunked else'fixed-length payload intact')
            finally:connection.close()
        for headers,wire,name in [({'Transfer-Encoding':'chunked'},b'10001\r\n','oversized chunk rejected before body read'),({'Transfer-Encoding':'chunked','Content-Length':'1'},b'','ambiguous framing rejected'),({'Transfer-Encoding':'chunked'},b'ZZ\r\n','invalid chunk rejected')]:
            connection=connect()
            try:
                connection.putrequest('POST','/api/v1/sync/mutations')
                for field,value in headers.items():connection.putheader(field,value)
                connection.endheaders()
                if wire:connection.send(wire)
                response=connection.getresponse();assert response.status==400;response.read();checks.append(name)
            finally:connection.close()
        passed=True
    finally:
        if frontend is not None:frontend.shutdown();frontend.server_close();thread.join(timeout=10)
        upstream.shutdown();upstream.server_close();worker.join(timeout=10)
        report={'passed':passed,'checks':checks,'driver_sha256':hashlib.sha256((ROOT/'tests/v4/mobile-lab.py').read_bytes()).hexdigest(),'test_sha256':hashlib.sha256(Path(__file__).read_bytes()).hexdigest()}
        (output/'report.json').write_text(json.dumps(report,indent=2)+'\n',encoding='utf-8')
    print(json.dumps(report))


if __name__=='__main__':main()
