<?php
declare(strict_types=1);
namespace Corevia\Persistence;
use Corevia\Core\TenantScope;

/** No raw query escape hatch. tenant_id stores a 64-character namespace digest, not a display ID. */
final readonly class TenantRecords
{
    public function __construct(private SqlConnection $connection,private TenantScope $scope,private string $table,private array $writable)
    {
        $connection->quoteIdentifier($table);
        foreach($writable as $field){$connection->quoteIdentifier($field);if($field==='tenant_id'){throw new \InvalidArgumentException('Tenant ownership is server controlled.');}}
    }
    private function query():Query{return $this->connection->table($this->table)->where('tenant_id',$this->scope->namespace('sql-records'));}
    private function values(array $data):void
    {
        if($data===[]||array_diff(array_keys($data),$this->writable)){throw new \InvalidArgumentException('Unknown or protected tenant record fields.');}
        foreach($data as $value){if($value!==null&&!is_scalar($value)){throw new \InvalidArgumentException('Use scalar record fields.');}}
    }
    public function insert(array $data):void{$this->values($data);$this->connection->table($this->table)->insert(['tenant_id'=>$this->scope->namespace('sql-records'),...$data]);}
    public function find(string $id):?array{return $this->query()->where('id',$id)->first();}
    public function page(int $page=1,int $perPage=20):array{return $this->query()->orderBy('id')->paginate($page,$perPage);}
    public function update(string $id,array $data):int{if(array_key_exists('id',$data)){throw new \InvalidArgumentException('Record identifiers are immutable.');}$this->values($data);return $this->query()->where('id',$id)->update($data);}
    public function delete(string $id):bool{return $this->query()->where('id',$id)->delete()>0;}
}
