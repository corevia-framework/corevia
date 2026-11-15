import assert from 'node:assert/strict';
import {readFile,writeFile,rename,realpath} from 'node:fs/promises';
import {resolve,join} from 'node:path';
import {OfflineSync,createSyncTransport} from '../../sdk/js/sync.js';
const [origin,directory,phase]=process.argv.slice(2),root=resolve('.'),chosen=await realpath(directory);
assert.match(origin,/^http:\/\/127\.0\.0\.1:[0-9]+$/);assert.ok(chosen.startsWith(join(root,'storage','sync-http-')));
const path=join(chosen,phase==='inspect-dart'?'dart-state.json':'js-state.json');
const store={async read(){try{return JSON.parse(await readFile(path,'utf8'));}catch(error){if(error.code==='ENOENT')return {version:0,payload:null};throw error;}},async compareExchange(version,payload){const row=await this.read();if(row.version!==version)return false;const tmp=path+'.tmp';await writeFile(tmp,JSON.stringify({version:version+1,payload}));await rename(tmp,path);return true;}};
let token='alpha-test',lost=phase==='lost';
const transport=createSyncTransport({baseUrl:origin,getToken:()=>token,developmentLoopback:true,fetchImpl:async(url,options)=>{
  const response=await fetch(url,options);if(lost&&response.ok&&options.method==='POST'){lost=false;await response.arrayBuffer();throw new Error('simulated lost committed acknowledgment');}return response;
}});
const sync=new OfflineSync({store,binding:'alpha:alice'});
if(phase==='inspect-dart'){const snapshot=await sync.snapshot();assert.equal(snapshot.records['dart-note'].revision,1);assert.ok(snapshot.pending.some(item=>item.id==='unsent-dart'));}
else if(phase==='lost'){
  await sync.enqueue('shared-note',0,{title:'from JavaScript'});assert.equal((await sync.flush(transport)).status,'offline');assert.equal((await sync.snapshot()).pending.length,1);
}else{
  assert.equal((await sync.flush(transport)).processed,1);assert.equal((await sync.snapshot()).records['shared-note'].revision,1);await sync.pull(transport);
  assert.equal((await transport.changes(null)).changes.length,1);
  const conflict=await sync.enqueue('shared-note',0,{title:'my old offline draft'});assert.equal((await sync.flush(transport)).status,'conflict');
  await sync.resolveConflict(conflict,{action:'rebase',expectedRevision:1,value:{title:'reviewed merge'}});await sync.flush(transport);assert.equal((await transport.changes(null)).changes.length,2);
  token='revoked-test';await sync.enqueue('unsent-js',0,{title:'kept offline'});assert.equal((await sync.flush(transport)).status,'auth_required');assert.equal((await sync.snapshot()).pending.length,1);
}
const wire=await readFile(path,'utf8');assert.equal(wire.includes('alpha-test'),false);assert.equal(wire.includes('revoked-test'),false);
console.log(phase==='inspect-dart'?'PASS JS reads Dart offline snapshot with pending edits intact':`PASS actual JS/PHP ${phase} retry, durable fixture state, current authorization and explicit conflict handling`);
