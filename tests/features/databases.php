<?php
declare(strict_types=1);
namespace CoreviaDatabaseTests;
require dirname(__DIR__,2).'/autoload.php';
use Corevia\Core\{Action,Corevia,ExecutionContext,Flow,Logger,Module,ModuleContext,State};
use Corevia\Persistence\{DatabaseManager,DocumentStore,JsonDocumentStore,SqlConnection,SqlRunStore};
function check(bool $value):void{if(!$value){throw new \RuntimeException('Assertion failed.');}}
function rejects(callable $call):void{try{$call();}catch(\Throwable){return;}throw new \RuntimeException('Expected rejection.');}
final class Work implements Action{public function handle(State $state,ExecutionContext $context):State{$state->data['count']=($state->data['count'] ?? 0)+1;return $state;}}
final class Demo implements Module{
    public function name():string{return 'dbtest';}public function dependencies():array{return [];}
    public function register(ModuleContext $context):void{$context->actions(['work'=>Work::class]);$context->flows([Flow::define('dbtest')->step('work')->waitFor('approved')->step('work')]);}
}
function application(SqlRunStore $store):Corevia{
    $logger=new class implements Logger{public function log(string $level,string $message,array $context=[]):void{}};
    $app=Corevia::create(__DIR__,$store,$logger);$app->modules([new Demo()]);$app->boot();return $app;
}
function sqlContract(SqlConnection $db):void{
    $table='cv_test_'.bin2hex(random_bytes(6));$name=$db->quoteIdentifier($table);
    $db->execute("CREATE TABLE $name (id VARCHAR(64) PRIMARY KEY, title VARCHAR(255), active INTEGER NOT NULL)");
    try{
        $query=$db->table($table);$query->insert(['id'=>'one','title'=>"O'Reilly; DROP TABLE users; --",'active'=>1]);
        check($query->where('id','one')->first()['title']==="O'Reilly; DROP TABLE users; --");
        $query->insert(['id'=>'two','title'=>null,'active'=>0]);check(count($query->where('title',null)->get())===1);
        check(count($query->get())===2);check(count($query->where('active',1)->get())===1); // immutable query branches
        $db->transaction(function(SqlConnection $db)use($query):void{
            $query->insert(['id'=>'outer','title'=>'saved','active'=>1]);
            try{$db->transaction(function()use($query):void{$query->insert(['id'=>'inner','title'=>'rolled back','active'=>1]);throw new \RuntimeException('rollback');});}catch(\RuntimeException){}
        });check($query->where('id','outer')->first()!==null);check($query->where('id','inner')->first()===null);
        check($query->where('id','one')->update(['title'=>'updated'])===1);check($query->where('id','two')->delete()===1);
        rejects(fn()=>$query->delete());rejects(fn()=>$query->update(['active'=>0]));rejects(fn()=>$db->table('users;DROP'));rejects(fn()=>$query->orderBy('id','DESC;DROP'));
        $store=new SqlRunStore($db);$store->migrate();$app=application($store);$key='case-sensitive-'.bin2hex(random_bytes(8));
        $run=$app->run('dbtest',[],$key);check($run->status()==='paused');check($app->run('dbtest',[],$key)->run['run_id']===$run->run['run_id']);
        check($app->run('dbtest',[],strtoupper($key))->run['run_id']!==$run->run['run_id']);
        check($store->lock($run->run['run_id']));check(!$store->lock($run->run['run_id']));$store->unlock($run->run['run_id']);$app->shutdown();
        $other=application(new SqlRunStore($db));$resumed=$other->resume($run->run['run_id'],'approved');check($resumed->status()==='completed');check($resumed->run['state']['count']===2);$other->shutdown();
    }finally{$db->execute('DROP TABLE '.$name);}
}
function documentsContract(DocumentStore $store):void{
    $collection='cv_test_'.bin2hex(random_bytes(5));
    try{
        $store->put($collection,'one',['title'=>'Hello','active'=>true,'optional'=>null]);$store->put($collection,'two',['title'=>'Other','active'=>false]);
        check($store->get($collection,'one')['title']==='Hello');check(count($store->find($collection,['active'=>true]))===1);check(count($store->find($collection,['optional'=>null]))===1);
        $store->put($collection,'one',['title'=>'Replaced']);check(!isset($store->get($collection,'one')['active']));check(count($store->find($collection,[],1))===1);
        rejects(fn()=>$store->find($collection,['title'=>['$ne'=>'x']]));rejects(fn()=>$store->put('../escape','id',[]));rejects(fn()=>$store->put($collection,'bad',['$where'=>'x']));
        check($store->delete($collection,'one'));check(!$store->delete($collection,'one'));check($store->get($collection,'one')===null);
    }finally{foreach(['one','two','bad'] as $id){$store->delete($collection,$id);}}
}
$directory=sys_get_temp_dir().'/corevia-db-'.bin2hex(random_bytes(8));mkdir($directory);
try{
    $manager=new DatabaseManager(['default'=>'local','connections'=>['local'=>['driver'=>'json','path'=>$directory.'/docs'],'invalid'=>['driver'=>'mysql','host'=>'127.0.0.1','database'=>'unused']]]);
    check(!$manager->configured()['invalid']['connected']);check($manager->documents() === $manager->documents());documentsContract($manager->documents());echo "PASS JSON document contract and lazy named connections\n";
    if(class_exists(\PDO::class) && in_array('sqlite',\PDO::getAvailableDrivers(),true)){sqlContract(SqlConnection::connect(['driver'=>'sqlite','database'=>':memory:']));echo "PASS SQLite prepared queries, nested transactions and durable SQL workflow contract\n";}
    check(SqlConnection::dsn(['driver'=>'mysql','database'=>'app'])==='mysql:host=127.0.0.1;port=3306;dbname=app;charset=utf8mb4');
    check(SqlConnection::dsn(['driver'=>'pgsql','database'=>'app','sslmode'=>'require'])==='pgsql:host=127.0.0.1;port=5432;dbname=app;sslmode=require');rejects(fn()=>SqlConnection::dsn(['driver'=>'pgsql','host'=>'x;password=y','database'=>'app']));
    if(getenv('COREVIA_DB_TESTS')==='1'){
        foreach(['mysql'=>3306,'pgsql'=>5432] as $driver=>$port){$db=SqlConnection::connect(['driver'=>$driver,'host'=>'127.0.0.1','port'=>$port,'database'=>'corevia_ci','username'=>'corevia','password'=>'ci-only-password','sslmode'=>'disable']);sqlContract($db);echo "PASS real $driver server contract\n";}
        $mongo=new \Corevia\Persistence\MongoDocumentStore('mongodb://127.0.0.1:27017','corevia_ci');documentsContract($mongo);echo "PASS real MongoDB document contract\n";
    }
}finally{foreach(glob($directory.'/docs/*') ?: [] as $file){unlink($file);}if(is_dir($directory.'/docs')){rmdir($directory.'/docs');}rmdir($directory);}
