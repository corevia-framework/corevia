import {readFile,writeFile,copyFile,mkdir} from 'node:fs/promises';
import {resolve,join} from 'node:path';
import {spawnSync} from 'node:child_process';
const stack=JSON.parse(await readFile(new URL('./package.json',import.meta.url),'utf8')).coreviaStack;
if(!['bootstrap','tailwind','react'].includes(stack))throw new Error('Unknown auth UI stack.');
// Resolve file URLs through the standard API so spaces and non-ASCII paths work.
const {fileURLToPath}=await import('node:url');
const cwd=fileURLToPath(new URL('.',import.meta.url));
const output=process.argv[2]?resolve(process.argv[2]):resolve(cwd,'../../public/assets');
await mkdir(output,{recursive:true});
if(stack!=='react')await copyFile(join(cwd,'src/account.js'),join(output,'auth-account.js'));
if(stack==='bootstrap')await writeFile(join(output,'auth.css'),await readFile(join(cwd,'node_modules/bootstrap/dist/css/bootstrap.min.css'),'utf8')+'\n.auth-card{max-width:28rem;border-radius:1rem}\n');
else{
  const cli=join(cwd,'node_modules/@tailwindcss/cli/dist/index.mjs');
  const result=spawnSync(process.execPath,[cli,'-i','src/auth.css','-o',join(output,'auth.css'),'--minify'],{cwd,windowsHide:true,stdio:'inherit'});
  if(result.status!==0)process.exit(result.status??1);
}
if(stack==='react'){
  const {build}=await import('esbuild');
  await build({entryPoints:[join(cwd,'src/app.jsx')],outfile:join(output,'auth.js'),bundle:true,minify:true,format:'esm',target:['es2022'],define:{'process.env.NODE_ENV':'"production"'},legalComments:'linked'});
}
const notices=[];
for(const name of stack==='bootstrap'?['bootstrap']:stack==='tailwind'?['tailwindcss']:['tailwindcss','react','react-dom','scheduler']){
  let found=false;
  for(const filename of ['LICENSE','LICENSE.md']){try{notices.push(name+'\n'+await readFile(join(cwd,'node_modules',name,filename),'utf8'));found=true;break;}catch(error){if(error.code!=='ENOENT')throw error;}}
  if(!found)throw new Error('Missing dependency license: '+name);
}
await writeFile(join(output,'auth-LICENSES.txt'),notices.join('\n\n'));
