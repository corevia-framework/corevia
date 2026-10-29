<?php
declare(strict_types=1);
require dirname(__DIR__,2).'/autoload.php';
use Corevia\Core\ApplicationArtifact;
use Corevia\Http\Request;
$root=dirname(__DIR__,2);$directory=sys_get_temp_dir().'/corevia-v3-boot-'.bin2hex(random_bytes(6));mkdir($directory);
$previous=[];foreach(['COREVIA_STORAGE','COREVIA_BUILD_ARTIFACT','COREVIA_BUILD_KEY_FILE'] as $name){$previous[$name]=getenv($name);}
putenv('COREVIA_STORAGE='.$directory);$key=str_repeat('test-build-key-',4);file_put_contents($directory.'/key',$key);
try{
    $bootstrap=require $root.'/config/web.php';$runtime=$bootstrap($root,true);
    try{$artifact=ApplicationArtifact::build($runtime['app'],getenv('APP_ENV')?:'development',ApplicationArtifact::defaultSources($root),$key);$artifact->write($directory.'/build.json');}finally{$runtime['app']->shutdown();}
    putenv('COREVIA_BUILD_ARTIFACT='.$directory.'/build.json');putenv('COREVIA_BUILD_KEY_FILE='.$directory.'/key');
    $runtime=$bootstrap($root);try{if($runtime['router']->dispatch(new Request('GET','/'))->status!==200){throw new RuntimeException('Artifact boot failed.');}}finally{$runtime['app']->shutdown();}
    file_put_contents($directory.'/key',str_repeat('wrong-key',6));$rejected=false;try{$bootstrap($root);}catch(InvalidArgumentException){$rejected=true;}if(!$rejected){throw new RuntimeException('Wrong build key accepted.');}
    echo "PASS database-free boot from private signed artifact and wrong-key rejection\n";
}finally{foreach($previous as $name=>$value){putenv($value===false?$name:$name.'='.$value);}foreach(glob($directory.'/*') as $file){unlink($file);}rmdir($directory);}
