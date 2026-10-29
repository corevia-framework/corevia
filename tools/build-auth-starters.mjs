import {mkdtemp,mkdir,readFile,writeFile,copyFile,readdir} from 'node:fs/promises';
import {resolve,join,dirname} from 'node:path';
import {fileURLToPath} from 'node:url';
import {createHash} from 'node:crypto';
import {spawnSync} from 'node:child_process';
const root=resolve(dirname(fileURLToPath(import.meta.url)),'..'),source=join(root,'starters/auth');
const check=process.argv.includes('--check'),manifest={format:1,stacks:{}};
function run(command,args,cwd){const result=spawnSync(command,args,{cwd,windowsHide:true,stdio:'inherit',shell:process.platform==='win32'&&command.endsWith('.cmd')});if(result.status!==0)throw new Error('Auth asset build failed.');}
for(const stack of ['bootstrap','tailwind','react']){
  const project=await mkdtemp(join(root,'storage',`auth-assets-${stack}-`)),frontend=join(project,'frontend/auth'),output=join(project,'public/assets');
  await mkdir(join(frontend,'src'),{recursive:true});await mkdir(join(project,'resources/views/auth'),{recursive:true});
  for(const file of ['package.json','package-lock.json']){try{await copyFile(join(source,'stacks',stack,file),join(frontend,file));}catch(error){if(file!=='package-lock.json'||error.code!=='ENOENT')throw error;}}
  await copyFile(join(source,'frontend/build.mjs'),join(frontend,'build.mjs'));
  const view=stack==='react'?'shell.php':'page.php';await copyFile(join(source,'common/resources/views/auth',view+'.stub'),join(project,'resources/views/auth',view));
  if(stack!=='react')await copyFile(join(source,'common/resources/views/auth/account.php.stub'),join(project,'resources/views/auth/account.php'));
  for(const name of ['settings','challenge'])await copyFile(join(source,'common/resources/views/auth',name+'.php.stub'),join(project,'resources/views/auth',name+'.php'));
  await copyFile(join(source,'frontend/src/account.js'),join(frontend,'src/account.js'));
  if(stack!=='bootstrap'){let css=await readFile(join(source,'frontend/src/auth.css'),'utf8');if(stack!=='react')css=css.replace('@source "./app.jsx";','');await writeFile(join(frontend,'src/auth.css'),css);}
  if(stack==='react')await copyFile(join(source,'frontend/src/app.jsx'),join(frontend,'src/app.jsx'));
  const npm=process.platform==='win32'?'npm.cmd':'npm';
  try{await readFile(join(frontend,'package-lock.json'));}catch{if(check)throw new Error('Missing starter dependency lock.');run(npm,['install','--package-lock-only','--ignore-scripts','--no-audit','--no-fund'],frontend);await copyFile(join(frontend,'package-lock.json'),join(source,'stacks',stack,'package-lock.json'));}
  run(npm,['ci','--ignore-scripts','--no-audit','--no-fund'],frontend);
  run(process.execPath,['build.mjs'],frontend);
  const files={};await mkdir(join(source,'assets',stack),{recursive:true});
  for(const name of (await readdir(output)).sort()){const bytes=await readFile(join(output,name));files[name]=createHash('sha256').update(bytes).digest('hex');const target=join(source,'assets',stack,name);if(check){if(!bytes.equals(await readFile(target)))throw new Error('Stale bundled auth asset: '+stack+'/'+name);}else await writeFile(target,bytes);}
  const pkg=JSON.parse(await readFile(join(frontend,'package.json'),'utf8'));manifest.stacks[stack]={dependencies:pkg.devDependencies,files};
  console.log('PASS reproducible '+stack+' auth assets');
}
const wire=JSON.stringify(manifest,null,2)+'\n',path=join(source,'assets/manifest.json');
if(check){if(wire!==await readFile(path,'utf8'))throw new Error('Stale auth asset manifest.');}else await writeFile(path,wire);
