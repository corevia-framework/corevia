<?php
declare(strict_types=1);
use Corevia\Http\Router;
return static function(Router $router):void{
    // No implicit action exposure: register an allowlisted, authorized gateway in your application.
    $router->get('/health',static fn():array=>['status'=>'ok'])->name('health');
};
