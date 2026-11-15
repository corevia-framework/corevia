<?php
declare(strict_types=1);
namespace CoreviaV4HumanToolReview;
require dirname(__DIR__,2).'/autoload.php';
require_once dirname(__DIR__).'/support/Fixtures.php';
use Corevia\Auth\{ApiTokens,AuthFailure,AuthService,Gate,HumanToolApproval,JsonAuthStore,TenantAccess};
use Corevia\Core\TenantScope;
use Corevia\Http\Request;
use Corevia\Persistence\{SqlConnection,SqlToolApprovals};
function check(bool $ok):void { if (!$ok) { throw new \RuntimeException('Human tool review failed.'); } }
function rejects(callable $work,?string $code=null):void { try { $work(); } catch (\Throwable $error) { if ($code!==null&&(!$error instanceof AuthFailure||$error->errorCode!==$code)) { throw $error; }return; }throw new \RuntimeException('Non-human review accepted.'); }
function request(array $input):Request { return new Request('POST','/operator/tool-review',['content-type'=>'application/json'],json_encode($input,JSON_THROW_ON_ERROR)); }
$directory=sys_get_temp_dir().'/corevia-v4-review-'.bin2hex(random_bytes(6));mkdir($directory);
try {
    $store=new JsonAuthStore($directory.'/identity.json');$password='fixture-password-123';$hash=password_hash($password,PASSWORD_BCRYPT,['cost'=>4]);
    $alice=$store->createUser('alice@example.test','Alice',$hash);$reviewer=$store->createUser('reviewer@example.test','Reviewer',$hash);
    $gate=new Gate($store,['reviewer'=>['ai.tools.approve']]);$gate->assign($alice['id'],['reviewer'],'alpha');$gate->assign($reviewer['id'],['reviewer'],'alpha');
    $auth=new AuthService($store);$login=static fn($email)=>$auth->login(request(['email'=>$email,'password'=>$password]),$auth->open(new Request('GET','/login')));
    $human=$login('reviewer@example.test');$self=$login('alice@example.test');
    $ledger=new SqlToolApprovals(new SqlConnection(new \PDO('sqlite::memory:')));$ledger->migrate();$helper=new HumanToolApproval($store,$auth,new TenantAccess($gate),$ledger);
    $scope=new TenantScope('alpha',$alice['id'],fn()=>true,['ai.tools.request','ai.tools.execute']);$fingerprint=hash('sha256','reviewed exact operation');$id=$ledger->propose($scope,$fingerprint,true,time());
    rejects(fn()=>$helper->approve(request(['current_password'=>'wrong']),$human,'alpha',$id,$fingerprint),'CREDENTIALS');
    rejects(fn()=>$helper->approve(request(['current_password'=>$password]),$self,'alpha',$id,$fingerprint));
    $tokens=new ApiTokens($store,$gate);$issued=$tokens->issue($human,['ai.tools.approve'],'alpha');$bearer=$tokens->authenticate($issued['token'],'ai.tools.approve','alpha');
    rejects(fn()=>$helper->approve(request(['current_password'=>$password]),$bearer,'alpha',$id,$fingerprint),'FORBIDDEN');
    $helper->approve(request(['current_password'=>$password]),$human,'alpha',$id,$fingerprint);check($ledger->consume($scope,$id,$fingerprint,time()));
    $gate->assign($reviewer['id'],[],'alpha');$next=$ledger->propose($scope,$fingerprint,true,time());rejects(fn()=>$helper->approve(request(['current_password'=>$password]),$human,'alpha',$next,$fingerprint));
} finally { \CoreviaTests\Fixtures::remove($directory,'corevia-v4-review-'); }
echo "PASS human password-confirmed tool approval, self-review/API-bearer rejection and role/session revocation\n";
