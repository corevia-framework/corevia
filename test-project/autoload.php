<?php

declare(strict_types=1);

// Load the existing Corevia framework; this project does not copy its runtime.
require_once dirname(__DIR__) . '/autoload.php';

spl_autoload_register(static function (string $class): void {
    $prefix = 'Learning\\';
    if (str_starts_with($class, $prefix)) {
        $file = __DIR__ . '/src/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
        if (is_file($file)) {
            require $file;
        }
    }
});
