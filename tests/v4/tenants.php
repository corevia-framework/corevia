<?php
declare(strict_types=1);
namespace CoreviaV4Tenants;
require dirname(__DIR__,2).'/autoload.php';
require dirname(__DIR__).'/support/Fixtures.php';
use Corevia\Auth\{AuthService,Gate,JsonAuthStore,TenantAccess};
use Corevia\Core\{JobClaim,TenantJobs,TenantScope};
use Corevia\Http\{LocalDisk,Request,TenantDisk};
use Corevia\Persistence\{JsonDocumentStore,SqlConnection,TenantCache,TenantDocuments,TenantRecords};
use Corevia\Ai\{MemoryVectorIndex,TenantVectors};
use Corevia\Testing\{Clock,FakeQueue};
function check(bool $ok):void{if(!$ok){throw new \RuntimeException('Tenant isolation contract failed.');}}
function rejects(callable $work):void{try{$work();}catch(\Throwable){return;}throw new \RuntimeException('Tenant escape was accepted.');}
$directory=sys_get_temp_dir().'/corevia-v4-tenants-'.bin2hex(random_bytes(6));mkdir($directory);mkdir($directory.'/public');
try{
    $valid=true;$a=new TenantScope('alpha','alice',static function()use(&$valid):bool{return $valid;});$b=new TenantScope('beta','bob',fn()=>true);
    $db=new SqlConnection(new \PDO('sqlite::memory:'));$db->execute('CREATE TABLE records (tenant_id VARCHAR(128),id VARCHAR(64),title TEXT,PRIMARY KEY(tenant_id,id))');
    $ra=new TenantRecords($db,$a,'records',['id','title']);$rb=new TenantRecords($db,$b,'records',['id','title']);
    $ra->insert(['id'=>'same','title'=>'private alpha']);$rb->insert(['id'=>'same','title'=>'private beta']);
    check($ra->find('same')['title']==='private alpha'&&$rb->page()['total']===1);
    rejects(fn()=>$ra->insert(['id'=>'evil','tenant_id'=>'beta']));rejects(fn()=>$ra->update('same',['tenant_id'=>'beta']));
    $ra->update('same',['title'=>'updated']);check($rb->find('same')['title']==='private beta');$ra->delete('same');check($rb->find('same')!==null);
    $raw=new JsonDocumentStore($directory.'/documents');$da=new TenantDocuments($raw,$a);$dbeta=new TenantDocuments($raw,$b);
    $da->put('records','same',['title'=>'A']);$dbeta->put('records','same',['title'=>'B']);check($da->get('records','same')['title']==='A');
    $da->delete('records','same');check($dbeta->get('records','same')['title']==='B');rejects(fn()=>$da->put('records','same',['tenant_id'=>'beta']));
    $clock=new Clock(time());$ca=new TenantCache($da,$clock);$cb=new TenantCache($dbeta,$clock);$ca->put('key',['value'=>'A']);check($cb->get('key')===null);$clock->advance(61);check($ca->get('key')===null);
    $disk=new LocalDisk($directory.'/files',$directory.'/public');$fa=new TenantDisk($disk,$a);$fb=new TenantDisk($disk,$b);$fa->put('same.txt','A');$fb->put('same.txt','B');check($fa->get('same.txt')==='A');$fa->delete('same.txt');check($fb->get('same.txt')==='B');rejects(fn()=>$fa->get('../beta/same.txt'));
    $index=new MemoryVectorIndex();$va=new TenantVectors($index,$a);$vb=new TenantVectors($index,$b);$va->put('same','A',[1,0]);$vb->put('same','B',[1,0]);check($va->search([1,0])[0]['text']==='A');
    $queue=new FakeQueue($clock);$jobs=new TenantJobs($queue,str_repeat('k',32));$jobs->dispatch($a,'record.process',['id'=>'one'],true);$claim=$queue->claim($queue->clock());
    $resolve=fn($actor,$tenant,$task)=>$a;check($jobs->execute($claim,$resolve,fn($scope)=>$scope->tenant)==='alpha');
    $key=fn($scope,$task,$input,$key)=>$key;$copy=new JobClaim('copied-claim',$claim->task,1,$claim->input(),$claim->token());
    check($jobs->execute($claim,$resolve,$key)===$jobs->execute($copy,$resolve,$key));
    $input=$claim->input();$input['envelope']=str_replace('alpha','beta',$input['envelope']);rejects(fn()=>$jobs->execute(new JobClaim($claim->id,$claim->task,1,$input,$claim->token()),$resolve,fn()=>true));
    rejects(fn()=>$jobs->execute($claim,fn()=>$b,fn()=>true));rejects(fn()=>serialize($a));
    $valid=false;foreach([fn()=>$ra->page(),fn()=>$da->find('records'),fn()=>$ca->get('key'),fn()=>$fa->exists('same.txt'),fn()=>$va->search([1,0]),fn()=>$jobs->execute($claim,$resolve,fn()=>true)] as $operation){rejects($operation);}
    // Resolve a real tenant membership and prove a subsequent role change revokes the scope.
    $store=new JsonAuthStore($directory.'/auth.json');$auth=new AuthService($store);$body=fn($data)=>new Request('POST','/fixture',['content-type'=>'application/json'],json_encode($data));$password='fixture-password-123';
    $context=$auth->register($body(['name'=>'Alice','email'=>'tenant@example.test','password'=>$password,'password_confirmation'=>$password]),$auth->open(new Request('GET','/session')));
    $gate=new Gate($store,['member'=>['records.read']]);$gate->assign($context->user['id'],['member'],'alpha');
    $context=$auth->login($body(['email'=>'tenant@example.test','password'=>$password]),$auth->open(new Request('GET','/session')));
    $access=new TenantAccess($gate);$scope=$access->resolve($context,'alpha','records.read');rejects(fn()=>$access->resolve($context,'beta','records.read'));
    $gate->assign($context->user['id'],[],'alpha');rejects(fn()=>$scope->check());
    echo "PASS tenant isolation across SQL, documents, cache, files, retrieval and signed jobs; membership revocation\n";
}finally{\CoreviaTests\Fixtures::remove($directory,'corevia-v4-tenants-');}
