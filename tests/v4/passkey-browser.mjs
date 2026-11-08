import assert from 'node:assert/strict';
import {createRequire} from 'node:module';
import {spawn} from 'node:child_process';
import {createServer} from 'node:net';
import {mkdir} from 'node:fs/promises';
import {resolve,join,dirname} from 'node:path';
import {fileURLToPath} from 'node:url';
import {createHash,createPrivateKey,randomUUID,sign} from 'node:crypto';
const require=createRequire(import.meta.url),{chromium}=require('../../tools/node_modules/playwright-core');
const root=resolve(dirname(fileURLToPath(import.meta.url)),'../..');
async function availablePort(){const reservation=createServer();await new Promise(r=>reservation.listen(0,'127.0.0.1',r));const port=reservation.address().port;await new Promise(r=>reservation.close(r));return port;}
const browser=await chromium.launch({headless:true,...(process.env.COREVIA_BROWSER?{executablePath:process.env.COREVIA_BROWSER}:{})});
try{for(const driver of ['json','sqlite']){
  const port=await availablePort(),origin=`http://localhost:${port}`,directory=join(root,'storage',`v4-passkey-browser-${randomUUID()}-${driver}`);await mkdir(directory,{recursive:false});
  const env={...process.env,APP_ENV:'development',APP_URL:origin,APP_HTTPS:'false',COREVIA_BUILD_ARTIFACT:'',COREVIA_PASSKEY_FIXTURE:'1',COREVIA_PASSKEY_STORAGE:directory,COREVIA_PASSKEY_SQL:driver==='sqlite'?'1':'0'};
  const server=spawn(process.env.PHP_BINARY??'php',['-d','display_errors=0','-d',`error_log=${join(directory,'php.log')}`,'-S',`127.0.0.1:${port}`,'tests/v4/passkey-router.php'],{cwd:root,env,windowsHide:true,stdio:'ignore'});
  const context=await browser.newContext();
  try{
    let ready=false;for(let attempt=0;attempt<150;attempt++){try{if((await fetch(origin)).ok){ready=true;break;}}catch{}await new Promise(r=>setTimeout(r,50));}assert.ok(ready,'Passkey fixture did not start; inspect private php.log.');
    const page=await context.newPage();await page.goto(origin);const cdp=await context.newCDPSession(page);await cdp.send('WebAuthn.enable');
    const {authenticatorId}=await cdp.send('WebAuthn.addVirtualAuthenticator',{options:{protocol:'ctap2',transport:'internal',hasResidentKey:true,hasUserVerification:true,isUserVerified:true,automaticPresenceSimulation:true}});
    async function post(path,body={}){return page.evaluate(async({path,body})=>{const session=await (await fetch('/auth/session')).json();const response=await fetch(path,{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/json','X-CSRF-Token':session.csrf},body:JSON.stringify(body)});return {status:response.status,body:await response.json()};},{path,body});}
    async function ceremony(kind){
      const result=await post(`/auth/passkeys/${kind}/options`,kind==='register'?{current_password:'fixture-password-123',label:'Virtual fixture'}:{});assert.equal(result.status,200);
      return page.evaluate(async({kind,options})=>{
        const decode=value=>Uint8Array.from(atob(value.replace(/-/g,'+').replace(/_/g,'/')),c=>c.charCodeAt(0));
        const encode=value=>btoa(String.fromCharCode(...new Uint8Array(value))).replace(/\+/g,'-').replace(/\//g,'_').replace(/=+$/,'');
        options.challenge=decode(options.challenge);if(options.user)options.user.id=decode(options.user.id);for(const item of options.excludeCredentials??options.allowCredentials??[])item.id=decode(item.id);
        const credential=kind==='register'?await navigator.credentials.create({publicKey:options}):await navigator.credentials.get({publicKey:options});
        const response={clientDataJSON:encode(credential.response.clientDataJSON)};
        if(kind==='register'){response.attestationObject=encode(credential.response.attestationObject);response.transports=credential.response.getTransports();}
        else{response.authenticatorData=encode(credential.response.authenticatorData);response.signature=encode(credential.response.signature);response.userHandle=credential.response.userHandle?encode(credential.response.userHandle):null;}
        return {id:credential.id,rawId:encode(credential.rawId),type:credential.type,response,clientExtensionResults:credential.getClientExtensionResults()};
      },{kind,options:result.body.publicKey});
    }
    assert.equal((await post('/auth/register',{name:'Passkey fixture',email:'passkey@example.test',password:'fixture-password-123',password_confirmation:'fixture-password-123'})).status,200);
    // Exact-origin rejection during registration, including a different port on the same host.
    const invalid=await ceremony('register');const client=JSON.parse(Buffer.from(invalid.response.clientDataJSON,'base64url').toString());client.origin=`http://localhost:${port+1}`;invalid.response.clientDataJSON=Buffer.from(JSON.stringify(client)).toString('base64url');
    assert.equal((await post('/auth/passkeys/register/finish',{credential:invalid})).status,401);
    for(const credential of (await cdp.send('WebAuthn.getCredentials',{authenticatorId})).credentials){await cdp.send('WebAuthn.removeCredential',{authenticatorId,credentialId:credential.credentialId});}
    const enrolled=await ceremony('register');assert.equal((await post('/auth/passkeys/register/finish',{credential:enrolled})).status,200);assert.equal((await post('/auth/passkeys/register/finish',{credential:enrolled})).status,401);
    const list=await page.evaluate(async()=>await (await fetch('/auth/passkeys')).json());assert.equal(list.passkeys.length,1);const enrolledId=list.passkeys[0].id;
    await post('/auth/logout');const signed=await ceremony('login');assert.equal((await post('/auth/passkeys/login/finish',{credential:signed})).status,200);const counter=Buffer.from(signed.response.authenticatorData,'base64url').readUInt32BE(33);assert.ok(counter>0);
    const virtual=(await cdp.send('WebAuthn.getCredentials',{authenticatorId})).credentials[0];const key=createPrivateKey({key:Buffer.from(virtual.privateKey,'base64'),format:'der',type:'pkcs8'});
    for(const attack of ['uv','rp','signature','handle','counter']){
      await post('/auth/logout');const credential=await ceremony('login');const data=Buffer.from(credential.response.authenticatorData,'base64url');
      if(attack==='uv')data[32]&=~4;if(attack==='rp')createHash('sha256').update('other.invalid').digest().copy(data,0);if(attack==='counter')data.writeUInt32BE(counter,33);
      credential.response.authenticatorData=data.toString('base64url');credential.response.signature=sign('sha256',Buffer.concat([data,createHash('sha256').update(Buffer.from(credential.response.clientDataJSON,'base64url')).digest()]),key).toString('base64url');
      if(attack==='signature')credential.response.signature=Buffer.from('invalid').toString('base64url');if(attack==='handle')credential.response.userHandle=Buffer.from('different-user').toString('base64url');
      const result=await post('/auth/passkeys/login/finish',{credential});assert.equal(result.status,401,attack);assert.equal(result.body.error.code,'PASSKEY_FAILED',attack);
    }
    const fresh=await ceremony('login');assert.equal((await post('/auth/passkeys/login/finish',{credential:fresh})).status,200);
    assert.equal((await post('/auth/passkeys/remove',{id:enrolledId,current_password:'fixture-password-123'})).status,200);await post('/auth/logout');const revoked=await ceremony('login');assert.equal((await post('/auth/passkeys/login/finish',{credential:revoked})).status,401);
    await cdp.send('WebAuthn.removeVirtualAuthenticator',{authenticatorId});console.log(`PASS ${driver} real WebAuthn verifier: enrollment/login/removal, replay, exact origin/port, signed UV/RP/counter negatives, signature and user-handle rejection`);
  }finally{await context.close();if(server.exitCode===null&&server.signalCode===null){const exited=new Promise(r=>server.once('exit',r));server.kill();await exited;}}
}}finally{await browser.close();}
