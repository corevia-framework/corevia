<?php
declare(strict_types=1);
$bootstrap=require __DIR__.'/bootstrap.php';$runtime=$bootstrap(dirname(__DIR__,3).'/storage/catalog-reference');
try{$runtime['router']->dispatch(\Corevia\Http\Request::fromGlobals())->send();}finally{$runtime['app']->shutdown();}
