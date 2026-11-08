<?php
declare(strict_types=1);
require dirname(__DIR__,2).'/autoload.php';
use Corevia\Core\{JobWorker,JobClaim,ScopeManager};
use Corevia\Persistence\{SqlConnection,SqlCoordinator};
$directory=realpath($argv[1]??'');$root=realpath(dirname(__DIR__,2).'/storage');
if($directory===false||$root===false||!str_starts_with(str_replace('\\','/',$directory),str_replace('\\','/',$root).'/')){throw new InvalidArgumentException('Use a private fixture directory inside storage.');}
$db=new SqlConnection(new PDO('sqlite:'.$directory.'/fixture.sqlite'));$queue=new SqlCoordinator($db,'cv_crash',3);$mode=$argv[2]??'';
if($mode==='setup'){
    $queue->migrate();$db->execute('CREATE TABLE fixture_effects (effect_key VARCHAR(64) PRIMARY KEY)');
    echo json_encode(['id'=>$queue->dispatch('effect',[],true,$queue->clock())],JSON_THROW_ON_ERROR)."\n";exit;
}
$worker=new JobWorker($queue,new ScopeManager(),['effect'=>function($input,$claim,$scope)use($db,$directory,$queue,$mode):array{
    // Teaching downstream idempotence: the replacement attempts the same stable effect key.
    $db->execute('INSERT OR IGNORE INTO fixture_effects (effect_key) VALUES (?)',[$claim->idempotencyKey()]);
    if($mode==='victim'){
        $scope->onClose(fn()=>file_put_contents($directory.'/victim-cleaned','unexpected after hard kill'));
        $lease=(int)$db->select('SELECT lease FROM cv_crash_jobs WHERE id=?',[$claim->id])[0]['lease'];
        file_put_contents($directory.'/claim.tmp',json_encode(['id'=>$claim->id,'task'=>$claim->task,'attempt'=>$claim->attempt,'token'=>$claim->token(),'expires'=>$lease],JSON_THROW_ON_ERROR));rename($directory.'/claim.tmp',$directory.'/claim.json');
        while(true){usleep(100000);}
    }
    if($claim->attempt!==2){throw new RuntimeException('Replacement did not reclaim the second attempt.');}
    $old=json_decode(file_get_contents($directory.'/claim.json'),true,8,JSON_THROW_ON_ERROR);
    $stale=new JobClaim($old['id'],$old['task'],$old['attempt'],[],$old['token']);
    if($queue->complete($stale,[], $queue->clock())){throw new RuntimeException('Stale owner acknowledged replacement work.');}
    $scope->onClose(fn()=>file_put_contents($directory.'/replacement-cleaned','yes'));
    return ['attempt'=>$claim->attempt];
}],10);
$result=$worker->once();$effects=(int)$db->select('SELECT COUNT(*) AS total FROM fixture_effects')[0]['total'];
echo json_encode(['result'=>$result,'effects'=>$effects,'queue'=>$queue->status(),'victim_cleanup_ran'=>file_exists($directory.'/victim-cleaned'),'replacement_cleanup_ran'=>file_exists($directory.'/replacement-cleaned')],JSON_THROW_ON_ERROR)."\n";
