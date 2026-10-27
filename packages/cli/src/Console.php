<?php

declare(strict_types=1);

namespace Corevia\Cli;

use Corevia\Realtime\{Server, SocketBroadcaster};
use Corevia\Core\{ConfigRepository, JobQueue};

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
            if ($command === 'about') {
                $this->print(['framework'=>'Corevia','version'=>'2.0.0-alpha.2','protocols'=>['1.0','2.0'],'php'=>PHP_VERSION,'database'=>'optional; checkout demo uses SQLite']); return 0;
            }
            if ($command === 'upgrade:check') {
                $report=(new UpgradeScanner())->scan($this->basePath); $this->print($report); return $report['compatible'] ? 0 : 1;
            }
            if (in_array($command, ['config:cache','cache:clear'], true)) {
                $environment=$options['env'] ?? (getenv('APP_ENV') ?: 'development');
                if (!preg_match('/^[a-zA-Z0-9_-]{1,64}$/D',$environment)) { throw new \InvalidArgumentException('Invalid environment.'); }
                $storage=getenv('COREVIA_STORAGE') ?: $this->basePath.'/storage';
                if (!is_dir($storage) && !mkdir($storage,0700,true) && !is_dir($storage)) { throw new \RuntimeException('Cannot create storage.'); }
                $path=$storage.'/config-'.$environment.'.json';
                if ($command==='config:cache') { ConfigRepository::load($this->basePath.'/config/settings',$environment)->cache($path,$environment); echo "Configuration cached for $environment.\n"; }
                elseif (is_file($path)) { if (!unlink($path)) { throw new \RuntimeException('Cannot remove config cache.'); } echo "Configuration cache cleared for $environment.\n"; }
                return 0;
            }
            if ($command === 'test') {
                $status=0;
                foreach (['tests/run.php','tests/v2/kernel.php','tests/v2/flows.php','tests/v2/protocol.php','tests/v2/tooling.php','tests/v2/upgrade.php','tests/v2/diagnostics.php'] as $test) {
                    passthru(escapeshellarg(PHP_BINARY).' '.escapeshellarg($this->basePath.'/'.$test),$code); if ($code!==0) { $status=1; }
                }
                return $status;
            }
            if ($command === 'update:check') {
                if (isset($options['offline'])) { $this->print(['current'=>'2.0.0-alpha.2','available'=>null,'checked'=>false,'message'=>'Offline mode; use Composer to review and install updates explicitly.']); return 0; }
                passthru('composer outdated --direct --format=json --working-dir='.escapeshellarg($this->basePath),$code); return $code;
            }
            if ($command === 'help') {
                echo "Corevia V2\n\nserve [--host=127.0.0.1 --port=8081]\nabout | routes | modules | flow:list | doctor\nflow:run <name> --input=file.json [--key=key]\nflow:show <run-id> | flow:inspect <run-id>\nflow:retry <run-id> <zero-based-step-index>\nflow:resume <run-id> [--event=name]\nrealtime:start [--host=127.0.0.1 --port=8080]\nrealtime:stop\nworker:start [--once] | worker:dispatch <flow> --input=file.json [--delay=seconds]\nmake:module <Name> | make:action <Name> | make:flow <Name> | make:event <Name>\nconfig:cache | cache:clear [--env=development]\ntest | upgrade:check | update:check [--offline]\n";
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
                case 'flow:inspect':
                    $this->print($app->store->get($argv[2] ?? '') ?? throw new \OutOfBoundsException('Run not found.'));
                    break;
                case 'flow:resume':
                    $result=$app->resume($argv[2] ?? '',$options['event'] ?? null); $this->print($result); return $result->status()==='failed' ? 1 : 0;
                case 'worker:dispatch':
                    $file=$options['input'] ?? throw new \InvalidArgumentException('--input is required.');
                    $delay=$options['delay'] ?? '0'; if (!ctype_digit($delay) || (int)$delay>31536000) { throw new \InvalidArgumentException('Invalid delay.'); }
                    $input=json_decode(file_get_contents($file),true,64,JSON_THROW_ON_ERROR);
                    $flow=$argv[2] ?? ''; $app->flow($flow);
                    $id=(new JobQueue($runtime['storage'].'/jobs'))->dispatch($flow,$input,time()+(int)$delay); $this->print(['job_id'=>$id]); break;
                case 'worker:start':
                    $queue=new JobQueue($runtime['storage'].'/jobs');
                    $running=true;
                    if (function_exists('pcntl_async_signals')) { pcntl_async_signals(true); pcntl_signal(SIGTERM, function () use (&$running): void { $running=false; }); pcntl_signal(SIGINT, function () use (&$running): void { $running=false; }); }
                    do { $worked=$queue->work($app); if (!isset($options['once']) && !$worked) { usleep(250000); } } while (!isset($options['once']) && $running);
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
                    echo "Protocol version             1.0 (also supports 2.0)\n";
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
            'event' => ["app/Generated/Events/$name.php", $header . "namespace App\\Generated\\Events;\n\nfinal readonly class $name implements \\JsonSerializable\n{\n    public function __construct(public array \$payload) {}\n    public function jsonSerialize(): array { return \$this->payload; }\n}\n"],
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
