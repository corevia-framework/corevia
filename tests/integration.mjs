import assert from 'node:assert/strict';
import { spawn, execFile } from 'node:child_process';
import { promisify } from 'node:util';
import { randomBytes } from 'node:crypto';
import { createServer, connect as tcpConnect } from 'node:net';
import { mkdtemp, mkdir, writeFile, readFile, rm } from 'node:fs/promises';
import { resolve, join } from 'node:path';
import { fileURLToPath } from 'node:url';
import { CoreviaClient } from '../sdk/js/index.js';

const root = resolve(fileURLToPath(new URL('..', import.meta.url)));
const php = process.env.PHP_BINARY || 'php';
const python = process.env.PYTHON || (process.platform === 'win32' ? 'python' : 'python3');
const exec = promisify(execFile);
const sleep = ms => new Promise(resolve => setTimeout(resolve, ms));
const children = [];
const storage = await mkdtemp(join(root, 'storage', 'integration-'));
const clients = [];
const rawSockets = [];
let sdk;

async function port() {
  const socket=createServer(); await new Promise(resolve=>socket.listen(0,'127.0.0.1',resolve));
  const port=socket.address().port; await new Promise(resolve=>socket.close(resolve)); return port;
}
const [httpPort, wsPort, ipcPort, workerPort] = await Promise.all([port(),port(),port(),port()]);
const env = {...process.env, COREVIA_STORAGE:storage, COREVIA_CONTROL_PORT:String(ipcPort), COREVIA_FRAUD_URL:`http://127.0.0.1:${workerPort}/cvp`, COREVIA_WORKER_URL:`http://127.0.0.1:${workerPort}`, COREVIA_WORKER_TOKEN:'integration-worker-secret', COREVIA_PRIVATE_TOKEN:'integration-private-secret', COREVIA_API_TOKEN:'integration-api-secret', COREVIA_ORIGINS:`http://127.0.0.1:${httpPort}`};
function start(binary, args) {
  const child=spawn(binary,args,{cwd:root,env,stdio:['ignore','pipe','pipe'],windowsHide:true});
  child.output=''; child.stdout.on('data',chunk=>{child.output+=chunk}); child.stderr.on('data',chunk=>{child.output+=chunk});
  child.on('error',error=>{child.spawnError=error}); children.push(child); return child;
}
async function waitFor(test, description) {
  const deadline=Date.now()+12000;
  while(Date.now()<deadline) {
    for (const child of children) if(child.spawnError || child.exitCode !== null) throw new Error(`Process failed: ${child.spawnError?.message ?? child.output}`);
    try { if(await test()) return; } catch {}
    await sleep(40);
  }
  throw new Error(`Timeout: ${description}`);
}
async function connectable(port) {
  return await new Promise(resolve=>{
    const socket=tcpConnect({port,host:'127.0.0.1'}); socket.setTimeout(200);
    socket.on('connect',()=>{socket.destroy();resolve(true)}); socket.on('error',()=>resolve(false));socket.on('timeout',()=>{socket.destroy();resolve(false)});
  });
}
async function cli(...args) { return (await exec(php,['corevia',...args],{cwd:root,env,timeout:12000})).stdout; }
function socket(url, token) {
  const instance = new WebSocket(url); clients.push(instance);
  const messages=[]; instance.addEventListener('message',event=>messages.push(JSON.parse(event.data)));
  return {instance,messages,async subscribe(target) {
    await waitFor(()=>instance.readyState===1,'socket open');
    instance.send(JSON.stringify({cvp:'1.0',id:'sub_'+target,type:'subscribe',target,payload:token?{token}:{},meta:{trace_id:'trace_sub'}}));
    await waitFor(()=>messages.some(m=>m.id==='sub_'+target),'subscription ack');
    return messages.find(m=>m.id==='sub_'+target);
  }};
}

