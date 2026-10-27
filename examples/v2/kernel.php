<?php

declare(strict_types=1);

namespace CoreviaExample;

use Corevia\Core\Action;
use Corevia\Core\ActionResult;
use Corevia\Core\ConfigRepository;
use Corevia\Core\Corevia;
use Corevia\Core\ExecutionContext;
use Corevia\Core\Flow;
use Corevia\Core\ManifestProvider;
use Corevia\Core\Module;
use Corevia\Core\ModuleContext;
use Corevia\Core\ModuleLifecycle;
use Corevia\Core\ModuleManifest;
use Corevia\Core\State;

require dirname(__DIR__, 2) . '/autoload.php';

final readonly class GreetingPolicy
{
    public function __construct(public string $prefix)
    {
    }
}

final class Greet implements Action
{
    public function __construct(private readonly GreetingPolicy $policy)
    {
    }

    public function handle(State $state, ExecutionContext $context): State|ActionResult
    {
        if (!is_string($state->data['name'] ?? null)) {
            return ActionResult::failure('NAME_REQUIRED', 'Supply a name.');
        }
        $state->data['message'] = $this->policy->prefix . ', ' . $state->data['name'];
        return $state;
    }
}

final class GreetingsModule implements Module, ManifestProvider, ModuleLifecycle
{
    public array $visited = [];

    public function name(): string
    {
        return 'greetings';
    }

    public function dependencies(): array
    {
        return [];
    }

    public function manifest(): ModuleManifest
    {
        return new ModuleManifest('greetings', '1.0.0', description: 'A database-free lifecycle example', requires: ['core.flows'], defaults: ['prefix' => 'Hello']);
    }

    public function register(ModuleContext $context): void
    {
        $this->visited[] = 'register';
        $context->actions(['greetings.greet' => Greet::class]);
        $context->flows([Flow::define('greet')->step('greetings.greet')]);
    }

    public function boot(ModuleContext $context): void
    {
        $this->visited[] = 'boot';
        $context->container->singleton(GreetingPolicy::class, fn (): GreetingPolicy => new GreetingPolicy($context->config['prefix']));
    }

    public function ready(ModuleContext $context): void
    {
        $this->visited[] = 'ready';
    }

    public function shutdown(ModuleContext $context): void
    {
        $this->visited[] = 'shutdown';
    }
}

$module = new GreetingsModule();
$config = new ConfigRepository(['modules' => ['greetings' => ['prefix' => 'Welcome']]]);
$app = Corevia::create(__DIR__, config: $config);
$app->modules([$module]);
try {
    $app->boot();
    $run = $app->run('greet', ['name' => 'Corevia developer']);
    echo json_encode(['message' => $run->run['state']['message'], 'kernel' => $app->kernel->state()->value, 'module_hooks' => $module->visited, 'capabilities' => $app->capabilities->all()], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . PHP_EOL;
} finally {
    $errors = $app->shutdown();
    echo json_encode(['kernel' => $app->kernel->state()->value, 'module_hooks' => $module->visited, 'cleanup_errors' => $errors], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . PHP_EOL;
}
