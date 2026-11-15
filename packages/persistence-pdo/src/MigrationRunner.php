<?php
declare(strict_types=1);
namespace Corevia\Persistence;
use Corevia\Core\CanonicalJson;

/** Application-owned, checksummed SQL migrations. No implicit document-store conversion. */
final class MigrationRunner
{
    private string $history;
    public function __construct(private readonly SqlConnection $connection,string $table='cv_app_migrations')
    {
        $this->history=$connection->quoteIdentifier($table);
        if(!in_array($connection->driver(),['sqlite','mysql','pgsql'],true)){throw new \InvalidArgumentException('Migrations require SQLite, MySQL or PostgreSQL.');}
    }
    public static function load(string $directory):array
    {
        if(!is_dir($directory)||is_link($directory)){throw new \InvalidArgumentException('Use a regular migration directory.');}
        $migrations=[];
        foreach(glob($directory.'/*.json')?:[] as $file){
            if(is_link($file)||filesize($file)>262144){throw new \InvalidArgumentException('Invalid migration file.');}
            $migration=json_decode(file_get_contents($file),true,32,JSON_THROW_ON_ERROR);
            if(!is_array($migration)||array_diff(array_keys($migration),['id','up','down'])||!is_string($migration['id']??null)
                ||!preg_match('/^[a-z0-9][a-z0-9_-]{0,119}$/D',$migration['id'])||isset($migrations[$migration['id']])){throw new \InvalidArgumentException('Invalid or duplicate migration ID.');}
            foreach(['up','down'] as $direction){
                if(!is_array($migration[$direction]??null)||array_diff(array_keys($migration[$direction]),['sqlite','mysql','pgsql'])){throw new \InvalidArgumentException('Declare explicit SQL-driver migration statements.');}
                foreach($migration[$direction] as $statements){
                    if(!is_array($statements)||!array_is_list($statements)||count($statements)>100){throw new \InvalidArgumentException('Migration statements must be bounded lists.');}
                    foreach($statements as $statement){if(!is_string($statement)||trim($statement)===''||strlen($statement)>65536||str_contains($statement,"\0")){throw new \InvalidArgumentException('Invalid migration SQL.');}}
                }
            }
            $migrations[$migration['id']]=$migration;
        }
        ksort($migrations,SORT_STRING);return $migrations;
    }
    private function rows():array
    {
        try{return $this->connection->select('SELECT id,checksum,batch,status,applied_at FROM '.$this->history.' ORDER BY id');}
        catch(\PDOException $error){
            // Only absence is a first-run state; permission/corruption/connectivity errors remain visible.
            $state=$error->errorInfo[0]??'';$code=(int)($error->errorInfo[1]??0);
            if($state==='42P01'||$state==='42S02'||$this->connection->driver()==='sqlite'&&$code===1&&str_contains($error->getMessage(),'no such table')){return [];}
            throw $error;
        }
    }
    public function status(string $directory):array
    {
        $files=self::load($directory);$rows=$this->rows();$applied=[];
        foreach($rows as $row){
            $id=$row['id'];$applied[$id]=$row;
            if(!isset($files[$id])||!hash_equals($row['checksum'],hash('sha256',CanonicalJson::encode($files[$id])))){throw new \LogicException('Applied migration was removed or edited.');}
            if($row['status']!=='applied'){throw new \LogicException('A non-transactional migration requires operator repair.');}
        }
        return ['driver'=>$this->connection->driver(),'applied'=>array_keys($applied),'pending'=>array_values(array_diff(array_keys($files),array_keys($applied)))];
    }
    public function up(string $directory):array{return $this->run($directory,false,0);}
    public function down(string $directory,int $steps=1):array
    {
        if($steps<1||$steps>100){throw new \InvalidArgumentException('Rollback steps must be 1..100.');}
        return $this->run($directory,true,$steps);
    }
    private function run(string $directory,bool $down,int $steps):array
    {
        if($this->connection->inTransaction()){throw new \LogicException('Migration runner must own the connection transaction.');}
        $driver=$this->connection->driver();$lockName='corevia-migrations-'.substr(hash('sha256',$this->history),0,40);
        $locked=false;
        if($driver==='mysql'){$row=$this->connection->select('SELECT GET_LOCK(?,0) AS acquired',[$lockName])[0];$locked=(int)$row['acquired']===1;}
        elseif($driver==='pgsql'){$row=$this->connection->select('SELECT pg_try_advisory_lock(hashtext(?)) AS acquired',[$lockName])[0];$locked=in_array($row['acquired'],[true,1,'1','t'],true);}
        if($driver!=='sqlite'&&!$locked){throw new \RuntimeException('Another application migrator owns the lock.');}
        try{
            $this->connection->execute('CREATE TABLE IF NOT EXISTS '.$this->history.' (id VARCHAR(120) PRIMARY KEY,checksum VARCHAR(64) NOT NULL,batch BIGINT NOT NULL,status VARCHAR(16) NOT NULL,applied_at BIGINT NOT NULL)'.($driver==='mysql'?' ENGINE=InnoDB':''));
            $operation=function()use($directory,$driver,$down,$steps):array{
                if($driver==='sqlite'){$this->connection->execute('UPDATE '.$this->history.' SET batch=batch WHERE id=?',['__migration_lock__']);}
                $status=$this->status($directory);$files=self::load($directory);$rows=$this->rows();$batch=1;
                if(!$down&&$status['applied']!==[]&&$status['pending']!==[]&&strcmp($status['pending'][0],max($status['applied']))<0){throw new \LogicException('New migration IDs must follow the applied history.');}
                foreach($rows as $row){$batch=max($batch,(int)$row['batch']+1);}
                $ids=$down?array_slice(array_reverse($status['applied']),0,$steps):$status['pending'];
                foreach($ids as $id){
                    $migration=$files[$id];$statements=$migration[$down?'down':'up'][$driver]??null;
                    if($statements===null||$statements===[]){throw new \LogicException('No migration statements for the selected driver/direction.');}
                    $checksum=hash('sha256',CanonicalJson::encode($migration));
                    if($driver==='mysql'){
                        if($down){$this->connection->execute('UPDATE '.$this->history.' SET status=? WHERE id=?',['running',$id]);}
                        else{$this->connection->execute('INSERT INTO '.$this->history.' (id,checksum,batch,status,applied_at) VALUES (?,?,?,?,?)',[$id,$checksum,$batch,'running',time()]);}
                    }
                    foreach($statements as $sql){$this->connection->execute($sql);}
                    if($down){$this->connection->execute('DELETE FROM '.$this->history.' WHERE id=?',[$id]);}
                    elseif($driver==='mysql'){$this->connection->execute('UPDATE '.$this->history.' SET status=? WHERE id=?',['applied',$id]);}
                    else{$this->connection->execute('INSERT INTO '.$this->history.' (id,checksum,batch,status,applied_at) VALUES (?,?,?,?,?)',[$id,$checksum,$batch,'applied',time()]);}
                }
                return ['driver'=>$driver,'direction'=>$down?'down':'up','executed'=>$ids,'transactional_ddl'=>$driver!=='mysql'];
            };
            return $driver==='mysql'?$operation():$this->connection->transaction($operation);
        }finally{
            if($locked){if($driver==='mysql'){$this->connection->select('SELECT RELEASE_LOCK(?)',[$lockName]);}else{$this->connection->select('SELECT pg_advisory_unlock(hashtext(?))',[$lockName]);}}
        }
    }
}
