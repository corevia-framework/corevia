<?php

declare(strict_types=1);

$source = new RecursiveDirectoryIterator(dirname(__DIR__), FilesystemIterator::SKIP_DOTS);
$filtered = new RecursiveCallbackFilterIterator($source, static fn (SplFileInfo $file): bool => !$file->isDir() || !in_array($file->getFilename(), ['.git', 'storage', 'vendor', 'node_modules', '.dart_tool'], true));
$iterator = new RecursiveIteratorIterator($filtered);
$failed = false;
$count = 0;
foreach ($iterator as $file) {
    if (($file->getExtension() === 'php' || $file->getFilename() === 'corevia') && !str_contains($file->getPathname(), DIRECTORY_SEPARATOR . 'vendor' . DIRECTORY_SEPARATOR)) {
        exec(escapeshellarg(PHP_BINARY) . ' -l ' . escapeshellarg($file->getPathname()) . ' 2>&1', $output, $code);
        if ($code !== 0) {
            echo implode(PHP_EOL, $output) . PHP_EOL;
            $failed = true;
        }
        $output = [];
        $count++;
    }
}
echo "Linted $count PHP files.\n";
exit($failed ? 1 : 0);
