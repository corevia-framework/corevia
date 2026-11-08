import test from 'node:test';
import assert from 'node:assert/strict';
import {OfflineSync,MemorySyncStore,SyncError,createSyncTransport} from '../sync.js';
const ids=()=>{let n=0;return ()=> (++n).toString(16).padStart(32,'0');};
const ack=(item,sequence=1)=>({operation_id:item.operation_id,id:item.id,revision:item.expected_revision+1,sequence,deleted:item.data===null});

test('offline restart/lost acknowledgment preserves one operation and never duplicates server effects',async()=>{
  const store=new MemorySyncStore(),sync=new OfflineSync({store,binding:'alpha:alice',newOperationId:ids()});
  const value={title:'original'},id=await sync.enqueue('note',0,value);value.title='caller mutation';
  let writes=0,lost=true;const receipts=new Map();
  const transport={async mutate(item){if(!receipts.has(item.operation_id)){receipts.set(item.operation_id,ack(item));writes++;}if(lost){lost=false;throw new Error('connection lost after commit');}return receipts.get(item.operation_id);}};
  assert.equal((await sync.flush(transport)).status,'offline');assert.equal((await sync.snapshot()).pending[0].operation_id,id);
  const restarted=new OfflineSync({store,binding:'alpha:alice'});assert.equal((await restarted.flush(transport)).processed,1);assert.equal(writes,1);assert.equal((await restarted.snapshot()).records.note.data.title,'original');
});
test('failure saving an acknowledgment retries the same server receipt',async()=>{
  const memory=new MemorySyncStore();let fail=false,writes=0;
  const store={read:()=>memory.read(),compareExchange:(version,payload)=>{if(fail){fail=false;throw new Error('disk unavailable');}return memory.compareExchange(version,payload);}};
  const sync=new OfflineSync({store,binding:'alpha:alice'});await sync.enqueue('note',0,{title:'hello'});let receipt;
  const transport={async mutate(item){if(!receipt){writes++;receipt=ack(item);fail=true;}return receipt;}};
  await assert.rejects(sync.flush(transport));assert.equal((await sync.snapshot()).pending.length,1);await sync.flush(transport);assert.equal(writes,1);assert.equal((await sync.snapshot()).pending.length,0);
});
test('conflicts pause the queue and require an explicit rebase with a new operation identifier',async()=>{
  const sync=new OfflineSync({store:new MemorySyncStore(),binding:'alpha:alice',newOperationId:ids()});const first=await sync.enqueue('note',0,{title:'mine'});
  const conflict={async mutate(){throw new SyncError('SYNC_REVISION_CONFLICT');}};assert.equal((await sync.flush(conflict)).status,'conflict');
  let called=0;assert.equal((await sync.flush({async mutate(){called++;}})).status,'conflict');assert.equal(called,0);
  const next=await sync.resolveConflict(first,{action:'rebase',expectedRevision:5,value:{title:'reviewed merge'}});assert.notEqual(next,first);
  await sync.flush({async mutate(item){assert.equal(item.expected_revision,5);return ack(item);}});assert.equal((await sync.snapshot()).records.note.revision,6);
});
test('feed disconnect/resume and tombstones commit with the cursor; identity partitions remain separate',async()=>{
  const store=new MemorySyncStore(),sync=new OfflineSync({store,binding:'alpha:alice'});let disconnected=true;
  const transport={async changes(cursor){if(cursor===null)return {changes:[{id:'note',revision:1,sequence:1,data:{title:'first'},deleted:false}],cursor:'c1',has_more:true};if(disconnected){disconnected=false;throw new Error('offline');}assert.equal(cursor,'c1');return {changes:[{id:'note',revision:2,sequence:2,data:null,deleted:true}],cursor:'c2',has_more:false};}};
  assert.equal((await sync.pull(transport)).status,'offline');assert.equal((await sync.snapshot()).cursor,'c1');
  const restored=new OfflineSync({store,binding:'alpha:alice'});await restored.pull(transport);const state=await restored.snapshot();assert.equal(state.cursor,'c2');assert.equal(state.records.note.deleted,true);
  await assert.rejects(new OfflineSync({store,binding:'beta:bob'}).snapshot(),{code:'SYNC_PARTITION_MISMATCH'});
  assert.equal((await restored.pull({async changes(){throw new SyncError('SYNC_CURSOR_REQUIRES_RESET');}})).status,'reset_required');assert.equal((await restored.snapshot()).cursor,'c2');
  await restored.resetFeed();assert.equal((await restored.snapshot()).cursor,null);
});
test('authority failure retains queued edits; bounds and invalid JSON are rejected',async()=>{
  const sync=new OfflineSync({store:new MemorySyncStore(),binding:'alpha:alice'});await sync.enqueue('note',0,{title:'unsent'});
  assert.equal((await sync.flush({async mutate(){throw new SyncError('AUTH_REQUIRED');}})).status,'auth_required');assert.equal((await sync.snapshot()).pending.length,1);
  await assert.rejects(sync.enqueue('bad',0,{n:NaN}),{code:'SYNC_JSON_VALUE'});await assert.rejects(sync.enqueue('bad',0,'not a record'),{code:'SYNC_DATA_BOUNDS'});
  await assert.rejects(sync.enqueue('bad',0,{broken:String.fromCharCode(0xd800)}),{code:'SYNC_JSON_VALUE'});
  await assert.rejects(sync.enqueue('bad',Number.MAX_SAFE_INTEGER,{}),{code:'SYNC_MUTATION_INVALID'});
  await assert.rejects(sync.enqueue('bad',0,{text:'x'.repeat(16385)}),{code:'SYNC_DATA_BOUNDS'});
  await assert.rejects(sync.pull({async changes(){return {changes:[{id:'bad',revision:1,sequence:0,data:null,deleted:true}],cursor:'bad',has_more:false};}}),{code:'SYNC_FEED_INVALID'});
  assert.equal((await sync.snapshot()).cursor,null);
});
test('CAS conflicts retry state writes and fresh bearer credentials stay outside offline state',async()=>{
  const memory=new MemorySyncStore();let conflict=true;
  const store={read:()=>memory.read(),compareExchange:(version,payload)=>{if(conflict){conflict=false;return false;}return memory.compareExchange(version,payload);}};
  const sync=new OfflineSync({store,binding:'alpha:alice'});await sync.enqueue('note',0,{title:'data'});
  let token='first-private-token';const observed=[];
  const transport=createSyncTransport({baseUrl:'https://app.example.test',getToken:()=>token,fetchImpl:async(url,options)=>{observed.push(options);return new Response(JSON.stringify({data:ack(JSON.parse(options.body))}),{headers:{'Content-Type':'application/json'}});}});
  await sync.flush(transport);token='second-private-token';await sync.enqueue('note',1,{title:'new'});await sync.flush(transport);
  assert.equal(observed[0].headers.Authorization,'Bearer first-private-token');assert.equal(observed[1].headers.Authorization,'Bearer second-private-token');assert.equal(observed[0].credentials,'omit');assert.equal(observed[0].redirect,'error');
  assert.equal(JSON.stringify(await sync.snapshot()).includes('private-token'),false);
});

test('credential lookup times out without dispatching a late request',async()=>{
  let release,calls=0;const transport=createSyncTransport({baseUrl:'https://app.example.test',timeoutMs:20,getToken:()=>new Promise(resolve=>{release=resolve;}),fetchImpl:async()=>{calls++;return new Response('{}');}});
  await assert.rejects(transport.changes(null),{code:'SYNC_TIMEOUT'});release('late-token');await new Promise(resolve=>setTimeout(resolve,5));assert.equal(calls,0);
});

test('corrupt persisted JSON is rejected without including private contents in parser errors',async()=>{
  const store=new MemorySyncStore();await store.compareExchange(0,'{private-account-data');
  await assert.rejects(new OfflineSync({store,binding:'alpha:alice'}).snapshot(),error=>error.code==='SYNC_STATE_INVALID'&&error.message==='SYNC_STATE_INVALID');
});
