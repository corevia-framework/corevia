<?php
declare(strict_types=1);

namespace Corevia\Core;

/** Framework-owned resources are located from the installed code, never the working directory. */
final class FrameworkResources
{
    public static function root(): string
    {
        return dirname(__DIR__, 3);
    }

    public static function hasPackage(string $application, string $package): bool
    {
        $entry = match ($package) {
            'auth' => 'packages/auth/src/AuthService.php',
            'ai' => 'packages/ai/src/AiClient.php',
            default => throw new \InvalidArgumentException('Unknown starter package.'),
        };
        return is_file($application . '/' . $entry)
            || is_file($application . '/vendor/corevia-framework/runtime/' . $entry);
    }
}
