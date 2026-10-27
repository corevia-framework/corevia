<?php
declare(strict_types=1);
namespace Corevia\Persistence;

/** Small prepared-query builder; application values are always bindings. */
final class Query
{
    private array $conditions=[]; private array $bindings=[]; private array $orders=[]; private ?int $limit=null;
    private readonly string $table;
    public function __construct(private readonly SqlConnection $connection,string $table) { $this->table=$connection->quoteIdentifier($table); }
    public function where(string $column,mixed $value,string $operator='='): self
    {
        if (!in_array($operator,['=','!=','<>','<','<=','>','>=','LIKE'],true)) { throw new \InvalidArgumentException('Unsupported SQL operator.'); }
        $copy=clone $this; $column=$this->connection->quoteIdentifier($column);
        if ($value===null) { if (!in_array($operator,['=','!=','<>'],true)) { throw new \InvalidArgumentException('Invalid NULL comparison.'); } $copy->conditions[]=$column.($operator==='='?' IS NULL':' IS NOT NULL'); }
        else { $copy->conditions[]="$column $operator ?"; $copy->bindings[]=$value; }
        return $copy;
    }
    public function orderBy(string $column,string $direction='asc'): self
    {
        $direction=strtoupper($direction); if (!in_array($direction,['ASC','DESC'],true)) { throw new \InvalidArgumentException('Invalid order direction.'); }
        $copy=clone $this; $copy->orders[]=$this->connection->quoteIdentifier($column).' '.$direction; return $copy;
    }
    public function limit(int $limit): self { if ($limit<1 || $limit>10000) { throw new \InvalidArgumentException('Limit must be 1..10000.'); } $copy=clone $this; $copy->limit=$limit; return $copy; }
    private function filter(): string { return $this->conditions ? ' WHERE '.implode(' AND ',$this->conditions) : ''; }
    public function get(array $columns=['*']): array
    {
        if ($columns===[]) { throw new \InvalidArgumentException('Select at least one column.'); }
        $select=implode(',',array_map(fn(string $c):string=>$c==='*'?'*':$this->connection->quoteIdentifier($c),$columns));
        $sql='SELECT '.$select.' FROM '.$this->table.$this->filter();
        if ($this->orders) { $sql.=' ORDER BY '.implode(',',$this->orders); }
        if ($this->limit!==null) { $sql.=' LIMIT '.$this->limit; }
        return $this->connection->select($sql,$this->bindings);
    }
    public function first(array $columns=['*']): ?array { return $this->limit(1)->get($columns)[0] ?? null; }
    public function insert(array $values): void
    {
        if ($values===[]) { throw new \InvalidArgumentException('Insert values are required.'); }
        $columns=array_map($this->connection->quoteIdentifier(...),array_keys($values));
        $this->connection->execute('INSERT INTO '.$this->table.' ('.implode(',',$columns).') VALUES ('.implode(',',array_fill(0,count($values),'?')).')',array_values($values));
    }
    public function update(array $values): int
    {
        if (!$this->conditions || $values===[]) { throw new \LogicException('Filtered update with values required.'); }
        $assignments=array_map(fn(string $c):string=>$this->connection->quoteIdentifier($c).' = ?',array_keys($values));
        return $this->connection->execute('UPDATE '.$this->table.' SET '.implode(',',$assignments).$this->filter(),[...array_values($values),...$this->bindings])->rowCount();
    }
    public function delete(): int
    {
        if (!$this->conditions) { throw new \LogicException('Filtered delete required.'); }
        return $this->connection->execute('DELETE FROM '.$this->table.$this->filter(),$this->bindings)->rowCount();
    }
}
