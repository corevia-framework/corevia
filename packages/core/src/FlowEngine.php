<?php

declare(strict_types=1);

namespace Corevia\Core;

final class FlowEngine
{
    /** @param array<string, array{class: string, module: string}> $actions */
    public function __construct(
        private readonly ActionRunner $runner,
        private readonly RunStore $store,
        private readonly EventBus $events,
        private readonly Logger $logger,
        private readonly array $actions,
    ) {
    }

    public function run(Flow $flow, State $state, ?string $idempotencyKey = null, ?string $traceId = null, ?string $requestId = null): FlowResult
    {
        if (!$state instanceof $flow->stateClass) {
            throw new \InvalidArgumentException('Invalid state type for ' . $flow->name);
        }
        $run = [
            'run_id' => 'run_' . bin2hex(random_bytes(12)), 'flow_name' => $flow->name,
            'status' => 'running', 'started_at' => gmdate('c'), 'finished_at' => null,
            'trace_id' => $traceId ?? bin2hex(random_bytes(16)), 'request_id' => $requestId ?? bin2hex(random_bytes(12)),
            'input_hash' => hash('sha256', json_encode($state, JSON_THROW_ON_ERROR)),
            'state' => $state->jsonSerialize(), 'steps' => [], 'error' => null,
        ];
        $reserved = $this->store->begin($run, $idempotencyKey);
        if ($reserved['run_id'] !== $run['run_id']) {
            if ($reserved['input_hash'] !== $run['input_hash']) {
                throw new \DomainException('IDEMPOTENCY_CONFLICT');
            }
            return new FlowResult($reserved);
        }
        foreach ($flow->steps as $index => $step) {
            try {
                $shouldRun = $step->condition === null || ($step->condition)($state);
            } catch (\Throwable $exception) {
                $this->logger->log('error', 'Flow condition failed', ['run_id' => $run['run_id'], 'trace_id' => $run['trace_id'], 'exception' => $exception::class]);
                $run['status'] = 'failed';
                $run['error'] = ['code' => 'CONDITION_EXCEPTION', 'message' => 'Flow condition failed.', 'retryable' => false];
                break;
            }
            if (!$shouldRun) {
                $run['steps'][$index] = ['action' => $step->action, 'status' => 'skipped', 'critical' => $step->isCritical, 'attempts' => 0, 'duration_ms' => 0, 'error' => null];
            } else {
                [$record, $state] = $this->execute($step, $state, $run);
                $run['steps'][$index] = $record;
                $run['state'] = $state->jsonSerialize();
                if ($record['status'] === 'failed' && $step->isCritical) {
                    $run['status'] = 'failed';
                    $run['error'] = $record['error'];
                }
            }
            $this->store->save($run);
            if ($run['status'] === 'failed') {
                break;
            }
        }
        if ($run['status'] !== 'failed') {
            $run['status'] = count(array_filter($run['steps'], static fn (array $s): bool => $s['status'] === 'failed')) > 0 ? 'completed_with_warnings' : 'completed';
        }
        $run['finished_at'] = gmdate('c');
        $this->store->save($run);
        return new FlowResult($run);
    }

    private function execute(Step $step, State $state, array $run): array
    {
        $entry = $this->actions[$step->action] ?? null;
        if ($entry === null) {
            foreach ($this->actions as $registered) {
                if ($registered['class'] === $step->action) {
                    $entry = $registered;
                    break;
                }
            }
        }
        $context = new ExecutionContext($run['run_id'], $run['trace_id'], $run['flow_name'], $entry['module'] ?? 'unknown', $this->events, $this->logger, $run['request_id']);
        $start = hrtime(true);
        $attempt = 0;
        do {
            // Failed attempts cannot mutate the state passed to later steps.
            $candidate = new ($state::class)(json_decode(json_encode($state, JSON_THROW_ON_ERROR), true, 512, JSON_THROW_ON_ERROR));
            $result = $entry === null ? ActionResult::failure('ACTION_NOT_FOUND', 'Action is not registered.') : $this->runner->run($entry['class'], $candidate, $context);
            $attempt++;
            if ($result->ok) {
                if ($result->data instanceof State) {
                    if (!$result->data instanceof $state) {
                        $result = ActionResult::failure('INVALID_STATE', 'Action returned the wrong state type.');
                    } else {
                        $state = $result->data;
                    }
                } elseif ($result->data !== null) {
                    $state->data[$step->action] = $result->data;
                } else {
                    $state = $candidate;
                }
            }
            if ($result->ok || !$result->error?->retryable || $attempt > $step->retries) {
                break;
            }
            $delay = min(30000, $step->delayMs * ($step->exponential ? 2 ** ($attempt - 1) : 1));
            usleep((int) $delay * 1000);
        } while (true);
        return [[
            'action' => $step->action, 'status' => $result->ok ? 'completed' : 'failed',
            'critical' => $step->isCritical, 'attempts' => $attempt,
            'retry_limit' => $step->retries, 'duration_ms' => (hrtime(true) - $start) / 1e6,
            'error' => $result->error?->jsonSerialize(),
        ], $state];
    }

    /** Retry only a failed non-critical step using the latest persisted state. */
    public function retry(Flow $flow, string $runId, int $index): FlowResult
    {
        if (!$this->store->lock($runId)) {
            throw new \DomainException('RUN_BUSY');
        }
        try {
            $run = $this->store->get($runId) ?? throw new \OutOfBoundsException('Run not found.');
            $step = $flow->steps[$index] ?? throw new \OutOfBoundsException('Step not found.');
            if ($run['flow_name'] !== $flow->name || !in_array($run['status'], ['completed_with_warnings', 'failed'], true) || $step->isCritical || ($run['steps'][$index]['status'] ?? '') !== 'failed') {
                throw new \DomainException('Only failed non-critical steps can be retried.');
            }
            [$record, $state] = $this->execute($step, new ($flow->stateClass)($run['state']), $run);
            $record['attempts'] += $run['steps'][$index]['attempts'];
            $run['steps'][$index] = $record;
            $run['state'] = $state->jsonSerialize();
            if ($run['status'] !== 'failed') {
                $run['status'] = count(array_filter($run['steps'], static fn (array $s): bool => $s['status'] === 'failed')) > 0 ? 'completed_with_warnings' : 'completed';
            }
            $this->store->save($run);
            return new FlowResult($run);
        } finally {
            $this->store->unlock($runId);
        }
    }
}
