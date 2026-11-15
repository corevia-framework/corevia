"""Actual V3-created workflow/auth data -> current code -> matching backup restored under V3."""
import hashlib
import io
import json
from pathlib import Path, PurePosixPath
import shutil
import stat
import subprocess
import tempfile
import zipfile

ROOT = Path(__file__).resolve().parents[2]
BASELINE = 'd7429ed14356de39aaf8e98fa0993fc5c8f38337'
GIT = ['git', '-c', 'safe.directory=' + ROOT.as_posix()]
PHP = shutil.which('php')
if not PHP:
    raise SystemExit('PHP is required.')

PROGRAM = r'''<?php
declare(strict_types=1);
require $argv[1];
use Corevia\Core\{Action,Corevia,ExecutionContext,Flow,JsonRunStore,Logger,Module,ModuleContext,State};
use Corevia\Auth\JsonAuthStore;
final class Work implements Action{public function handle(State $state,ExecutionContext $context):State{$state->data['count']=($state->data['count']??0)+1;return $state;}}
final class LegacyModule implements Module{
 public function name():string{return 'legacy';}public function dependencies():array{return [];}
 public function register(ModuleContext $context):void{$context->actions(['work'=>Work::class]);$context->flows([Flow::define('legacy')->step('work')->waitFor('approved')->step('work')]);}
}
$directory=$argv[2];$create=$argv[3]==='create';$now=time();
$quiet=new class implements Logger{public function log(string $level,string $message,array $context=[]):void{}};
$app=Corevia::create($directory,new JsonRunStore($directory.'/runs.json'),$quiet);$app->modules([new LegacyModule()]);$app->boot();
try{
 $auth=new JsonAuthStore($directory.'/auth.json');
 if($create){
  $user=$auth->createUser('fixture@example.test','Fixture','not-a-real-password-hash');
  $auth->saveSession('synthetic-session',['user_id'=>$user['id'],'user_version'=>0,'expires'=>$now+3600],$now);
  if(!$auth->issueAccountToken($user['id'],'verify','synthetic-token',$now+3600,0,$now)){throw new RuntimeException('Token creation failed.');}
 }
 $user=$auth->findUser('fixture@example.test');$session=$auth->session('synthetic-session',$now);
 if(!$user||($session['user_id']??null)!==$user['id']||!$auth->accountToken('synthetic-token','verify',$now)){throw new RuntimeException('Persisted identity changed.');}
 $run=$app->run('legacy',['label'=>'V3 data'],'stable-key');
 if(!$create){
  if($run->status()!=='paused'||$run->run['state']['count']!==1){throw new RuntimeException('Checkpoint changed.');}
  $run=$app->resume($run->run['run_id'],'approved');
  if(!$auth->completeAccountToken('synthetic-token','verify',$now,null,$user['id'])||$auth->accountToken('synthetic-token','verify',$now)!==null){throw new RuntimeException('Single-use token failed.');}
 }
 echo json_encode(['id'=>$run->run['run_id'],'status'=>$run->status(),'count'=>$run->run['state']['count'],'label'=>$run->run['state']['label'],'user_id'=>$user['id'],'session_user_id'=>$session['user_id']],JSON_THROW_ON_ERROR);
}finally{$app->shutdown();}
'''

archive = subprocess.check_output(GIT + ['archive', '--format=zip', BASELINE, 'packages', 'autoload.php'], cwd=ROOT)
with tempfile.TemporaryDirectory(prefix='v4-data-', dir=ROOT / 'storage') as temporary:
    workspace = Path(temporary)
    old = workspace / 'baseline'
    with zipfile.ZipFile(io.BytesIO(archive)) as incoming:
        for entry in incoming.infolist():
            path = PurePosixPath(entry.filename)
            if path.is_absolute() or '..' in path.parts or stat.S_ISLNK(entry.external_attr >> 16):
                raise RuntimeError('Unsafe baseline archive.')
        incoming.extractall(old)
    script = workspace / 'application.php'
    script.write_text(PROGRAM, encoding='utf-8')
    data = workspace / 'data'
    data.mkdir()

    def execute(loader, operation):
        return json.loads(subprocess.check_output([PHP, '-n', str(script), str(loader), str(data), operation], text=True))

    initial = execute(old / 'autoload.php', 'create')
    assert initial['status'] == 'paused' and initial['count'] == 1
    backup = {name: (data / name).read_bytes() for name in ['runs.json', 'auth.json']}
    resumed = execute(ROOT / 'autoload.php', 'resume')
    assert resumed['id'] == initial['id'] and resumed['user_id'] == initial['user_id']
    assert resumed['status'] == 'completed' and resumed['count'] == 2 and resumed['label'] == 'V3 data'
    for name, content in backup.items():
        (data / name).write_bytes(content)
        assert hashlib.sha256((data / name).read_bytes()).digest() == hashlib.sha256(content).digest()
    restored = execute(old / 'autoload.php', 'resume')
    assert restored == resumed
print('PASS actual V3 workflow, users, sessions and single-use tokens; current resume and exact-backup rollback under V3')
