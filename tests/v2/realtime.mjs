import assert from 'node:assert/strict';
import {spawn} from 'node:child_process';
import {createServer,connect} from 'node:net';
import {randomBytes} from 'node:crypto';
const delay=ms=>new Promise(r=>setTimeout(r,ms));
async function port(){const s=createServer();await new Promise(r=>s.listen(0,'127.0.0.1',r));const p=s.address().port;await new Promise(r=>s.close(r));return p;}
const [wsPort,ipcPort]=await Promise.all([port(),port()]);
const server=spawn(process.env.PHP_BINARY??'php',['tests/v2/realtime-server.php',String(wsPort),String(ipcPort)],{windowsHide:true,stdio:['ignore','pipe','pipe']});
let output=''; server.stdout.on('data',d=>output+=d);server.stderr.on('data',d=>output+=d);
const sockets=[];
async function until(test){const end=Date.now()+7000;while(Date.now()<end){if(test())return;await delay(20);}throw new Error('Timed out: '+output);}
async function socket(){const ws=new WebSocket(`ws://127.0.0.1:${wsPort}`);sockets.push(ws);const messages=[];ws.onmessage=e=>messages.push(JSON.parse(e.data));await new Promise((r,j)=>{ws.onopen=r;ws.onerror=j;});return {ws,messages};}
function send(c,type,target,payload={}){const id=randomBytes(8).toString('hex');c.ws.send(JSON.stringify({cvp:'2.0',id,type,target,payload,meta:{trace_id:'test'}}));return id;}
async function reply(c,id){await until(()=>c.messages.some(m=>m.id===id));return c.messages.find(m=>m.id===id);}
async function broadcast(channel){const c=connect(ipcPort,'127.0.0.1');c.resume();await new Promise((r,j)=>{c.once('connect',r);c.once('error',j);});c.end(JSON.stringify({secret:'x'.repeat(32),command:'broadcast',message:{cvp:'2.0',id:'event',type:'event',target:channel,payload:{event:'Updated',data:{id:42}},meta:{}}})+'\n');await new Promise(r=>c.on('close',r));}
try {
  await until(()=>output.includes('Realtime started'));
  const first=await socket(),second=await socket();
  let r=await reply(first,send(first,'subscribe','presence-orders'));assert.equal(r.error.code,'FORBIDDEN');assert.equal(r.cvp,'2.0');
  r=await reply(first,send(first,'subscribe','presence-orders',{token:'test-only-token'}));assert.equal(r.result.members.length,1);assert.equal(r.result.members[0].id,'user_1');assert.equal(r.result.event_version,1);assert.ok(r.result.connection_id);
  await reply(second,send(second,'subscribe','presence-orders',{token:'test-only-token'}));
  await reply(first,send(first,'unsubscribe','presence-orders'));assert.equal(second.messages.filter(m=>m.payload?.event==='PresenceLeft').length,0);
  await reply(first,send(first,'subscribe','presence-orders',{token:'test-only-token'}));second.ws.close();await until(()=>second.ws.readyState===3);
  r=await reply(first,send(first,'subscribe','orders.*'));assert.equal(r.ok,true);
  await broadcast('orders.42');await until(()=>first.messages.some(m=>m.target==='orders.42'));assert.equal(first.messages.find(m=>m.target==='orders.42').meta.event_version,1);
  r=await reply(first,send(first,'subscribe','private-orders.*',{token:'test-only-token'}));assert.equal(r.error.code,'FORBIDDEN');
  r=await reply(first,send(first,'subscribe','*'));assert.equal(r.error.code,'FORBIDDEN');
  await broadcast('private-orders.42');await delay(50);assert.equal(first.messages.some(m=>m.target==='private-orders.42'),false);
  console.log('PASS CVP/2 presence authorization, duplicate-member accounting, room metadata, public wildcards and private wildcard denial');
  const raw=connect(wsPort,'127.0.0.1');sockets.push(raw);let closed=false;raw.on('close',()=>closed=true);raw.on('data',()=>{});await new Promise(r=>raw.once('connect',r));
  raw.write(`GET /realtime HTTP/1.1\r\nHost: localhost\r\nUpgrade: websocket\r\nConnection: Upgrade\r\nSec-WebSocket-Version: 13\r\nSec-WebSocket-Key: ${randomBytes(16).toString('base64')}\r\n\r\n`);
  await until(()=>closed);console.log('PASS stale WebSocket connection is closed after unanswered heartbeat');
} finally {for(const s of sockets){if(s.destroy)s.destroy();else s.close();}server.kill();await new Promise(r=>{if(server.exitCode!==null)r();else server.once('exit',r);});}
