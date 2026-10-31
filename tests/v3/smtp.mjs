import assert from 'node:assert/strict';
import {mkdtemp,writeFile,readFile,rm} from 'node:fs/promises';
import {join,resolve} from 'node:path';
import {execFile,execFileSync} from 'node:child_process';
import net from 'node:net';
import tls from 'node:tls';
const root=resolve('.'),php=process.env.PHP_BINARY??'php',directory=await mkdtemp(join(root,'storage','smtp-test-'));
const setup=String.raw`$config=$argv[1];$key=openssl_pkey_new(['private_key_type'=>OPENSSL_KEYTYPE_RSA,'private_key_bits'=>2048,'config'=>$config]);if($key===false)throw new RuntimeException('Fixture key generation failed');$csr=openssl_csr_new(['commonName'=>'localhost'],$key,['config'=>$config,'digest_alg'=>'sha256']);$cert=openssl_csr_sign($csr,null,$key,1,['config'=>$config,'x509_extensions'=>'v3','digest_alg'=>'sha256']);openssl_pkey_export($key,$pem,null,['config'=>$config]);openssl_x509_export($cert,$crt);file_put_contents($argv[2],$pem);file_put_contents($argv[3],$crt);`;
const probe=String.raw`require $argv[1];$settings=json_decode(base64_decode($argv[2]),true);try{(new Corevia\Auth\SmtpTransport($settings))->send(str_repeat('a',32),new Corevia\Auth\AccountMessage('recipient@example.test','reset',time()+600,'https://app.example.test/reset-password#token=private-fixture-link'));echo json_encode(['ok'=>true]);}catch(Throwable $error){echo json_encode(['ok'=>false,'message'=>$error->getMessage()]);}`;
function client(settings){return new Promise((resolve,reject)=>execFile(php,['-r',probe,join(root,'autoload.php'),Buffer.from(JSON.stringify(settings)).toString('base64')],{windowsHide:true,timeout:35000},(error,stdout,stderr)=>{if(error||stderr)return reject(error??new Error(stderr));try{resolve(JSON.parse(stdout));}catch(error){reject(error);}}));}
try{
  const config=join(directory,'openssl.cnf'),keyPath=join(directory,'key.pem'),certPath=join(directory,'cert.pem');
  await writeFile(config,'[req]\ndistinguished_name=dn\nx509_extensions=v3\nprompt=no\n[dn]\nCN=localhost\n[v3]\nbasicConstraints=critical,CA:TRUE\nkeyUsage=critical,digitalSignature,keyEncipherment,keyCertSign\nextendedKeyUsage=serverAuth\nsubjectAltName=DNS:localhost\n');
  execFileSync(php,['-r',setup,config,keyPath,certPath],{windowsHide:true});const key=await readFile(keyPath),cert=await readFile(certPath),secureContext=tls.createSecureContext({key,cert});
  async function fixture(mode,{advertise=true,trusted=true,hostname='localhost',auth='PLAIN'}={}){
    const sockets=new Set(),state={messages:[],auth:[],plaintextAuth:false};
    function handler(socket,encrypted,greet=true){let buffer='',data=false,lines=[],login=0;socket.on('error',()=>{});if(greet)socket.write('220 localhost fixture\r\n');
      const receive=bytes=>{buffer+=bytes.toString();let end;while((end=buffer.indexOf('\r\n'))>=0){const line=buffer.slice(0,end);buffer=buffer.slice(end+2);
        if(data){if(line==='.'){state.messages.push(lines.join('\r\n'));data=false;lines=[];socket.write('250 accepted\r\n');}else lines.push(line);continue;}
        if(login){state.auth.push(Buffer.from(line,'base64').toString());socket.write(login===1?'334 UGFzc3dvcmQ6\r\n':'235 authenticated\r\n');login=login===1?2:0;continue;}
        if(line.startsWith('EHLO ')){socket.write('250-localhost\r\n'+(!encrypted&&advertise?'250-STARTTLS\r\n':'')+'250 AUTH '+auth+'\r\n');}
        else if(line==='STARTTLS'){socket.removeListener('data',receive);socket.write('220 start TLS\r\n',()=>{const secure=new tls.TLSSocket(socket,{isServer:true,secureContext});handler(secure,true,false);});return;}
        else if(line.startsWith('AUTH ')){if(!encrypted)state.plaintextAuth=true;if(line==='AUTH LOGIN'){login=1;socket.write('334 VXNlcm5hbWU6\r\n');}else{state.auth.push(Buffer.from(line.slice('AUTH PLAIN '.length),'base64').toString());socket.write('235 authenticated\r\n');}}
        else if(line.startsWith('MAIL FROM:')||line.startsWith('RCPT TO:'))socket.write('250 recipient accepted\r\n');
        else if(line==='DATA'){data=true;socket.write('354 send data\r\n');}
        else if(line==='QUIT')socket.end('221 goodbye\r\n');else socket.write('500 unknown fixture command\r\n');
      }};socket.on('data',receive);
    }
    const server=mode==='smtps'?tls.createServer({key,cert},socket=>handler(socket,true)):net.createServer(socket=>handler(socket,false));server.on('tlsClientError',()=>{});server.on('connection',socket=>{sockets.add(socket);socket.on('close',()=>sockets.delete(socket));});
    await new Promise(resolve=>server.listen(0,'127.0.0.1',resolve));
    try{const result=await client({host:hostname,port:server.address().port,encryption:mode,username:'fixture-user',password:'fixture-password',from:'sender@example.test',...(trusted?{cafile:certPath}:{})});
      assert.equal(state.plaintextAuth,false);if(advertise&&trusted&&hostname==='localhost'){assert.equal(result.ok,true,JSON.stringify(result));assert.equal(state.messages.length,1);assert.match(state.messages[0],/Message-ID: <a{32}@example.test>/);assert.match(Buffer.from(state.messages[0].split('\r\n\r\n')[1],'base64').toString(),/private-fixture-link/);if(auth==='PLAIN')assert.deepEqual(state.auth,['\0fixture-user\0fixture-password']);else assert.deepEqual(state.auth,['fixture-user','fixture-password']);}
      else{assert.equal(result.ok,false);assert.equal(state.messages.length,0);assert.equal(state.auth.length,0);assert.doesNotMatch(result.message,/fixture-password|recipient@example.test|private-fixture-link/);}
    }finally{for(const socket of sockets)socket.destroy();await new Promise(resolve=>server.close(resolve));}
  }
  await fixture('smtps');await fixture('starttls');await fixture('smtps',{auth:'LOGIN'});await fixture('starttls',{advertise:false});await fixture('smtps',{trusted:false});await fixture('smtps',{hostname:'127.0.0.1'});
  console.log('PASS loopback SMTPS/STARTTLS, verified CA/hostname, AUTH PLAIN/LOGIN, MIME delivery and downgrade rejection (no external email)');
}finally{if(!directory.startsWith(join(root,'storage','smtp-test-')))throw new Error('Unsafe fixture cleanup');await rm(directory,{recursive:true,force:true,maxRetries:5,retryDelay:100});}
