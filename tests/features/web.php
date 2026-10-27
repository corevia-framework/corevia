<?php
declare(strict_types=1);
namespace CoreviaWebTests;
require dirname(__DIR__,2).'/autoload.php';
use Corevia\Core\Corevia;
use Corevia\Http\{Csrf,Request,Response,Router,View};
function check(bool $value):void{if(!$value){throw new \RuntimeException('Assertion failed.');}}
function rejects(callable $call):void{try{$call();}catch(\Throwable){return;}throw new \RuntimeException('Expected rejection.');}
final class Controller{public function show(Request $request,int $id):array{return ['id'=>$id,'path'=>$request->path];}}
$app=Corevia::create(__DIR__);$app->boot();$router=new Router($app);
try{
    $router->get('/posts/{id}',[Controller::class,'show'])->name('posts.show')->where('id','[0-9]+');
    check($router->url('posts.show',['id'=>42],['q'=>'a b'])==='/posts/42?q=a%20b');check($router->dispatch(new Request('GET','/posts/42'))->body['id']===42);check($router->dispatch(new Request('GET','/posts/no'))->status===404);
    $router->group('/admin',static function(Router $r):void{$r->get('/hello/{name}',static fn(string $name):string=>'Hello '.$name)->name('hello');},namePrefix:'admin.');
    check($router->url('admin.hello',['name'=>'Ada Lovelace'])==='/admin/hello/Ada%20Lovelace');check($router->dispatch(new Request('GET','/admin/hello/Ada'))->body==='Hello Ada');
    rejects(fn()=>$router->get('/other',static fn():string=>'x')->name('posts.show'));rejects(fn()=>Response::redirect('//evil.example'));rejects(fn()=>new Response([],headers:['X-Test'=>"a\r\nb"]));
    $view=new View(dirname(__DIR__,2).'/resources/views');rejects(fn()=>$view->render('../config'));check(View::escape('<script>')==='&lt;script&gt;');
    $csrf=new Csrf(str_repeat('test-only-',4));$router->get('/form',static fn(Request $r):array=>['token'=>$r->attributes['csrf_token']])->middleware($csrf);$router->post('/form',static fn():array=>['ok'=>true])->middleware($csrf);
    $form=$router->dispatch(new Request('GET','/form'));$cookie=explode(';',$form->headers['Set-Cookie'])[0];$token=$form->body['token'];
    check($router->dispatch(new Request('POST','/form',['content-type'=>'application/x-www-form-urlencoded'],'name=one'))->status===419);
    check($router->dispatch(new Request('POST','/form',['content-type'=>'application/x-www-form-urlencoded','cookie'=>$cookie],http_build_query(['_token'=>$token])))->status===200);
    check($router->dispatch(new Request('POST','/form',['content-type'=>'application/x-www-form-urlencoded','cookie'=>$cookie],http_build_query(['_token'=>'bad'])))->status===419);
    echo "PASS controllers, named URLs, constraints, groups, HTML, safe redirects, escaped views and CSRF\n";
}finally{$app->shutdown();}
$directory=sys_get_temp_dir().'/corevia-web-'.bin2hex(random_bytes(8));mkdir($directory);putenv('COREVIA_STORAGE='.$directory);
try{
    $boot=require dirname(__DIR__,2).'/config/web.php';$runtime=$boot(dirname(__DIR__,2));
    foreach(['/','/guide','/database','/contact','/hello/developer'] as $path){$response=$runtime['router']->dispatch(new Request('GET',$path));check($response->status===200);check(str_contains($response->body,'<!doctype html>'));}
    check($runtime['router']->dispatch(new Request('GET','/docs'))->headers['Location']==='/guide');
    foreach($runtime['database']->configured() as $connection){check(!$connection['connected']);}$runtime['app']->shutdown();
    echo "PASS prebuilt pages boot without opening any database connection\n";
}finally{putenv('COREVIA_STORAGE');foreach(glob($directory.'/*') as $file){unlink($file);}rmdir($directory);}
