import assert from 'node:assert/strict';
import {spawn} from 'node:child_process';
import {createServer,connect} from 'node:net';
import {createCorevia} from '../../sdk/js/index.js';
const delay=ms=>new Promise(r=>setTimeout(r,ms));
async function port(){const s=createServer();await new Promise(r=>s.listen(0,'127.0.0.1',r));const p=s.address().port;await new Promise(r=>s.close(r));return p;}
const [phpPort,pythonPort]=await Promise.all([port(),port()]);
const env={...process.env,PYTHONPATH:'sdk/python',V2_WORKER_URL:`http://127.0.0.1:${pythonPort}`};
const children=[];
function start(command,args){const child=spawn(command,args,{env,windowsHide:true,stdio:['ignore','pipe','pipe']});child.output='';child.stdout.on('data',d=>child.output+=d);child.stderr.on('data',d=>child.output+=d);child.on('error',e=>child.output+=e);children.push(child);return child;}
async function ready(port){const end=Date.now()+10000;while(Date.now()<end){const ok=await new Promise(r=>{const s=connect(port,'127.0.0.1');s.on('connect',()=>{s.destroy();r(true);});s.on('error',()=>r(false));});if(ok)return;await delay(25);}throw new Error(children.map(c=>c.output).join('\n'));}
try {
  start(process.env.PYTHON??'python',['tests/v2/python-worker.py']);start(process.env.PHP_BINARY??'php',['-S',`127.0.0.1:${phpPort}`,'tests/v2/gateway.php']);await Promise.all([ready(phpPort),ready(pythonPort)]);
  const client=createCorevia({baseURL:`http://127.0.0.1:${phpPort}`,token:'test-only-token'});
  const value=await client.action('bridge.echo',{id:42},{traceId:'trace-cross-v2',requestId:'req-cross-v2',parentTraceId:'parent-cross-v2'});
  assert.deepEqual(value.data,{id:42});assert.equal(value.requestId,'req-cross-v2');assert.equal(value.traceId,'trace-cross-v2');
  const denied=createCorevia({baseURL:`http://127.0.0.1:${phpPort}`});await assert.rejects(()=>denied.action('bridge.echo',{}),{code:'FORBIDDEN'});
  await assert.rejects(()=>client.action('bridge.echo',{sleep:true}),e=>['REMOTE_UNAVAILABLE','REMOTE_TIMEOUT'].includes(e.code)&&e.retryable);
  const direct=createCorevia({baseURL:env.V2_WORKER_URL,token:'test-only-token'});assert.deepEqual((await direct.action('echo',{source:'js-python'})).data,{source:'js-python'});
  console.log('PASS live JS → PHP → Python CVP/2 correlation, authorization, business payloads and network deadlines');
} finally {for(const child of children)child.kill();await Promise.all(children.map(child=>new Promise(r=>{if(child.exitCode!==null)r();else child.once('exit',r); })));}
