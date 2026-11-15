<?php
declare(strict_types=1);
namespace Corevia\Persistence;

/** Small explicit model/repository helper; unknown mass-assignment fields are rejected. */
final readonly class RecordRepository
{
    public function __construct(private SqlConnection $connection,private string $table,private array $writable,private string $key='id')
    {
        $connection->quoteIdentifier($table);$connection->quoteIdentifier($key);
        foreach($writable as $field){$connection->quoteIdentifier($field);}
    }
    private function values(array $values):array
    {
        if($values===[]||array_diff(array_keys($values),$this->writable)){throw new \InvalidArgumentException('Only declared writable fields may be assigned.');}
        return $values;
    }
    public function query():Query{return $this->connection->table($this->table);}
    public function find(string|int $id):?array{return $this->query()->where($this->key,$id)->first();}
    public function insert(array $values):void{$this->query()->insert($this->values($values));}
    public function update(string|int $id,array $values):int{return $this->query()->where($this->key,$id)->update($this->values($values));}
    public function delete(string|int $id):int{return $this->query()->where($this->key,$id)->delete();}
}
