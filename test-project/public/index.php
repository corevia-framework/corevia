<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/autoload.php';

use Corevia\Http\Request;
use Corevia\Http\Response;

$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
if ($_SERVER['REQUEST_METHOD'] === 'GET' && $path === '/') {
    header('Content-Type: text/html; charset=utf-8');
    readfile(__DIR__ . '/index.html');
    return;
}

try {
    // This front controller uses only the learning app bootstrap.
    $boot = require dirname(__DIR__) . '/bootstrap.php';
    $runtime = $boot();
    $runtime['router']->dispatch(Request::fromGlobals())->send();
} catch (LengthException) {
    (new Response(['ok' => false, 'error' => ['code' => 'PAYLOAD_TOO_LARGE', 'message' => 'Request too large.']], 413))->send();
} catch (Throwable $exception) {
    error_log('Learning app failed: ' . $exception::class);
    (new Response(['ok' => false, 'error' => ['code' => 'APP_UNAVAILABLE', 'message' => 'Check the application storage and logs.']], 500))->send();
} finally {
    if (isset($runtime)) {
        $runtime['app']->shutdown();
    }
}
