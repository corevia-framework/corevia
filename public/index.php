<?php

declare(strict_types=1);

use Corevia\Core\ActionResult;
use Corevia\Http\{Request, Response};
use Corevia\Protocol\Protocol;

// Explicit static allowlist keeps storage, source files and credentials inaccessible.
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
if (in_array($_SERVER['REQUEST_METHOD'],['GET','HEAD'],true) && in_array($path, ['/demo.html', '/corevia-client.js','/assets/app.css','/assets/icon.svg'], true)) {
    $file = match($path){'/corevia-client.js'=>dirname(__DIR__).'/sdk/js/index.js','/assets/app.css'=>__DIR__.'/assets/app.css','/assets/icon.svg'=>__DIR__.'/assets/icon.svg',default=>__DIR__.'/demo.html'};
    header('Content-Type: ' . (match($path){'/corevia-client.js'=>'text/javascript','/assets/app.css'=>'text/css','/assets/icon.svg'=>'image/svg+xml',default=>'text/html'}) . '; charset=utf-8');
    header('X-Content-Type-Options: nosniff');
    if($_SERVER['REQUEST_METHOD']!=='HEAD'){readfile($file);}
    return;
}
require_once dirname(__DIR__).'/autoload.php';
$bootstrap = require dirname(__DIR__) . (getenv('COREVIA_PROFILE') || getenv('COREVIA_BUILD_ARTIFACT')?'/config/runtime.php':(in_array($path,['/checkout','/cvp','/health'],true)?'/config/bootstrap.php':'/config/web.php'));
try {
    $runtime = $bootstrap(dirname(__DIR__));
    if(!isset($runtime['router'])){throw new LogicException('The selected profile has no HTTP adapter.');}
    $runtime['router']->dispatch(Request::fromGlobals())->send();
} catch (LengthException) {
    (new Response(Protocol::response('invalid', ActionResult::failure('PAYLOAD_TOO_LARGE', 'Request too large.')), 413))->send();
} catch (Throwable $exception) {
    error_log('Corevia bootstrap failed: ' . $exception::class);
    (new Response(Protocol::response('invalid', ActionResult::failure('INTERNAL_ERROR', 'Runtime unavailable.')), 500))->send();
} finally {
    if (isset($runtime)) {
        $runtime['app']->shutdown();
    }
}
