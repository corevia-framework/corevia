<?php
declare(strict_types=1);
namespace CoreviaV4SchedulerTests;
require dirname(__DIR__,2).'/autoload.php';
require_once dirname(__DIR__).'/support/Services.php';
use Corevia\Core\{CronExpression,ScheduleEntry,ScopeManager};
use Corevia\Persistence\{SqlConnection,SqlCoordinator,SqlScheduler};
function check(bool $ok):void { if (!$ok) { throw new \RuntimeException('Scheduler assertion failed.'); } }
function rejects(callable $work):void { try { $work(); } catch (\Throwable) { return; } throw new \RuntimeException('Unsafe schedule accepted.'); }
function stamp(string $value):int { return (new \DateTimeImmutable($value))->getTimestamp(); }
foreach (['* * * *','60 * * * *','* 24 * * *','* * 0 * *','* * * 13 *','* * * * 7','*/0 * * * *','5/2 * * * *','@daily','* * * * MON','9-2 * * * *'] as $bad) { rejects(fn()=>new CronExpression($bad)); }
$once=new ScheduleEntry('dst',new CronExpression('30 1 * * *'),'America/New_York','report');
$each=new ScheduleEntry('dst',new CronExpression('30 1 * * *'),'America/New_York','report',repeatedMinute:'each');
$first=stamp('2026-11-01T05:30:00Z');$second=stamp('2026-11-01T06:30:00Z');
check($once->occurrence($first)===$once->occurrence($second)&&$each->occurrence($first)!==$each->occurrence($second));
$spring=new ScheduleEntry('spring',new CronExpression('30 2 * * *'),'America/New_York','report');
for ($n=stamp('2026-03-08T00:00:00Z');$n<stamp('2026-03-09T00:00:00Z');$n+=60) { check($spring->occurrence($n)===null); }
$rule=new CronExpression('*/15 9-17 * * 1-5');check($rule->matches(new \DateTimeImmutable('2026-11-09 09:15:00 UTC'))&&!$rule->matches(new \DateTimeImmutable('2026-11-08 09:15:00 UTC')));
$days=new CronExpression('0 0 1 * 1');check($days->matches(new \DateTimeImmutable('2026-11-09 UTC'))&&$days->matches(new \DateTimeImmutable('2026-12-01 UTC'))&&!$days->matches(new \DateTimeImmutable('2026-11-10 UTC')));
rejects(fn()=>new ScheduleEntry('bad',new CronExpression('* * * * *'),'+02:00','task'));
function contract(SqlConnection $db,string $prefix):void
{
    $q=new SqlCoordinator($db,$prefix,2,60);$q->migrate();$q->migrate();$now=$q->clock();
    $id=$q->dispatchOnce('event','task',['secret'=>'private-payload'],true,$now);
    check($q->dispatchOnce('event','task',['secret'=>'private-payload'],true,$now)===$id);
    rejects(fn()=>$q->dispatchOnce('event','task',['secret'=>'changed'],true,$now));
    rejects(fn()=>$q->dispatchOnce('bad','task',[],false,$now));
    $claim=$q->claim($now);check($claim->id===$id&&$q->fail($claim,$now,true));
    $metadata=$q->inspect();check(count($metadata)===1&&!str_contains(json_encode($metadata),'private-payload')&&!str_contains(json_encode($metadata),$claim->token()));
    check($q->retryDead($id,$now)&&!$q->retryDead($id,$now));
    $retry=$q->claim($now);check($retry->id===$id&&$retry->attempt===1&&$retry->idempotencyKey()===$claim->idempotencyKey());
    check($q->complete($retry,[],$now));
    // Expiring queue payloads must not erase the dispatch reservation (including DST's second hour).
    check($q->claim($now+61)===null&&$q->dispatchOnce('event','task',['secret'=>'private-payload'],true,$now+3600)===$id&&array_sum($q->status())===0);
    $lease=$q->acquire('fence',$now,10);check($q->renewLease($lease,$now+1,10));
    $replacement=$q->acquire('fence',$now+12,10);check($replacement->fence>$lease->fence&&!$q->renewLease($lease,$now+12)&&!$q->release($lease,$now+12));
    check($q->release($replacement,$now+13));

    $scopes=new ScopeManager();$entry=new ScheduleEntry('minutely',new CronExpression('* * * * *'),'UTC','report',['example'=>true]);
    $scheduler=new SqlScheduler($q,$scopes,'test',[$entry]);rejects(fn()=>new SqlScheduler($q,$scopes,'test',[$entry,$entry]));
    // tick uses real server time, so two adjacent minutes are valid if the test straddles a boundary.
    $jobs=$scheduler->tick();$again=$scheduler->tick();check(count($jobs)===1&&count($again)===1);
    $seen=[];$closed=0;$handlers=['report'=>static function($input,$claim,$deadline,$scope,$fence)use(&$seen,&$closed):array{
        check($input===['example'=>true]&&$deadline->remainingMilliseconds()>0&&$fence>=1);
        $seen[]=$claim->idempotencyKey();$scope->onClose(static function()use(&$closed):void { $closed++; });return ['ok'=>true];
    }];
    check($scheduler->workOnce($handlers)['status']==='completed'&&$closed===1&&$scopes->activeCount()===0);
    if ($jobs!==$again) { check($scheduler->workOnce($handlers)['status']==='completed'); }
    check($scheduler->workOnce($handlers)['status']==='idle');
    // Explicit overlap lock skips a different occurrence without entering application code.
    $lock=$q->acquire('schedule:test:minutely',$q->clock(),60);
    $q->dispatch('corevia.schedule.test',['entry'=>'minutely','definition'=>$entry->fingerprint,'occurrence'=>'fixture-overlap'],true,$q->clock());
    $before=count($seen);check($scheduler->workOnce($handlers)['status']==='skipped_overlap'&&count($seen)===$before);$q->release($lock,$q->clock());
    $q->dispatch('corevia.schedule.test',['entry'=>'minutely','definition'=>'old','occurrence'=>'fixture-drift'],true,$q->clock());
    check($scheduler->workOnce($handlers)['status']==='invalid_definition'&&count($seen)===$before);
    $q->dispatch('corevia.schedule.test',['entry'=>'minutely','definition'=>$entry->fingerprint,'occurrence'=>'fixture-failure'],true,$q->clock());
    $failure=$scheduler->workOnce(['report'=>static function($input,$claim,$deadline,$scope)use(&$closed):array {
        $scope->onClose(static function()use(&$closed):void { $closed++; });throw new \RuntimeException('private error');
    }]);check($failure['status']==='failed'&&!str_contains(json_encode($failure),'private error')&&$scopes->activeCount()===0);
    // A suspended old worker resumes after a replacement has completed a newer occurrence.
    $q->dispatch('corevia.schedule.test',['entry'=>'minutely','definition'=>$entry->fingerprint,'occurrence'=>'fixture-stale'],true,$q->clock());
    $stale=$scheduler->workOnce(['report'=>static function()use($db,$prefix,$q,$scheduler,$handlers):array {
        $table=$db->quoteIdentifier($prefix.'_leases');
        $db->execute('UPDATE '.$table.' SET expires=0 WHERE resource=:key',['key'=>hash('sha256','schedule:test:minutely')]);
        $q->dispatch('corevia.schedule.test',['entry'=>'minutely','definition'=>(new ScheduleEntry('minutely',new CronExpression('* * * * *'),'UTC','report',['example'=>true]))->fingerprint,'occurrence'=>'fixture-new'],true,$q->clock());
        check($scheduler->workOnce($handlers)['status']==='completed');return [];
    }]);check($stale['status']==='lost_ownership'&&$scopes->activeCount()===0);
    $q->dispatch('corevia.schedule.test',['entry'=>'minutely','definition'=>$entry->fingerprint,'occurrence'=>'fixture-cancel'],true,$q->clock());
    check($scheduler->workOnce(['report'=>static function($input,$claim,$deadline):array { $deadline->cancel();return []; }])['status']==='failed');
    rejects(fn()=>$q->inspect('dead',101));
}
if (!class_exists(\PDO::class)||!in_array('sqlite',\PDO::getAvailableDrivers(),true)) { throw new \RuntimeException('Scheduler contract requires PDO SQLite.'); }
contract(new SqlConnection(new \PDO('sqlite::memory:')),'cv_schedule');
echo "PASS cron ranges/day rules, DST missing/repeated minutes, atomic dispatch retention, fencing, retry metadata and scoped scheduler cleanup\n";
if (getenv('COREVIA_DB_TESTS')==='1') {
    foreach (\CoreviaTests\Services::drivers() as $driver) {
        $db=SqlConnection::connect(\CoreviaTests\Services::sql($driver));$prefix='cv_sched_'.bin2hex(random_bytes(4));
        try { contract($db,$prefix);echo "PASS $driver scheduler contract\n"; }
        finally { foreach (['dedupe','jobs','leases','mutex'] as $table) { $db->execute('DROP TABLE IF EXISTS '.$db->quoteIdentifier($prefix.'_'.$table)); } }
    }
}
