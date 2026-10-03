import test from 'node:test';
import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import { CoreviaClient, CoreviaError, validateEnvelope } from '../index.js';

test('shared protocol fixtures', async () => {
  const valid = JSON.parse(await readFile(new URL('../../../protocol/fixtures/valid.json', import.meta.url)));
  const invalid = JSON.parse(await readFile(new URL('../../../protocol/fixtures/invalid.json', import.meta.url)));
  for (const value of valid) assert.equal(validateEnvelope(value), value);
  for (const value of invalid) assert.throws(() => validateEnvelope(value), CoreviaError);
});

test('HTTP calls inject auth, expose trace/request ids and return real results', async () => {
  const client = new CoreviaClient({ baseUrl: 'http://test', token: () => 'secret', fetch: async (url, options) => {
    assert.equal(url, 'http://test/cvp'); assert.equal(options.headers.Authorization, 'Bearer secret');
    const request = JSON.parse(options.body);
    return { ok: true, status: 200, text: async () => JSON.stringify({cvp:'1.0',id:request.id,ok:true,result:{answer:42},error:null,meta:request.meta}) };
  }});
  assert.deepEqual(await client.call('test', {}, {requestId:'req_test',traceId:'trace_test'}), {data:{answer:42},requestId:'req_test',traceId:'trace_test'});
});

test('server errors preserve stable codes and invalid responses fail', async () => {
  const client = new CoreviaClient({baseUrl:'http://test',fetch:async (_url, options)=>({ok:false,status:422,text:async()=>JSON.stringify({cvp:'1.0',id:JSON.parse(options.body).id,ok:false,result:null,error:{code:'DECLINED',message:'Declined',retryable:false}})})});
  await assert.rejects(client.call('test', {}), error => error.code === 'DECLINED' && !error.retryable && !!error.requestId);
  client.fetchImpl = async()=>({ok:true,status:200,text:async()=>'<html>'});
  await assert.rejects(client.call('test', {}), error => error.code === 'PROTOCOL_ERROR');
});

test('serialization rejects non-finite numbers and arbitrary objects', async () => {
  const client=new CoreviaClient({baseUrl:'http://test'});
  await assert.rejects(client.call('test',{number:NaN}),error=>error.code==='NON_JSON_VALUE');
  await assert.rejects(client.call('test',{date:new Date()}),error=>error.code==='NON_JSON_VALUE');
  const cyclic={}; cyclic.self=cyclic;
  await assert.rejects(client.call('test',cyclic),error=>error.code==='NON_JSON_VALUE');
});

test('network and auth calls have a deadline', async () => {
  const client = new CoreviaClient({baseUrl:'http://test',timeout:10,token:()=>new Promise(()=>{}),fetch:()=>new Promise(()=>{})});
  await assert.rejects(client.call('test', {}), error => error.code === 'TIMEOUT' && error.retryable);
});

class FakeSocket {
  static instances = [];
  constructor() { this.readyState=0; this.sent=[]; FakeSocket.instances.push(this); }
  open() { this.readyState=1; this.onopen?.(); }
  send(data) { this.sent.push(JSON.parse(data)); }
  close() { this.readyState=3; this.onclose?.(); }
  message(data) { this.onmessage?.({data:JSON.stringify(data)}); }
}
const tick = () => new Promise(resolve=>setTimeout(resolve,10));

test('reconnect restores subscriptions and unsubscribe prevents later events', async () => {
  FakeSocket.instances=[];
  const client = new CoreviaClient({baseUrl:'http://test',realtimeUrl:'ws://test',WebSocket:FakeSocket,reconnectDelay:1,maxReconnectDelay:2});
  const seen=[]; const channel=client.channel('orders'); channel.on('order.updated',data=>seen.push(data));
  const first=FakeSocket.instances[0]; first.open(); await tick();
  assert.equal(first.sent[0].type,'subscribe');
  first.close(); await tick();
  const second=FakeSocket.instances[1]; second.open(); await tick();
  assert.equal(second.sent[0].target,'orders');
  second.message({cvp:'1.0',id:'evt',type:'event',target:'orders',payload:{event:'order.updated',data:{id:1}}});
  assert.deepEqual(seen,[{id:1}]);
  channel.unsubscribe(); assert.equal(second.sent.at(-1).type,'unsubscribe');
  second.message({cvp:'1.0',id:'evt2',type:'event',target:'orders',payload:{event:'order.updated',data:{id:2}}});
  assert.equal(seen.length,1);
  client.disconnect(); await tick(); assert.equal(FakeSocket.instances.length,2);
});

test('private-channel denial surfaces typed errors', async () => {
  const client=new CoreviaClient({baseUrl:'http://test',realtimeUrl:'ws://test',WebSocket:FakeSocket,token:async()=>'private-token'});
  const errors=[]; client.onError(error=>errors.push(error)); client.channel('private-orders').on('event',()=>{});
  const socket=client.socket; socket.open(); await tick(); assert.equal(socket.sent[0].payload.token,'private-token');
  socket.message({cvp:'1.0',id:'deny',ok:false,result:null,error:{code:'FORBIDDEN',message:'Denied',retryable:false}});
  assert.equal(errors[0].code,'FORBIDDEN'); client.disconnect();
});
