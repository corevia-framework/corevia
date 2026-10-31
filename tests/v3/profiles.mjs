import assert from 'node:assert/strict';
import {spawn,spawnSync} from 'node:child_process';
import {createServer} from 'node:net';
import {mkdtemp,rm,readdir,readFile} from 'node:fs/promises';
import {join,resolve} from 'node:path';
const root=resolve('.'),php=process.env.PHP_BINARY??'php';
const storage=await mkdtemp(join(root,'storage','profiles-live-'));
const children=[];
const delay=ms=>new Promise(r=>setTimeout(r,ms));
async function port(){const s=createServer();await new Promise(r=>s.listen(0,'127.0.0.1',r));const p=s.address().port;await new Promise(r=>s.close(r));return p;}
function start(args,env){const child=spawn(php,['-n','-d','disable_functions=ctype_digit',...args],{windowsHide:true,env:{...process.env,COREVIA_BUILD_ARTIFACT:'',COREVIA_BUILD_KEY_FILE:'',...env},stdio:['ignore','pipe','pipe']});children.push(child);let output='';child.stdout.on('data',d=>output+=d);child.stderr.on('data',d=>output+=d);return {child,output:()=>output};}
function cli(args,env){return spawnSync(php,['-n','-d','disable_functions=ctype_digit','corevia',...args],{windowsHide:true,env:{...process.env,COREVIA_BUILD_ARTIFACT:'',COREVIA_BUILD_KEY_FILE:'',...env},encoding:'utf8',timeout:10000});}
try{
  const apiStorage=join(storage,'api'),apiEnv={COREVIA_PROFILE:'api',COREVIA_STORAGE:apiStorage};
  const apiPort=await port(),api=start(['-S',`127.0.0.1:${apiPort}`,'-t','public','public/index.php'],apiEnv),base=`http://127.0.0.1:${apiPort}`;
  let ready=false;for(let i=0;i<100;i++){try{const response=await fetch(base+'/health');if(response.status===200){ready=true;break;}}catch{}await delay(30);}assert.ok(ready,api.output());
  assert.deepEqual(await (await fetch(base+'/health')).json(),{status:'ok'});
  for(const path of ['/','/contact','/checkout','/cvp'])assert.equal((await fetch(base+path)).status,404);
  assert.equal((await readdir(apiStorage)).includes('web-signing.secret'),false);
  let result=cli(['doctor'],apiEnv);assert.equal(result.status,0,result.stderr);assert.equal(JSON.parse(result.stdout).profile,'api');
  const workerEnv={COREVIA_PROFILE:'worker',COREVIA_STORAGE:join(storage,'worker')};
  result=cli(['worker:start','--once'],workerEnv);assert.equal(result.status,0,result.stderr);
  for(const command of ['serve','route:list','realtime:start']){result=cli([command],workerEnv);assert.equal(result.status,1);assert.match(result.stderr,/does not enable/);}
  const realtimeEnv={COREVIA_PROFILE:'realtime',COREVIA_STORAGE:join(storage,'realtime'),COREVIA_CONTROL_PORT:String(await port())};
  const realtime=start(['corevia','realtime:start',`--port=${await port()}`],realtimeEnv);
  let log='';for(let i=0;i<200 && !log.includes('Realtime started');i++){try{log=await readFile(join(realtimeEnv.COREVIA_STORAGE,'runtime.log'),'utf8');}catch{}await delay(20);}
  assert.match(log+realtime.output(),/Realtime started/);
  result=cli(['realtime:stop'],realtimeEnv);assert.equal(result.status,0,result.stderr);
  await Promise.race([new Promise(r=>{if(realtime.child.exitCode!==null)r();else realtime.child.once('exit',r);}),delay(5000)]);assert.equal(realtime.child.exitCode,0,realtime.output());
  console.log('PASS live API-only routes, profile doctor, database-free worker, denied adapters and realtime start/stop');
}finally{
  for(const child of children){if(child.exitCode===null)child.kill();await new Promise(r=>{if(child.exitCode!==null || child.signalCode!==null)r();else child.once('exit',r);});}
  if(!storage.startsWith(join(root,'storage','profiles-live-')))throw new Error('Unsafe cleanup path');
  await rm(storage,{recursive:true,force:true,maxRetries:5,retryDelay:100});
}