function maskedFrame(payload, opcode=1, final=true) {
  const body=Buffer.from(payload); const mask=randomBytes(4); let header;
  if(body.length < 126) header=Buffer.from([(final?0x80:0)|opcode,0x80|body.length]);
  else {header=Buffer.alloc(4);header[0]=(final?0x80:0)|opcode;header[1]=0xfe;header.writeUInt16BE(body.length,2)}
  const masked=Buffer.from(body);for(let i=0;i<body.length;i++)masked[i]^=mask[i%4];
  return Buffer.concat([header,mask,masked]);
}
async function rawWebSocket(origin) {
  const stream=tcpConnect({host:'127.0.0.1',port:wsPort});rawSockets.push(stream);
  const state={stream,buffer:Buffer.alloc(0),frames:[],closed:false,ready:false};
  stream.on('error',()=>{});stream.on('close',()=>{state.closed=true});
  stream.on('data',data=>{
    state.buffer=Buffer.concat([state.buffer,data]);
    if(!state.ready) {
      const end=state.buffer.indexOf('\r\n\r\n');if(end<0)return;
      assert.match(state.buffer.subarray(0,end).toString(),/^HTTP\/1.1 101/);
      state.ready=true;state.buffer=state.buffer.subarray(end+4);
    }
    while(state.buffer.length>=2) {
      let size=state.buffer[1]&0x7f;let offset=2;
      if(size===126) {if(state.buffer.length<4)return;size=state.buffer.readUInt16BE(2);offset=4;}
      if(size===127) {if(state.buffer.length<10)return;size=state.buffer.readUInt32BE(6);offset=10;}
      if(state.buffer.length<offset+size)return;
      state.frames.push({opcode:state.buffer[0]&0xf,payload:state.buffer.subarray(offset,offset+size)});
      state.buffer=state.buffer.subarray(offset+size);
    }
  });
  await new Promise((resolve,reject)=>{stream.once('connect',resolve);stream.once('error',reject)});
  stream.write(`GET /realtime HTTP/1.1\r\nHost: 127.0.0.1:${wsPort}\r\nUpgrade: websocket\r\nConnection: Upgrade\r\nSec-WebSocket-Version: 13\r\nSec-WebSocket-Key: ${randomBytes(16).toString('base64')}\r\n${origin ? `Origin: ${origin}\r\n` : ''}\r\n`);
  await waitFor(()=>state.ready||state.closed,'raw WebSocket handshake');
  return state;
}

