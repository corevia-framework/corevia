<?php
declare(strict_types=1);
namespace Corevia\Ai;
use Corevia\Persistence\SqlConnection;
/** Portable bounded exact-search index. Replace VectorIndex for ANN/pgvector at large scale. */
final readonly class SqlVectorIndex implements VectorIndex
{
    public function __construct(private SqlConnection $db,private string $name='cv_vectors'){if(!preg_match('/^[a-z][a-z0-9_]{0,39}$/D',$name)){throw new \InvalidArgumentException('Invalid vector table.');}}
    private function table():string{return $this->db->quoteIdentifier($this->name);}
    public function migrate():void{$mysql=$this->db->driver()==='mysql';$this->db->execute('CREATE TABLE IF NOT EXISTS '.$this->table().' (tenant VARCHAR(128) NOT NULL,id VARCHAR(128) NOT NULL,body TEXT NOT NULL,vector '.($mysql?'LONGTEXT':'TEXT').' NOT NULL, PRIMARY KEY (tenant,id))'.($mysql?' ENGINE=InnoDB DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_bin':''));}
    public function put(string $tenant,string $id,string $text,array $vector):void{VectorRules::identity($tenant,$id);VectorRules::vector($vector);if(strlen($text)>16384){throw new \LengthException('Document chunk exceeds 16 KiB.');}$json=json_encode($vector,JSON_THROW_ON_ERROR);$base='INSERT INTO '.$this->table().' (tenant,id,body,vector) VALUES (:tenant,:id,:body,:vector)';$suffix=$this->db->driver()==='mysql'?' ON DUPLICATE KEY UPDATE body=VALUES(body),vector=VALUES(vector)':' ON CONFLICT (tenant,id) DO UPDATE SET body=excluded.body,vector=excluded.vector';$this->db->execute($base.$suffix,['tenant'=>$tenant,'id'=>$id,'body'=>$text,'vector'=>$json]);}
    public function search(string $tenant,array $vector,int $limit=5):array{VectorRules::identity($tenant,'query');VectorRules::vector($vector);$memory=new MemoryVectorIndex();foreach($this->db->select('SELECT id,body,vector FROM '.$this->table().' WHERE tenant=:tenant ORDER BY id LIMIT 5001',['tenant'=>$tenant]) as $row){$memory->put($tenant,$row['id'],$row['body'],json_decode($row['vector'],true,64,JSON_THROW_ON_ERROR));}return $memory->search($tenant,$vector,$limit);}
    public function delete(string $tenant,string $id):void{VectorRules::identity($tenant,$id);$this->db->execute('DELETE FROM '.$this->table().' WHERE tenant=:tenant AND id=:id',['tenant'=>$tenant,'id'=>$id]);}
}
