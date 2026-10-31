<?php

declare(strict_types=1);

spl_autoload_register(static function (string $class): void {
    $prefixes = [
        'Corevia\\Core\\' => 'packages/core/src/',
        'Corevia\\Auth\\' => 'packages/auth/src/',
        'Corevia\\Ai\\' => 'packages/ai/src/',
        'Corevia\\Http\\' => 'packages/http/src/',
        'Corevia\\Protocol\\' => 'packages/protocol/src/',
        'Corevia\\Persistence\\' => 'packages/persistence-pdo/src/',
        'Corevia\\Realtime\\' => 'packages/realtime/src/',
        'Corevia\\Cli\\' => 'packages/cli/src/',
        'App\\' => 'app/',
    ];
    foreach ($prefixes as $prefix => $directory) {
        if (str_starts_with($class, $prefix)) {
            $file = __DIR__ . '/' . $directory . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
            if (is_file($file)) {
                require $file;
            }
            return;
        }
    }
});
