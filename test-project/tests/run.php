<?php

declare(strict_types=1);

use Corevia\Http\Request;
use Learning\Tasks\JsonTaskRepository;

$boot = require dirname(__DIR__) . '/bootstrap.php';
$storage = sys_get_temp_dir() . '/corevia-learning-' . bin2hex(random_bytes(8));
mkdir($storage, 0700);
$failures = 0;
$count = 0;

function expect(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$test = static function (string $name, callable $run) use (&$failures, &$count): void {
    $count++;
    try {
        $run();
        echo "PASS $name\n";
    } catch (Throwable $exception) {
        $failures++;
        echo "FAIL $name: {$exception->getMessage()}\n";
    }
};

try {
    $runtime = $boot($storage);
    $app = $runtime['app'];
    $router = $runtime['router'];
    $tasks = $runtime['tasks'];

    $test('module boots and empty task list needs no database', static function () use ($app, $router): void {
        expect($app->registry->health()['tasks']['status'] === 'healthy', 'Module did not boot.');
        expect($router->dispatch(new Request('GET', '/api/tasks'))->body['tasks'] === [], 'List is not empty.');
    });
    $test('valid title runs all four actions, saves task and emits event', static function () use ($app, $tasks): void {
        $run = $app->run('tasks.create', ['title' => '  Learn Corevia  '], traceId: 'learning-trace');
        expect($run->status() === 'completed', 'Flow did not complete.');
        expect(count($run->run['steps']) === 4, 'Missing action steps.');
        expect($run->run['state']['task']['title'] === 'Learn Corevia', 'Title was not normalized.');
        expect($run->run['state']['event_emitted'] === 'task.created', 'Event missing.');
        expect(count($tasks->all()) === 1, 'Task was not saved.');
    });
    $test('critical title failure stops before save', static function () use ($app, $tasks): void {
        foreach (['', '   ', 123, str_repeat('a', 121)] as $title) {
            $run = $app->run('tasks.create', ['title' => $title]);
            expect($run->status() === 'failed', 'Invalid title was accepted.');
            expect(count($run->run['steps']) === 1, 'Later steps ran after validation failed.');
        }
        expect(count($tasks->all()) === 1, 'Invalid tasks were saved.');
    });
    $test('non-critical reminder failure preserves the task', static function () use ($app, $tasks): void {
        $run = $app->run('tasks.create', ['title' => 'Keep this task', 'simulate_reminder_failure' => true]);
        expect($run->status() === 'completed_with_warnings', 'Wrong failure status.');
        expect($run->run['steps'][1]['status'] === 'completed', 'Save failed.');
        expect($run->run['steps'][3]['error']['code'] === 'DEMO_REMINDER_FAILED', 'Missing reminder failure.');
        expect(count($tasks->all()) === 2, 'Task disappeared.');
    });
    $test('HTTP create and complete flows propagate IDs and persist across new boot', static function () use ($boot, $storage, $router): void {
        $response = $router->dispatch(new Request('POST', '/api/tasks', ['x-request-id' => 'req_learning', 'x-trace-id' => 'trace_learning'], '{"title":"HTTP task"}'));
        expect($response->status === 200 && $response->body['ok'], 'HTTP creation failed.');
        expect($response->body['result']['trace_id'] === 'trace_learning', 'Trace ID lost.');
        $id = $response->body['result']['state']['task']['id'];
        $fresh = $boot($storage);
        $complete = $fresh['router']->dispatch(new Request('PATCH', '/api/tasks/' . $id . '/complete'));
        expect($complete->status === 200 && $complete->body['result']['state']['task']['completed'], 'Complete failed.');
        $again = $fresh['router']->dispatch(new Request('PATCH', '/api/tasks/' . $id . '/complete'));
        expect($again->body['result']['state']['task']['completed'], 'Repeat completion failed.');
        $reloaded = new JsonTaskRepository($storage . '/tasks.json');
        expect($reloaded->all()[2]['completed'], 'JSON did not preserve completion.');
    });
    $test('unknown task, malformed JSON and wrong simulation type return errors', static function () use ($router): void {
        expect($router->dispatch(new Request('PATCH', '/api/tasks/task_0000000000000000/complete'))->status === 404, 'Unknown task accepted.');
        expect($router->dispatch(new Request('POST', '/api/tasks', [], '{'))->status === 400, 'Malformed JSON accepted.');
        expect($router->dispatch(new Request('POST', '/api/tasks', [], '{"title":"Test","simulate_reminder_failure":"yes"}'))->status === 422, 'Wrong boolean type accepted.');
    });
    $test('JSON storage corruption is reported instead of silently resetting data', static function () use ($storage): void {
        $file = $storage . '/corrupt.json';
        file_put_contents($file, 'broken');
        try {
            (new JsonTaskRepository($file))->all();
            throw new RuntimeException('Corrupted storage was accepted.');
        } catch (JsonException) {
            expect(file_get_contents($file) === 'broken', 'Corrupted file was overwritten.');
        }
    });
    $test('task-created event appears in the application log', static function () use ($storage): void {
        expect(str_contains(file_get_contents($storage . '/runtime.log'), 'task.created event received'), 'Event listener log missing.');
    });
} finally {
    foreach (['tasks.json', 'runtime.log', 'corrupt.json'] as $name) {
        if (is_file($storage . '/' . $name)) {
            unlink($storage . '/' . $name);
        }
    }
    rmdir($storage);
}

echo "$count learning-app tests, $failures failures.\n";
exit($failures ? 1 : 0);
