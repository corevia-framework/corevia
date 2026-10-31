"""Repository-only V2 -> current engine -> backup rollback acceptance; no live sites."""
import hashlib
import json
import os
from pathlib import Path
import shutil
import subprocess
import tempfile
import zipfile

ROOT = Path(__file__).resolve().parents[1]
PHP = shutil.which(os.environ.get('PHP_BINARY', 'php'))
GIT = ['git', '-c', 'safe.directory=' + ROOT.as_posix()]
if not PHP:
    raise SystemExit('PHP is required.')
BASELINE = subprocess.check_output(GIT + ['rev-parse', 'v2.0.0^{commit}'], cwd=ROOT, text=True).strip()
workspace = Path(tempfile.mkdtemp(prefix='v3-upgrade-', dir=ROOT/'storage')).resolve()
assert workspace.is_relative_to((ROOT/'storage').resolve())

program = r'''<?php
declare(strict_types=1);
require $argv[1];
use Corevia\Core\{Action,Corevia,ExecutionContext,Flow,JsonRunStore,Logger,Module,ModuleContext,State};
final class Work implements Action { public function handle(State $s,ExecutionContext $c):State{$s->data['count']=($s->data['count']??0)+1;return $s;} }
final class LegacyApplication implements Module {
 public function name():string{return 'legacy';} public function dependencies():array{return [];}
 public function register(ModuleContext $c):void{$c->actions(['work'=>Work::class]);$c->flows([Flow::define('legacy.persisted')->step('work')->waitFor('approved')->step('work')]);}
}
$quiet=new class implements Logger{public function log(string $level,string $message,array $context=[]):void{}};
$app=Corevia::create(dirname($argv[2]),new JsonRunStore($argv[2]),$quiet);$app->modules([new LegacyApplication()]);$app->boot();
try{
 $operation=$argv[3];
 if($operation==='create'){$run=$app->run('legacy.persisted',['label'=>'V2 data'],'upgrade-idempotency');}
 else{$stored=$app->run('legacy.persisted',['label'=>'V2 data'],'upgrade-idempotency');if($stored->run['state']['count']!==1||$stored->status()!=='paused'){throw new RuntimeException('Persisted V2 run changed before resume.');}$run=$app->resume($stored->run['run_id'],'approved');}
 echo json_encode(['id'=>$run->run['run_id'],'status'=>$run->status(),'count'=>$run->run['state']['count'],'label'=>$run->run['state']['label']],JSON_THROW_ON_ERROR);
}finally{$app->shutdown();}
'''

def execute(autoload, store, operation):
    output=subprocess.check_output([PHP,'-n',str(workspace/'application.php'),str(autoload),str(store),operation],cwd=workspace,text=True)
    return json.loads(output)

try:
    archive=workspace/'v2.zip'
    subprocess.run(GIT+['archive','--format=zip','-o',str(archive),BASELINE],cwd=ROOT,check=True)
    old=workspace/'v2'
    with zipfile.ZipFile(archive) as incoming:
        for name in incoming.namelist():
            if Path(name).is_absolute() or '..' in Path(name).parts:
                raise RuntimeError('Unsafe baseline archive.')
        incoming.extractall(old)
    (workspace/'application.php').write_text(program,encoding='utf-8')
    store=workspace/'runs.json';backup=workspace/'backup.json'
    original=execute(old/'autoload.php',store,'create')
    assert original['status']=='paused' and original['count']==1
    shutil.copyfile(store,backup);before=hashlib.sha256(backup.read_bytes()).hexdigest()
    upgraded=execute(ROOT/'autoload.php',store,'resume')
    assert upgraded['id']==original['id'] and upgraded['status']=='completed' and upgraded['count']==2 and upgraded['label']=='V2 data'
    # Restore a matching pre-upgrade data backup before rolling back code.
    shutil.copyfile(backup,store)
    assert hashlib.sha256(store.read_bytes()).hexdigest()==before
    rolled_back=execute(old/'autoload.php',store,'resume')
    assert rolled_back==upgraded
    names=subprocess.check_output(GIT+['ls-files','--cached','--others','--exclude-standard','-z'],cwd=ROOT);source=hashlib.sha256()
    for name in sorted(n for n in names.split(b'\0') if n):
        path=ROOT/os.fsdecode(name)
        if path.is_symlink() or not path.is_file():raise RuntimeError('Unsafe current source fingerprint.')
        source.update(name+b'\0'+hashlib.sha256(path.read_bytes()).digest())
    report={'format':1,'passed':True,'source_fingerprint':source.hexdigest(),'baseline_commit':BASELINE,'baseline_tag':'v2.0.0','v2_created_data':True,'v3_resumed_same_id':True,'idempotency_preserved':True,'restored_backup_resumed_under_v2':True,'live_sites_accessed':False,'scope':'Framework module/flow/JSON persistence compatibility; application migrations and external side effects need their own policy.'}
    receipt=ROOT/'storage/v3-upgrade-verification.json';receipt.write_text(json.dumps(report,indent=2)+'\n',encoding='utf-8');os.chmod(receipt,0o600)
    print('PASS V2-created paused workflow -> current engine resume -> restored backup under V2; same application code, identity and persisted data')
finally:
    # Keep review artifacts private for diagnosis; never recursively remove a live path.
    print('Private upgrade artifacts: '+str(workspace))
