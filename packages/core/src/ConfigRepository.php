<?php

declare(strict_types=1);

namespace Corevia\Core;

/** JSON-compatible configuration, with explicit trusted PHP files and environment overlays. */
final class ConfigRepository
{
    private bool $frozen = false;

    public function __construct(private array $values = [])
    {
        self::validate($values);
    }

    public function get(string $key, mixed $default = null): mixed
    {
        $value = $this->values;
        foreach (explode('.', $key) as $part) {
            if (!is_array($value) || !array_key_exists($part, $value)) {
                return $default;
            }
            $value = $value[$part];
        }
        return $value;
    }

    public function set(string $key, mixed $value): void
    {
        if ($this->frozen) {
            throw new \LogicException('Configuration is frozen after registration.');
        }
        if ($key === '' || in_array('', explode('.', $key), true)) {
            throw new \InvalidArgumentException('Invalid configuration key.');
        }
        self::validate($value);
        $cursor = &$this->values;
        $parts = explode('.', $key);
        $last = array_pop($parts);
        foreach ($parts as $part) {
            if (isset($cursor[$part]) && !is_array($cursor[$part])) {
                throw new \InvalidArgumentException('Configuration path crosses a scalar value.');
            }
            $cursor[$part] ??= [];
            $cursor = &$cursor[$part];
        }
        $cursor[$last] = $value;
    }

    public function all(): array
    {
        return $this->values;
    }

    public function freeze(): void
    {
        $this->frozen = true;
    }

    /** Maps merge recursively; lists replace rather than merge by numeric index. */
    public static function merge(array $base, array $overlay): array
    {
        foreach ($overlay as $key => $value) {
            if (is_array($value) && $value !== [] && !array_is_list($value) && is_array($base[$key] ?? null) && !array_is_list($base[$key])) {
                $base[$key] = self::merge($base[$key], $value);
            } else {
                $base[$key] = $value;
            }
        }
        return $base;
    }

    public static function load(string $directory, string $environment = 'development', ?string $cachePath = null): self
    {
        if (!preg_match('/^[a-zA-Z0-9_-]{1,64}$/D', $environment)) {
            throw new \InvalidArgumentException('Invalid environment name.');
        }
        if ($cachePath !== null && is_file($cachePath)) {
            if (filesize($cachePath) > 1048576) {
                throw new \LengthException('Configuration cache exceeds 1 MiB.');
            }
            $cache = json_decode(file_get_contents($cachePath), true, 64, JSON_THROW_ON_ERROR);
            if (!is_array($cache) || ($cache['format'] ?? null) !== 1 || ($cache['environment'] ?? null) !== $environment || !is_array($cache['values'] ?? null)) {
                throw new \InvalidArgumentException('Invalid or mismatched configuration cache.');
            }
            return new self($cache['values']);
        }
        if (!is_dir($directory)) {
            throw new \InvalidArgumentException('Configuration directory does not exist.');
        }
        $read = static function (string $path): array {
            $config = [];
            foreach (glob($path . '/*.php') ?: [] as $file) {
                $value = (static fn (string $filename): mixed => require $filename)($file);
                if (!is_array($value)) {
                    throw new \InvalidArgumentException('Configuration files must return arrays.');
                }
                $config[pathinfo($file, PATHINFO_FILENAME)] = $value;
            }
            return $config;
        };
        return new self(self::merge($read($directory), $read($directory . '/environments/' . $environment)));
    }

    public function cache(string $path, string $environment): void
    {
        if (!preg_match('/^[a-zA-Z0-9_-]{1,64}$/D', $environment)) {
            throw new \InvalidArgumentException('Invalid environment name.');
        }
        $json = json_encode(['format' => 1, 'environment' => $environment, 'values' => $this->values], JSON_THROW_ON_ERROR);
        if (strlen($json) > 1048576) {
            throw new \LengthException('Configuration cache exceeds 1 MiB.');
        }
        $temporary = $path . '.' . bin2hex(random_bytes(8)) . '.tmp';
        try {
            if (file_put_contents($temporary, $json, LOCK_EX) === false || !chmod($temporary, 0600) || !rename($temporary, $path)) {
                throw new \RuntimeException('Cannot write private configuration cache.');
            }
        } finally {
            if (is_file($temporary)) {
                unlink($temporary);
            }
        }
    }

    private static function validate(mixed $value, int $depth = 0): void
    {
        if ($depth > 60 || is_object($value) || is_resource($value) || (is_float($value) && !is_finite($value))) {
            throw new \InvalidArgumentException('Configuration must contain finite JSON-compatible values.');
        }
        if (is_array($value)) {
            foreach ($value as $item) {
                self::validate($item, $depth + 1);
            }
        }
    }
}
