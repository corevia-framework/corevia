"""Static package boundaries. Parses source without importing/executing application code."""
import argparse
import ast
import json
import os
from pathlib import Path
import re
import subprocess
import sys

TOOLS = Path(__file__).resolve().parent

def parsed(command, paths):
    if not paths:
        return {}
    result = subprocess.run(command, input=json.dumps([str(p) for p in paths]), text=True, capture_output=True)
    if result.returncode:
        raise RuntimeError(result.stderr.strip() or 'Architecture parser failed.')
    return json.loads(result.stdout)

def check(root, rules=None):
    root = Path(root).resolve()
    rules = rules or json.loads((TOOLS / 'architecture_rules.json').read_text())
    errors = []
    def fail(path, reason):
        errors.append(str(path.relative_to(root)).replace('\\','/') + ': ' + reason)
    def owned_files(owner,suffixes):
        cursor=owner
        while cursor!=root:
            if cursor.is_symlink():
                fail(owner,'Source directory may not be a symlink.')
                return []
            cursor=cursor.parent
        files=[]
        for directory,dirs,names in os.walk(owner):
            dirs[:]=[name for name in dirs if name not in {'test','tests','node_modules','vendor','__pycache__','.dart_tool','.git','.venv'}]
            for name in list(dirs):
                path=Path(directory)/name
                if path.is_symlink():
                    fail(path,'Source directory may not be a symlink.')
                    dirs.remove(name)
            for name in names:
                path=Path(directory)/name
                if path.is_symlink():
                    fail(path,'Source files may not be symlinks.')
                elif path.suffix in suffixes:
                    files.append(path)
        return sorted(files)
    php_files = [path for owner in sorted((root/'packages').glob('*/src')) for path in owned_files(owner,{'.php'})]
    tokens = parsed([os.environ.get('PHP_BINARY','php'),str(TOOLS/'architecture_tokens.php')],php_files)
    for path in php_files:
        layer = path.relative_to(root).parts[1]
        allowed = rules['php'].get(layer)
        if allowed is None:
            fail(path,'Package has no architecture policy.')
            continue
        code = ''.join(text if kind not in {'T_COMMENT','T_DOC_COMMENT','T_CONSTANT_ENCAPSED_STRING','T_ENCAPSED_AND_WHITESPACE'} else ' ' for kind,text,_ in tokens[str(path)])
        # Expand PHP grouped imports before checking fully qualified references.
        groups = re.findall(r'\buse\s+([\w\\]+)\\\s*\{([^}]+)\}\s*;',code)
        references = re.findall(r'(?<![\w\\])\\?((?:Corevia|App)\\[\w\\]+)',code)
        for prefix, body in groups:
            references.extend(prefix+'\\'+item.strip().split()[0] for item in body.split(','))
        if re.search(r'\buse\s+(?:function\s+|const\s+)?App\s*(?:as\s+\w+\s*)?;',code):
            references.append('App')
        # An aliased Corevia root would obscure the target layer; use an explicit package import.
        if re.search(r'\buse\s+Corevia\s*(?:as\s+\w+\s*)?;',code):
            fail(path,'Import a specific Corevia package instead of aliasing the framework root.')
        for kind,text,_ in tokens[str(path)]:
            if kind == 'T_CONSTANT_ENCAPSED_STRING':
                value = text[1:-1].replace('\\\\','\\')
                if re.fullmatch(r'\\?(?:App|Corevia)\\[\w\\]+',value):
                    references.append(value.lstrip('\\'))
            if kind in {'T_REQUIRE','T_REQUIRE_ONCE','T_INCLUDE','T_INCLUDE_ONCE'} and path.relative_to(root).as_posix() not in rules['php_include_owners']:
                fail(path,'Executable includes belong to audited configuration/view/CLI loaders.')
            if kind == 'T_EVAL':
                fail(path,'eval bypasses inspectable package dependencies.')
        for reference in sorted(set(references)):
            parts = reference.split('\\')
            if parts[0]=='App' or (parts[0]=='Corevia' and (len(parts)<2 or parts[1] not in allowed)):
                fail(path,'Forbidden dependency '+reference+' from '+layer+'.')
        namespaces = re.findall(r'\bnamespace\s+([\w\\]+)\s*[;{]',code)
        own = {'core':'Core','protocol':'Protocol','http':'Http','persistence-pdo':'Persistence','realtime':'Realtime','cli':'Cli'}[layer]
        if not namespaces or any(not (n=='Corevia\\'+own or n.startswith('Corevia\\'+own+'\\')) for n in namespaces):
            fail(path,'Source namespace does not match its package.')

    python_roots = [root/'sdk/python/corevia',root/'contracts/generated/python',root/'packages/protocol/templates']
    for owner in python_roots:
        for path in owned_files(owner,{'.py'}):
            tree = ast.parse(path.read_text(encoding='utf-8'),filename=str(path))
            for node in ast.walk(tree):
                names = []
                if isinstance(node,ast.Import):
                    names = [alias.name for alias in node.names]
                elif isinstance(node,ast.ImportFrom):
                    if node.level:
                        target=path.parent
                        for _ in range(node.level-1):
                            target=target.parent
                        if not target.is_relative_to(owner):
                            fail(path,'Relative Python import escapes its SDK.')
                    else:
                        names=[node.module or '']
                for name in names:
                    if name.split('.')[0] in {'sqlite3','_sqlite3','dbm'} or name.split('.')[0] not in sys.stdlib_module_names|{'corevia','__future__'}:
                        fail(path,'Python SDK may not import server/application/driver module '+name+'.')
                if isinstance(node,ast.Call):
                    function=ast.unparse(node.func)
                    if function in {'__import__','importlib.import_module','eval','exec'}:
                        fail(path,'Dynamic execution/import bypasses Python adapter boundaries.')

    frontend_roots = [root/'sdk/js',root/'sdk/dart/lib',root/'contracts/generated/js',root/'contracts/generated/dart',root/'packages/protocol/templates']
    source_files={owner:owned_files(owner,{'.js','.ts','.dart','.jsx','.tsx','.mjs','.mts','.cts','.cjs'}) for owner in frontend_roots}
    js_paths=[path for paths in source_files.values() for path in paths if path.suffix in {'.js','.mjs','.cjs','.ts'} and (path.suffix!='.ts' or path.name.endswith('.d.ts'))]
    imports=parsed([os.environ.get('NODE_BINARY','node'),str(TOOLS/'architecture_modules.mjs')],js_paths)
    for owner in frontend_roots:
        for path in source_files[owner]:
            if path.suffix in {'.jsx','.tsx','.mts','.cts'} or (path.suffix=='.ts' and not path.name.endswith('.d.ts')):
                fail(path,'Register a source parser before adding this SDK source format.')
                continue
            source=path.read_text(encoding='utf-8')
            entry=imports.get(str(path),{'imports':[],'computed':[]})
            dependencies=list(entry['imports'])
            for reason in entry['computed']:
                fail(path,reason)
            if path.suffix=='.dart':
                # Lex directives outside comments/string contents, including conditional imports.
                pattern=r'''r?"""[\s\S]*?"""|r?\x27\x27\x27[\s\S]*?\x27\x27\x27|r?"(?:\\.|[^"\\])*"|r?'(?:\\.|[^'\\])*'|//[^\n]*|/\*[\s\S]*?\*/|[A-Za-z_$][\w$]*|[^\s]'''
                tokens=[m[0] for m in re.finditer(pattern,source) if not m[0].startswith(('//','/*'))]
                directive=False
                for token in tokens:
                    if token in {'import','export','part'}:
                        directive=True
                    elif token==';':
                        directive=False
                    elif directive and token.lstrip('r').startswith(('"',"'")):
                        dependencies.append(ast.literal_eval(token))
            for dependency in sorted(set(dependencies)):
                if dependency.startswith('.') or (path.suffix=='.dart' and ':' not in dependency):
                    if not (path.parent/dependency).resolve().is_relative_to(owner.resolve()):
                        fail(path,'Frontend import escapes its SDK: '+dependency)
                elif path.suffix=='.dart' and dependency.startswith('dart:'):
                    pass
                elif dependency not in rules['dart_externals' if path.suffix=='.dart' else 'javascript_externals']:
                    fail(path,'Frontend SDK cannot import server/external dependency '+dependency+'.')
    return sorted(set(errors))

def main():
    parser=argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--root',type=Path,default=TOOLS.parent)
    args=parser.parse_args()
    try:
        errors=check(args.root)
    except Exception as error:
        print('Architecture check failed: '+str(error),file=sys.stderr)
        return 1
    for error in errors:
        print('FAIL '+error)
    if not errors:
        print('PASS PHP package, Python adapter and frontend SDK architecture boundaries')
    return 1 if errors else 0

if __name__=='__main__':
    raise SystemExit(main())
