<?php
declare(strict_types=1);
namespace Corevia\Ai;
use Corevia\Core\{TenantScope,WorkContext};

/** Deterministic retrieval metrics against application-owned labeled cases; never an LLM safety judge. */
final readonly class RetrievalEvaluation
{
    public function __construct(private KnowledgeBase $knowledge) {}
    public function run(TenantScope $scope,array $cases,int $limit=5):array
    {
        $scope->requireAbility('ai.documents.read');
        if (!array_is_list($cases)||count($cases)<1||count($cases)>100||$limit<1||$limit>20) { throw new \InvalidArgumentException('Use 1..100 labeled retrieval cases and top-k 1..20.'); }
        $seen=[];
        foreach ($cases as $case) {
            if (!is_array($case)||!is_string($case['id']??null)||!preg_match('/^[a-zA-Z0-9][a-zA-Z0-9_.-]{0,63}$/D',$case['id'])||isset($seen[$case['id']])
                ||!is_string($case['question']??null)||$case['question']===''||strlen($case['question'])>4096||preg_match('//u',$case['question'])!==1
                ||!is_array($case['expected']??null)||!array_is_list($case['expected'])||count($case['expected'])<1||count($case['expected'])>20) { throw new \InvalidArgumentException('Invalid labeled retrieval case.'); }
            $seen[$case['id']]=true;foreach ($case['expected'] as $id) { VectorRules::identity('evaluation',$id); }
            if (count(array_unique($case['expected']))!==count($case['expected'])) { throw new \InvalidArgumentException('Expected documents must be unique.'); }
        }
        $rows=[];$recall=0.0;$precision=0.0;$rr=0.0;$hitRate=0;
        foreach ($cases as $case) {
            $scope->check();WorkContext::current()?->check();$hits=$this->knowledge->search($scope,$case['question'],$limit);
            $documents=array_values(array_unique(array_column($hits,'document')));$matched=array_intersect($documents,$case['expected']);$rank=0;
            foreach ($documents as $i=>$document) { if (in_array($document,$case['expected'],true)) { $rank=$i+1;break; } }
            $caseRecall=count($matched)/count($case['expected']);$casePrecision=count($matched)/max(1,count($documents));$reciprocal=$rank===0?0.0:1/$rank;
            $rows[]=['id'=>$case['id'],'retrieved_documents'=>count($documents),'expected_documents'=>count($case['expected']),
                'recall'=>$caseRecall,'precision'=>$casePrecision,'reciprocal_rank'=>$reciprocal];
            $recall+=$caseRecall;$precision+=$casePrecision;$rr+=$reciprocal;$hitRate+=$rank>0?1:0;
        }
        $n=count($cases);return ['cases'=>$rows,'top_k_chunks'=>$limit,'mean_recall'=>$recall/$n,'mean_precision'=>$precision/$n,'mean_reciprocal_rank'=>$rr/$n,'hit_rate'=>$hitRate/$n];
    }
}
