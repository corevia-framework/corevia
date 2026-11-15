<?php
declare(strict_types=1);
namespace CoreviaV4AiPlatform;
require dirname(__DIR__,2).'/autoload.php';
use Corevia\Ai\{AiClient,QdrantVectorIndex,StructuredOutput,TenantVectors};
use Corevia\Core\{ScopeManager,TenantScope};
use Corevia\Http\{HttpClient,NativeHttpClient};
use Corevia\Protocol\ContractManifest;
use Corevia\Testing\FakeAi;
function check(bool $ok):void { if (!$ok) { throw new \RuntimeException('AI platform assertion failed.'); } }
function rejects(callable $work):void { try { $work(); } catch (\Throwable) { return; }throw new \RuntimeException('Unsafe AI operation accepted.'); }
$contract=ContractManifest::fromArray(['format'=>1,'name'=>'AiExample','version'=>'1.0.0','actions'=>['answer'=>[
    'input'=>['type'=>'object','properties'=>[],'additionalProperties'=>false],
    'output'=>['type'=>'object','properties'=>['answer'=>['type'=>'string','minLength'=>1,'maxLength'=>30]],'required'=>['answer'],'additionalProperties'=>false],
]]]);
$fake=new FakeAi(['{"answer":','"hello"}']);$scopes=new ScopeManager();$structured=new StructuredOutput(new AiClient($fake,$scopes),$contract,'answer');
check($structured->complete([['role'=>'user','content'=>'Hello']])->answer==='hello'&&$scopes->activeCount()===0&&count($fake->calls())===1);
foreach (['```json {"answer":"hello"}```','{"answer":"hello","command":"unsafe"}','{"answer":3}','null'] as $bad) {
    $fake=new FakeAi([$bad]);rejects(fn()=>(new StructuredOutput(new AiClient($fake,$scopes),$contract,'answer'))->complete([['role'=>'user','content'=>'hello']]));check(count($fake->calls())===1&&$scopes->activeCount()===0);
}
rejects(fn()=>new StructuredOutput(new AiClient(new FakeAi(),$scopes),$contract,'missing'));
$http=new class implements HttpClient {
    public array $requests=[];public array $reply=['status'=>'ok','result'=>['points'=>[]]];
    public function stream(string $method,string $url,array $headers=[],string $body=''):iterable { $this->requests[]=compact('method','url','headers','body');yield json_encode($this->reply,JSON_THROW_ON_ERROR); }
};
$index=new QdrantVectorIndex('https://vector.example.test','fixture',3,http:$http);
$index->put('alpha','one','private alpha',[1,0,0]);$id=json_decode($http->requests[0]['body'],true)['points'][0]['id'];
$http->reply=['status'=>'ok','result'=>['points'=>[['id'=>$id,'score'=>1.0,'payload'=>['tenant'=>'alpha','document_id'=>'one','text'=>'private alpha']]]]];
check($index->search('alpha',[1,0,0])[0]['id']==='one');$query=json_decode($http->requests[1]['body'],true);check($query['filter']['must'][0]['match']['value']==='alpha');
$http->reply['result']['points'][0]['payload']['tenant']='beta';rejects(fn()=>$index->search('alpha',[1,0,0]));
$http->reply['result']['points'][0]['payload']['tenant']='alpha';$http->reply['result']['points'][0]['id']=str_repeat('a',32);rejects(fn()=>$index->search('alpha',[1,0,0]));
rejects(fn()=>$index->put('alpha','one','text',[1,0]));rejects(fn()=>$index->search('alpha',[1,0,0],21));
rejects(fn()=>new QdrantVectorIndex('http://public.example.test','fixture',3,http:$http));
$valid=true;$scope=new TenantScope('alpha','user',static function()use(&$valid):bool { return $valid; });$tenant=new TenantVectors($index,$scope);$valid=false;rejects(fn()=>$tenant->search([1,0,0]));
echo "PASS structured output contract/shape/fence rejection without retries; vector tenant predicates, response ownership, dimension and access-revocation guards\n";

if (getenv('COREVIA_VECTOR_TESTS')==='1') {
    $origin=getenv('COREVIA_TEST_QDRANT_ORIGIN');$key=getenv('COREVIA_TEST_QDRANT_KEY');
    if (!is_string($origin)||!preg_match('#^http://127\.0\.0\.1:[0-9]+$#D',$origin)||!is_string($key)||$key==='') { throw new \LogicException('Only the dedicated private loopback vector fixture is supported by this test.'); }
    $http=new NativeHttpClient(allowLoopback:true,timeout:10);$collection='cv_test_'.bin2hex(random_bytes(8));
    $index=new QdrantVectorIndex($origin,$collection,3,$key,$http,true);
    try {
        $index->createCollection();$index->assertCollection();rejects(fn()=>$index->createCollection());
        rejects(fn()=>(new QdrantVectorIndex($origin,$collection,4,$key,$http,true))->assertCollection());
        $index->put('alpha','one','alpha one',[1,0,0]);$index->put('alpha','two','alpha two',[0,1,0]);$index->put('beta','one','beta secret',[1,0,0]);
        $hits=$index->search('alpha',[1,0,0]);check(count($hits)===2&&$hits[0]['id']==='one'&&!str_contains(json_encode($hits),'beta secret'));
        $index->put('alpha','one','updated',[1,0,0]);check($index->search('alpha',[1,0,0])[0]['text']==='updated');
        $index->delete('alpha','one');check(count($index->search('alpha',[1,0,0]))===1&&count($index->search('beta',[1,0,0]))===1);
        define('COREVIA_KNOWLEDGE_CONTRACT_ONLY',true);require_once __DIR__.'/knowledge.php';
        \CoreviaV4Knowledge\contract(new \Corevia\Persistence\SqlConnection(new \PDO('sqlite::memory:')),$index,'cv_vector_knowledge');
        echo "PASS actual Qdrant collection provisioning/config guard, cosine query, tenant isolation, updates and tenant-bound deletion\n";
    } finally { iterator_to_array($http->stream('DELETE',$origin.'/collections/'.$collection,['api-key'=>$key])); }
}
