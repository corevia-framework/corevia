import assert from 'node:assert/strict';
import {createRequire} from 'node:module';
import {spawn,spawnSync} from 'node:child_process';
import {mkdtemp,mkdir,cp,copyFile,readFile,writeFile,readdir,rm} from 'node:fs/promises';
import {createServer} from 'node:net';
import {createHmac} from 'node:crypto';
import {join,resolve} from 'node:path';
const require=createRequire(import.meta.url),{chromium}=require('../../tools/node_modules/playwright-core');
const root=resolve('.'),php=process.env.PHP_BINARY??'php';
const sql=process.env.COREVIA_AUTH_SQL==='1',crypto=sql||process.env.COREVIA_AUTH_CRYPTO==='1',phpFlags=crypto?[]:['-n'];
function totp(secret){let bits='';for(const char of secret)bits+='ABCDEFGHIJKLMNOPQRSTUVWXYZ234567'.indexOf(char).toString(2).padStart(5,'0');const bytes=Buffer.from(bits.match(/.{8}/g).map(byte=>parseInt(byte,2))),counter=Buffer.alloc(8);counter.writeBigUInt64BE(BigInt(Math.floor(Date.now()/30000)));const hash=createHmac('sha1',bytes).update(counter).digest(),offset=hash[19]&15;return ((hash.readUInt32BE(offset)&0x7fffffff)%1000000).toString().padStart(6,'0');}
const directory=await mkdtemp(join(root,'storage','auth-browser-'));
const browser=await chromium.launch({headless:true,...(process.env.COREVIA_BROWSER?{executablePath:process.env.COREVIA_BROWSER}:{})});
const children=[];
function run(args,cwd){const result=spawnSync(php,[...phpFlags,...args],{cwd,windowsHide:true,encoding:'utf8',timeout:15000});assert.equal(result.status,0,result.stderr);return JSON.parse(result.stdout);}
async function port(){const server=createServer();await new Promise(r=>server.listen(0,'127.0.0.1',r));const value=server.address().port;await new Promise(r=>server.close(r));return value;}
try{
  for(const stack of (process.env.COREVIA_AUTH_STACK?[process.env.COREVIA_AUTH_STACK]:['bootstrap','tailwind','react'])){
    const project=join(directory,stack);await mkdir(project);
    for(const name of ['packages','config','routes','resources','public','starters'])await cp(join(root,name),join(project,name),{recursive:true});
    for(const name of ['autoload.php','corevia'])await copyFile(join(root,name),join(project,name));
    const plan=run(['corevia','starter:auth',`--stack=${stack}`,'--dry-run'],project);assert.equal(plan.dry_run,true);
    const installed=run(['corevia','starter:auth',`--stack=${stack}`],project);assert.equal(installed.installed,true);
    assert.equal(run(['corevia','starter:auth',`--stack=${stack}`,'--check'],project).checked,true);
    const repeated=spawnSync(php,['-n','corevia','starter:auth',`--stack=${stack}`],{cwd:project,windowsHide:true,encoding:'utf8'});assert.equal(repeated.status,1);assert.match(repeated.stderr,/Refusing to overwrite/);
    const chosen=await port(),base=`http://127.0.0.1:${chosen}`;
    const env={...process.env,APP_ENV:'development',APP_URL:base,APP_HTTPS:'false',RUN_STORE:'json',COREVIA_STORAGE:join(project,'storage'),COREVIA_PROFILE:'web',COREVIA_BUILD_ARTIFACT:'',COREVIA_BUILD_KEY_FILE:'',AUTH_STORE:sql?'sql':'json',AUTH_CONNECTION:'sqlite',SQLITE_DATABASE:join(project,'storage/auth.sqlite'),AUTH_DELIVERY:'preview',AUTH_REQUIRE_VERIFIED_EMAIL:'false'};
    if(crypto){await mkdir(join(project,'app/Auth'),{recursive:true});await writeFile(join(project,'app/Auth/FixtureProvider.php'),String.raw`<?php namespace App\Auth; final class FixtureProvider implements \Corevia\Auth\OAuthProvider {public function authorizationEndpoint():string{return 'https://fixture-provider.example/authorize';}public function clientId():string{return 'fixture-client';}public function scopes():array{return ['identity'];}public function identity(string $code,string $verifier,string $redirectUri):string{if($code!=='fixture-code'||strlen($verifier)!==43)throw new \RuntimeException('Invalid fixture exchange');return 'fixture-subject';}}`);const file=join(project,'config/auth.php');await writeFile(file,(await readFile(file,'utf8')).replace("'oauth'=>[]","'oauth'=>['fixture'=>\\App\\Auth\\FixtureProvider::class]"));}
    if(sql){for(let i=0;i<2;i++){const migration=spawnSync(php,['corevia','auth:migrate'],{cwd:project,windowsHide:true,env,encoding:'utf8'});assert.equal(migration.status,0,migration.stderr);}}
    const child=spawn(php,[...phpFlags,'-S',`127.0.0.1:${chosen}`,'-t','public','public/index.php'],{cwd:project,windowsHide:true,env,stdio:['ignore','pipe','pipe']});children.push(child);let output='';child.stdout.on('data',d=>output+=d);child.stderr.on('data',d=>output+=d);
    let ready=false;for(let i=0;i<100;i++){try{if((await fetch(base+'/login')).status===200){ready=true;break;}}catch{}await new Promise(r=>setTimeout(r,30));}assert.ok(ready,output);
    const context=await browser.newContext(),page=await context.newPage(),external=[],errors=[];
    await context.route('**/*',route=>{const url=new URL(route.request().url());if(url.origin==='https://fixture-provider.example')return route.fulfill({contentType:'text/html',body:'<!doctype html><h1>In-memory provider fixture</h1>'});if(url.origin!==base){external.push(route.request().url());return route.abort();}return route.continue();});
    page.on('pageerror',error=>errors.push(error.message));
    const password='example-password-123',email=stack+'@example.test',name='<script>Ada</script>';
    await page.goto(base+'/register');await page.getByLabel('Your name').fill(name);await page.getByLabel('Email address').fill(email);await page.getByLabel('Password',{exact:true}).fill(password);await page.getByLabel('Confirm password').fill(password);
    await page.getByRole('button',{name:'Create account',exact:true}).click();try{await page.waitForURL('**/dashboard');}catch(error){console.error('Registration page:',await page.locator('body').innerText());console.error(output.slice(-2000));throw error;}
    await page.getByRole('heading',{name:'Welcome, '+name,exact:true}).waitFor();
    const card=await page.locator('main section').boundingBox();assert.ok(card.width>=280 && card.width<=600,'Auth stylesheet did not size the card.');
    assert.match(await page.locator('h1').innerText(),/Welcome, <script>Ada<\/script>/);assert.equal(await page.locator('h1 script').count(),0);
    const me=await context.request.get(base+'/auth/me');assert.equal(me.status(),200);const user=(await me.json()).user;assert.deepEqual(Object.keys(user).sort(),['email','id','name']);
    const session=await (await context.request.get(base+'/auth/session')).json();
    assert.equal((await context.request.post(base+'/auth/logout',{data:{}})).status(),419);
    assert.equal((await context.request.post(base+'/auth/logout',{headers:{Origin:'https://evil.test','X-CSRF-Token':session.csrf},data:{}})).status(),403);
    const beforeCookies=await context.cookies(),oldCookie=beforeCookies.find(c=>c.name==='corevia_auth');assert.ok(oldCookie?.httpOnly);assert.equal(oldCookie.sameSite,'Lax');
    const records=await readFile(join(project,sql?'storage/auth.sqlite':'storage/auth/records.json'));assert.equal(records.includes(Buffer.from(password)),false);assert.equal(records.includes(Buffer.from(oldCookie.value)),false);
    for(const path of ['/storage/auth/records.json','/config/auth.php','/frontend/auth/package.json'])assert.equal((await context.request.get(base+path)).status(),404);
    await page.getByRole('button',{name:'Sign out',exact:true}).click();await page.waitForURL('**/login');assert.equal((await context.request.get(base+'/auth/me')).status(),401);
    assert.equal((await fetch(base+'/auth/me',{headers:{Cookie:'corevia_auth='+oldCookie.value}})).status,401);
    await page.getByLabel('Email address').fill(email);await page.getByLabel('Password',{exact:true}).fill('wrong-password');await page.getByRole('button',{name:'Sign in',exact:true}).click();await page.getByRole('alert').waitFor();assert.match(await page.getByRole('alert').innerText(),/incorrect/);
    await page.getByLabel('Email address').fill(email.toUpperCase());await page.getByLabel('Password',{exact:true}).fill(password);await page.getByRole('button',{name:'Sign in',exact:true}).click();await page.waitForURL('**/dashboard');
    await page.getByRole('heading',{name:'Welcome, '+name,exact:true}).waitFor();
    assert.notEqual((await context.cookies()).find(c=>c.name==='corevia_auth').value,oldCookie.value);
    const outbox=join(project,'storage/auth/outbox');
    async function message(purpose){const values=await Promise.all((await readdir(outbox)).map(async name=>({name,...JSON.parse(await readFile(join(outbox,name),'utf8'))})));return values.find(value=>value.purpose===purpose);}
    const verification=await message('verify');assert.ok(verification?.link.includes('#token='));
    assert.equal((await context.request.get(base+'/auth/verified')).status(),403);
    const verifyToken=new URL(verification.link).hash.slice('#token='.length);assert.equal(records.includes(Buffer.from(verifyToken)),false);
    assert.equal((await context.request.get(base+'/storage/auth/outbox/'+verification.name)).status(),404);
    const listing=spawnSync(php,[...phpFlags,'corevia','auth:outbox'],{cwd:project,windowsHide:true,env,encoding:'utf8'});assert.equal(listing.status,0,listing.stderr);assert.equal(listing.stdout.includes(verifyToken),false);
    await page.goto(verification.link);await page.getByRole('button',{name:'Confirm email',exact:true}).click();await page.waitForURL('**/dashboard');await page.getByRole('heading',{name:'Welcome, '+name,exact:true}).waitFor();assert.equal((await context.request.get(base+'/auth/verified')).status(),200);
    const freshSession=await (await context.request.get(base+'/auth/session')).json();
    const replay=await context.request.post(base+'/auth/email/verify',{headers:{'X-CSRF-Token':freshSession.csrf},data:{token:verifyToken}});assert.equal(replay.status(),422);
    const existing=await context.request.post(base+'/auth/password/forgot',{headers:{'X-CSRF-Token':freshSession.csrf},data:{email:'missing@example.test'}});assert.equal(existing.status(),200);const generic=await existing.json();
    await page.goto(base+'/forgot-password');await page.getByLabel('Email address').fill(email);await page.getByRole('button',{name:'Send recovery link',exact:true}).click();await page.getByRole('status').filter({hasText:'If an account exists'}).waitFor();
    const reset=await message('reset');assert.ok(reset?.link.includes('#token='));assert.match(generic.message,/If an account exists/);
    const beforeReset=(await context.cookies()).find(cookie=>cookie.name==='corevia_auth').value;
    await page.goto(reset.link);await page.getByLabel('New password',{exact:true}).fill('changed-password-456');await page.getByLabel('Confirm password').fill('changed-password-456');await page.getByRole('button',{name:'Update password',exact:true}).click();await page.waitForURL('**/login');
    assert.equal((await fetch(base+'/auth/me',{headers:{Cookie:'corevia_auth='+beforeReset}})).status,401);
    await page.getByLabel('Email address').fill(email);await page.getByLabel('Password',{exact:true}).fill(password);await page.getByRole('button',{name:'Sign in',exact:true}).click();await page.getByRole('alert').waitFor();
    await page.getByLabel('Email address').fill(email);await page.getByLabel('Password',{exact:true}).fill('changed-password-456');await page.getByRole('button',{name:'Sign in',exact:true}).click();await page.waitForURL('**/dashboard');await page.getByRole('heading',{name:'Welcome, '+name,exact:true}).waitFor();
    assert.equal(output.includes(verifyToken),false);assert.equal(output.includes(new URL(reset.link).hash.slice('#token='.length)),false);
    if(stack!=='react'){
      const nojs=await browser.newContext({javaScriptEnabled:false}),fallback=await nojs.newPage();await fallback.goto(reset.link);
      await fallback.getByLabel('New password',{exact:true}).fill('never-log-this-password');await fallback.getByLabel('Confirm password').fill('never-log-this-password');
      const posted=fallback.waitForRequest(request=>request.url().endsWith('/auth/password/reset'));await fallback.getByRole('button',{name:'Update password'}).click();const submission=await posted;assert.equal(submission.method(),'POST');assert.equal(submission.url().includes('password='),false);await nojs.close();
    }
    // Shared account settings render in every kit, including the React starter.
    await page.getByRole('link',{name:'Account settings',exact:true}).click();await page.getByRole('heading',{name:'Account settings',exact:true}).waitFor();
    async function submitForm(action,values,button){const form=page.locator(`form[action="${action}"]`);for(const [key,value]of Object.entries(values))await form.locator(`[name="${key}"]`).fill(value);let captured;const intercept=async route=>{const response=await route.fetch();captured={status:response.status(),body:await response.json()};await route.fulfill({response});};await page.route(base+action,intercept);try{const received=page.waitForResponse(response=>response.url()===base+action&&response.request().method()==='POST');await form.getByRole('button',{name:button,exact:true}).click();await received;await page.waitForLoadState('networkidle');assert.equal(captured.status,200,JSON.stringify(captured.body));return captured.body;}finally{await page.unroute(base+action,intercept);}}
    await submitForm('/auth/account/profile',{name:'Updated '+stack,current_password:'changed-password-456'},'Save name');assert.equal((await(await context.request.get(base+'/auth/me')).json()).user.name,'Updated '+stack);
    let codes=[];
    if(crypto){
      const link=page.locator('form[action="/auth/oauth/fixture/start"]');await link.locator('[name="current_password"]').fill('changed-password-456');await link.getByRole('button',{name:'Link fixture',exact:true}).click();await page.waitForURL('https://fixture-provider.example/**');let params=new URL(page.url()).searchParams;assert.equal(params.get('code_challenge_method'),'S256');assert.equal(params.get('redirect_uri'),base+'/auth/oauth/fixture/callback');await page.goto(base+'/auth/oauth/fixture/callback?'+new URLSearchParams({state:params.get('state'),code:'fixture-code'}));await page.waitForURL('**/account');
      const old=(await context.cookies()).find(cookie=>cookie.name==='corevia_auth').value;
      const setup=await submitForm('/auth/mfa/setup',{current_password:'changed-password-456'},'Start authenticator setup');assert.match(setup.setup.uri,/^otpauth:\/\/totp\//);
      const enabled=await submitForm('/auth/mfa/enable',{current_password:'changed-password-456',code:totp(setup.setup.secret)},'Confirm authenticator setup');codes=enabled.recovery_codes;assert.equal(codes.length,8);await page.waitForFunction(()=>document.querySelector('[data-account-output]').textContent.includes('['));assert.equal((await fetch(base+'/auth/me',{headers:{Cookie:'corevia_auth='+old}})).status,401);
      await page.getByRole('link',{name:'Back to dashboard',exact:true}).click();await page.getByRole('button',{name:'Sign out',exact:true}).click();await page.waitForURL('**/login');await page.getByLabel('Email address').fill(email);await page.getByLabel('Password',{exact:true}).fill('changed-password-456');await page.getByRole('button',{name:'Sign in',exact:true}).click();await page.getByRole('alert').waitFor();await page.getByLabel('Email address').fill(email);await page.getByLabel('Password',{exact:true}).fill('changed-password-456');await page.getByLabel('Authenticator or recovery code (if enabled)').fill(codes[0]);await page.getByLabel('Remember me for up to 30 days').check();await page.getByRole('button',{name:'Sign in',exact:true}).click();await page.waitForURL('**/dashboard');assert.ok((await context.cookies()).find(cookie=>cookie.name==='corevia_auth').expires-Date.now()/1000>2500000);
      await page.getByRole('button',{name:'Sign out',exact:true}).click();await page.waitForURL('**/login');await page.getByRole('button',{name:'Continue with fixture',exact:true}).click();await page.waitForURL('https://fixture-provider.example/**');params=new URL(page.url()).searchParams;await page.goto(base+'/auth/oauth/fixture/callback?'+new URLSearchParams({state:params.get('state'),code:'fixture-code'}));await page.waitForURL('**/mfa-challenge');assert.equal((await context.request.get(base+'/auth/me')).status(),401);await page.getByLabel('Authenticator or recovery code',{exact:true}).fill(codes[1]);await page.getByRole('button',{name:'Sign in',exact:true}).click();await page.waitForURL('**/dashboard');await page.getByRole('link',{name:'Account settings',exact:true}).click();
    }
    await submitForm('/auth/account/password',{current_password:'changed-password-456',password:'final-password-789',password_confirmation:'final-password-789',code:codes[2]??''},'Save password');
    const updatedEmail='updated-'+email;await submitForm('/auth/account/email',{current_password:'final-password-789',email:updatedEmail,code:codes[3]??''},'Save email');const changedSession=await(await context.request.get(base+'/auth/session')).json();assert.equal(changedSession.user.email,updatedEmail);assert.equal(changedSession.email_verified,false);assert.equal((await context.request.get(base+'/auth/admin')).status(),403);
    function roles(value){const result=spawnSync(php,[...phpFlags,'corevia','auth:roles','--email='+updatedEmail,'--set='+value],{cwd:project,windowsHide:true,env,encoding:'utf8'});assert.equal(result.status,0,result.stderr);assert.equal(JSON.parse(result.stdout).sessions_revoked,true);}
    async function signBack(code){await page.goto(base+'/login');await page.getByLabel('Email address').fill(updatedEmail);await page.getByLabel('Password',{exact:true}).fill('final-password-789');if(code)await page.getByLabel('Authenticator or recovery code (if enabled)').fill(code);await page.getByRole('button',{name:'Sign in',exact:true}).click();await page.waitForURL('**/dashboard');}
    roles('admin');assert.equal((await context.request.get(base+'/auth/me')).status(),401);await signBack(codes[4]);assert.equal((await context.request.get(base+'/auth/admin')).status(),200);roles('');await signBack(codes[5]);assert.equal((await context.request.get(base+'/auth/admin')).status(),403);
    await page.getByRole('link',{name:'Account settings',exact:true}).click();await submitForm('/auth/account/delete',{confirmation:'DELETE',current_password:'final-password-789',code:codes[6]??''},'Delete account');await page.waitForURL('**/login');assert.equal((await context.request.get(base+'/auth/me')).status(),401);
    assert.equal(external.length,0);assert.deepEqual(errors,[]);assert.equal((await context.request.get(base+'/assets/auth.css')).status(),200);
    await context.close();
    for(const command of ['app:compile','app:verify']){const result=spawnSync(php,[...phpFlags,'corevia',command],{cwd:project,windowsHide:true,env,encoding:'utf8'});assert.equal(result.status,0,result.stderr);}
    const drift=spawnSync(php,[...phpFlags,'corevia','app:verify'],{cwd:project,windowsHide:true,env:{...env,AUTH_REGISTRATION:'false'},encoding:'utf8'});assert.equal(drift.status,1);assert.match(drift.stderr,/configuration changed/);
    await writeFile(join(project,'config/auth.php'),'hand edited');const changed=spawnSync(php,['-n','corevia','starter:auth',`--stack=${stack}`,'--check'],{cwd:project,windowsHide:true,encoding:'utf8'});assert.equal(changed.status,1);assert.match(changed.stderr,/edited/);
    console.log('PASS '+stack+' '+(sql?'SQLite':'JSON')+' browser auth/recovery/account deletion, role CLI/admin gating'+(crypto?', MFA/recovery/remember-me/provider-neutral OAuth':'')+', token privacy and protected installer');
  }
}finally{
  await browser.close();
  for(const child of children){if(child.exitCode===null)child.kill();await new Promise(r=>{if(child.exitCode!==null||child.signalCode!==null)r();else child.once('exit',r);});}
  if(!directory.startsWith(join(root,'storage','auth-browser-')))throw new Error('Unsafe cleanup path');
  await rm(directory,{recursive:true,force:true,maxRetries:5,retryDelay:100});
}
