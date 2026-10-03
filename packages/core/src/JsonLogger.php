<?php

declare(strict_types=1);

namespace Corevia\Core;

final class JsonLogger implements Logger
{
    public function __construct(private readonly ?string $path = null)
    {
    }

    public function log(string $level, string $message, array $context = []): void
    {
        $scrub = static function (array $data) use (&$scrub): array {
            foreach ($data as $key => $value) {
                if (preg_match('/token|secret|password|authorization/i', (string) $key)) {
                    $data[$key] = '[REDACTED]';
                } elseif (is_array($value)) {
                    $data[$key] = $scrub($value);
                }
            }
            return $data;
        };
        $line = json_encode(['time' => gmdate('c'), 'level' => $level, 'message' => $message, 'context' => $scrub($context)], JSON_THROW_ON_ERROR) . PHP_EOL;
        if ($this->path !== null) {
            if (file_put_contents($this->path, $line, FILE_APPEND | LOCK_EX) === false) {
                throw new \RuntimeException('Unable to write runtime log.');
            }
        } else {
            error_log(rtrim($line));
        }
    }
}
