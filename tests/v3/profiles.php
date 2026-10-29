<?php
declare(strict_types=1);
namespace CoreviaV3Profiles;
require dirname(__DIR__,2).'/autoload.php';
use Corevia\Core\{ApplicationArtifact,CanonicalJson,Corevia,Module,ModuleContext,ModuleDefinition,RuntimeProfile};
use Corevia\Http\{Request,Router,View};
function check(bool $value):void{if(!$value){throw new \RuntimeException('Profile assertion failed.');}}
function rejects(callable $work):void{try{$work();}catch(\Throwable){return;}throw new \RuntimeException('Expected profile rejection.');}
abstract class Fixture implements Module{
    public static array $constructed=[];
    public function __construct(){self::$constructed[]=static::class;}
    public function dependencies():array{return [];}
    public function register(ModuleContext $context):void{}
}
final class Shared extends Fixture{public function name():string{return 'shared';}}
final class Web extends Fixture{public function name():string{return 'web';}public function dependencies():array{return ['shared'];}}
final class Worker extends Fixture{public function name():string{return 'worker';}}
$catalog=[new ModuleDefinition('web',Web::class,['web'],['shared']),new ModuleDefinition('worker',Worker::class,['worker']),new ModuleDefinition('shared',Shared::class,[])];
$lazy=[...$catalog,new ModuleDefinition('unused','NotInstalled\\NoSqlModule',['ai'])];
$a=Corevia::create(__DIR__);$a->profile('web',$lazy);$a->boot();
$b=Corevia::create(__DIR__);$b->profile('web',array_reverse($lazy));$b->boot();
try{
    check($a->runtimeProfile()->selected===['shared','web']);check(!in_array(Worker::class,Fixture::$constructed,true));check(!class_exists('NotInstalled\\NoSqlModule',false));
    check(CanonicalJson::encode($a->graph())===CanonicalJson::encode($b->graph()));check(!$a->capabilities->has('profile.python'));check($a->capabilities->has('profile.views'));
    rejects(fn()=>$a->profile('worker',$catalog));rejects(fn()=>RuntimeProfile::resolve('unknown',$catalog));rejects(fn()=>RuntimeProfile::resolve('web',$catalog,exclude:['shared']));
    rejects(fn()=>RuntimeProfile::resolve('web',$catalog,include:['missing']));rejects(fn()=>RuntimeProfile::resolve('web',$catalog,include:['worker'],exclude:['worker']));
    check(RuntimeProfile::resolve('web',$catalog,include:['worker'])->selected===['shared','web','worker']);check(RuntimeProfile::resolve('full',$catalog)->selected===['shared','web','worker']);
    $cycle=[new ModuleDefinition('one',Shared::class,['web'],['two']),new ModuleDefinition('two',Shared::class,[],['one'])];rejects(fn()=>RuntimeProfile::resolve('web',$cycle));
    $mismatch=Corevia::create(__DIR__);rejects(fn()=>$mismatch->profile('web',[new ModuleDefinition('wrong',Web::class,['web'])]));$mismatch->shutdown();
    $sealed=Corevia::create(__DIR__);$sealed->profile('web');rejects(fn()=>$sealed->modules([Worker::class]));$sealed->shutdown();
    foreach(array_keys(RuntimeProfile::presets()) as $name){$app=Corevia::create(__DIR__);$app->profile($name);$app->boot();try{check($app->graph()['profile']['name']===$name);if($app->runtimeProfile()->allows('http')){$router=new Router($app);if(!$app->runtimeProfile()->allows('views')){rejects(fn()=>$router->view('/','welcome'));}}else{rejects(fn()=>new Router($app));}if(!$app->runtimeProfile()->allows('jobs')){rejects(fn()=>$app->requireFeature('jobs'));}}finally{$app->shutdown();}}
    echo "PASS lazy profile selection, dependency closure, stable graphs, overrides, feature guards and all six presets\n";
}finally{$a->shutdown();$b->shutdown();}
$root=dirname(__DIR__,2);$directory=sys_get_temp_dir().'/corevia-v3-profile-'.bin2hex(random_bytes(6));mkdir($directory);
$names=['COREVIA_STORAGE','COREVIA_PROFILE','COREVIA_BUILD_ARTIFACT','COREVIA_BUILD_KEY_FILE'];$previous=[];foreach($names as $name){$previous[$name]=getenv($name);}putenv('COREVIA_STORAGE='.$directory);putenv('COREVIA_BUILD_ARTIFACT');putenv('COREVIA_BUILD_KEY_FILE');
try{
    $bootstrap=require $root.'/config/runtime.php';
    foreach(['web','api','worker','realtime','ai','full'] as $name){
        putenv('COREVIA_PROFILE='.$name);$runtime=$bootstrap($root);
        try{
            $app=$runtime['app'];check($app->runtimeProfile()->name===$name);
            if($name==='api' || $name==='ai'){check(!$app->container->has(View::class));check(!$app->container->has(\Corevia\Persistence\DatabaseManager::class));check($runtime['router']->dispatch(new Request('GET','/health'))->body['status']==='ok');check($runtime['router']->dispatch(new Request('GET','/'))->status===404);}
            if($name==='worker' || $name==='realtime'){check(!isset($runtime['router']));check(!$app->container->has(\Corevia\Persistence\DatabaseManager::class));}
            if($name==='worker'){$key=str_repeat('test-only-profile-key-',3);file_put_contents($directory.'/signing',$key);ApplicationArtifact::build($app,getenv('APP_ENV')?:'development',ApplicationArtifact::defaultSources($root),$key)->write($directory.'/build.json');}
        }finally{$app->shutdown();}
    }
    putenv('COREVIA_PROFILE');putenv('COREVIA_BUILD_ARTIFACT='.$directory.'/build.json');putenv('COREVIA_BUILD_KEY_FILE='.$directory.'/signing');
    $runtime=$bootstrap($root);try{check($runtime['app']->runtimeProfile()->name==='worker');}finally{$runtime['app']->shutdown();}
    putenv('COREVIA_PROFILE=realtime');rejects(fn()=>$bootstrap($root));
    echo "PASS database-free boot of every preset, API-only routes and artifact profile restoration/drift\n";
}finally{foreach($previous as $name=>$value){putenv($value===false?$name:$name.'='.$value);}foreach(glob($directory.'/*') as $file){unlink($file);}rmdir($directory);}
