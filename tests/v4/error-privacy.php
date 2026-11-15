<?php
declare(strict_types=1);
require dirname(__DIR__,2).'/autoload.php';
use Corevia\Core\{Action,ActionRunner,ActionResult,Container,EventBus,ExecutionContext,ExecutionScope,Logger,ScopeManager,State};
use Corevia\Http\{ApiBoundary,Request};
use Corevia\Auth\{AccountMessage,MailQueue,MailTransport,SecretBox};
function check(bool $ok):void{if(!$ok){throw new RuntimeException('Exception privacy contract failed.');}}
$error=new class('PRIVATE_EXCEPTION_MESSAGE') extends RuntimeException {};
check(str_contains($error::class,__FILE__));
$logger=new class implements Logger {
    public array $entries=[];
    public function log(string $level,string $message,array $context=[]):void{$this->entries[]=$context;}
};
$scopes=new ScopeManager($logger);
try{$scopes->run('job',fn(ExecutionScope $scope)=>$scope->onClose(static fn()=>throw $error));throw new LogicException('Expected cleanup failure.');}
catch(RuntimeException $failure){check($failure->getMessage()==='SCOPE_CLEANUP_FAILED');}
check($logger->entries[0]['exception_classes']===['Throwable']&&$scopes->activeCount()===0);
$container=new Container();$container->set('fixture.action',new class($error) implements Action {
    public function __construct(private Throwable $error){}
    public function handle(State $state,ExecutionContext $context):State|ActionResult{throw $this->error;}
});
$events=new class implements EventBus{public function on(string $event,callable $handler):void{}public function emit(object $event):array{return [];}};
$context=new ExecutionContext('run','trace','flow','fixture',$events,$logger);
$result=(new ActionRunner($container))->run('fixture.action',new State(),$context);
check(!$result->ok&&$result->metadata['exception_class']==='Throwable');
$response=(new ApiBoundary(logger:$logger))->process(new Request('GET','/private'),static fn()=>throw $error);
check($response->status===500);
$directory=dirname(__DIR__,2).'/storage/privacy-fixture-'.bin2hex(random_bytes(8));mkdir($directory,0700);
try{
    $transport=new class($error) implements MailTransport{public function __construct(private Throwable $error){}public function send(string $id,AccountMessage $message):void{throw $this->error;}};
    $queue=new MailQueue($directory.'/mail.json',new SecretBox(random_bytes(32)),$transport);
    $queue->deliver(new AccountMessage('nobody@example.test','verify',time()+3600,'https://example.invalid/private-fixture'));
    check($queue->workOnce()['status']==='retry');
    $spool=file_get_contents($directory.'/mail.json');check(str_contains($spool,'"error_class":"Throwable"'));
    $wire=json_encode([$logger->entries,$result->metadata,$response->body,$spool],JSON_THROW_ON_ERROR);
    foreach([__FILE__,'PRIVATE_EXCEPTION_MESSAGE','@anonymous','\\u0000']as $private){check(!str_contains($wire,$private));}
}finally{foreach(new FilesystemIterator($directory)as $file){unlink($file->getPathname());}rmdir($directory);}
echo "PASS anonymous exception source-path/message redaction across cleanup logs, action metadata, API and private mail retries; no email sent\n";
