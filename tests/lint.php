<?php

declare(strict_types=1);

$iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(dirname(__DIR__), FilesystemIterator::SKIP_DOTS));
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
