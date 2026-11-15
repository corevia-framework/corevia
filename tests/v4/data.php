<?php
declare(strict_types=1);
namespace CoreviaV4Data;
require dirname(__DIR__,2).'/autoload.php';
require dirname(__DIR__).'/support/Services.php';
require dirname(__DIR__).'/support/Fixtures.php';
use Corevia\Persistence\{MigrationRunner,RecordRepository,Seeder,SqlConnection};
function check(bool $ok):void{if(!$ok){throw new \RuntimeException('Data contract failed.');}}
function rejects(callable $work):void{try{$work();}catch(\Throwable){return;}throw new \RuntimeException('Unsafe data operation accepted.');}
function contract(SqlConnection $db):void
{
    $directory=sys_get_temp_dir().'/corevia-v4-data-'.bin2hex(random_bytes(6));mkdir($directory);
    $prefix='cv4_'.bin2hex(random_bytes(4));$items=$prefix.'_items';$groups=$prefix.'_groups';$history=$prefix.'_history';
    $qi=$db->quoteIdentifier($items);$qg=$db->quoteIdentifier($groups);
    $up=['CREATE TABLE '.$qg.' (id INTEGER PRIMARY KEY,label VARCHAR(100) NOT NULL)',
        'CREATE TABLE '.$qi.' (id INTEGER PRIMARY KEY,group_id INTEGER NOT NULL,title VARCHAR(100) NOT NULL)'];
    $down=['DROP TABLE '.$qi,'DROP TABLE '.$qg];
    $migration=['id'=>'001_initial','up'=>['sqlite'=>$up,'mysql'=>$up,'pgsql'=>$up],'down'=>['sqlite'=>$down,'mysql'=>$down,'pgsql'=>$down]];
    $file=$directory.'/001_initial.json';file_put_contents($file,json_encode($migration,JSON_THROW_ON_ERROR));
    $runner=new MigrationRunner($db,$history);
    try{
        check($runner->status($directory)['applied']===[]);
        check($runner->up($directory)['executed']===['001_initial']);check($runner->up($directory)['executed']===[]);
        Seeder::insert($db,$groups,[['id'=>1,'label'=>'First'],['id'=>2,'label'=>'Second']]);
        $records=new RecordRepository($db,$items,['id','group_id','title']);
        $records->insert(['id'=>1,'group_id'=>1,'title'=>'One']);$records->insert(['id'=>2,'group_id'=>2,'title'=>'Two']);$records->insert(['id'=>3,'group_id'=>1,'title'=>'Three']);
        $joined=$db->table($items)->join($groups,$items.'.group_id',$groups.'.id')->whereIn($items.'.id',[1,3])->orderBy($items.'.id');
        $page=$joined->paginate(2,1,[$items.'.id',$groups.'.label']);
        check($page['total']===2&&$page['data'][0]['label']==='First'&&(int)$page['data'][0]['id']===3);
        check($db->table($items)->whereIn('id',[])->count()===0);
        rejects(fn()=>$joined->delete());rejects(fn()=>$db->table($items)->orderBy('id;DROP TABLE users')->get());
        rejects(fn()=>$records->update(1,['role'=>'admin']));rejects(fn()=>$db->table($items)->paginate());
        $migration['up'][$db->driver()][]='ALTER TABLE '.$qi.' ADD unsafe_column INTEGER';file_put_contents($file,json_encode($migration,JSON_THROW_ON_ERROR));
        rejects(fn()=>$runner->status($directory));array_pop($migration['up'][$db->driver()]);file_put_contents($file,json_encode($migration,JSON_THROW_ON_ERROR));
        check($runner->down($directory)['executed']===['001_initial']);check($runner->status($directory)['pending']===['001_initial']);
        if($db->driver()==='sqlite'){
            $broken=$migration;$broken['up']['sqlite']=['CREATE TABLE '.$qi.' (id INTEGER)','INVALID SQL'];file_put_contents($file,json_encode($broken,JSON_THROW_ON_ERROR));
            rejects(fn()=>$runner->up($directory));check($runner->status($directory)['applied']===[]);
            check($db->select("SELECT name FROM sqlite_master WHERE type='table' AND name=?",[$items])===[]);
        }
        echo 'PASS '.$db->driver()." migrations/checksums/rollback, joins/pagination, safe writes and seed transactions\n";
    }finally{
        foreach([$items,$groups,$history] as $table){$db->execute('DROP TABLE IF EXISTS '.$db->quoteIdentifier($table));}
        \CoreviaTests\Fixtures::remove($directory,'corevia-v4-data-');
    }
}
if(class_exists(\PDO::class)&&in_array('sqlite',\PDO::getAvailableDrivers(),true)){contract(new SqlConnection(new \PDO('sqlite::memory:')));}
else{throw new \RuntimeException('SQLite is required for local data acceptance.');}
if(getenv('COREVIA_DB_TESTS')==='1'){foreach(\CoreviaTests\Services::drivers() as $driver){contract(SqlConnection::connect(\CoreviaTests\Services::sql($driver)));}}
