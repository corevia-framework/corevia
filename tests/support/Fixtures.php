<?php
declare(strict_types=1);
namespace CoreviaTests;

final class Fixtures
{
    /** Remove only a named test's private directory immediately beneath the OS temp directory. */
    public static function remove(string $directory, string $prefix): void
    {
        $root = realpath($directory);
        $temporary = realpath(sys_get_temp_dir());
        if (is_link($directory) || $root === false || $temporary === false
            || dirname($root) !== $temporary || !str_starts_with($prefix, 'corevia-')
            || !str_starts_with(basename($root), $prefix)) {
            throw new \RuntimeException('Unsafe fixture cleanup path.');
        }
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($iterator as $file) {
            $file->isDir() && !$file->isLink() ? rmdir($file->getPathname()) : unlink($file->getPathname());
        }
        unset($file, $iterator);
        rmdir($root);
    }
}
