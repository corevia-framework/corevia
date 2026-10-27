<?php
declare(strict_types=1);
namespace CoreviaUpgradeFixture;
use Corevia\Core\{Action,ExecutionContext,Flow,Module,ModuleContext,State};

// Representative V1 application: no manifests, lifecycle hooks, or V2 configuration APIs.
final class LegacyAction implements Action
{
    public function handle(State $state,ExecutionContext $context): State { $state->data['v1_result']='still works'; return $state; }
}
final class LegacyModule implements Module
{
    public function name(): string { return 'legacy'; }
    public function dependencies(): array { return []; }
    public function register(ModuleContext $context): void
    {
        $context->actions(['legacy.action'=>LegacyAction::class]);
        $context->flows([Flow::define('legacy.flow')->step('legacy.action')->critical()->retry(2)]);
    }
}
