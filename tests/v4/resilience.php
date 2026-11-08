<?php
declare(strict_types=1);
namespace CoreviaV4ResilienceTests;
require dirname(__DIR__,2).'/autoload.php';
require_once dirname(__DIR__).'/support/Services.php';
use Corevia\Core\{Deadline,RetryExecutor};
use Corevia\Persistence\{SqlConnection,SqlResilience};
use Corevia\Testing\Clock;
function check(bool $ok):void { if (!$ok) { throw new \RuntimeException('Resilience assertion failed.'); } }
function rejects(callable $work,?string $message=null):void
{
    try { $work(); } catch (\Throwable $error) { if ($message!==null&&$error->getMessage()!==$message) { throw $error; }return; }
    throw new \RuntimeException('Unsafe resilience operation accepted.');
}
function contract(SqlConnection $db,string $prefix):void
{
    $clock=new Clock(1000);
    $gate=new SqlResilience($db,'dependency',2,10,5,3,60,$prefix,$clock);$gate->migrate();$gate->migrate();
    $otherHost=new SqlResilience($db,'dependency',2,10,5,3,60,$prefix,$clock);
    $oldSuccess=$gate->enter();$oldFailure=$gate->enter();$a=$gate->enter();$b=$gate->enter();
    $gate->fail($a);$otherHost->fail($b);check($gate->status()['state']==='open');
    // Results from before opening cannot close or prolong a newer circuit generation.
    $gate->succeed($oldSuccess);$gate->fail($oldFailure);check($gate->status()['failures']===2);
    rejects(fn()=>$gate->enter(),'CIRCUIT_OPEN');$clock->advance(10);
    $probe=$gate->enter();rejects(fn()=>$otherHost->enter(),'CIRCUIT_OPEN');
    $clock->advance(5);$replacement=$otherHost->enter();$gate->succeed($probe);check($gate->status()['state']==='half_open');
    $otherHost->fail($replacement);check($gate->status()['state']==='open');rejects(fn()=>$otherHost->fail($replacement));
    $clock->advance(10);$probe=$gate->enter();$gate->succeed($probe);check($gate->status()['state']==='closed');
    check($gate->takeRetry()&&$otherHost->takeRetry()&&$gate->takeRetry()&&!$otherHost->takeRetry());
    $clock->advance(60);check($gate->takeRetry());
    rejects(fn()=>(new SqlResilience($db,'dependency',3,10,5,3,60,$prefix,$clock))->enter());
    rejects(fn()=>$db->transaction(fn()=>$gate->enter()));
    $regressed=new SqlResilience($db,'dependency',2,10,5,3,60,$prefix,fn()=>1000);rejects(fn()=>$regressed->enter(),'RESILIENCE_CLOCK_REGRESSION');

    $retryGate=new SqlResilience($db,'retry',100,10,5,3,60,$prefix,$clock);$retryGate->migrate();
    $calls=0;$sleeps=[];$nanos=0;
    $runner=new RetryExecutor($retryGate,3,10,100,static function($ms)use(&$sleeps,&$nanos):void { $sleeps[]=$ms;$nanos+=$ms*1000000; },static fn($min,$max)=>$max);
    $deadline=new Deadline(1000,static function()use(&$nanos):int { return $nanos; });
    $operation=static function($attempt,$deadline)use(&$calls):string { $calls++;$deadline->check();if ($attempt<3) { throw new \RuntimeException('transient'); }return 'ok'; };
    check($runner->run($operation,$deadline,true,fn($error)=>$error->getMessage()==='transient')==='ok'&&$calls===3&&$sleeps===[10,20]);
    $calls=0;rejects(fn()=>$runner->run($operation,new Deadline(1000,static fn()=>0),false,fn()=>true),'transient');check($calls===1);
    $calls=0;rejects(fn()=>$runner->run($operation,new Deadline(1000,static fn()=>0),true,fn()=>false),'transient');check($calls===1);
    // One retry quota remains. It is reserved despite failure; no refund after uncertain outcomes.
    $calls=0;rejects(fn()=>$runner->run($operation,new Deadline(1000,static fn()=>0),true,fn()=>true),'RETRY_BUDGET_EXHAUSTED');check($calls===2);
    $calls=0;rejects(fn()=>$runner->run($operation,new Deadline(1,static fn()=>0),true,fn()=>true),'RETRY_DEADLINE_EXHAUSTED');check($calls===1);
    $cancelled=new Deadline(1000,static fn()=>0);$cancelled->cancel();$calls=0;rejects(fn()=>$runner->run($operation,$cancelled,true,fn()=>true),'WORK_CANCELLED');check($calls===0);
    $cancelGate=new SqlResilience($db,'cancel',100,10,5,10,60,$prefix,$clock);
    $cancel=new Deadline(1000,static fn()=>0);$calls=0;
    $cancelRunner=new RetryExecutor($cancelGate,3,100,100,static fn()=>$cancel->cancel(),static fn($min,$max)=>$max);
    rejects(fn()=>$cancelRunner->run($operation,$cancel,true,fn()=>true),'WORK_CANCELLED');check($calls===1);
    $different=new SqlResilience($db,'other',2,10,5,3,60,$prefix,$clock);rejects(fn()=>$different->succeed($gate->enter()));
}
contract(new SqlConnection(new \PDO('sqlite::memory:')),'cv_resilience_test');
echo "PASS SQL retry quota, policy drift, circuit generations/single probes, late-result fencing, retry safety, deadline and cancellation\n";
if (getenv('COREVIA_DB_TESTS')==='1') {
    foreach (\CoreviaTests\Services::drivers() as $driver) {
        $db=SqlConnection::connect(\CoreviaTests\Services::sql($driver));$prefix='cv_resil_'.bin2hex(random_bytes(5));
        try { contract($db,$prefix);echo "PASS $driver resilience contract\n"; }
        finally { foreach (['states','mutex'] as $table) { $db->execute('DROP TABLE IF EXISTS '.$db->quoteIdentifier($prefix.'_'.$table)); } }
    }
}
