<?php
declare(strict_types=1);
namespace CoreviaV4MachineIdentity;
require dirname(__DIR__,2).'/autoload.php';require dirname(__DIR__).'/support/Fixtures.php';
use Corevia\Auth\{AuthFailure,AuthService,JsonAuthStore,ServiceAccounts,ServiceTokenMiddleware,SqlAuthStore};
use Corevia\Core\TenantScope;
use Corevia\Http\{ApiResponse,Request};
use Corevia\Persistence\SqlConnection;
use Corevia\Testing\Clock;
function check(bool $ok):void{if(!$ok){throw new \RuntimeException('Machine identity contract failed.');}}
function rejects(callable $work,?string $code=null):void{try{$work();}catch(\Throwable $error){if($code!==null&&(!$error instanceof AuthFailure||$error->errorCode!==$code)){throw $error;}return;}throw new \RuntimeException('Unsafe machine operation accepted.');}
function contract($store):void
{
    $clock=new Clock(time());$accounts=new ServiceAccounts($store,['jobs.submit','records.read'],$clock);$admin=new TenantScope('alpha','operator',fn()=>true,['service.manage','service.read']);$other=new TenantScope('beta','operator',fn()=>true,['service.manage','service.read']);
    $created=$accounts->create($admin,'Importer',['jobs.submit']);check(!str_contains(json_encode($store->state('service-account:'.$created['id'])),$created['credential']));
    check(!isset($accounts->describe($admin,$created['id'])['digest']));rejects(fn()=>$accounts->describe($other,$created['id']),'NOT_FOUND');rejects(fn()=>$accounts->rotate($other,$created['id']),'NOT_FOUND');
    $identity=$accounts->authenticate($created['credential'],'alpha','jobs.submit',2);$identity->requireAbility('jobs.submit');rejects(fn()=>$accounts->create($identity,'Escalation',['jobs.submit']));
    rejects(fn()=>$accounts->authenticate($created['credential'],'beta','jobs.submit'),'FORBIDDEN');rejects(fn()=>$accounts->authenticate($created['credential'],'alpha','records.read'),'FORBIDDEN');
    $accounts->authenticate($created['credential'],'alpha','jobs.submit',2);rejects(fn()=>$accounts->authenticate($created['credential'],'alpha','jobs.submit',2),'RATE_LIMIT');
    $clock->advance(61);$accounts->authenticate($created['credential'],'alpha','jobs.submit',2);$rotated=$accounts->rotate($admin,$created['id']);rejects(fn()=>$identity->check());rejects(fn()=>$accounts->authenticate($created['credential'],'alpha','jobs.submit'),'UNAUTHENTICATED');
    $identity=$accounts->authenticate($rotated['credential'],'alpha','jobs.submit');$accounts->revoke($admin,$created['id']);rejects(fn()=>$identity->check());rejects(fn()=>$accounts->authenticate($rotated['credential'],'alpha','jobs.submit'),'UNAUTHENTICATED');
    $expiring=$accounts->create($admin,'Short lived',['records.read'],60);$scope=$accounts->authenticate($expiring['credential'],'alpha','records.read');$clock->advance(61);rejects(fn()=>$scope->check());rejects(fn()=>$accounts->authenticate($expiring['credential'],'alpha','records.read'),'UNAUTHENTICATED');
    $auth=new AuthService($store);check($auth->open(new Request('GET','/',['cookie'=>'corevia_auth='.$expiring['credential']]))->user===null);
    $http=$accounts->create($admin,'HTTP',['records.read']);$middleware=new ServiceTokenMiddleware($accounts,'alpha','records.read');$response=$middleware->process(new Request('GET','/api',headers:['authorization'=>'Bearer '.$http['credential']]),fn($request)=>ApiResponse::data(['actor'=>$request->attributes['service_identity']->actor]));check($response->status===200);
    check($middleware->process(new Request('GET','/api',headers:['authorization'=>'Bearer cv4_'.str_repeat('a',43)]),fn()=>ApiResponse::data([]))->status===401);
}
$directory=sys_get_temp_dir().'/corevia-v4-machines-'.bin2hex(random_bytes(6));mkdir($directory);
try{contract(new JsonAuthStore($directory.'/auth.json'));$sql=new SqlAuthStore(new SqlConnection(new \PDO('sqlite::memory:')));$sql->migrate();contract($sql);echo "PASS JSON/SQLite machine identity scope/tenant/quota, digest-only credentials, rotation/revocation/expiry, delegation and cookie separation\n";}
finally{\CoreviaTests\Fixtures::remove($directory,'corevia-v4-machines-');}
