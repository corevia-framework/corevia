<?php

declare(strict_types=1);

use Corevia\Core\Corevia;
use Corevia\Core\JsonLogger;
use Corevia\Core\MemoryRunStore;
use Corevia\Http\Request;
use Corevia\Http\Response;
use Corevia\Http\Router;
use Learning\Tasks\JsonTaskRepository;
use Learning\Tasks\TaskRepository;
use Learning\Tasks\TasksModule;

require_once __DIR__ . '/autoload.php';

return static function (?string $storagePath = null): array {
    $storage = $storagePath ?? (getenv('COREVIA_LEARNING_STORAGE') ?: __DIR__ . '/storage');

    // 1. Choose storage. No PDO, MySQL or SQLite is used by this example.
    $tasks = new JsonTaskRepository($storage . '/tasks.json');

    // 2. Create the runtime. Execution records are in memory; task data is in JSON.
    $app = Corevia::create(__DIR__, new MemoryRunStore(), new JsonLogger($storage . '/runtime.log'));

    // 3. Tell the container what to inject when an action requests TaskRepository.
    $app->container->set(TaskRepository::class, $tasks);

    // 4. Register this project's module and its actions/flows/listeners.
    $app->modules([TasksModule::class]);
    $app->boot();

    // 5. Connect HTTP URLs to the runtime.
    $origins = array_filter(explode(',', getenv('COREVIA_LEARNING_ORIGINS') ?: 'http://127.0.0.1:8091,http://localhost:8091'));
    $router = new Router($app, $origins);
    $router->add('GET', '/api/tasks', static fn (Request $request): Response => new Response(['tasks' => $tasks->all()]));
    $router->flow('POST', '/api/tasks', 'tasks.create');
    $router->add('PATCH', '/api/tasks/{id}/complete', static function (Request $request) use ($app): Response {
        $run = $app->run('tasks.complete', ['id' => $request->params['id']], traceId: $request->header('x-trace-id'), requestId: $request->header('x-request-id'));
        $error = $run->run['error'];
        $status = $run->status() === 'failed' ? (($error['code'] ?? '') === 'TASK_NOT_FOUND' ? 404 : 422) : 200;
        return new Response(['ok' => $run->status() !== 'failed', 'result' => $run->run, 'error' => $error], $status);
    });

    return compact('app', 'router', 'tasks', 'storage');
};
