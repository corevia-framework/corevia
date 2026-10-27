import assert from 'node:assert/strict';
import {spawn} from 'node:child_process';
import {createServer} from 'node:net';
import {mkdtemp,rm} from 'node:fs/promises';
import {resolve,join} from 'node:path';
const root=resolve('.');
const storage=await mkdtemp(join(root,'storage','web-http-'));
const listener=createServer();await new Promise(r=>listener.listen(0,'127.0.0.1',r));const port=listener.address().port;await new Promise(r=>listener.close(r));
const child=spawn(process.env.PHP_BINARY??'php',['-n','-S',`127.0.0.1:${port}`,'-t','public','public/index.php'],{windowsHide:true,env:{...process.env,COREVIA_STORAGE:storage},stdio:['ignore','pipe','pipe']});
let output='';child.stdout.on('data',d=>output+=d);child.stderr.on('data',d=>output+=d);
const base=`http://127.0.0.1:${port}`;
try{
  for(let i=0;i<100;i++){try{await fetch(base+'/up');break;}catch{await new Promise(r=>setTimeout(r,30));}}
  for(const path of ['/','/guide','/database','/hello/Ada','/contact']){const response=await fetch(base+path);assert.equal(response.status,200,output);assert.match(response.headers.get('content-type'),/text\/html/);assert.match(await response.text(),/corevia/);}
  const css=await fetch(base+'/assets/app.css');assert.equal(css.status,200);assert.match(css.headers.get('content-type'),/text\/css/);
  const redirect=await fetch(base+'/docs',{redirect:'manual'});assert.equal(redirect.status,302);assert.equal(redirect.headers.get('location'),'/guide');
  const head=await fetch(base+'/',{method:'HEAD'});assert.equal(head.status,200);assert.equal(await head.text(),'');
  for(const path of ['/storage/web-signing.secret','/config/settings/database.php','/resources/views/layout.php','/.env','/hello/invalid!']){assert.equal((await fetch(base+path)).status,404);}
  const form=await fetch(base+'/contact');const cookie=form.headers.get('set-cookie').split(';')[0];const html=await form.text();const token=html.match(/name="_token" value="([^"]+)"/)[1];
  const submit=body=>fetch(base+'/contact',{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded','Cookie':cookie,'Origin':base},body:new URLSearchParams(body)});
  assert.equal((await submit({name:'Ada',email:'ada@example.com',message:'Hello'})).status,419);
  const invalid=await submit({_token:token,name:'Ada',email:'invalid',message:'Hello'});assert.equal(invalid.status,422);
  const valid=await submit({_token:token,name:'Ada',email:'ada@example.com',message:'<script>alert(1)</script>'});assert.equal(valid.status,200);const result=await valid.text();assert.match(result,/&lt;script&gt;/);assert.equal(result.includes('<script>alert(1)</script>'),false);assert.match(valid.headers.get('content-security-policy'),/form-action 'self'/);
  console.log('PASS database-free homepage, guides, named parameter routes, redirects, HEAD and private-file denial');
  console.log('PASS real form cookie/CSRF, validation, HTML escaping and security headers');
}finally{child.kill();await new Promise(r=>{if(child.exitCode!==null)r();else child.once('exit',r);});if(!storage.startsWith(join(root,'storage','web-http-'))){throw new Error('Unsafe cleanup path');}await rm(storage,{recursive:true,force:true,maxRetries:5,retryDelay:100});}