try {
  start(python,['examples/python_worker.py']);
  await waitFor(()=>connectable(workerPort),'Python worker');
  const remote=JSON.parse((await exec(php,['examples/remote.php'],{cwd:root,env,timeout:12000})).stdout);
  assert.equal(remote.ok,true); assert.equal(remote.result.words,5);
  console.log('PASS PHP → Python CVP/1 remote action');

  start(php,['corevia','realtime:start',`--port=${wsPort}`]);
  await waitFor(()=>connectable(wsPort),'realtime listener');
  start(php,['-S',`127.0.0.1:${httpPort}`,'-t','public','public/index.php']);
  const baseUrl=`http://127.0.0.1:${httpPort}`;
  await waitFor(async()=>{const response=await fetch(baseUrl+'/health');return response.ok},'HTTP server');
  const realtimeUrl=`ws://127.0.0.1:${wsPort}/realtime`;
  const observer=socket(realtimeUrl); assert.equal((await observer.subscribe('orders')).ok,true);
  const denied=socket(realtimeUrl); assert.equal((await denied.subscribe('private-orders')).error.code,'FORBIDDEN');
  const privateClient=socket(realtimeUrl,'integration-private-secret'); assert.equal((await privateClient.subscribe('private-orders')).ok,true);
  console.log('PASS WebSocket handshake, public/private subscriptions and authorization');

  const blocked=await rawWebSocket('https://untrusted.example');assert.equal(blocked.ready,false);
  const raw=await rawWebSocket(`http://127.0.0.1:${httpPort}`);assert.equal(raw.ready,true);
  raw.stream.write(maskedFrame('heartbeat',9));
  await waitFor(()=>raw.frames.some(f=>f.opcode===10),'WebSocket control pong');
  assert.equal(raw.frames.find(f=>f.opcode===10).payload.toString(),'heartbeat');
  const subscription=JSON.stringify({cvp:'1.0',id:'raw_sub',type:'subscribe',target:'orders',payload:{}});
  raw.stream.write(Buffer.concat([maskedFrame(subscription.slice(0,20),1,false),maskedFrame(subscription.slice(20),0,true)]));
  await waitFor(()=>raw.frames.some(f=>f.opcode===1&&JSON.parse(f.payload).id==='raw_sub'),'fragmented subscription');
  assert.equal(JSON.parse(raw.frames.find(f=>f.opcode===1).payload).ok,true);
  const oversized=Buffer.alloc(14);oversized[0]=0x81;oversized[1]=0xff;oversized.writeUInt32BE(65537,6);
  raw.stream.write(oversized);
  await waitFor(()=>raw.frames.some(f=>f.opcode===8)||raw.closed,'oversized frame rejection');
  const limited=await rawWebSocket();limited.stream.write(Buffer.concat(Array.from({length:121},()=>maskedFrame('x',9))));
  await waitFor(()=>limited.frames.some(f=>f.opcode===8)||limited.closed,'frame rate limit');
  console.log('PASS WebSocket origin limits, ping/pong, fragmentation, payload bounds and rate limiting');

  const events=[]; sdk=new CoreviaClient({baseUrl,realtimeUrl,token:()=>env.COREVIA_API_TOKEN,reconnectDelay:10});
  sdk.channel('orders').on('order.updated',(data,envelope)=>events.push({data,envelope}));
  await waitFor(()=>sdk.socket?.readyState===1,'JS SDK connect'); await sleep(80);
  const payload={items:[{name:'Demo',price:54.5}],email:'test@example.com'};
  const requestOptions={method:'POST',headers:{'Content-Type':'application/json','Idempotency-Key':'integration-checkout','X-Request-Id':'req_integration','X-Trace-Id':'trace_integration'},body:JSON.stringify(payload)};
  const result=await (await fetch(baseUrl+'/checkout',requestOptions)).json();
  assert.equal(result.ok,true); assert.equal(result.result.status,'completed_with_warnings');
  assert.equal(result.result.state.fraud_runtime,'python'); assert.equal(result.result.steps[5].attempts,6);
  assert.equal(result.result.steps[6].status,'completed');
  await waitFor(()=>events.length===1,'SDK broadcast delivery');
  assert.equal(events[0].envelope.meta.trace_id,'trace_integration');
  assert.equal(events[0].data.order_id,result.result.state.order_id);
  await waitFor(()=>observer.messages.some(m=>m.type==='event'),'WebSocket observer event');
  console.log('PASS HTTP → checkout → Python fraud check → isolated notification failure → JS event');

  const duplicate=await (await fetch(baseUrl+'/checkout',requestOptions)).json();
  assert.equal(duplicate.result.run_id,result.result.run_id); await sleep(60); assert.equal(events.length,1);
  const conflict=await fetch(baseUrl+'/checkout',{...requestOptions,body:JSON.stringify({...payload,email:'different@example.com'})});
  assert.equal(conflict.status,409);
  const critical=await (await fetch(baseUrl+'/checkout',{...requestOptions,headers:{'Content-Type':'application/json'},body:JSON.stringify({...payload,decline_payment:true})})).json();
  assert.equal(critical.ok,false); assert.equal(critical.error.code,'PAYMENT_DECLINED');
  console.log('PASS persistent HTTP idempotency, input conflicts and critical failure');

  const inspect=JSON.parse(await cli('flow:show',result.result.run_id)); assert.equal(inspect.trace_id,'trace_integration');
  assert.deepEqual(JSON.parse(await cli('flow:list')),['checkout']);
  assert.equal(JSON.parse(await cli('modules')).orders.status,'healthy');
  assert.ok(JSON.parse(await cli('routes')).some(route=>route.path==='/checkout'));
  assert.match(await cli('doctor',`--port=${wsPort}`),/Protocol version\s+1\.0/);
  await writeFile(join(storage,'notifications.online'),'online');
  const retry=JSON.parse(await cli('flow:retry',result.result.run_id,'5')); assert.equal(retry.status,'completed');
  assert.equal(retry.state.payment_id,result.result.state.payment_id);
  const lookup=await sdk.call('orders.get',{run_id:result.result.run_id}); assert.equal(lookup.data.status,'completed');
  console.log('PASS CLI persistence inspection, manual retry and JS authenticated action call');

  const oldSocket=sdk.socket; oldSocket.close();
  await waitFor(()=>sdk.socket!==oldSocket && sdk.socket?.readyState===1,'real SDK reconnect'); await sleep(80);
  const realtimeModule=await cli('flow:run','checkout','--input=examples/checkout.json','--key=integration-second');
  assert.equal(JSON.parse(realtimeModule).status,'completed');
  await waitFor(()=>events.length===2,'event after reconnect'); sdk.disconnect();
  console.log('PASS real WebSocket reconnect and subscription restoration');

  const generated=join(storage,'generated');await mkdir(generated);
  const generateCode='require $argv[1] . "/autoload.php"; exit((new \\Corevia\\Cli\\Console($argv[2]))->run(["corevia", $argv[3], $argv[4]]));';
  const generator=(kind,name)=>exec(php,['-r',generateCode,root,generated,`make:${kind}`,name],{cwd:root,env,timeout:5000});
  await generator('module','Inventory');await generator('action','RecordVisit');await generator('flow','Visit');
  for(const file of ['app/Inventory/InventoryModule.php','app/Generated/Actions/RecordVisit.php','app/Generated/Flows/Visit.php']) {
    await exec(php,['-l',join(generated,file)],{cwd:root,env,timeout:5000});
    assert.ok((await readFile(join(generated,file),'utf8')).includes('declare(strict_types=1)'));
  }
  await assert.rejects(generator('module','Inventory'));
  await assert.rejects(generator('action','../../Unsafe'));
  console.log('PASS CLI commands and generators, overwrite protection and path validation');
  await cli('realtime:stop');
  console.log('All integration checks passed.');
} finally {
  sdk?.disconnect();
  for(const client of clients) client.close();
  for(const stream of rawSockets) stream.destroy();
  for(const child of children) if(child.exitCode===null) child.kill();
  await Promise.all(children.map(child=>child.exitCode!==null || child.spawnError ? Promise.resolve() : new Promise(resolve=>{child.once('exit',resolve);setTimeout(resolve,2000)})));
  // Only remove the unique directory created by this test beneath workspace/storage.
  if (!storage.startsWith(join(root,'storage','integration-'))) throw new Error('Unsafe test cleanup path.');
  await rm(storage,{recursive:true,force:true,maxRetries:5,retryDelay:200});
}
