<?php

declare(strict_types=1);

$command = $argv[1] ?? 'help';
try {
    if ($command === 'serve') {
        // Local development server; keep this terminal open while using the app.
        passthru(escapeshellarg(PHP_BINARY) . ' -S 127.0.0.1:8091 -t ' . escapeshellarg(__DIR__ . '/public') . ' ' . escapeshellarg(__DIR__ . '/public/index.php'), $code);
        exit($code);
    }
    if ($command === 'help') {
        echo "Corevia learning app (no database)\n\n";
        echo "php test-project/console.php serve\nphp test-project/console.php create \"Learn Corevia\" [--fail-reminder]\nphp test-project/console.php list\nphp test-project/console.php complete <task-id>\nphp test-project/console.php routes\nphp test-project/tests/run.php\n";
        exit(0);
    }
    $boot = require __DIR__ . '/bootstrap.php';
    $runtime = $boot();
    $result = match ($command) {
        'create' => $runtime['app']->run('tasks.create', ['title' => $argv[2] ?? '', 'simulate_reminder_failure' => in_array('--fail-reminder', $argv, true)]),
        'complete' => $runtime['app']->run('tasks.complete', ['id' => $argv[2] ?? '']),
        'list' => $runtime['tasks']->all(),
        'routes' => $runtime['router']->routes(),
        default => throw new InvalidArgumentException('Unknown command. Run console.php help.'),
    };
    echo json_encode($result, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES) . PHP_EOL;
    exit($result instanceof \Corevia\Core\FlowResult && $result->status() === 'failed' ? 1 : 0);
} catch (Throwable $exception) {
    fwrite(STDERR, 'Learning app: ' . $exception->getMessage() . PHP_EOL);
    exit(1);
}
