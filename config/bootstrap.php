<?php

declare(strict_types=1);

use App\Notifications\Actions\SendConfirmation;
use App\Notifications\NotificationsModule;
use App\Orders\Actions\FraudCheck;
use App\Orders\OrdersModule;
use App\Payments\PaymentsModule;
use App\Shared\DemoLedger;
use Corevia\Core\{ConfigRepository, Corevia, JsonLogger, RunStore};
use Corevia\Http\{Request, Router};
use Corevia\Persistence\PdoRunStore;
use Corevia\Protocol\RemoteAction;
use Corevia\Realtime\{Broadcaster, SocketBroadcaster};

require_once dirname(__DIR__) . '/autoload.php';

return static function (string $basePath): array {
    $storage = getenv('COREVIA_STORAGE') ?: $basePath . '/storage';
    if (!is_dir($storage) && !mkdir($storage, 0700, true) && !is_dir($storage)) {
        throw new RuntimeException('Cannot create storage.');
    }
    $secretFile = $storage . '/realtime.secret';
    if (!is_file($secretFile)) {
        $file = @fopen($secretFile, 'x');
        if ($file !== false) {
            fwrite($file, bin2hex(random_bytes(32)));
            fclose($file);
            chmod($secretFile, 0600);
        }
    }
    $secret = trim((string) file_get_contents($secretFile));
    if (strlen($secret) < 32) {
        throw new RuntimeException('Invalid realtime secret file.');
    }
    $pdo = new PDO('sqlite:' . $storage . '/corevia.sqlite');
    $store = new PdoRunStore($pdo);
    $logger = new JsonLogger($storage . '/runtime.log');
    $environment = getenv('APP_ENV') ?: 'development';
    $configuration = ConfigRepository::load($basePath . '/config/settings', $environment, $storage . '/config-' . $environment . '.json');
    $app = Corevia::create($basePath, $store, $logger, $configuration);
    $ledger = new DemoLedger($pdo);
    $controlPort = (int) (getenv('COREVIA_CONTROL_PORT') ?: 8082);
    $broadcast = new SocketBroadcaster($secret, $controlPort);
    $app->container->set(RunStore::class, $store);
    $app->container->set(DemoLedger::class, $ledger);
    $app->container->set(Broadcaster::class, $broadcast);
    $app->container->set(SendConfirmation::class, new SendConfirmation($ledger, $storage . '/notifications.online'));
    $fraudUrl = getenv('COREVIA_FRAUD_URL');
    $app->container->set(FraudCheck::class, new FraudCheck($fraudUrl ? new RemoteAction($fraudUrl, getenv('COREVIA_WORKER_TOKEN') ?: null) : null));
    $disabled = array_filter(explode(',', getenv('COREVIA_DISABLED_MODULES') ?: ''));
    $app->modules([OrdersModule::class, PaymentsModule::class, NotificationsModule::class], $disabled);
    $app->boot();
    $origins = array_filter(explode(',', getenv('COREVIA_ORIGINS') ?: 'http://localhost:8081,http://127.0.0.1:8081'));
    $router = new Router($app, $origins);
    $router->add('GET', '/health', static fn (): \Corevia\Http\Response => new \Corevia\Http\Response(['cvp' => '1.0', 'modules' => $app->registry->health()]));
    $router->flow('POST', '/checkout', 'checkout');
    $apiToken = getenv('COREVIA_API_TOKEN');
    if ($apiToken) {
        $router->actionGateway(['orders.get'], static fn (Request $request): bool => hash_equals('Bearer ' . $apiToken, $request->header('authorization')));
    }
    return compact('app', 'router', 'secret', 'storage', 'origins', 'controlPort');
};
