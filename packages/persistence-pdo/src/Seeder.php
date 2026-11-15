<?php
declare(strict_types=1);
namespace Corevia\Persistence;
/** Explicit operator seed data; never expose this operation as a public HTTP handler. */
final class Seeder
{
    public static function insert(SqlConnection $connection,string $table,array $rows):int
    {
        if(!array_is_list($rows)||count($rows)>10000){throw new \InvalidArgumentException('Seed rows must be a bounded list.');}
        $connection->quoteIdentifier($table);
        foreach($rows as $row){if(!is_array($row)||$row===[]){throw new \InvalidArgumentException('Invalid seed record.');}foreach($row as $column=>$value){$connection->quoteIdentifier($column);if($value!==null&&!is_scalar($value)){throw new \InvalidArgumentException('Seed values must be scalars.');}}}
        return $connection->transaction(static function()use($connection,$table,$rows):int{foreach($rows as $row){$connection->table($table)->insert($row);}return count($rows);});
    }
}
