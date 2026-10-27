<?php
declare(strict_types=1);
use App\Http\HomeController;
use Corevia\Http\{Csrf,Router};

return static function(Router $router,Csrf $csrf):void{
    $router->group('',static function(Router $route):void{
        $route->get('/',[HomeController::class,'index'])->name('home');
        $route->get('/guide',[HomeController::class,'guide'])->name('guide');
        $route->get('/database',[HomeController::class,'database'])->name('database');
        $route->get('/hello/{name}',[HomeController::class,'hello'])->name('hello')->where('name','[A-Za-z0-9_-]{1,40}');
        $route->get('/contact',[HomeController::class,'contact'])->name('contact');
        $route->post('/contact',[HomeController::class,'submit'])->name('contact.submit');
    },[$csrf]);
    $router->redirect('/docs','/guide')->name('docs');
    $router->get('/up',static fn():array=>['status'=>'ok','framework'=>'Corevia'])->name('up');
};
