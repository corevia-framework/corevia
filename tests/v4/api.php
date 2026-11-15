<?php
declare(strict_types=1);
namespace CoreviaV4Api;
require dirname(__DIR__,2).'/autoload.php';
require dirname(__DIR__).'/support/Fixtures.php';
use Corevia\Auth\{ApiIdempotency,ApiTokenMiddleware,ApiTokens,AuthFailure,AuthService,Gate,JsonAuthStore};
use Corevia\Core\Corevia;
use Corevia\Http\{ApiBoundary,ApiProblem,ApiResponse,Request,Router};
use Corevia\Testing\{Clock,TestClient};
function check(bool $ok):void{if(!$ok){throw new \RuntimeException('API contract failed.');}}
function rejects(callable $work,?string $code=null):void{try{$work();}catch(\Throwable $error){if($code!==null&&(!($error instanceof AuthFailure||$error instanceof ApiProblem)||$error->errorCode!==$code)){throw $error;}return;}throw new \RuntimeException('Expected API rejection.');}
$directory=sys_get_temp_dir().'/corevia-v4-api-'.bin2hex(random_bytes(6));mkdir($directory);$clock=new Clock(time());$store=new JsonAuthStore($directory.'/identity.json');$auth=new AuthService($store,clock:$clock);
function request(array $body):Request{return new Request('POST','/auth/fixture',['content-type'=>'application/json'],json_encode($body,JSON_THROW_ON_ERROR));}
try{
    $anon=$auth->open(new Request('GET','/auth/session'));$account=$auth->register(request(['name'=>'API fixture','email'=>'api@example.test','password'=>'fixture-password-123','password_confirmation'=>'fixture-password-123']),$anon);
    $gate=new Gate($store,['reader'=>['items.read'],'writer'=>['items.read','items.write']]);$gate->assign($account->user['id'],['reader']);
    $context=$auth->login(request(['email'=>'api@example.test','password'=>'fixture-password-123']),$auth->open(new Request('GET','/login')));
    $tokens=new ApiTokens($store,$gate,$clock);rejects(fn()=>$tokens->issue($context,['items.write']),'FORBIDDEN');
    $issued=$tokens->issue($context,['items.read'],seconds:3600);$bearer=$tokens->authenticate($issued['token'],'items.read');check($bearer->user['id']===$context->user['id']);
    rejects(fn()=>$tokens->issue($bearer,['items.read']),'FORBIDDEN');
    check(!str_contains(file_get_contents($directory.'/identity.json'),$issued['token']));
    rejects(fn()=>$tokens->authenticate($issued['token'],'items.write'),'FORBIDDEN');rejects(fn()=>$tokens->authenticate($issued['token'],'items.read','other-tenant'),'FORBIDDEN');
    $tokens->authenticate($issued['token'],'items.read',perMinute:2);rejects(fn()=>$tokens->authenticate($issued['token'],'items.read',perMinute:2),'RATE_LIMIT');
    $clock->advance(61);check($tokens->authenticate($issued['token'],'items.read',perMinute:2)->user!==null);
    $app=Corevia::create($directory);$router=new Router($app,['http://127.0.0.1:8081']);
    $router->get('/api/v1/items',fn()=>ApiResponse::data([['id'=>1]]))->middleware(new ApiBoundary())->middleware(new ApiTokenMiddleware($tokens,'items.read'));
    $app->boot();try{$client=new TestClient($router);$client->request('GET','/api/v1/items')->status(401)->field('error.code','UNAUTHENTICATED');$client->request('GET','/api/v1/items',headers:['Authorization'=>'Bearer '.$issued['token']])->status(200)->field('data.0.id',1);}finally{$app->shutdown();}
    $cookie=$auth->open(new Request('GET','/',['cookie'=>'corevia_auth='.$issued['token']]));check($cookie->user===null);
    $tokens->revoke($context,$issued['id']);rejects(fn()=>$tokens->authenticate($issued['token'],'items.read'),'UNAUTHENTICATED');
    $revocable=$tokens->issue($context,['items.read']);$gate->assign($context->user['id'],[]);rejects(fn()=>$tokens->authenticate($revocable['token'],'items.read'),'UNAUTHENTICATED');
    $idempotency=new ApiIdempotency($store);$calls=0;$effect=static function()use(&$calls):array{$calls++;return ['created'=>1];};
    $actor=$context->user['id'];check($idempotency->execute($actor,'items.create','fixture-key-1',['title'=>'one'],$effect)===['created'=>1]);
    $idempotency->execute($actor,'items.create','fixture-key-1',['title'=>'one'],$effect);check($calls===1);
    rejects(fn()=>$idempotency->execute($actor,'items.create','fixture-key-1',['title'=>'other'],$effect),'IDEMPOTENCY_CONFLICT');
    rejects(fn()=>$idempotency->execute($actor,'items.create','fixture-key-2',[],fn()=>throw new \RuntimeException('uncertain effect')));
    rejects(fn()=>$idempotency->execute($actor,'items.create','fixture-key-2',[],$effect),'IDEMPOTENCY_PENDING');check($calls===1);
    $headers=ApiResponse::version('1','2030-01-01');check(str_starts_with($headers['Deprecation'],'@')&&$headers['Sunset']==='Tue, 01 Jan 2030 00:00:00 GMT');
    echo "PASS API scopes/quotas/tenant boundaries, cookie separation, secret non-persistence, revocation and uncertain idempotency\n";
}finally{\CoreviaTests\Fixtures::remove($directory,'corevia-v4-api-');}
