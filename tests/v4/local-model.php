<?php
declare(strict_types=1);
require dirname(__DIR__,2).'/autoload.php';
use Corevia\Ai\{AiClient,CompatibleProvider,StructuredOutput};
use Corevia\Core\{Deadline,ScopeManager,WorkContext};
use Corevia\Http\NativeHttpClient;
use Corevia\Protocol\ContractManifest;

// Explicit optional fixture: only an already-installed model at the local Ollama API.
$model=$argv[1]??throw new InvalidArgumentException('Provide an installed local Ollama model.');
if(!preg_match('/^[a-zA-Z0-9_.:-]{1,128}$/D',$model)){throw new InvalidArgumentException('Invalid local model name.');}
$maxTokens=filter_var($argv[3]??8,FILTER_VALIDATE_INT);if($maxTokens===false||$maxTokens<1||$maxTokens>64){throw new InvalidArgumentException('Choose 1..64 smoke-test tokens.');}
$scopes=new ScopeManager();$http=new NativeHttpClient(allowLoopback:true,timeout:120);
$client=new AiClient(new CompatibleProvider($http,'http://127.0.0.1:11434/v1',$model),$scopes);
$report=['model'=>$model,'passed'=>false,'scope'=>'local streaming interoperability, not model quality or safety certification'];$start=hrtime(true);$chunks=0;$bytes=0;
try{
WorkContext::run(new Deadline(120000),function()use($client,&$chunks,&$bytes,$maxTokens):void{
    $client->stream([['role'=>'user','content'=>'Say hello briefly.']],function(string $text)use(&$chunks,&$bytes):void{$chunks++;$bytes+=strlen($text);},$maxTokens);
});
if($chunks<1||$bytes<1||$bytes>16384||$scopes->activeCount()!==0||WorkContext::current()!==null){throw new RuntimeException('Local stream/cleanup contract failed.');}
$report+=['max_tokens'=>$maxTokens,'chunks'=>$chunks,'output_bytes'=>$bytes,'stream_ms'=>round((hrtime(true)-$start)/1e6)];
if(($argv[2]??'')==='--lifecycle'){
    $cancelled=false;
    try{WorkContext::run(new Deadline(120000),fn()=>$client->stream([['role'=>'user','content'=>'Count from one to twenty.']],static fn(string $text):bool=>false,32));}
    catch(RuntimeException $error){if($error->getMessage()!=='AI_CANCELLED'){throw $error;}$cancelled=true;}
    if(!$cancelled||$scopes->activeCount()!==0||WorkContext::current()!==null){throw new RuntimeException('Local cancellation/cleanup failed.');}
    $report['client_stream_cancellation']=true;
    $manifest=ContractManifest::fromArray(['format'=>1,'name'=>'LocalModelAcceptance','version'=>'1.0.0','actions'=>['fixture.answer'=>['input'=>['type'=>'object'],'output'=>['type'=>'object','properties'=>['answer'=>['type'=>'string','enum'=>['ready']]],'required'=>['answer'],'additionalProperties'=>false]]]]);
    try{WorkContext::run(new Deadline(120000),fn()=>(new StructuredOutput($client,$manifest,'fixture.answer'))->complete([['role'=>'user','content'=>'Return a JSON object with answer set to ready.']],48));$report['structured_output']='schema_valid';}
    catch(\JsonException|\Corevia\Protocol\ContractViolation){$report['structured_output']='model_output_rejected_by_contract';}
    if($scopes->activeCount()!==0||WorkContext::current()!==null){throw new RuntimeException('Structured output cleanup failed.');}
}
$report['passed']=true;
}catch(\Throwable $error){$report['failure']=in_array($error->getMessage(),['DEADLINE_EXCEEDED','AI_CANCELLED'],true)?$error->getMessage():\Corevia\Core\ExceptionIdentity::name($error);$report['chunks_before_failure']=$chunks;$report['bytes_before_failure']=$bytes;$report['scope_cleanup']=$scopes->activeCount()===0&&WorkContext::current()===null;}
echo json_encode($report,JSON_THROW_ON_ERROR)."\n";exit($report['passed']?0:1);
