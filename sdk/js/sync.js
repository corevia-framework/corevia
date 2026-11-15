/** Optional bounded offline state. Supply an atomic durable store and current-auth transport per app. */
export class SyncError extends Error { constructor(code){super(code);this.name='SyncError';this.code=code;} }
const fail=code=>{throw new SyncError(code);};
const recordId=value=>typeof value==='string'&&/^[A-Za-z0-9][A-Za-z0-9_.:-]{0,127}$/.test(value);
const operationId=value=>typeof value==='string'&&/^[a-f0-9]{32}$/.test(value);
const revision=value=>Number.isSafeInteger(value)&&value>=0;
const bytes=text=>new TextEncoder().encode(text).length;
function validText(text){for(let i=0;i<text.length;i++){const unit=text.charCodeAt(i);if(unit>=0xd800&&unit<=0xdbff){const next=text.charCodeAt(++i);if(!(next>=0xdc00&&next<=0xdfff))return false;}else if(unit>=0xdc00&&unit<=0xdfff)return false;}return true;}
function json(value,depth=0){
  if(depth>32)fail('SYNC_JSON_DEPTH');
  if(typeof value==='string'){if(!validText(value))fail('SYNC_JSON_VALUE');return;}
  if(value===null||typeof value==='boolean')return;
  if(typeof value==='number'&&Number.isFinite(value))return;
  if(Array.isArray(value)){for(const item of value)json(item,depth+1);return;}
  if(value&&typeof value==='object'&&[Object.prototype,null].includes(Object.getPrototypeOf(value))){for(const [key,item] of Object.entries(value)){if(!validText(key))fail('SYNC_JSON_VALUE');json(item,depth+1);}return;}
  fail('SYNC_JSON_VALUE');
}
function data(value){json(value);if(value!==null&&(typeof value!=='object'||bytes(JSON.stringify(value))>16384))fail('SYNC_DATA_BOUNDS');}
function stable(value){if(Array.isArray(value))return value.map(stable);if(value&&typeof value==='object')return Object.fromEntries(Object.keys(value).sort().map(key=>[key,stable(value[key])]));return value;}
const same=(a,b)=>JSON.stringify(stable(a))===JSON.stringify(stable(b));
const defaultId=()=>{const bytes=new Uint8Array(16);if(!globalThis.crypto?.getRandomValues)fail('SYNC_SECURE_RANDOM_REQUIRED');globalThis.crypto.getRandomValues(bytes);return Array.from(bytes,value=>value.toString(16).padStart(2,'0')).join('');};

