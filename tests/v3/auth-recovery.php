<?php
declare(strict_types=1);
namespace CoreviaAuthRecoveryTests;
require dirname(__DIR__,2).'/autoload.php';
use Corevia\Auth\{AccountDelivery,AccountMessage,AccountRecovery,AuthFailure,AuthRoutes,AuthService,JsonAuthStore,PreviewDelivery,RequireVerifiedEmail};
use Corevia\Core\{Corevia,Logger};
use Corevia\Http\{Request,Response,Router,View};
function check(bool $value):void{if(!$value){throw new \RuntimeException('Account recovery assertion failed.');}}
function rejects(callable $work,?string $code=null):void{try{$work();}catch(\Throwable $error){if($code!==null && (!$error instanceof AuthFailure || $error->errorCode!==$code)){throw $error;}return;}throw new \RuntimeException('Expected account rejection.');}
function request(array $body=[],string $cookie='',string $path='/auth/test'):Request{return new Request('POST',$path,['content-type'=>'application/json','cookie'=>$cookie],json_encode($body,JSON_THROW_ON_ERROR),attributes:['client_ip'=>'127.0.0.1']);}
function token(AccountMessage $message):string{parse_str(parse_url($message->link(),PHP_URL_FRAGMENT),$values);return $values['token'];}
final class Inbox implements AccountDelivery{public array $messages=[];public function deliver(AccountMessage $message):void{$this->messages[]=$message;}}
final class Log implements Logger{public array $records=[];public function log(string $level,string $message,array $context=[]):void{$this->records[]=[$message,$context];}}
$directory=sys_get_temp_dir().'/corevia-account-'.bin2hex(random_bytes(6));mkdir($directory);$now=1000;$clock=static function()use(&$now):int{return $now;};$store=new JsonAuthStore($directory.'/auth.json');$auth=new AuthService($store,clock:$clock);$inbox=new Inbox();$log=new Log();$accounts=new AccountRecovery($store,$inbox,$auth,$log,$clock);
try{
    $anon=$auth->open(new Request('GET','/login'));$input=['name'=>'Ada','email'=>'ada@example.test','password'=>'example-password-123','password_confirmation'=>'example-password-123'];$user=$auth->register(request($input),$anon);$cookie=explode(';',$user->cookie())[0];$accounts->afterRegistration($user);check(count($inbox->messages)===1);$verify=token($inbox->messages[0]);
    check(!str_contains(file_get_contents($directory.'/auth.json'),$verify));check(!str_contains(json_encode($inbox->messages[0]),$verify));check(str_contains($inbox->messages[0]->link(),'#token='));
    $gate=new RequireVerifiedEmail($accounts);$authorized=request(path:'/auth/verified')->withAttribute('auth',$user);check($gate->process($authorized,fn()=>new Response(['ok'=>true]))->status===403);
    $app=Corevia::create(dirname(__DIR__,2),logger:$log);$app->profile('web');$app->boot();$router=new Router($app);AuthRoutes::mount($router,new View(__DIR__),$auth,'bootstrap',$accounts,true);check($router->dispatch(new Request('GET','/dashboard',['cookie'=>$cookie]))->headers['Location']==='/verify-email');$app->shutdown();
    rejects(fn()=>$accounts->resetPassword(request(['token'=>$verify,'password'=>'changed-password-456','password_confirmation'=>'changed-password-456'])),'TOKEN_INVALID');
    $other=$auth->register(request([...$input,'email'=>'other@example.test']),$auth->open(new Request('GET','/login')));rejects(fn()=>$accounts->verifyEmail(request(['token'=>$verify]),$other),'ACCOUNT_REQUIRED');
    $accounts->verifyEmail(request(['token'=>$verify]),$user);check($accounts->isVerified($user));check($gate->process($authorized,fn()=>new Response(['ok'=>true]))->status===200);rejects(fn()=>$accounts->verifyEmail(request(['token'=>$verify]),$user),'TOKEN_INVALID');
    $count=count($inbox->messages);$accounts->requestReset(request(['email'=>'missing@example.test']));check(count($inbox->messages)===$count);
    $accounts->requestReset(request(['email'=>'ada@example.test']));$first=token($inbox->messages[array_key_last($inbox->messages)]);$accounts->requestReset(request(['email'=>'ada@example.test']));$reset=token($inbox->messages[array_key_last($inbox->messages)]);rejects(fn()=>$accounts->resetPassword(request(['token'=>$first])),'TOKEN_INVALID');
    rejects(fn()=>$accounts->resetPassword(request(['token'=>$reset,'password'=>'short','password_confirmation'=>'short'])),'VALIDATION');
    $accounts->resetPassword(request(['token'=>$reset,'password'=>'changed-password-456','password_confirmation'=>'changed-password-456']));check($auth->open(request(cookie:$cookie))===null);rejects(fn()=>$accounts->resetPassword(request(['token'=>$reset])),'TOKEN_INVALID');
    rejects(fn()=>$auth->login(request(['email'=>'ada@example.test','password'=>$input['password']]),$anon),'CREDENTIALS');$fresh=$auth->login(request(['email'=>'ada@example.test','password'=>'changed-password-456']),$anon);check($fresh->user['id']===$user->user['id']);
    $accounts->requestReset(request(['email'=>'ada@example.test']));$expired=token($inbox->messages[array_key_last($inbox->messages)]);$now+=1800;rejects(fn()=>$accounts->resetPassword(request(['token'=>$expired])),'TOKEN_INVALID');
    $failed=new AccountRecovery($store,new class implements AccountDelivery{public function deliver(AccountMessage $message):void{throw new \RuntimeException('private delivery credential '.$message->link());}},$auth,$log,$clock);$failed->requestReset(request(['email'=>'ada@example.test']));check(!str_contains(json_encode($log->records),'private delivery credential'));check(!str_contains(file_get_contents($directory.'/auth.json'),'changed-password-456'));
    $before=getenv('APP_ENV');putenv('APP_ENV=production');try{rejects(fn()=>new PreviewDelivery($directory.'/outbox'));}finally{putenv($before===false?'APP_ENV':'APP_ENV='.$before);}
    $legacyToken=str_repeat('a',64);file_put_contents($directory.'/legacy.json',json_encode(['format'=>1,'users'=>['legacy@example.test'=>['id'=>'legacy','email'=>'legacy@example.test','name'=>'Legacy','password_hash'=>password_hash('legacy-password-123',PASSWORD_BCRYPT,['cost'=>4])]],'sessions'=>[hash('sha256',$legacyToken)=>['user_id'=>'legacy','csrf'=>str_repeat('b',64),'expires'=>$now+3600]],'limits'=>[]],JSON_THROW_ON_ERROR));
    $legacy=new AuthService(new JsonAuthStore($directory.'/legacy.json'),clock:$clock);check($legacy->open(new Request('GET','/login',['cookie'=>'corevia_auth='.$legacyToken]))->user['id']==='legacy');
    echo "PASS recovery/verification purpose and account binding, single use/expiry, all-session reset, verified gate, private messages and sanitized delivery failures\n";
}finally{foreach(glob($directory.'/*') as $file){unlink($file);}rmdir($directory);}
