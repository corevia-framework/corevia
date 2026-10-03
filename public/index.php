<?php

declare(strict_types=1);

use Corevia\Core\ActionResult;
use Corevia\Http\{Request, Response};
use Corevia\Protocol\Protocol;

// Explicit static allowlist keeps storage, source files and credentials inaccessible.
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
if ($_SERVER['REQUEST_METHOD'] === 'GET' && in_array($path, ['/', '/demo.html', '/corevia-client.js'], true)) {
    $file = $path === '/corevia-client.js' ? dirname(__DIR__) . '/sdk/js/index.js' : __DIR__ . '/demo.html';
    header('Content-Type: ' . ($path === '/corevia-client.js' ? 'text/javascript' : 'text/html') . '; charset=utf-8');
    readfile($file);
    return;
}
$bootstrap = require dirname(__DIR__) . '/config/bootstrap.php';
try {
    $runtime = $bootstrap(dirname(__DIR__));
    $runtime['router']->dispatch(Request::fromGlobals())->send();
} catch (LengthException) {
    (new Response(Protocol::response('invalid', ActionResult::failure('PAYLOAD_TOO_LARGE', 'Request too large.')), 413))->send();
} catch (Throwable $exception) {
    error_log('Corevia bootstrap failed: ' . $exception::class);
    (new Response(Protocol::response('invalid', ActionResult::failure('INTERNAL_ERROR', 'Runtime unavailable.')), 500))->send();
}
