<?php
declare(strict_types=1);
namespace CoreviaV4Tools;
require dirname(__DIR__,2).'/autoload.php';
require_once dirname(__DIR__).'/support/Services.php';
use Corevia\Ai\{CallableTool,ToolBroker,ToolDefinition,ToolExecution,TransportTool};
use Corevia\Core\{ScopeManager,TenantScope};
use Corevia\Persistence\{SqlConnection,SqlToolApprovals};
use Corevia\Protocol\{ContractManifest,ProcessTransport};
function check(bool $ok):void { if (!$ok) { throw new \RuntimeException('AI tools assertion failed.'); } }
function rejects(callable $work,?string $message=null):void { try { $work(); } catch (\Throwable $error) { if ($message!==null&&$error->getMessage()!==$message) { throw $error; }return; }throw new \RuntimeException('Unsafe tool accepted.'); }
function contract(SqlConnection $db,string $prefix):void
{
    $contract=ContractManifest::fromArray(['format'=>1,'name'=>'Tools','version'=>'1.0.0','actions'=>['double'=>[
        'input'=>['type'=>'object','properties'=>['value'=>['type'=>'integer','minimum'=>1,'maximum'=>5]],'required'=>['value'],'additionalProperties'=>false],
        'output'=>['type'=>'object','properties'=>['value'=>['type'=>'integer','minimum'=>2,'maximum'=>10]],'required'=>['value'],'additionalProperties'=>false],
    ]]]);
    $valid=true;$abilities=['ai.tools.request','ai.tools.execute','ai.tools.approve','numbers.double'];
    $a=new TenantScope('alpha','alice',static function()use(&$valid):bool { return $valid; },$abilities);$reviewer=new TenantScope('alpha','reviewer',fn()=>true,['ai.tools.approve']);
    $b=new TenantScope('beta','alice',fn()=>true,$abilities);$other=new TenantScope('alpha','other',fn()=>true,$abilities);
    $ledger=new SqlToolApprovals($db,$prefix);$ledger->migrate();$scopes=new ScopeManager();$closed=0;$effects=[];
    $handler=new CallableTool(static function($input,ToolExecution $execution)use(&$closed,&$effects,$scopes):object {
        $execution->check();check($execution->remainingMilliseconds()>0);$effects[]=$execution->effectKey;
        $scopes->current()->onClose(static function()use(&$closed):void { $closed++; });return (object)['value'=>$input->value*2];
    });
    $definition=new ToolDefinition('double','numbers.double','double','v1',$handler);$now=1000;
    $broker=new ToolBroker($contract,$ledger,$scopes,[$definition],clock:static function()use(&$now):int { return $now; });
    $arguments=(object)['value'=>2];$request=$broker->propose($a,'double',$arguments);
    rejects(fn()=>$broker->execute($a,'double',$arguments,$request['request_id']),'TOOL_APPROVAL_REQUIRED_OR_CONSUMED');
    rejects(fn()=>$ledger->approve($a,$request['request_id'],$request['fingerprint'],$now),'TOOL_APPROVAL_DENIED');
    rejects(fn()=>$ledger->approve($b,$request['request_id'],$request['fingerprint'],$now),'TOOL_APPROVAL_DENIED');
    rejects(fn()=>$ledger->approve($reviewer,$request['request_id'],str_repeat('f',64),$now),'TOOL_APPROVAL_DENIED');
    $ledger->approve($reviewer,$request['request_id'],$request['fingerprint'],$now);
    rejects(fn()=>$broker->execute($a,'double',(object)['value'=>3],$request['request_id']),'TOOL_APPROVAL_REQUIRED_OR_CONSUMED');
    rejects(fn()=>$broker->execute($other,'double',$arguments,$request['request_id']),'TOOL_APPROVAL_REQUIRED_OR_CONSUMED');
    rejects(fn()=>$broker->execute($b,'double',$arguments,$request['request_id']),'TOOL_APPROVAL_REQUIRED_OR_CONSUMED');
    check($broker->execute($a,'double',$arguments,$request['request_id'])->value===4&&$closed===1&&$scopes->activeCount()===0);
    rejects(fn()=>$broker->execute($a,'double',$arguments,$request['request_id']),'TOOL_APPROVAL_REQUIRED_OR_CONSUMED');check(count($effects)===1);
    $record=json_encode($db->select('SELECT * FROM '.$db->quoteIdentifier($prefix.'_requests')));check(!str_contains($record,'"value"')&&!str_contains($record,'alice'));
    rejects(fn()=>$broker->propose($a,'shell',(object)[]),'TOOL_NOT_REGISTERED');rejects(fn()=>$broker->propose($a,'double',(object)['value'=>100]));
    $drift=$broker->propose($a,'double',$arguments);$ledger->approve($reviewer,$drift['request_id'],$drift['fingerprint'],$now);
    $changed=new ToolBroker($contract,$ledger,$scopes,[new ToolDefinition('double','numbers.double','double','v2',$handler)],clock:fn()=>$now);
    rejects(fn()=>$changed->execute($a,'double',$arguments,$drift['request_id']),'TOOL_APPROVAL_REQUIRED_OR_CONSUMED');
    $expired=$broker->propose($a,'double',$arguments);$now+=301;rejects(fn()=>$ledger->approve($reviewer,$expired['request_id'],$expired['fingerprint'],$now),'TOOL_APPROVAL_DENIED');
    $revoked=$broker->propose($a,'double',$arguments);$ledger->approve($reviewer,$revoked['request_id'],$revoked['fingerprint'],$now);$valid=false;rejects(fn()=>$broker->execute($a,'double',$arguments,$revoked['request_id']));$valid=true;
    $noApproval=new ToolBroker($contract,$ledger,$scopes,[new ToolDefinition('double','numbers.double','double','read-v1',$handler,false)],maximumCalls:1,clock:fn()=>$now);
    $read=$noApproval->propose($a,'double',$arguments);check($noApproval->execute($a,'double',$arguments,$read['request_id'])->value===4);
    $again=$noApproval->propose($a,'double',$arguments);rejects(fn()=>$noApproval->execute($a,'double',$arguments,$again['request_id']),'TOOL_CALL_BUDGET_EXHAUSTED');
    $failing=new CallableTool(static function($input,$execution)use($scopes,&$closed):void { $scopes->current()->onClose(static function()use(&$closed):void { $closed++; });throw new \RuntimeException('uncertain external effect'); });
    $fails=new ToolBroker($contract,$ledger,$scopes,[new ToolDefinition('double','numbers.double','double','fail-v1',$failing,false)],clock:fn()=>$now);
    $attempt=$fails->propose($a,'double',$arguments);rejects(fn()=>$fails->execute($a,'double',$arguments,$attempt['request_id']),'uncertain external effect');check($scopes->activeCount()===0);
    rejects(fn()=>$fails->execute($a,'double',$arguments,$attempt['request_id']),'TOOL_APPROVAL_REQUIRED_OR_CONSUMED');
    if ($db->driver()==='sqlite') {
        putenv('COREVIA_TEST_PARENT_SECRET=fixture-only');
        try {
            $transport=new ProcessTransport([PHP_BINARY,__DIR__.'/tool-process.php'],environment:['APP_ENV'=>'development']);
            $process=new ToolBroker($contract,$ledger,$scopes,[new ToolDefinition('double','numbers.double','double','process-v1',new TransportTool($transport,'double'),false)],clock:fn()=>$now);
            $call=$process->propose($a,'double',$arguments);check($process->execute($a,'double',$arguments,$call['request_id'])->value===4);
        } finally { putenv('COREVIA_TEST_PARENT_SECRET'); }
    }
    echo 'PASS '.$db->driver()." tool allowlists/capabilities, schema/argument/version binding, distinct-human approval, tenant/actor/expiry checks, one-use execution, run budgets and cleanup\n";
}
contract(new SqlConnection(new \PDO('sqlite::memory:')),'cv_tools_test');
if (getenv('COREVIA_DB_TESTS')==='1') {
    foreach (\CoreviaTests\Services::drivers() as $driver) {
        $db=SqlConnection::connect(\CoreviaTests\Services::sql($driver));$prefix='cv_tool_'.bin2hex(random_bytes(5));
        try { contract($db,$prefix); }
        finally { foreach (['requests','mutex'] as $table) { $db->execute('DROP TABLE IF EXISTS '.$db->quoteIdentifier($prefix.'_'.$table)); } }
    }
}
