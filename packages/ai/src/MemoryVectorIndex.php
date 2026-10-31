<?php
declare(strict_types=1);
namespace Corevia\Ai;
final class MemoryVectorIndex implements VectorIndex
{
    private array $rows=[];
    public function put(string $tenant,string $id,string $text,array $vector):void{VectorRules::identity($tenant,$id);VectorRules::vector($vector);if(strlen($text)>16384){throw new \LengthException('Document chunk exceeds 16 KiB.');}if(count($this->rows[$tenant]??[])>=5000&&!isset($this->rows[$tenant][$id])){throw new \LengthException('Reference index holds at most 5000 chunks per tenant.');}foreach($this->rows[$tenant]??[] as $row){if(count($row['vector'])!==count($vector)){throw new \InvalidArgumentException('Tenant vector dimensions must match.');}}$this->rows[$tenant][$id]=compact('id','text','vector');}
    public function search(string $tenant,array $vector,int $limit=5):array{VectorRules::identity($tenant,'query');VectorRules::vector($vector);if($limit<1||$limit>20){throw new \InvalidArgumentException('Choose 1..20 results.');}$rows=[];foreach($this->rows[$tenant]??[] as $row){$rows[]=['id'=>$row['id'],'text'=>$row['text'],'score'=>VectorRules::score($vector,$row['vector'])];}usort($rows,static fn($a,$b)=>($b['score']<=>$a['score'])?:strcmp($a['id'],$b['id']));return array_slice($rows,0,$limit);}
    public function delete(string $tenant,string $id):void{VectorRules::identity($tenant,$id);unset($this->rows[$tenant][$id]);}
}
