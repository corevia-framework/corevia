<?php

declare(strict_types=1);

namespace Corevia\Core;

final class ActionRunner
{
    public function __construct(private readonly Container $container)
    {
    }

    public function run(string $class, State $state, ExecutionContext $context): ActionResult
    {
        $start = hrtime(true);
        try {
            $action = $this->container->get($class);
            if (!$action instanceof Action) {
                throw new \InvalidArgumentException('Not a Corevia action.');
            }
            $output = $action->handle($state, $context);
            $result = $output instanceof ActionResult ? $output : ActionResult::success($output);
        } catch (\Throwable $exception) {
            $context->logger->log('error', 'Unexpected action failure', ['module' => $context->module, 'flow' => $context->flow, 'action' => $class, 'run_id' => $context->runId, 'trace_id' => $context->traceId, 'exception' => $exception::class]);
            $result = ActionResult::failure('ACTION_EXCEPTION', 'Action execution failed.');
        }
        $context->logger->log($result->ok ? 'info' : 'warning', 'Action executed', ['module' => $context->module, 'flow' => $context->flow, 'action' => $class, 'run_id' => $context->runId, 'trace_id' => $context->traceId, 'request_id' => $context->requestId, 'duration_ms' => (hrtime(true) - $start) / 1e6, 'error_code' => $result->error?->code]);
        return $result;
    }
}
