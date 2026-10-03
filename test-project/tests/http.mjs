import assert from 'node:assert/strict';
import { spawn, execFile } from 'node:child_process';
import { promisify } from 'node:util';
import { createServer } from 'node:net';
import { mkdtemp, readFile, readdir, rm } from 'node:fs/promises';
import { resolve, join } from 'node:path';
import { fileURLToPath } from 'node:url';
import { Script } from 'node:vm';

const exec = promisify(execFile);
const root = resolve(fileURLToPath(new URL('..', import.meta.url)));
const php = process.env.PHP_BINARY || 'php';
const storage = await mkdtemp(join(root, 'storage', 'http-test-'));
const reservation = createServer();
await new Promise(resolve => reservation.listen(0, '127.0.0.1', resolve));
const port = reservation.address().port;
await new Promise(resolve => reservation.close(resolve));
const baseUrl = `http://127.0.0.1:${port}`;
const env = {...process.env, COREVIA_LEARNING_STORAGE:storage, COREVIA_LEARNING_ORIGINS:baseUrl};
const server = spawn(php, ['-n', '-S', `127.0.0.1:${port}`, '-t', join(root,'public'), join(root,'public','index.php')], {cwd:root,env,stdio:['ignore','pipe','pipe'],windowsHide:true});
let output='';let spawnError;
server.stdout.on('data',chunk=>{output+=chunk});server.stderr.on('data',chunk=>{output+=chunk});server.on('error',error=>{spawnError=error});
const sleep = ms => new Promise(resolve=>setTimeout(resolve,ms));
const request = (path, options={}) => fetch(baseUrl+path,{...options,signal:AbortSignal.timeout(3000)});

try {
  let ready=false;
  for(let attempt=0;attempt<100;attempt++) {
    if(spawnError || server.exitCode!==null)throw new Error(`PHP server failed: ${spawnError?.message || output}`);
    try {ready=(await request('/')).ok;if(ready)break;}catch{}
    await sleep(40);
  }
  assert.equal(ready,true,'PHP server did not start');
  const page=await (await request('/')).text();assert.match(page,/Build a task/);
  new Script(page.match(/<script>([\s\S]*?)<\/script>/)[1]);
  assert.deepEqual((await (await request('/api/tasks')).json()).tasks,[]);
  console.log('PASS learning page and empty task API with PHP extensions disabled');

  const create=async payload=>request('/api/tasks',{method:'POST',headers:{'Content-Type':'application/json','Origin':baseUrl,'X-Trace-Id':'learning-http-trace'},body:JSON.stringify(payload)});
  const normal=await (await create({title:'Learn actions'})).json();
  assert.equal(normal.result.status,'completed');assert.equal(normal.result.steps.length,4);
  assert.equal(normal.result.trace_id,'learning-http-trace');
  const warning=await (await create({title:'Saved despite reminder failure',simulate_reminder_failure:true})).json();
  assert.equal(warning.result.status,'completed_with_warnings');
  assert.equal(warning.result.steps[3].error.code,'DEMO_REMINDER_FAILED');
  const invalidResponse=await create({title:'   '});assert.equal(invalidResponse.status,422);
  const invalid=await invalidResponse.json();assert.equal(invalid.result.steps.length,1);
  const list=await (await request('/api/tasks')).json();assert.equal(list.tasks.length,2);
  console.log('PASS real HTTP flow, critical validation failure and isolated reminder failure');

  const completed=await (await request(`/api/tasks/${normal.result.state.task.id}/complete`,{method:'PATCH'})).json();
  assert.equal(completed.result.state.task.completed,true);
  assert.equal((await request('/api/tasks/task_0000000000000000/complete',{method:'PATCH'})).status,404);
  assert.equal((await request('/storage/tasks.json')).status,404);
  assert.equal((await request('/api/tasks',{headers:{Origin:'https://untrusted.example'}})).status,403);
  const diskTasks=JSON.parse(await readFile(join(storage,'tasks.json'),'utf8'));
  assert.equal(diskTasks[0].completed,true);
  assert.ok((await readdir(storage)).every(file=>!file.endsWith('.sqlite')));
  console.log('PASS completion, JSON persistence and private storage routing');

  const cli=async args=>exec(php,['-n',join(root,'console.php'),...args],{cwd:root,env,timeout:5000});
  const cliTask=JSON.parse((await cli(['create','Task from CLI'])).stdout);assert.equal(cliTask.status,'completed');
  const cliList=JSON.parse((await cli(['list'])).stdout);assert.equal(cliList.length,3);
  const cliComplete=JSON.parse((await cli(['complete',cliTask.state.task.id])).stdout);assert.equal(cliComplete.state.task.completed,true);
  assert.equal(JSON.parse((await cli(['routes'])).stdout).length,3);
  console.log('PASS CLI and HTTP share JSON tasks; no PDO/MySQL/SQLite required');
} finally {
  if(server.exitCode===null && !spawnError) {
    server.kill();
    await Promise.race([new Promise(resolve=>server.once('exit',resolve)),sleep(2000)]);
  }
  // Remove only this test's unique storage directory.
  if(!storage.startsWith(join(root,'storage','http-test-')))throw new Error('Unsafe test cleanup path');
  await rm(storage,{recursive:true,force:true,maxRetries:5,retryDelay:200});
}
