"""Record V3 PHP/SDK/contract/persistence source inventories and a reproducible local baseline fixture."""
import ast
import hashlib
import io
import json
from pathlib import Path, PurePosixPath
import re
import shutil
import stat
import subprocess
import tempfile
import zipfile

ROOT=Path(__file__).resolve().parents[1]
BASELINE='d7429ed14356de39aaf8e98fa0993fc5c8f38337'
GIT=['git','-c','safe.directory='+ROOT.as_posix()]
php=shutil.which('php')
if not php: raise SystemExit('PHP is required.')
archive=subprocess.check_output(GIT+['archive','--format=zip',BASELINE,'packages','autoload.php','sdk','contracts'],cwd=ROOT)
with zipfile.ZipFile(io.BytesIO(archive)) as files:
    data={entry.filename:files.read(entry) for entry in files.infolist() if not entry.is_dir()}
sdk={};schemas={};exports={}
for name,content in sorted(data.items()):
    if name.startswith('sdk/') and Path(name).suffix in {'.php','.py','.js','.ts','.dart','.json','.toml','.yaml'}:
        sdk[name]=hashlib.sha256(content).hexdigest()
        if name.startswith('sdk/python/corevia/') and name.endswith('.py'):
            tree=ast.parse(content.decode('utf-8'))
            symbols=[]
            for node in tree.body:
                if isinstance(node,(ast.ClassDef,ast.FunctionDef,ast.AsyncFunctionDef)) and not node.name.startswith('_'):
                    symbols.append(node.name)
                    if isinstance(node,ast.ClassDef):
                        symbols.extend(node.name+'.'+method.name for method in node.body if isinstance(method,(ast.FunctionDef,ast.AsyncFunctionDef)) and not method.name.startswith('_'))
            exports[name]=sorted(symbols)
        elif name.startswith('sdk/js/') and name.endswith('.js') and '/test/' not in name:
            exports[name]=sorted(set(re.findall(r'export\s+(?:async\s+)?(?:class|function|const)\s+([A-Za-z_$][\w$]*)',content.decode('utf-8'))))
        elif name.startswith('sdk/dart/lib/') and name.endswith('.dart'):
            exports[name]=sorted(set(re.findall(r'(?:class|enum|typedef)\s+([A-Za-z][\w]*)',content.decode('utf-8'))))
    if name.startswith('contracts/') and name.endswith('.json'):
        schemas[name]=hashlib.sha256(content).hexdigest()
persisted=['packages/core/src/JsonRunStore.php','packages/core/src/JobQueue.php','packages/core/src/ApplicationArtifact.php','packages/core/src/ConfigRepository.php','packages/core/src/FlowEngine.php',
    'packages/auth/src/JsonAuthStore.php','packages/auth/src/SqlAuthStore.php','packages/auth/src/MailQueue.php','packages/auth/src/SqlMailQueue.php',
    'packages/auth/src/AtomicAuthStore.php','packages/auth/src/SnapshotAuthStore.php','packages/auth/src/SecretBox.php',
    'packages/persistence-pdo/src/PdoRunStore.php','packages/persistence-pdo/src/SqlRunStore.php','packages/persistence-pdo/src/SqlCoordinator.php',
    'packages/persistence-pdo/src/MongoSnapshotStore.php','packages/persistence-pdo/src/JsonDocumentStore.php','packages/persistence-pdo/src/MongoDocumentStore.php']
inventory={'format':1,'baseline_commit':BASELINE,'sdk_files':sdk,'sdk_symbols':exports,'contract_files':schemas,
    'persistence_sources':{name:hashlib.sha256(data[name]).hexdigest() for name in persisted if name in data},
    'limits':['SDK symbol lists are an inventory; conformance fixtures establish behavior, not regex extraction.',
        'Persistence source hashes identify reviewed implementations, not automatic schema migration/downgrade guarantees.']}
storage=ROOT/'storage'
if storage.is_symlink(): raise SystemExit('Storage cannot be a symlink.')
storage.mkdir(exist_ok=True)
(storage/'v4-compatibility-inventory.json').write_text(json.dumps(inventory,indent=2)+'\n')
with tempfile.TemporaryDirectory(prefix='v4-benchmark-',dir=storage) as temporary:
    directory=Path(temporary)
    for name,content in data.items():
        relative=PurePosixPath(name)
        if relative.is_absolute() or '..' in relative.parts or '\\' in name or ':' in name: raise RuntimeError('Unsafe baseline member.')
        target=directory/name;target.parent.mkdir(parents=True,exist_ok=True);target.write_bytes(content)
    results={}
    for label,loader in [('v3',directory/'autoload.php'),('current',ROOT/'autoload.php')]:
        results[label]=json.loads(subprocess.check_output([php,str(ROOT/'tools/v4-baseline-benchmark.php'),str(loader)],text=True))
    (storage/'v4-baseline-benchmark.json').write_text(json.dumps({'format':1,'baseline_commit':BASELINE,'results':results,
        'decision':'Recorded local methodology/baseline only; no performance release threshold or comparative winner is inferred.'},indent=2)+'\n')
print('PASS V3 SDK/contract/persistence inventory and matched in-process baseline/current measurement; results under ignored storage/')
