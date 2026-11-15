<?php
declare(strict_types=1);
namespace Corevia\Ai;
use Corevia\Core\{CanonicalJson,RevisionCatalog,TenantScope,WorkContext};

/** Immutable ingestion revisions with an atomic active pointer and server-authorized retrieval. */
final readonly class KnowledgeBase
{
    public function __construct(private EmbeddingProvider $embeddings,private RevisionVectorIndex $vectors,private RevisionCatalog $catalog,private string $model)
    {
        if (!preg_match('/^[a-zA-Z0-9][a-zA-Z0-9_.:-]{0,127}$/D',$model)) { throw new \InvalidArgumentException('Pin an explicit embedding model/revision identity.'); }
    }
    private function embeddings(array $texts):array
    {
        WorkContext::current()?->check();$vectors=$this->embeddings->embed($texts);
        if (!array_is_list($vectors)||count($vectors)!==count($texts)) { throw new \RuntimeException('INVALID_EMBEDDING_BATCH'); }
        $dimensions=null;foreach ($vectors as $vector) { if (!is_array($vector)) { throw new \RuntimeException('INVALID_EMBEDDING_BATCH'); }VectorRules::vector($vector);$dimensions??=count($vector);if (count($vector)!==$dimensions) { throw new \RuntimeException('EMBEDDING_DIMENSION_CHANGED'); } }
        WorkContext::current()?->check();return $vectors;
    }
    public function replace(TenantScope $scope,string $document,int $expectedVersion,string $text):array
    {
        $scope->requireAbility('ai.documents.write');
        if ($text===''||strlen($text)>65536||preg_match('//u',$text)!==1) { throw new \InvalidArgumentException('Use 1..65536 bytes of UTF-8 text.'); }
        preg_match_all('/.{1,1000}/us',$text,$parts);$chunks=$parts[0];
        $plan=$this->catalog->begin($scope,$document,$expectedVersion,$this->model,hash('sha256',$text),count($chunks));$revision=$plan['id'];
        try {
            $dimensions=null;
            foreach (array_chunk($chunks,16,true) as $batch) {
                $scope->check();$vectors=$this->embeddings(array_values($batch));
                foreach (array_keys($batch) as $position=>$offset) {
                    $scope->check();$dimensions??=count($vectors[$position]);if (count($vectors[$position])!==$dimensions) { throw new \RuntimeException('EMBEDDING_DIMENSION_CHANGED'); }
                    $this->vectors->putRevision($scope->namespace('knowledge.vectors'),$revision,$revision.':'.$offset,$chunks[$offset],$vectors[$position]);
                }
            }
            $scope->check();WorkContext::current()?->check();return $this->catalog->activate($scope,$revision);
        } catch (\Throwable $error) {
            // If activation outcome is uncertain, never delete a revision the catalog says is active.
            try { if ($this->catalog->abandon($scope,$revision)) { $this->vectors->deleteRevision($scope->namespace('knowledge.vectors'),$revision);$this->catalog->collected($scope,$revision); } }
            catch (\Throwable) { /* Retain uncertain staging resources for explicit operator reconciliation. */ }
            throw $error;
        }
    }
    public function search(TenantScope $scope,string $question,int $limit=5):array
    {
        $scope->requireAbility('ai.documents.read');if ($question===''||strlen($question)>4096||preg_match('//u',$question)!==1||$limit<1||$limit>20) { throw new \InvalidArgumentException('Use a bounded UTF-8 retrieval question.'); }
        $active=$this->catalog->active($scope);if (count($active)>128) { throw new \LengthException('Catalog exceeds the active revision limit.'); }
        $revisions=[];foreach ($active as $row) { if ($row['model']!==$this->model) { throw new \RuntimeException('KNOWLEDGE_MODEL_REINDEX_REQUIRED'); }$revisions[$row['id']]=$row; }
        if ($revisions===[]) { return []; }
        $query=$this->embeddings([$question])[0];$scope->check();
        $hits=$this->vectors->searchRevisions($scope->namespace('knowledge.vectors'),array_keys($revisions),$query,$limit);
        if (!array_is_list($hits)||count($hits)>$limit) { throw new \RuntimeException('INVALID_RETRIEVAL_RESULTS'); }
        $output=[];
        foreach ($hits as $hit) {
            if (!is_array($hit)||!is_string($hit['id']??null)||!preg_match('/^([a-f0-9]{32}):([0-9]{1,3})$/D',$hit['id'],$match)
                ||!isset($revisions[$match[1]])||(int)$match[2]>=(int)$revisions[$match[1]]['chunks']||!is_string($hit['text']??null)
                ||strlen($hit['text'])>16384||preg_match('//u',$hit['text'])!==1||!is_numeric($hit['score']??null)||!is_finite((float)$hit['score'])) { throw new \RuntimeException('RETRIEVAL_REVISION_OR_SHAPE'); }
            $output[]=['id'=>$hit['id'],'text'=>$hit['text'],'score'=>(float)$hit['score'],'document'=>$revisions[$match[1]]['document'],'version'=>(int)$revisions[$match[1]]['version']];
        }
        $scope->check();if (CanonicalJson::hash($active)!==CanonicalJson::hash($this->catalog->active($scope))) { throw new \RuntimeException('KNOWLEDGE_REVISION_CHANGED'); }
        return $output;
    }
    public function delete(TenantScope $scope,string $document,int $expectedVersion):array { return $this->catalog->delete($scope,$document,$expectedVersion); }
    /** Explicit bounded garbage collection. Staging/active vectors are never collected automatically. */
    public function collect(TenantScope $scope,int $limit=20):int
    {
        $rows=$this->catalog->collectable($scope,$limit);$count=0;
        foreach ($rows as $row) { $scope->requireAbility('ai.documents.write');WorkContext::current()?->check();$this->vectors->deleteRevision($scope->namespace('knowledge.vectors'),$row['id']);$this->catalog->collected($scope,$row['id']);$count++; }
        return $count;
    }
}
