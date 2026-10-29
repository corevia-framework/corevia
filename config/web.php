<?php
declare(strict_types=1);
use Corevia\Core\{ApplicationArtifact,ConfigRepository,Corevia,JsonLogger,JsonRunStore,MemoryRunStore};
use Corevia\Http\{Csrf,Router,View};
use Corevia\Persistence\{DatabaseManager,SqlRunStore};
require_once dirname(__DIR__).'/autoload.php';

return static function(string $basePath,bool $canonical=false):array{
    $storage=getenv('COREVIA_STORAGE') ?: $basePath.'/storage';
    if(!is_dir($storage) && !mkdir($storage,0700,true) && !is_dir($storage)){throw new RuntimeException('Cannot create storage.');}
    $environment=getenv('APP_ENV') ?: 'development';
    $artifact=null;$artifactKey=null;$artifactPath=getenv('COREVIA_BUILD_ARTIFACT');
    if($artifactPath){$keyFile=getenv('COREVIA_BUILD_KEY_FILE');if(!$keyFile || !is_file($keyFile)){throw new RuntimeException('A separate build key file is required.');}$artifactKey=file_get_contents($keyFile);$artifact=ApplicationArtifact::load($artifactPath,$artifactKey,$environment);$config=$artifact->configuration();}
    else{$config=ConfigRepository::load($basePath.'/config/settings',$environment,$storage.'/config-'.$environment.'.json');}
    $database=new DatabaseManager($config->get('database',[]));
    $store=match($config->get('runtime.run_store','json')){'json'=>new JsonRunStore($storage.'/web-runs.json'),'memory'=>new MemoryRunStore(),'sql'=>new SqlRunStore($database->sql($config->get('runtime.connection'))),default=>throw new InvalidArgumentException('Unknown runtime run store.')};
    $app=Corevia::create($basePath,$store,new JsonLogger($storage.'/web.log'),$config);
    try{
    $app->container->set(DatabaseManager::class,$database);
    $app->container->set(View::class,new View($basePath.'/resources/views'));
    if($canonical || $artifact!==null || getenv('COREVIA_COMPOSITION')==='canonical'){$app->compose([]);}
    $app->onShutdown(static fn()=>$database->disconnect());
    $origins=array_filter(explode(',',getenv('COREVIA_ORIGINS') ?: 'http://localhost:8081,http://127.0.0.1:8081'));
    $router=new Router($app,$origins);$app->container->set(Router::class,$router);
    $secretFile=$storage.'/web-signing.secret';$lock=fopen($secretFile.'.lock','c+b');
    if($lock===false || !flock($lock,LOCK_EX)){throw new RuntimeException('Cannot lock web signing key.');}
    try{
        if(!is_file($secretFile)){if(file_put_contents($secretFile,bin2hex(random_bytes(32)),LOCK_EX)===false || !chmod($secretFile,0600)){throw new RuntimeException('Cannot create signing key.');}}
        $secret=trim(file_get_contents($secretFile));
    }finally{flock($lock,LOCK_UN);fclose($lock);}
    $csrf=new Csrf($secret,secure:(getenv('APP_HTTPS')==='true'));
    $routes=require $basePath.'/routes/web.php';$routes($router,$csrf);
    $app->boot();
    if($artifact!==null){$artifact->verify($app,ApplicationArtifact::defaultSources($basePath),$artifactKey);}
    return compact('app','router','database','storage');
    }catch(Throwable $error){try{$app->shutdown();}catch(Throwable){}throw $error;}
};
