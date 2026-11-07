import assert from 'node:assert/strict';
import {createRequire} from 'node:module';
import {spawn} from 'node:child_process';
import {createServer} from 'node:net';
import {mkdir} from 'node:fs/promises';
import {resolve,join} from 'node:path';
const require=createRequire(import.meta.url),{chromium}=require('../../tools/node_modules/playwright-core');
const project=resolve(process.argv[2]??'.');
const reservation=createServer();await new Promise(r=>reservation.listen(0,'127.0.0.1',r));const port=reservation.address().port;await new Promise(r=>reservation.close(r));
const origin=`http://127.0.0.1:${port}`,env={...process.env,APP_ENV:'development',COREVIA_STORAGE:join(project,'storage'),APP_URL:origin,APP_HTTPS:'false',COREVIA_ORIGINS:origin,COREVIA_BUILD_ARTIFACT:''};
const server=spawn(process.env.PHP_BINARY??'php',['-n','-S',`127.0.0.1:${port}`,'-t','public','public/index.php'],{cwd:project,env,windowsHide:true,stdio:['ignore','ignore','pipe']});
let errors='';server.stderr.on('data',bytes=>errors+=bytes);
let browser;
try{
  for(let attempt=0;attempt<100;attempt++){try{if((await fetch(origin+'/up')).ok)break;}catch{}await new Promise(r=>setTimeout(r,50));}
  browser=await chromium.launch({headless:true,...(process.env.COREVIA_BROWSER?{executablePath:process.env.COREVIA_BROWSER}:{})});
  const page=await browser.newPage();const failures=[];page.on('pageerror',error=>failures.push(error.message));
  for(const width of [390,768,1440]){
    await page.setViewportSize({width,height:1000});await page.goto(origin);await page.getByRole('heading',{name:'A clear foundation. Room to build.'}).waitFor();
    assert.equal(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth),true,`Horizontal overflow at ${width}`);
    assert.equal((await page.locator('[data-theme-toggle]').innerText()).startsWith('Theme:'),true);
    await page.getByRole('link',{name:'Open your workspace'}).click();await page.getByRole('heading',{name:'Your workspace. Your next step.'}).waitFor();
    assert.ok(await page.getByText('No widgets or business records are configured yet.').isVisible());
  }
  await page.goto(origin);await page.getByRole('button',{name:'Change color theme'}).click();assert.equal(await page.locator('html').getAttribute('data-theme'),'light');
  await page.getByRole('button',{name:'Change color theme'}).click();assert.equal(await page.locator('html').getAttribute('data-theme'),'dark');
  await page.reload();assert.equal(await page.locator('html').getAttribute('data-theme'),'dark');
  await page.emulateMedia({reducedMotion:'reduce'});await page.goto(origin);await page.keyboard.press('Tab');assert.ok(await page.getByRole('link',{name:'Skip to content'}).evaluate(node=>document.activeElement===node));
  await page.locator('h1').click();
  const output=join(project,'storage','workspace-screenshots');await mkdir(output,{recursive:true});
  await page.setViewportSize({width:1440,height:1100});await page.screenshot({path:join(output,'dark-desktop.png'),fullPage:true});
  await page.getByRole('button',{name:'Change color theme'}).click();await page.getByRole('button',{name:'Change color theme'}).click();
  await page.setViewportSize({width:390,height:844});await page.screenshot({path:join(output,'mobile.png'),fullPage:true});
  assert.deepEqual(failures,[]);console.log('PASS V4 workspace mobile/tablet/desktop, empty states, theme persistence, keyboard focus, reduced-motion and CSP scripts');
}finally{if(browser)await browser.close();server.kill();await new Promise(r=>server.once('exit',r));}
