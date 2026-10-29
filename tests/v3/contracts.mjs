import assert from 'node:assert/strict';
import {readFileSync} from 'node:fs';
import {createClient,fingerprint,manifest} from '../../contracts/generated/js/client.js';
const client=createClient(async(name,payload,auth)=>{assert.equal(auth[0].scheme,'session');return {message:'Hello '+payload.name};});
const fixtures=JSON.parse(readFileSync(new URL('../../contracts/fixtures.json',import.meta.url)));
for(const fixture of fixtures){let valid=true;try{client[{input:'validateInput',output:'validateOutput',event:'validateEvent'}[fixture.kind]](fixture.name,fixture.value);}catch{valid=false;}assert.equal(valid,fixture.valid,JSON.stringify(fixture));}
assert.equal((await client.action('demo.greet',{name:'Ada'})).message,'Hello Ada');
assert.equal(client.requirements('demo.greet')[0].scheme,'session');assert.equal(manifest.errors.UNAVAILABLE.retryable,true);assert.match(fingerprint,/^[a-f0-9]{64}$/);
client.validateError('demo.greet','UNAVAILABLE',true);assert.throws(()=>client.validateError('demo.greet','UNAVAILABLE',false));
console.log(`PASS ${fixtures.length} generated JavaScript application contracts and client/auth/error metadata`);
