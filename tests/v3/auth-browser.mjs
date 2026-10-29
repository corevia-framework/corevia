import assert from 'node:assert/strict';
import {createRequire} from 'node:module';
import {spawn,spawnSync} from 'node:child_process';
import {mkdtemp,mkdir,cp,copyFile,readFile,writeFile,rm} from 'node:fs/promises';
import {createServer} from 'node:net';
import {join,resolve} from 'node:path';
const require=createRequire(import.meta.url),{chromium}=require('../../tools/node_modules/playwright-core');
const root=resolve('.'),php=process.env.PHP_BINARY??'php';
const directory=await mkdtemp(join(root,'storage','auth-browser-'));
const browser=await chromium.launch({headless:true,...(process.env.COREVIA_BROWSER?{executablePath:process.env.COREVIA_BROWSER}:{})});
const children=[];
function run(args,cwd){const result=spawnSync(php,['-n',...args],{cwd,windowsHide:true,encoding:'utf8',timeout:15000});assert.equal(result.status,0,result.stderr);return JSON.parse(result.stdout);}
async function port(){const server=createServer();await new Promise(r=>server.listen(0,'127.0.0.1',r));const value=server.address().port;await new Promise(r=>server.close(r));return value;}
try{
  for(const stack of ['bootstrap','tailwind','react']){
    const project=join(directory,stack);await mkdir(project);
    for(const name of ['packages','config','routes','resources','public','starters'])await cp(join(root,name),join(project,name),{recursive:true});
    for(const name of ['autoload.php','corevia'])await copyFile(join(root,name),join(project,name));
    const plan=run(['corevia','starter:auth',`--stack=${stack}`,'--dry-run'],project);assert.equal(plan.dry_run,true);
    const installed=run(['corevia','starter:auth',`--stack=${stack}`],project);assert.equal(installed.installed,true);
    assert.equal(run(['corevia','starter:auth',`--stack=${stack}`,'--check'],project).checked,true);
    const repeated=spawnSync(php,['-n','corevia','starter:auth',`--stack=${stack}`],{cwd:project,windowsHide:true,encoding:'utf8'});assert.equal(repeated.status,1);assert.match(repeated.stderr,/Refusing to overwrite/);
    const chosen=await port(),base=`http://127.0.0.1:${chosen}`;
    const env={...process.env,APP_ENV:'development',APP_URL:base,APP_HTTPS:'false',RUN_STORE:'json',COREVIA_STORAGE:join(project,'storage'),COREVIA_PROFILE:'web',COREVIA_BUILD_ARTIFACT:'',COREVIA_BUILD_KEY_FILE:''};
    const child=spawn(php,['-n','-S',`127.0.0.1:${chosen}`,'-t','public','public/index.php'],{cwd:project,windowsHide:true,env,stdio:['ignore','pipe','pipe']});children.push(child);let output='';child.stdout.on('data',d=>output+=d);child.stderr.on('data',d=>output+=d);
    let ready=false;for(let i=0;i<100;i++){try{if((await fetch(base+'/login')).status===200){ready=true;break;}}catch{}await new Promise(r=>setTimeout(r,30));}assert.ok(ready,output);
    const context=await browser.newContext(),page=await context.newPage(),external=[],errors=[];
    await context.route('**/*',route=>{if(new URL(route.request().url()).origin!==base){external.push(route.request().url());return route.abort();}return route.continue();});
    page.on('pageerror',error=>errors.push(error.message));
    const password='example-password-123',email=stack+'@example.test',name='<script>Ada</script>';
    await page.goto(base+'/register');await page.getByLabel('Your name').fill(name);await page.getByLabel('Email address').fill(email);await page.getByLabel('Password',{exact:true}).fill(password);await page.getByLabel('Confirm password').fill(password);
    await page.getByRole('button',{name:'Create account',exact:true}).click();await page.waitForURL('**/dashboard');
    await page.getByRole('heading',{name:'Welcome, '+name,exact:true}).waitFor();
    const card=await page.locator('main section').boundingBox();assert.ok(card.width>=280 && card.width<=600,'Auth stylesheet did not size the card.');
    assert.match(await page.locator('h1').innerText(),/Welcome, <script>Ada<\/script>/);assert.equal(await page.locator('h1 script').count(),0);
    const me=await context.request.get(base+'/auth/me');assert.equal(me.status(),200);const user=(await me.json()).user;assert.deepEqual(Object.keys(user).sort(),['email','id','name']);
    const session=await (await context.request.get(base+'/auth/session')).json();
    assert.equal((await context.request.post(base+'/auth/logout',{data:{}})).status(),419);
    assert.equal((await context.request.post(base+'/auth/logout',{headers:{Origin:'https://evil.test','X-CSRF-Token':session.csrf},data:{}})).status(),403);
    const beforeCookies=await context.cookies(),oldCookie=beforeCookies.find(c=>c.name==='corevia_auth');assert.ok(oldCookie?.httpOnly);assert.equal(oldCookie.sameSite,'Lax');
    const records=await readFile(join(project,'storage/auth/records.json'),'utf8');assert.equal(records.includes(password),false);assert.equal(records.includes(oldCookie.value),false);
    for(const path of ['/storage/auth/records.json','/config/auth.php','/frontend/auth/package.json'])assert.equal((await context.request.get(base+path)).status(),404);
    await page.getByRole('button',{name:'Sign out',exact:true}).click();await page.waitForURL('**/login');assert.equal((await context.request.get(base+'/auth/me')).status(),401);
    assert.equal((await fetch(base+'/auth/me',{headers:{Cookie:'corevia_auth='+oldCookie.value}})).status,401);
    await page.getByLabel('Email address').fill(email);await page.getByLabel('Password',{exact:true}).fill('wrong-password');await page.getByRole('button',{name:'Sign in',exact:true}).click();await page.getByRole('alert').waitFor();assert.match(await page.getByRole('alert').innerText(),/incorrect/);
    await page.getByLabel('Email address').fill(email.toUpperCase());await page.getByLabel('Password',{exact:true}).fill(password);await page.getByRole('button',{name:'Sign in',exact:true}).click();await page.waitForURL('**/dashboard');
    await page.getByRole('heading',{name:'Welcome, '+name,exact:true}).waitFor();
    assert.notEqual((await context.cookies()).find(c=>c.name==='corevia_auth').value,oldCookie.value);
    assert.equal(external.length,0);assert.deepEqual(errors,[]);assert.equal((await context.request.get(base+'/assets/auth.css')).status(),200);
    await context.close();
    for(const command of ['app:compile','app:verify']){const result=spawnSync(php,['-n','corevia',command],{cwd:project,windowsHide:true,env,encoding:'utf8'});assert.equal(result.status,0,result.stderr);}
    const drift=spawnSync(php,['-n','corevia','app:verify'],{cwd:project,windowsHide:true,env:{...env,AUTH_REGISTRATION:'false'},encoding:'utf8'});assert.equal(drift.status,1);assert.match(drift.stderr,/configuration changed/);
    await writeFile(join(project,'config/auth.php'),'hand edited');const changed=spawnSync(php,['-n','corevia','starter:auth',`--stack=${stack}`,'--check'],{cwd:project,windowsHide:true,encoding:'utf8'});assert.equal(changed.status,1);assert.match(changed.stderr,/edited/);
    console.log('PASS '+stack+' browser registration/login/logout, escaped dashboard, API/CSRF/origin/privacy guards and protected installer');
  }
}finally{
  await browser.close();
  for(const child of children){if(child.exitCode===null)child.kill();await new Promise(r=>{if(child.exitCode!==null||child.signalCode!==null)r();else child.once('exit',r);});}
  if(!directory.startsWith(join(root,'storage','auth-browser-')))throw new Error('Unsafe cleanup path');
  await rm(directory,{recursive:true,force:true,maxRetries:5,retryDelay:100});
}
