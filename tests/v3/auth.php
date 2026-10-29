<?php
declare(strict_types=1);
namespace CoreviaV3Auth;
require dirname(__DIR__,2).'/autoload.php';
use Corevia\Auth\{AuthFailure,AuthService,JsonAuthStore,SessionMiddleware};
use Corevia\Http\{Request,Response};
function check(bool $value):void{if(!$value){throw new \RuntimeException('Authentication assertion failed.');}}
function rejects(callable $work,?string $code=null):void{try{$work();}catch(\Throwable $error){if($code!==null && (!$error instanceof AuthFailure || $error->errorCode!==$code)){throw $error;}return;}throw new \RuntimeException('Expected authentication rejection.');}
function cookie(string $value):string{return explode(';',$value)[0];}
function request(string $method,string $cookie='',array $body=[],string $csrf='',string $origin='http://localhost:8081'):Request{return new Request($method,'/auth/login',['cookie'=>$cookie,'content-type'=>'application/json','x-csrf-token'=>$csrf,'origin'=>$origin],json_encode($body,JSON_THROW_ON_ERROR),attributes:['client_ip'=>'127.0.0.1']);}
$directory=sys_get_temp_dir().'/corevia-auth-'.bin2hex(random_bytes(8));mkdir($directory);$path=$directory.'/auth.json';$now=1000;$store=new JsonAuthStore($path);$auth=new AuthService($store,ttl:3600,clock:static function()use(&$now):int{return $now;});
try{
    $anonymous=$auth->open(request('GET'));$oldCookie=cookie($anonymous->cookie());check($anonymous->user===null);check(str_contains($anonymous->cookie(),'HttpOnly'));check($auth->open(request('POST'))===null);
    rejects(fn()=>$auth->verify(request('POST',$oldCookie,[],''),$anonymous),'CSRF');rejects(fn()=>$auth->verify(request('POST',$oldCookie,[],$anonymous->csrf,'https://evil.example'),$anonymous),'CSRF');
    $auth->verify(request('POST',$oldCookie,[],$anonymous->csrf),$anonymous);
    $input=['name'=>'<script>Ada</script>','email'=>'ADA@example.com','password'=>'twelve-characters-plus','password_confirmation'=>'twelve-characters-plus','role'=>'admin'];
    $registered=$auth->register(request('POST',$oldCookie,$input,$anonymous->csrf),$anonymous);check($registered->user['email']==='ada@example.com');check(array_keys($registered->user)===['id','name','email']);check($registered->digest!==$anonymous->digest && $registered->csrf!==$anonymous->csrf);
    check($auth->open(request('POST',$oldCookie))===null);$signedCookie=cookie($registered->cookie());check($auth->open(request('GET',$signedCookie))->user===$registered->user);
    $record=$store->findUser('ada@example.com');check(password_verify($input['password'],$record['password_hash']));$wire=file_get_contents($path);check(!str_contains($wire,$input['password']));check(!str_contains($wire,explode('=',$signedCookie,2)[1]));
    rejects(fn()=>$auth->register(request('POST',$signedCookie,$input),$registered),'REGISTRATION');
    foreach([str_repeat('x',73),"contains\0null-bytes"] as $password){$bad=[...$input,'password'=>$password,'password_confirmation'=>$password];rejects(fn()=>$auth->register(request('POST',$signedCookie,$bad),$registered),'VALIDATION');}
    rejects(fn()=>$auth->login(request('POST',$signedCookie,['email'=>'ada@example.com','password'=>'incorrect']),$registered),'CREDENTIALS');
    rejects(fn()=>$auth->login(request('POST',$signedCookie,['email'=>'absent@example.com','password'=>'incorrect']),$registered),'CREDENTIALS');
    rejects(fn()=>$auth->login(request('POST',$signedCookie,['email'=>'absent@example.com','password'=>'corevia-public-timing-sentinel']),$registered),'CREDENTIALS');
    $login=$auth->login(request('POST',$signedCookie,['email'=>'ada@example.com','password'=>$input['password']]),$registered);check($auth->open(request('POST',$signedCookie))===null);
    $loginCookie=cookie($login->cookie());$logout=$auth->logout($login);check(str_contains($logout,'Max-Age=0'));check($auth->open(request('POST',$loginCookie))===null);
    $disabled=new AuthService($store,registration:false);rejects(fn()=>$disabled->register(request('POST',$oldCookie,$input),$anonymous),'REGISTRATION_DISABLED');
    $secure=new AuthService($store,'https://example.test');$secureSession=$secure->open(request('GET'));check(str_starts_with($secureSession->cookie(),'__Host-corevia_auth='));check(str_contains($secureSession->cookie(),'; Secure'));
    $before=getenv('APP_ENV');putenv('APP_ENV=production');try{rejects(fn()=>new AuthService($store,'http://example.test'));}finally{putenv($before===false?'APP_ENV':'APP_ENV='.$before);}
    $expiring=$auth->open(request('GET'));$expiringCookie=cookie($expiring->cookie());$now+=3600;check($auth->open(request('POST',$expiringCookie))===null);
    for($i=0;$i<10;$i++){check($store->consume([['limit',10,60]],$now));}check(!$store->consume([['limit',10,60]],$now));check($store->consume([['limit',10,60]],$now+60));
    $middleware=new SessionMiddleware($auth);$response=$middleware->process(request('POST'),static fn():Response=>new Response(['private'=>'value']));check($response->status===419 && !isset($response->body['private']));check($response->headers['Cache-Control']==='no-store');
    $cookies=(new Response([]))->withCookie('one=1; HttpOnly')->withCookie('two=2; HttpOnly');check(count($cookies->headers['Set-Cookie'])===2);rejects(fn()=>$cookies->withCookie("bad=1\r\nInjected: yes"));rejects(fn()=>new Response([],headers:['X-Example'=>['one','two']]));
    $children=[];$program='require $argv[1]; $store=new Corevia\\Auth\\JsonAuthStore($argv[2]); echo json_encode($store->createUser("concurrent@example.test","Concurrent",$argv[3])!==null);';
    foreach([1,2] as $index){$pipes=[];$process=proc_open([PHP_BINARY,'-n','-r',$program,dirname(__DIR__,2).'/autoload.php',$directory.'/concurrent.json',$record['password_hash']],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);if(!is_resource($process)){throw new \RuntimeException('Cannot start concurrency test.');}fclose($pipes[0]);$children[]=[$process,$pipes];}
    $created=0;foreach($children as [$process,$pipes]){$created+=json_decode(stream_get_contents($pipes[1]),true)===true?1:0;$stderr=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);check(proc_close($process)===0 && $stderr==='');}check($created===1);
    file_put_contents($path,'{corrupt');rejects(fn()=>$store->findUser('ada@example.com'));check(file_get_contents($path)==='{corrupt');
    echo "PASS private auth records, password limits/hashing, CSRF/origin guards, session rotation/expiry/revocation, rate limits, secure cookies and corruption preservation\n";
}finally{foreach(glob($directory.'/*') as $file){unlink($file);}rmdir($directory);}
