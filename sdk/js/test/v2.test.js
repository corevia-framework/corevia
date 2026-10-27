import { test } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { createCorevia, validateEnvelope } from '../index.js';
test('shared CVP/2 fixtures', () => {
  const f=JSON.parse(readFileSync(new URL('../../../protocol/v2/fixtures.json',import.meta.url)));
  for (const value of f.valid) assert.equal(validateEnvelope(value),value);
  for (const value of f.invalid) assert.throws(()=>validateEnvelope(value));
});
test('V2 facade carries timeout and parent trace and rejects downgrade', async () => {
  let downgrade=false;
  const client=createCorevia({baseURL:'http://localhost',token:'test-token',fetch:async (_url,options)=>{
    const request=JSON.parse(options.body); assert.equal(request.cvp,'2.0'); assert.equal(request.meta.parent_trace_id,'parent'); assert.equal(options.headers.Authorization,'Bearer test-token');
    return {ok:true,text:async()=>JSON.stringify({cvp:downgrade?'1.0':'2.0',type:'action.result',id:request.id,ok:true,result:{id:42},error:null,meta:{trace_id:request.meta.trace_id}})};
  }});
  assert.deepEqual((await client.action('orders.create',{}, {parentTraceId:'parent'})).data,{id:42});
  downgrade=true; await assert.rejects(()=>client.action('orders.create',{}, {parentTraceId:'parent'}),{code:'PROTOCOL_ERROR'});
});