/** In-memory teaching/test adapter. Production apps must supply atomic durable/encrypted storage as needed. */
export class MemorySyncStore {
  #version=0;#payload=null;
  async read(){return {version:this.#version,payload:this.#payload};}
  async compareExchange(version,payload){if(version!==this.#version)return false;this.#payload=payload;this.#version++;return true;}
}

export class OfflineSync {
  #store;#binding;#id;#busy=false;
  constructor({store,binding,newOperationId=defaultId}={}){
    if(!store||typeof store.read!=='function'||typeof store.compareExchange!=='function'||typeof binding!=='string'||!binding||binding.length>256||typeof newOperationId!=='function')fail('SYNC_CONFIGURATION');
    this.#store=store;this.#binding=binding;this.#id=newOperationId;
  }
  #empty(){return {format:1,binding:this.#binding,cursor:null,sequence:0,pending:[],records:{}};}
  #validate(state){
    if(!state||state.format!==1||state.binding!==this.#binding)fail('SYNC_PARTITION_MISMATCH');
    if(!revision(state.sequence)||(state.cursor!==null&&(typeof state.cursor!=='string'||state.cursor.length>2048))||!Array.isArray(state.pending)||state.pending.length>100||!state.records||Array.isArray(state.records)||typeof state.records!=='object'||Object.keys(state.records).length>5000)fail('SYNC_STATE_INVALID');
    const seen=new Set();for(const item of state.pending){if(!item||typeof item!=='object'||Array.isArray(item)||!operationId(item.operation_id)||seen.has(item.operation_id)||!recordId(item.id)||(!revision(item.expected_revision)||item.expected_revision>=Number.MAX_SAFE_INTEGER)||!['pending','conflict'].includes(item.state))fail('SYNC_STATE_INVALID');seen.add(item.operation_id);data(item.data);}
    for(const [id,item] of Object.entries(state.records)){if(!recordId(id)||!item||!revision(item.revision)||item.revision<1||typeof item.deleted!=='boolean'||item.deleted!==(item.data===null))fail('SYNC_STATE_INVALID');data(item.data);}
    json(state);if(bytes(JSON.stringify(state))>2097152)fail('SYNC_STATE_CAPACITY');return state;
  }
  async #read(){const row=await this.#store.read();if(!row||!revision(row.version)||(row.payload!==null&&typeof row.payload!=='string'))fail('SYNC_STORE_INVALID');if(row.payload!==null&&bytes(row.payload)>2097152)fail('SYNC_STATE_CAPACITY');let decoded=null;if(row.payload!==null){try{decoded=JSON.parse(row.payload);}catch{fail('SYNC_STATE_INVALID');}}return {version:row.version,state:row.payload===null?this.#empty():this.#validate(decoded)};}
  async #change(work){for(let attempt=0;attempt<8;attempt++){const row=await this.#read();const result=work(row.state);this.#validate(row.state);if(await this.#store.compareExchange(row.version,JSON.stringify(row.state)))return result;}fail('SYNC_STORE_BUSY');}
  #nextId(){const id=this.#id();if(!operationId(id))fail('SYNC_OPERATION_ID');return id;}
  async snapshot(){return (await this.#read()).state;}
  async enqueue(id,expectedRevision,value){
    if(!recordId(id)||!revision(expectedRevision)||expectedRevision>=Number.MAX_SAFE_INTEGER)fail('SYNC_MUTATION_INVALID');data(value);const copied=JSON.parse(JSON.stringify(value)),operation=this.#nextId();
    await this.#change(state=>{if(state.pending.length>=100||state.pending.some(item=>item.operation_id===operation))fail('SYNC_QUEUE_CAPACITY');state.pending.push({operation_id:operation,id,expected_revision:expectedRevision,data:copied,state:'pending'});});return operation;
  }
  async flush(transport,{maximum=20}={}){
    if(this.#busy)fail('SYNC_BUSY');if(!Number.isInteger(maximum)||maximum<1||maximum>100||typeof transport?.mutate!=='function')fail('SYNC_CONFIGURATION');this.#busy=true;let processed=0;
    try{while(processed<maximum){
      const item=(await this.#read()).state.pending[0];if(!item)return {status:'idle',processed};if(item.state==='conflict')return {status:'conflict',processed};
      let ack;try{ack=await transport.mutate({operation_id:item.operation_id,id:item.id,expected_revision:item.expected_revision,data:item.data});}
      catch(error){if(['SYNC_REVISION_CONFLICT','SYNC_OPERATION_CONFLICT','SYNC_REJECTED'].includes(error?.code)){await this.#change(state=>{const entry=state.pending.find(entry=>entry.operation_id===item.operation_id);if(entry)entry.state='conflict';});return {status:'conflict',processed};}return {status:['AUTH_REQUIRED','FORBIDDEN'].includes(error?.code)?'auth_required':'offline',processed};}
      if(!ack||ack.operation_id!==item.operation_id||ack.id!==item.id||!revision(ack.revision)||ack.revision!==item.expected_revision+1||!revision(ack.sequence)||ack.sequence<1||ack.deleted!==(item.data===null))fail('SYNC_ACK_INVALID');
      await this.#change(state=>{
        const index=state.pending.findIndex(entry=>entry.operation_id===item.operation_id);if(index>=0)state.pending.splice(index,1);
        const old=Object.hasOwn(state.records,item.id)?state.records[item.id]:null;
        if(!old||old.revision<ack.revision)state.records[item.id]={revision:ack.revision,deleted:ack.deleted,data:item.data};
        else if(old.revision===ack.revision&&!same(old.data,item.data))fail('SYNC_FEED_INCONSISTENT');
      });processed++;
    }return {status:'bounded',processed};}finally{this.#busy=false;}
  }
  async pull(transport,{maximumPages=5}={}){
    if(this.#busy)fail('SYNC_BUSY');if(!Number.isInteger(maximumPages)||maximumPages<1||maximumPages>20||typeof transport?.changes!=='function')fail('SYNC_CONFIGURATION');this.#busy=true;let pages=0;
    try{while(pages<maximumPages){const before=(await this.#read()).state;let page;
      try{page=await transport.changes(before.cursor);}catch(error){return {status:error?.code==='SYNC_CURSOR_REQUIRES_RESET'?'reset_required':(['AUTH_REQUIRED','FORBIDDEN'].includes(error?.code)?'auth_required':'offline'),pages};}
      if(!page||!Array.isArray(page.changes)||page.changes.length>100||typeof page.cursor!=='string'||page.cursor.length>2048||typeof page.has_more!=='boolean')fail('SYNC_FEED_INVALID');
      let sequence=before.sequence;for(const item of page.changes){if(!recordId(item.id)||!revision(item.revision)||item.revision<1||!revision(item.sequence)||item.sequence<=sequence||typeof item.deleted!=='boolean'||item.deleted!==(item.data===null))fail('SYNC_FEED_INVALID');data(item.data);sequence=item.sequence;}
      const applied=await this.#change(state=>{
        if(state.cursor!==before.cursor||state.sequence!==before.sequence)return false;
        for(const item of page.changes){const old=Object.hasOwn(state.records,item.id)?state.records[item.id]:null;if(!old||item.revision>old.revision)state.records[item.id]={revision:item.revision,deleted:item.deleted,data:item.data};else if(item.revision===old.revision&&!same(old.data,item.data))fail('SYNC_FEED_INCONSISTENT');}
        state.sequence=sequence;state.cursor=page.cursor;return true;
      });if(!applied)return {status:'busy',pages};pages++;if(!page.has_more)return {status:'idle',pages};
    }return {status:'bounded',pages};}finally{this.#busy=false;}
  }
  async resolveConflict(operation,{action,expectedRevision,value}={}){
    if(this.#busy)fail('SYNC_BUSY');if(!operationId(operation)||!['drop','rebase'].includes(action))fail('SYNC_RESOLUTION_INVALID');
    let next=null;if(action==='rebase'){if(!revision(expectedRevision)||expectedRevision>=Number.MAX_SAFE_INTEGER)fail('SYNC_RESOLUTION_INVALID');data(value);next={operation_id:this.#nextId(),expected_revision:expectedRevision,data:JSON.parse(JSON.stringify(value)),state:'pending'};}
    return this.#change(state=>{const index=state.pending.findIndex(item=>item.operation_id===operation);if(index<0||state.pending[index].state!=='conflict')fail('SYNC_NOT_CONFLICTED');if(action==='drop')state.pending.splice(index,1);else{if(state.pending.some(item=>item.operation_id===next.operation_id))fail('SYNC_OPERATION_ID');state.pending[index]={...next,id:state.pending[index].id};}return next?.operation_id??null;});
  }
  async resetFeed(){if(this.#busy)fail('SYNC_BUSY');return this.#change(state=>{state.cursor=null;state.sequence=0;state.records={};});}
  /** Explicit logout/discard operation. Never called automatically on authentication failure. */
  async reset(){if(this.#busy)fail('SYNC_BUSY');for(let attempt=0;attempt<8;attempt++){const row=await this.#store.read();if(await this.#store.compareExchange(row.version,JSON.stringify(this.#empty())))return;}fail('SYNC_STORE_BUSY');}
}

/** Fixed HTTPS API with fresh bearer lookup per request. Provider/server keys never belong in this client. */
export function createSyncTransport({baseUrl,getToken,fetchImpl=globalThis.fetch,timeoutMs=5000,developmentLoopback=false}={}){
  const base=new URL(baseUrl),local=developmentLoopback&&['127.0.0.1','[::1]'].includes(base.hostname)&&base.protocol==='http:';
  if((!local&&base.protocol!=='https:')||base.username||base.password||base.search||base.hash||typeof getToken!=='function'||typeof fetchImpl!=='function'||!Number.isInteger(timeoutMs)||timeoutMs<1||timeoutMs>30000)fail('SYNC_TRANSPORT_CONFIGURATION');
  const prefix=base.pathname==='/'?'/api/v1/sync':base.pathname.replace(/\/$/,'');if(!/^\/[A-Za-z0-9/_-]{1,100}$/.test(prefix))fail('SYNC_TRANSPORT_CONFIGURATION');
  async function request(path,input){
    const controller=new AbortController(),timer=setTimeout(()=>controller.abort(),timeoutMs);
    try{
      const cancelled=new Promise((_,reject)=>controller.signal.addEventListener('abort',()=>reject(new SyncError('SYNC_TIMEOUT')),{once:true}));
      const token=await Promise.race([Promise.resolve().then(getToken),cancelled]);if(typeof token!=='string'||!token||token.length>4096||/[^\x21-\x7e]/.test(token))fail('AUTH_REQUIRED');
      const target=base.origin+prefix+path,response=await fetchImpl(target,{method:input===undefined?'GET':'POST',credentials:'omit',redirect:'error',signal:controller.signal,
        headers:{Authorization:'Bearer '+token,Accept:'application/json',...(input===undefined?{}:{'Content-Type':'application/json'})},...(input===undefined?{}:{body:JSON.stringify(input)})});
      if(response.redirected||(response.url&&new URL(response.url).origin!==base.origin))fail('SYNC_REDIRECT');
      if(response.status===401||response.status===403){controller.abort();fail('AUTH_REQUIRED');}
      let text='';
      if(response.body?.getReader){const reader=response.body.getReader(),decoder=new TextDecoder('utf-8',{fatal:true});let size=0;try{while(true){const item=await reader.read();if(item.done)break;size+=item.value.byteLength;if(size>2097152)fail('SYNC_RESPONSE_CAPACITY');text+=decoder.decode(item.value,{stream:true});}text+=decoder.decode();}finally{try{await reader.cancel();}finally{reader.releaseLock();}}}
      else {text=await response.text();if(bytes(text)>2097152)fail('SYNC_RESPONSE_CAPACITY');}
      let result;try{result=JSON.parse(text);}catch{fail('SYNC_PROTOCOL');}
      if(!response.ok){const code=result?.error?.code;if(response.status===409&&['SYNC_REVISION_CONFLICT','SYNC_OPERATION_CONFLICT','SYNC_CURSOR_REQUIRES_RESET'].includes(code))fail(code);if(response.status===400||response.status===422)fail('SYNC_REJECTED');fail('SYNC_UNAVAILABLE');}
      if(!result||typeof result.data!=='object'||result.data===null||Array.isArray(result.data))fail('SYNC_PROTOCOL');return result.data;
    }finally{clearTimeout(timer);}
  }
  return {mutate:input=>request('/mutations',input),changes:cursor=>request('/changes'+(cursor===null?'':'?cursor='+encodeURIComponent(cursor)))};
}
