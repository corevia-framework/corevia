<?php
declare(strict_types=1);
namespace CoreviaV4Knowledge;
require_once dirname(__DIR__,2).'/autoload.php';
require_once dirname(__DIR__).'/support/Services.php';
use Corevia\Ai\{EmbeddingProvider,KnowledgeBase,RevisionVectorIndex,SqlRevisionVectors};
use Corevia\Core\{RevisionCatalog,TenantScope};
use Corevia\Persistence\{SqlConnection,SqlRevisionCatalog};
function check(bool $ok):void { if (!$ok) { throw new \RuntimeException('Knowledge revision contract failed.'); } }
function rejects(callable $work,?string $message=null):void { try { $work(); } catch (\Throwable $error) { if ($message!==null&&$error->getMessage()!==$message) { throw $error; }return; }throw new \RuntimeException('Unsafe knowledge operation accepted.'); }
final class Embeddings implements EmbeddingProvider
{
    public int $calls=0;public ?\Closure $during=null;
    public function embed(array $texts):array { $this->calls++;if ($this->during!==null) { ($this->during)(); }return array_map(static fn($text)=>str_contains($text,'red')?[1,0,0]:(str_contains($text,'blue')?[0,1,0]:[0,0,1]),$texts); }
}
function contract(SqlConnection $db,RevisionVectorIndex $vectors,string $prefix):void
{
    $catalog=new SqlRevisionCatalog($db,$prefix);$catalog->migrate();$embeddings=new Embeddings();$kb=new KnowledgeBase($embeddings,$vectors,$catalog,'fixture-model-v1');
    $valid=true;$a=new TenantScope('alpha','alice',static function()use(&$valid):bool { return $valid; },['ai.documents.write','ai.documents.read']);$b=new TenantScope('beta','bob',fn()=>true,['ai.documents.write','ai.documents.read']);
    $first=$kb->replace($a,'guide',0,'red original');$kb->replace($b,'guide',0,'blue beta secret');check($first['version']===1);
    $hits=$kb->search($a,'red');check(count($hits)===1&&$hits[0]['text']==='red original'&&$hits[0]['document']==='guide');
    $before=$embeddings->calls;rejects(fn()=>$kb->replace($a,'guide',0,'stale'),'DOCUMENT_VERSION_CONFLICT');check($embeddings->calls===$before);
    $staging=$catalog->begin($a,'guide',1,'fixture-model-v1',hash('sha256','staging'),1);$vectors->putRevision($a->namespace('knowledge.vectors'),$staging['id'],$staging['id'].':0','red not active',[1,0,0]);
    $second=$kb->replace($a,'guide',1,'blue replacement');$hits=$kb->search($a,'red');check($second['version']===2&&count($hits)===1&&$hits[0]['text']==='blue replacement');
    // Retirement collection never erases active or staging resources.
    check($kb->collect($a)===1);rejects(fn()=>$catalog->collected($a,$second['revision']));rejects(fn()=>$catalog->collected($a,$staging['id']));
    check($catalog->abandon($a,$staging['id'])&&$kb->collect($a)===1&&$kb->search($a,'blue')[0]['text']==='blue replacement');
    $left=$catalog->begin($a,'race',0,'fixture-model-v1',hash('sha256','left'),1);$right=$catalog->begin($a,'race',0,'fixture-model-v1',hash('sha256','right'),1);
    $vectors->putRevision($a->namespace('knowledge.vectors'),$left['id'],$left['id'].':0','red winner',[1,0,0]);$catalog->activate($a,$left['id']);rejects(fn()=>$catalog->activate($a,$right['id']),'DOCUMENT_VERSION_CONFLICT');$catalog->abandon($a,$right['id']);
    // Simulate a successful activation followed by a lost reply: cleanup must preserve the committed revision.
    $uncertain=new class($catalog) implements RevisionCatalog {
        public function __construct(private RevisionCatalog $inner) {}
        public function begin(TenantScope $s,string $d,int $v,string $m,string $h,int $c):array { return $this->inner->begin($s,$d,$v,$m,$h,$c); }
        public function activate(TenantScope $s,string $r):array { $this->inner->activate($s,$r);throw new \RuntimeException('lost activation reply'); }
        public function abandon(TenantScope $s,string $r):bool { return $this->inner->abandon($s,$r); }
        public function active(TenantScope $s):array { return $this->inner->active($s); }
        public function delete(TenantScope $s,string $d,int $v):array { return $this->inner->delete($s,$d,$v); }
        public function collectable(TenantScope $s,int $l=20):array { return $this->inner->collectable($s,$l); }
        public function collected(TenantScope $s,string $r):void { $this->inner->collected($s,$r); }
    };
    rejects(fn()=>(new KnowledgeBase($embeddings,$vectors,$uncertain,'fixture-model-v1'))->replace($a,'uncertain',0,'red committed'),'lost activation reply');
    check(in_array('red committed',array_column($kb->search($a,'red',20),'text'),true));
    $embeddings->during=static function()use(&$valid):void { $valid=false; };rejects(fn()=>$kb->replace($a,'guide',2,'red revoked'));$valid=true;$embeddings->during=null;
    check(in_array('blue replacement',array_column($kb->search($a,'blue',20),'text'),true));
    rejects(fn()=>(new KnowledgeBase($embeddings,$vectors,$catalog,'other-model'))->search($a,'red'),'KNOWLEDGE_MODEL_REINDEX_REQUIRED');
    $kb->delete($a,'guide',2);rejects(fn()=>$kb->replace($a,'guide',0,'red stale recreation'),'DOCUMENT_VERSION_CONFLICT');check($kb->replace($a,'guide',3,'red recreated')['version']===4);
    $evaluation=(new \Corevia\Ai\RetrievalEvaluation($kb))->run($b,[['id'=>'case-one','question'=>'blue private query','expected'=>['guide']]],1);check($evaluation['hit_rate']==1&&$evaluation['mean_reciprocal_rank']==1&&!str_contains(json_encode($evaluation),'private query')&&!str_contains(json_encode($evaluation),'blue beta secret'));
    check(count($kb->search($b,'blue'))===1&&!str_contains(json_encode($kb->search($a,'blue',20)),'beta secret'));
    $none=new TenantScope('alpha','limited',fn()=>true);rejects(fn()=>$kb->search($none,'red'));rejects(fn()=>$kb->replace($none,'forbidden',0,'red'));
    echo 'PASS '.$db->driver()." immutable ingestion/staging, active-revision filtering, optimistic swaps/tombstones, uncertain commit preservation, tenant/model/authority checks and explicit safe collection\n";
}
if (!defined('COREVIA_KNOWLEDGE_CONTRACT_ONLY')) {
    $db=new SqlConnection(new \PDO('sqlite::memory:'));$vectors=new SqlRevisionVectors($db);$vectors->migrate();contract($db,$vectors,'cv_knowledge_test');
    if (getenv('COREVIA_DB_TESTS')==='1') {
        foreach (\CoreviaTests\Services::drivers() as $driver) {
            $db=SqlConnection::connect(\CoreviaTests\Services::sql($driver));$prefix='cv_knowledge_'.bin2hex(random_bytes(5));$vectors=new SqlRevisionVectors($db,$prefix.'_vectors');
            try { $vectors->migrate();contract($db,$vectors,$prefix); }
            finally { foreach (['vectors','documents','revisions','mutex'] as $table) { $db->execute('DROP TABLE IF EXISTS '.$db->quoteIdentifier($prefix.'_'.$table)); } }
        }
    }
}
