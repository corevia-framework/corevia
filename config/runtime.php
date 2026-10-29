<?php
declare(strict_types=1);
use Corevia\Core\{ApplicationArtifact,ConfigRepository,Corevia,JsonLogger,JsonRunStore,MemoryRunStore,RuntimeProfile};
use Corevia\Persistence\{DatabaseManager,SqlRunStore};
require_once dirname(__DIR__).'/autoload.php';

return static function(string $basePath,bool $canonical=true):array{
    $storage=getenv('COREVIA_STORAGE')?:$basePath.'/storage';
    if(!is_dir($storage) && !mkdir($storage,0700,true) && !is_dir($storage)){throw new RuntimeException('Cannot create storage.');}
    $environment=getenv('APP_ENV')?:'development';$artifact=null;$key=null;
    if($path=getenv('COREVIA_BUILD_ARTIFACT')){$keyPath=getenv('COREVIA_BUILD_KEY_FILE');if(!$keyPath || !is_file($keyPath)){throw new RuntimeException('A separate build key file is required.');}$key=file_get_contents($keyPath);$artifact=ApplicationArtifact::load($path,$key,$environment);$config=$artifact->configuration();}
    else{$config=ConfigRepository::load($basePath.'/config/settings',$environment,$storage.'/config-'.$environment.'.json');}
    $name=getenv('COREVIA_PROFILE')?:$config->get('runtime.profile','web');$name=$name?:'web';
    if(in_array('http',RuntimeProfile::featuresFor($name),true)){$web=require $basePath.'/config/web.php';return $web($basePath,true,$name);}
    $database=null;$kind=$config->get('runtime.run_store','json');
    $store=match($kind){'json'=>new JsonRunStore($storage.'/web-runs.json'),'memory'=>new MemoryRunStore(),'sql'=>new SqlRunStore(($database=new DatabaseManager($config->get('database',[])))->sql($config->get('runtime.connection'))),default=>throw new InvalidArgumentException('Unknown runtime run store.')};
    $app=Corevia::create($basePath,$store,new JsonLogger($storage.'/runtime.log'),$config);
    try{
        if($database!==null){$app->container->set(DatabaseManager::class,$database);$app->onShutdown(static fn()=>$database->disconnect());}
        $app->profile($name,require $basePath.'/config/modules.php');$app->boot();
        if($artifact!==null){$artifact->verify($app,ApplicationArtifact::defaultSources($basePath),$key);}
        return compact('app','storage');
    }catch(Throwable $error){try{$app->shutdown();}catch(Throwable){}throw $error;}
};
