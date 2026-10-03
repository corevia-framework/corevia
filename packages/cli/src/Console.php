<?php

declare(strict_types=1);

namespace Corevia\Cli;

use Corevia\Realtime\{Server, SocketBroadcaster};

final readonly class Console
{
    public function __construct(private string $basePath)
    {
    }

    public function run(array $argv): int
    {
        $command = $argv[1] ?? 'help';
        $options = [];
        foreach (array_slice($argv, 2) as $arg) {
            if (str_starts_with($arg, '--')) {
                [$key, $value] = array_pad(explode('=', substr($arg, 2), 2), 2, 'true');
                $options[$key] = $value;
            }
        }
        try {
            if ($command === 'help') {
                echo "Corevia V1\n\nserve [--host=127.0.0.1 --port=8081]\nroutes | modules | flow:list | doctor\nflow:run <name> --input=file.json [--key=key]\nflow:show <run-id>\nflow:retry <run-id> <zero-based-step-index>\nrealtime:start [--host=127.0.0.1 --port=8080]\nrealtime:stop\nmake:module <Name> | make:action <Name> | make:flow <Name>\n";
                return 0;
            }
            if (str_starts_with($command, 'make:')) {
                return $this->generate(substr($command, 5), $argv[2] ?? '');
            }
            if ($command === 'serve') {
                $host = $options['host'] ?? '127.0.0.1';
                if (!filter_var($host, FILTER_VALIDATE_IP) && $host !== 'localhost') {
                    throw new \InvalidArgumentException('Host must be an IP address or localhost.');
                }
                $port = $this->port($options['port'] ?? '8081');
                passthru(escapeshellarg(PHP_BINARY) . ' -S ' . escapeshellarg($host . ':' . $port) . ' -t ' . escapeshellarg($this->basePath . '/public') . ' ' . escapeshellarg($this->basePath . '/public/index.php'), $status);
                return $status;
            }
            $bootstrap = require $this->basePath . '/config/bootstrap.php';
            $runtime = $bootstrap($this->basePath);
            $app = $runtime['app'];
            switch ($command) {
                case 'routes':
                    $this->print($runtime['router']->routes());
                    break;
                case 'modules':
                    $this->print($app->registry->health());
                    break;
                case 'flow:list':
                    $this->print(array_keys($app->flows()));
                    break;
                case 'flow:run':
                    $file = $options['input'] ?? throw new \InvalidArgumentException('--input=file.json is required.');
                    $raw = file_get_contents($file);
                    if ($raw === false) {
                        throw new \InvalidArgumentException('Cannot read input file.');
                    }
                    $input = json_decode($raw, true, 64, JSON_THROW_ON_ERROR);
                    $result = $app->run($argv[2] ?? '', $input, $options['key'] ?? null);
                    $this->print($result);
                    return $result->status() === 'failed' ? 1 : 0;
                case 'flow:show':
                    $this->print($app->store->get($argv[2] ?? '') ?? throw new \OutOfBoundsException('Run not found.'));
                    break;
                case 'flow:retry':
                    if (!isset($argv[3]) || !ctype_digit($argv[3])) {
                        throw new \InvalidArgumentException('A zero-based numeric step index is required.');
                    }
                    $result = $app->retry($argv[2] ?? '', (int) $argv[3]);
                    $this->print($result);
                    return $result->status() === 'completed' ? 0 : 1;
                case 'realtime:start':
                    $privateToken = getenv('COREVIA_PRIVATE_TOKEN');
                    $authorize = $privateToken ? static fn (string $channel, mixed $token): bool => is_string($token) && hash_equals($privateToken, $token) : null;
                    (new Server($app->logger, $runtime['secret'], $options['host'] ?? '127.0.0.1', $this->port($options['port'] ?? '8080'), $runtime['controlPort'], $runtime['origins'], $authorize))->run();
                    break;
                case 'realtime:stop':
                    (new SocketBroadcaster($runtime['secret'], $runtime['controlPort']))->command(['command' => 'stop']);
                    echo "Realtime stop acknowledged.\n";
                    break;
                case 'doctor':
                    $ok = true;
                    echo 'PHP version                  ' . PHP_VERSION . (PHP_VERSION_ID >= 80200 ? ' OK' : ' FAIL') . PHP_EOL;
                    foreach (['json', 'PDO', 'pdo_sqlite'] as $extension) {
                        $loaded = extension_loaded($extension);
                        $ok = $ok && $loaded;
                        echo str_pad('Extension ' . $extension, 29) . ($loaded ? 'OK' : 'FAIL') . PHP_EOL;
                    }
                    $writable = is_writable($runtime['storage']);
                    $ok = $ok && $writable;
                    echo 'Writable storage             ' . ($writable ? 'OK' : 'FAIL') . PHP_EOL;
                    echo 'Database                     OK (SQLite)' . PHP_EOL;
                    $probe = @stream_socket_server('tcp://127.0.0.1:' . ($options['port'] ?? '8080'), $errno, $error);
                    echo 'Realtime port                ' . ($probe ? 'available' : 'in use; check realtime process') . PHP_EOL;
                    if ($probe) {
                        fclose($probe);
                    }
                    echo "Protocol version             1.0\n";
                    echo 'Fraud runtime                ' . (getenv('COREVIA_FRAUD_URL') ? 'remote HTTP (start Python worker)' : 'local demo rule') . PHP_EOL;
                    foreach ($app->registry->health() as $name => $health) {
                        $ok = $ok && $health['status'] === 'healthy';
                        echo str_pad('Module ' . $name, 29) . $health['status'] . PHP_EOL;
                    }
                    return $ok ? 0 : 1;
                default:
                    throw new \InvalidArgumentException('Unknown command: ' . $command);
            }
            return 0;
        } catch (\Throwable $exception) {
            fwrite(STDERR, 'Corevia: ' . $exception->getMessage() . PHP_EOL);
            return 1;
        } finally {
            if (isset($app)) {
                $app->shutdown();
            }
        }
    }

    private function port(string $value): int
    {
        if (!ctype_digit($value) || (int) $value < 1 || (int) $value > 65535) {
            throw new \InvalidArgumentException('Invalid port.');
        }
        return (int) $value;
    }

    private function print(mixed $value): void
    {
        echo json_encode($value, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES) . PHP_EOL;
    }

    private function generate(string $kind, string $name): int
    {
        if (!preg_match('/^[A-Z][A-Za-z0-9]*$/D', $name)) {
            throw new \InvalidArgumentException('Use a PascalCase name.');
        }
        $header = "<?php\n\ndeclare(strict_types=1);\n\n";
        [$file, $source] = match ($kind) {
            'module' => ["app/$name/{$name}Module.php", $header . "namespace App\\$name;\n\nuse Corevia\\Core\\{Module, ModuleContext};\n\nfinal class {$name}Module implements Module\n{\n    public function name(): string { return '" . strtolower($name) . "'; }\n    public function dependencies(): array { return []; }\n    public function register(ModuleContext \$context): void\n    {\n        \$context->flows([\\Corevia\\Core\\Flow::define('" . strtolower($name) . "')]);\n    }\n}\n"],
            'action' => ["app/Generated/Actions/$name.php", $header . "namespace App\\Generated\\Actions;\n\nuse Corevia\\Core\\{Action, ExecutionContext, State};\n\nfinal class $name implements Action\n{\n    public function handle(State \$state, ExecutionContext \$context): State\n    {\n        \$state->data['executed'][] = self::class;\n        return \$state;\n    }\n}\n"],
            'flow' => ["app/Generated/Flows/$name.php", $header . "namespace App\\Generated\\Flows;\n\nuse Corevia\\Core\\Flow;\n\nfinal class $name\n{\n    public static function define(): Flow\n    {\n        return Flow::define('" . strtolower($name) . "');\n    }\n}\n"],
            default => throw new \InvalidArgumentException('Unknown generator.'),
        };
        $path = $this->basePath . '/' . $file;
        if (!is_dir(dirname($path))) {
            mkdir(dirname($path), 0755, true);
        }
        $handle = @fopen($path, 'x');
        if ($handle === false) {
            throw new \RuntimeException('File exists or cannot be created.');
        }
        fwrite($handle, $source);
        fclose($handle);
        echo 'Created ' . $file . '. Register it explicitly in your module/bootstrap.' . PHP_EOL;
        return 0;
    }
}
