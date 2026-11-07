<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
$password=getenv('CATALOG_DEMO_PASSWORD');if(!$password){fwrite(STDERR,"Set CATALOG_DEMO_PASSWORD to a development-only password of at least 12 characters.\n");exit(1);}
$bootstrap=require __DIR__.'/bootstrap.php';$runtime=$bootstrap(dirname(__DIR__,3).'/storage/catalog-reference');
try{
    $request=static fn(array $data)=>new \Corevia\Http\Request('POST','/fixture',['content-type'=>'application/json'],json_encode($data,JSON_THROW_ON_ERROR));
    $anonymous=$runtime['auth']->open(new \Corevia\Http\Request('GET','/session'));
    try{$context=$runtime['auth']->register($request(['name'=>'Local catalog editor','email'=>'catalog@example.test','password'=>$password,'password_confirmation'=>$password]),$anonymous);}
    catch(\Corevia\Auth\AuthFailure $failure){if($failure->errorCode!=='REGISTRATION'){throw $failure;}$context=$runtime['auth']->login($request(['email'=>'catalog@example.test','password'=>$password]),$anonymous);}
    $runtime['gate']->assign($context->user['id'],['catalog_editor']);
    $context=$runtime['auth']->login($request(['email'=>'catalog@example.test','password'=>$password]),$runtime['auth']->open(new \Corevia\Http\Request('GET','/session')));
    $token=$runtime['tokens']->issue($context,['items.read','items.write']);
    echo 'Development-only bearer token (shown once): '.$token['token'].PHP_EOL;
}finally{$runtime['app']->shutdown();}
