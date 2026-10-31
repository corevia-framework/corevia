import assert from 'node:assert/strict';
import {mkdtemp,writeFile,readFile,rm} from 'node:fs/promises';
import {join,resolve} from 'node:path';
import {execFile,execFileSync} from 'node:child_process';
import https from 'node:https';
import http from 'node:http';
const root=resolve('.'),php=process.env.PHP_BINARY??'php',directory=await mkdtemp(join(root,'storage','http-client-'));
const setup=String.raw`$c=$argv[1];$k=openssl_pkey_new(['private_key_type'=>OPENSSL_KEYTYPE_RSA,'private_key_bits'=>2048,'config'=>$c]);$r=openssl_csr_new(['commonName'=>'localhost'],$k,['config'=>$c]);$x=openssl_csr_sign($r,null,$k,1,['config'=>$c,'x509_extensions'=>'v3']);openssl_pkey_export($k,$p,null,['config'=>$c]);openssl_x509_export($x,$s);file_put_contents($argv[2],$p);file_put_contents($argv[3],$s);`;
const probe=String.raw`require $argv[1];try{$c=new Corevia\Http\NativeHttpClient(false,$argv[3]==='none'?null:$argv[3],(int)$argv[4]);$value='';foreach($c->stream('GET',$argv[2],['Authorization'=>'Bearer private-fixture-key']) as $chunk)$value.=$chunk;echo json_encode(['ok'=>true,'value'=>$value]);}catch(Throwable $error){echo json_encode(['ok'=>false,'message'=>$error->getMessage()]);}`;
function request(url,ca,timeout=5){return new Promise((resolve,reject)=>execFile(php,['-r',probe,join(root,'autoload.php'),url,ca,String(timeout)],{windowsHide:true,timeout:10000},(error,stdout,stderr)=>{if(error||stderr)return reject(error??new Error(stderr));try{resolve(JSON.parse(stdout));}catch(error){reject(error);}}));}
let server,plain;
try{
  const config=join(directory,'openssl.cnf'),key=join(directory,'key.pem'),cert=join(directory,'cert.pem');await writeFile(config,'[req]\ndistinguished_name=dn\nx509_extensions=v3\nprompt=no\n[dn]\nCN=localhost\n[v3]\nbasicConstraints=critical,CA:TRUE\nkeyUsage=critical,digitalSignature,keyEncipherment,keyCertSign\nextendedKeyUsage=serverAuth\nsubjectAltName=DNS:localhost\n');execFileSync(php,['-r',setup,config,key,cert],{windowsHide:true});let forwarded=0;
  server=https.createServer({key:await readFile(key),cert:await readFile(cert)},(req,res)=>{if(req.url==='/redirect'){res.writeHead(302,{Location:'/private'});res.end();}else if(req.url==='/private'){forwarded++;res.end('must not follow');}else if(req.url==='/slow'){res.writeHead(200);res.flushHeaders();setTimeout(()=>res.end('late'),1500);}else if(req.url==='/error'){res.writeHead(503);res.end('private upstream diagnostic');}else if(req.url==='/chunked'){res.writeHead(200);res.write('verified ');setTimeout(()=>res.end('response'),50);}else{assert.equal(req.headers.authorization,'Bearer private-fixture-key');res.end('verified response');}});server.on('tlsClientError',()=>{});await new Promise(r=>server.listen(0,'127.0.0.1',r));const base=`https://localhost:${server.address().port}`;
  assert.deepEqual(await request(base+'/ok',cert),{ok:true,value:'verified response'});assert.deepEqual(await request(base+'/chunked',cert),{ok:true,value:'verified response'});for(const [url,ca,timeout]of [[base+'/ok','none',5],[base.replace('localhost','127.0.0.1')+'/ok',cert,5],[base+'/redirect',cert,5],[base+'/error',cert,5],[base+'/slow',cert,1]]){const result=await request(url,ca,timeout);assert.equal(result.ok,false);assert.doesNotMatch(result.message,/private-fixture-key|upstream diagnostic/);}assert.equal(forwarded,0);console.log('PASS outbound HTTPS CA/hostname verification, chunked responses, redirect credential protection, deadlines and sanitized upstream errors');
  // Plain HTTP can set stale timeout metadata while returning buffered data.
  // A streaming consumer must receive the first fragment before the tail exists.
  let releaseTail;const tail=new Promise(resolve=>{releaseTail=resolve;});let complete=false;
  plain=http.createServer((req,res)=>{res.writeHead(200,{'Content-Type':'text/event-stream','Connection':'close'});res.write('first');tail.then(()=>{complete=true;res.end('last');});});
  await new Promise(resolve=>plain.listen(0,'127.0.0.1',resolve));
  const streaming=String.raw`require $argv[1];$c=new Corevia\Http\NativeHttpClient(allowLoopback:true,timeout:3);foreach($c->stream('GET',$argv[2]) as $chunk){echo $chunk;flush();}`;
  await new Promise((resolve,reject)=>{
    let received='';const child=execFile(php,['-r',streaming,join(root,'autoload.php'),`http://127.0.0.1:${plain.address().port}`],{windowsHide:true,timeout:5000},(error,stdout,stderr)=>{releaseTail();if(error||stderr)return reject(error??new Error(stderr));try{assert.equal(received,'firstlast');assert.equal(complete,true);resolve();}catch(error){reject(error);}});
    child.stdout.on('data',chunk=>{received+=chunk;try{if(!complete){assert.equal(received,'first');releaseTail();}}catch(error){releaseTail();reject(error);}});
  });
  console.log('PASS plain HTTP delivers buffered SSE data before the remaining response exists');
}finally{if(plain){plain.closeAllConnections();await new Promise(r=>plain.close(r));}if(server){server.closeAllConnections();await new Promise(r=>server.close(r));}if(!directory.startsWith(join(root,'storage','http-client-')))throw new Error('Unsafe cleanup');await rm(directory,{recursive:true,force:true,maxRetries:5,retryDelay:100});}
