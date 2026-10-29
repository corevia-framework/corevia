import {parse,tokenizer} from 'acorn';
import {readFileSync} from 'node:fs';
const files=JSON.parse(readFileSync(0,'utf8'));
const result={};
for(const file of files){
  const source=readFileSync(file,'utf8'),entry={imports:[],computed:[]};
  if(file.endsWith('.d.ts')){
    const tokens=[...tokenizer(source,{ecmaVersion:'latest',sourceType:'module'})];
    for(let i=0;i<tokens.length;i++){
      const token=tokens[i],next=tokens[i+1];
      if((token.type.label==='import' || token.value==='from') && next?.type.label==='string')entry.imports.push(next.value);
      if((token.type.label==='import' || token.value==='require') && next?.type.label==='('){
        if(tokens[i+2]?.type.label==='string' && tokens[i+3]?.type.label===')')entry.imports.push(tokens[i+2].value);
        else entry.computed.push('Computed imports cannot be checked; use literal SDK dependencies.');
      }
    }
  }else{
    const tree=parse(source,{ecmaVersion:'latest',sourceType:'module',allowReturnOutsideFunction:file.endsWith('.cjs')});
    function walk(node){
      if(!node || typeof node!=='object')return;
      if(['ImportDeclaration','ExportAllDeclaration','ExportNamedDeclaration'].includes(node.type) && node.source)entry.imports.push(node.source.value);
      if(node.type==='ImportExpression' || node.type==='CallExpression' && node.callee.type==='Identifier' && node.callee.name==='require'){
        const argument=node.type==='ImportExpression'?node.source:node.arguments[0];
        if(argument?.type==='Literal' && typeof argument.value==='string')entry.imports.push(argument.value);
        else entry.computed.push('Computed imports cannot be checked; use literal SDK dependencies.');
      }
      if(['CallExpression','NewExpression'].includes(node.type) && node.callee.type==='Identifier' && ['eval','Function'].includes(node.callee.name))entry.computed.push('Dynamic execution bypasses frontend SDK boundaries.');
      for(const child of Object.values(node)){if(Array.isArray(child)){for(const item of child)walk(item);}else if(child && typeof child==='object')walk(child);}
    }
    walk(tree);
  }
  result[file]=entry;
}
process.stdout.write(JSON.stringify(result));
