<?php

declare(strict_types=1);

require dirname(__DIR__) . '/autoload.php';

$remote = new \Corevia\Protocol\RemoteAction(getenv('COREVIA_FRAUD_URL') ?: 'http://127.0.0.1:8090/cvp', getenv('COREVIA_WORKER_TOKEN') ?: null);
$result = $remote->call('ai.analyze', ['text' => 'Corevia connects PHP and Python.'], 'demo_trace');
echo json_encode($result, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . PHP_EOL;
exit($result->ok ? 0 : 1);
