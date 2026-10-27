<?php
declare(strict_types=1);
namespace Corevia\Protocol;
use Corevia\Core\{Action, ActionResult, ExecutionContext, State};

/** Bind this action class to a configured instance in the container. */
final readonly class RemoteActionBinding implements Action
{
    public function __construct(private RemoteAction $remote, private string $target) {}
    public function handle(State $state, ExecutionContext $context): ActionResult
    {
        return $this->remote->call($this->target, $state->data, $context->traceId, $context->requestId);
    }
}
