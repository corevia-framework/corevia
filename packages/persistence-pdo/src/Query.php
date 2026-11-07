<?php
declare(strict_types=1);
namespace Corevia\Persistence;

/** Small prepared-query builder; application values are always bindings. */
final class Query
{
    private array $conditions=[]; private array $bindings=[]; private array $orders=[]; private ?int $limit=null;
    private array $joins=[]; private int $offset=0;
    private readonly string $table;
    public function __construct(private readonly SqlConnection $connection,string $table) { $this->table=$connection->quoteIdentifier($table); }
    public function where(string $column,mixed $value,string $operator='='): self
    {
        if (!in_array($operator,['=','!=','<>','<','<=','>','>=','LIKE'],true)) { throw new \InvalidArgumentException('Unsupported SQL operator.'); }
        $copy=clone $this; $column=$this->column($column);
        if ($value===null) { if (!in_array($operator,['=','!=','<>'],true)) { throw new \InvalidArgumentException('Invalid NULL comparison.'); } $copy->conditions[]=$column.($operator==='='?' IS NULL':' IS NOT NULL'); }
        else { $copy->conditions[]="$column $operator ?"; $copy->bindings[]=$value; }
        return $copy;
    }
    public function orderBy(string $column,string $direction='asc'): self
    {
        $direction=strtoupper($direction); if (!in_array($direction,['ASC','DESC'],true)) { throw new \InvalidArgumentException('Invalid order direction.'); }
        $copy=clone $this; $copy->orders[]=$this->column($column).' '.$direction; return $copy;
    }
    public function limit(int $limit): self { if ($limit<1 || $limit>10000) { throw new \InvalidArgumentException('Limit must be 1..10000.'); } $copy=clone $this; $copy->limit=$limit; return $copy; }
    public function offset(int $offset):self {if($offset<0||$offset>1000000){throw new \InvalidArgumentException('Offset must be 0..1000000.');}$copy=clone $this;$copy->offset=$offset;return $copy;}
    private function column(string $name):string
    {
        $parts=explode('.',$name);if(count($parts)>2){throw new \InvalidArgumentException('Use a column or table.column.');}
        return implode('.',array_map($this->connection->quoteIdentifier(...),$parts));
    }
    public function join(string $table,string $left,string $right,string $type='inner'):self
    {
        $type=strtoupper($type);if(!in_array($type,['INNER','LEFT'],true)){throw new \InvalidArgumentException('Use an inner or left join.');}
        $copy=clone $this;$copy->joins[]=$type.' JOIN '.$this->connection->quoteIdentifier($table).' ON '.$this->column($left).' = '.$this->column($right);return $copy;
    }
    public function whereIn(string $column,array $values):self
    {
        if(!array_is_list($values)||count($values)>1000){throw new \InvalidArgumentException('Use a list of at most 1000 values.');}
        foreach($values as $value){if(!is_scalar($value)||is_float($value)&&!is_finite($value)){throw new \InvalidArgumentException('IN values must be finite scalars.');}}
        $copy=clone $this;
        if($values===[]){$copy->conditions[]='1 = 0';return $copy;}
        $copy->conditions[]=$this->column($column).' IN ('.implode(',',array_fill(0,count($values),'?')).')';$copy->bindings=[...$copy->bindings,...$values];return $copy;
    }
    private function from():string{return $this->table.($this->joins?' '.implode(' ',$this->joins):'');}
    public function count():int{return (int)$this->connection->select('SELECT COUNT(*) AS total FROM '.$this->from().$this->filter(),$this->bindings)[0]['total'];}
    public function paginate(int $page=1,int $perPage=20,array $columns=['*']):array
    {
        if($page<1||$perPage<1||$perPage>1000||($page-1)*$perPage>1000000){throw new \InvalidArgumentException('Invalid pagination bounds.');}
        if($this->orders===[]){throw new \LogicException('Pagination requires an explicit stable order.');}
        $total=$this->count();$data=$this->limit($perPage)->offset(($page-1)*$perPage)->get($columns);
        return ['data'=>$data,'page'=>$page,'per_page'=>$perPage,'total'=>$total,'last_page'=>max(1,(int)ceil($total/$perPage))];
    }
    private function filter(): string { return $this->conditions ? ' WHERE '.implode(' AND ',$this->conditions) : ''; }
    public function get(array $columns=['*']): array
    {
        if ($columns===[]) { throw new \InvalidArgumentException('Select at least one column.'); }
        $select=implode(',',array_map(fn(string $c):string=>$c==='*'?'*':$this->column($c),$columns));
        $sql='SELECT '.$select.' FROM '.$this->from().$this->filter();
        if ($this->orders) { $sql.=' ORDER BY '.implode(',',$this->orders); }
        if ($this->limit!==null) { $sql.=' LIMIT '.$this->limit; }
        if($this->offset>0){if($this->limit===null){throw new \LogicException('Offset requires a bounded limit.');}$sql.=' OFFSET '.$this->offset;}
        return $this->connection->select($sql,$this->bindings);
    }
    public function first(array $columns=['*']): ?array { return $this->limit(1)->get($columns)[0] ?? null; }
    public function insert(array $values): void
    {
        if($this->joins){throw new \LogicException('Joined queries are read-only.');}
        if ($values===[]) { throw new \InvalidArgumentException('Insert values are required.'); }
        $columns=array_map($this->connection->quoteIdentifier(...),array_keys($values));
        $this->connection->execute('INSERT INTO '.$this->table.' ('.implode(',',$columns).') VALUES ('.implode(',',array_fill(0,count($values),'?')).')',array_values($values));
    }
    public function update(array $values): int
    {
        if($this->joins){throw new \LogicException('Joined queries are read-only.');}
        if (!$this->conditions || $values===[]) { throw new \LogicException('Filtered update with values required.'); }
        $assignments=array_map(fn(string $c):string=>$this->connection->quoteIdentifier($c).' = ?',array_keys($values));
        return $this->connection->execute('UPDATE '.$this->table.' SET '.implode(',',$assignments).$this->filter(),[...array_values($values),...$this->bindings])->rowCount();
    }
    public function delete(): int
    {
        if($this->joins){throw new \LogicException('Joined queries are read-only.');}
        if (!$this->conditions) { throw new \LogicException('Filtered delete required.'); }
        return $this->connection->execute('DELETE FROM '.$this->table.$this->filter(),$this->bindings)->rowCount();
    }
}
