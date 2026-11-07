<?php
declare(strict_types=1);
namespace Corevia\Persistence;
/** Refuse existing MyISAM tables rather than pretending transactions protect their writes. */
final class TransactionalTables
{
    public static function check(SqlConnection $db,array $tables):void
    {
        if(!in_array($db->driver(),['sqlite','mysql','pgsql'],true)){throw new \InvalidArgumentException('This adapter requires SQLite, MySQL InnoDB or PostgreSQL.');}
        foreach($tables as $table){$db->quoteIdentifier($table);}
        if($db->driver()!=='mysql'){return;}
        if(count($tables)<1||count($tables)>16){throw new \InvalidArgumentException('Invalid table capability check.');}
        foreach($db->select('SELECT ENGINE AS engine FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name IN ('.implode(',',array_fill(0,count($tables),'?')).')',$tables) as $row){
            if(strtoupper($row['engine']??'')!=='INNODB'){throw new \LogicException('Existing adapter tables must use InnoDB.');}
        }
    }
}
