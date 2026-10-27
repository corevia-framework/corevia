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
        private readonly ?ParallelExecutor $parallel = null,
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
            'definition_hash' => $this->fingerprint($flow), 'next_step' => 0, 'timeline' => [], 'compensations' => [],
        ];
        $reserved = $this->store->begin($run, $idempotencyKey);
        if ($reserved['run_id'] !== $run['run_id']) {
            if ($reserved['input_hash'] !== $run['input_hash']) {
                throw new \DomainException('IDEMPOTENCY_CONFLICT');
            }
            return new FlowResult($reserved);
        }
        return $this->advance($flow, $state, $run);
    }

    private function advance(Flow $flow, State $state, array $run): FlowResult
    {
        foreach ($flow->steps as $index => $step) {
            if ($index < $run['next_step']) { continue; }
            if ($step->checkpoint !== null) {
                $run['status'] = 'paused';
                $run['checkpoint'] = ['name' => $step->checkpoint, 'event' => $step->waitEvent];
                $run['steps'][$index] = ['action' => '@checkpoint', 'status' => 'paused', 'critical' => true, 'attempts' => 0, 'duration_ms' => 0, 'error' => null];
                $run['next_step'] = $index + 1;
                $run['timeline'][] = ['type' => 'paused', 'step' => $index, 'at' => gmdate('c')];
                $this->store->save($run);
                return new FlowResult($run);
            }
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
                [$record, $state] = $step->parallelActions ? $this->executeParallel($step, $state, $run) : $this->execute($step, $state, $run);
                $run['steps'][$index] = $record;
                $run['state'] = $state->jsonSerialize();
                if ($record['status'] === 'failed' && $step->isCritical) {
                    $run['status'] = 'failed';
                    $run['error'] = $record['error'];
                }
            }
            $run['next_step'] = $index + 1;
            $run['timeline'][] = ['type' => $run['steps'][$index]['status'], 'step' => $index, 'at' => gmdate('c')];
            $this->store->save($run);
            if ($run['status'] === 'failed') {
                break;
            }
        }
        if ($run['status'] === 'failed') { $run = $this->compensate($flow, $run); }
        if ($run['status'] !== 'failed') {
            $run['status'] = count(array_filter($run['steps'], static fn (array $s): bool => $s['status'] === 'failed')) > 0 ? 'completed_with_warnings' : 'completed';
        }
        $run['finished_at'] = gmdate('c');
        $this->store->save($run);
        return new FlowResult($run);
    }

    /** Resume only through an authorized application boundary; approvals are never automatic. */
    public function resume(Flow $flow, string $runId, ?string $event = null): FlowResult
    {
        if (!$this->store->lock($runId)) { throw new \DomainException('RUN_BUSY'); }
        try {
            $run = $this->store->get($runId) ?? throw new \OutOfBoundsException('Run not found.');
            if ($run['status'] !== 'paused' || $run['flow_name'] !== $flow->name) { throw new \DomainException('RUN_NOT_PAUSED'); }
            if (($run['definition_hash'] ?? null) !== $this->fingerprint($flow)) { throw new \DomainException('FLOW_DEFINITION_CHANGED'); }
            if (($run['checkpoint']['event'] ?? null) !== $event) { throw new \DomainException('EVENT_MISMATCH'); }
            $index = $run['next_step'] - 1;
            $run['steps'][$index]['status'] = 'completed'; $run['status'] = 'running'; unset($run['checkpoint']);
            $run['timeline'][] = ['type' => 'resumed', 'step' => $index, 'at' => gmdate('c')];
            $this->store->save($run);
            return $this->advance($flow, new ($flow->stateClass)($run['state']), $run);
        } finally { $this->store->unlock($runId); }
    }

    private function fingerprint(Flow $flow): string
    {
        $steps = [];
        foreach ($flow->steps as $step) { $values = get_object_vars($step); $values['condition'] = $step->condition !== null; $steps[] = $values; }
        return hash('sha256', json_encode([$flow->name, $flow->stateClass, $steps], JSON_THROW_ON_ERROR));
    }

    private function compensate(Flow $flow, array $run): array
    {
        $run['status'] = 'compensating'; $this->store->save($run);
        foreach (array_reverse(array_keys($run['steps'])) as $index) {
            $step = $flow->steps[$index];
            if ($run['steps'][$index]['status'] !== 'completed' || $step->compensation === null) { continue; }
            // Persist intent first: a crash leaves an inspectable uncertain operation, never an automatic replay.
            $run['compensations'][$index] = ['action' => $step->compensation, 'status' => 'running']; $this->store->save($run);
            [$record, $state] = $this->execute(new Step($step->compensation), new ($flow->stateClass)($run['state']), $run);
            $run['compensations'][$index] = $record; $run['state'] = $state->jsonSerialize();
            $run['timeline'][] = ['type' => 'compensation_' . $record['status'], 'step' => $index, 'at' => gmdate('c')];
            $this->store->save($run);
        }
        $run['status'] = 'failed'; return $run;
    }

    private function executeParallel(Step $step, State $state, array $run): array
    {
        $failure = fn (string $code): array => [['action' => '@parallel', 'status' => 'failed', 'critical' => $step->isCritical, 'attempts' => 0, 'duration_ms' => 0, 'error' => ['code' => $code, 'message' => 'Parallel group could not commit.', 'retryable' => false]], $state];
        if ($this->parallel === null) { return $failure('PARALLEL_UNAVAILABLE'); }
        $tasks = [];
        foreach ($step->parallelActions as $action) { $tasks[] = fn (): array => $this->execute(new Step($action), new ($state::class)($state->data), $run); }
        $start = hrtime(true);
        try { $results = $this->parallel->execute($tasks); } catch (\Throwable) { return $failure('PARALLEL_EXCEPTION'); }
        if (count($results) !== count($tasks)) { return $failure('PARALLEL_INVALID_RESULT'); }
        $merged = $state->data; $written = []; $records = [];
        foreach ($results as [$record, $candidate]) {
            $records[] = $record;
            if ($record['status'] !== 'completed' || !$candidate instanceof $state) { return $failure('PARALLEL_BRANCH_FAILED'); }
            foreach (array_unique([...array_keys($state->data), ...array_keys($candidate->data)]) as $key) {
                if (array_key_exists($key, $state->data) === array_key_exists($key, $candidate->data) && ($state->data[$key] ?? null) === ($candidate->data[$key] ?? null)) { continue; }
                if (isset($written[$key])) { return $failure('PARALLEL_OUTPUT_CONFLICT'); }
                $written[$key] = true;
                if (array_key_exists($key, $candidate->data)) { $merged[$key] = $candidate->data[$key]; } else { unset($merged[$key]); }
            }
        }
        return [['action' => '@parallel', 'status' => 'completed', 'critical' => $step->isCritical, 'attempts' => 1, 'duration_ms' => (hrtime(true) - $start) / 1e6, 'error' => null, 'branches' => $records], new ($state::class)($merged)];
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
            $attemptStart = hrtime(true);
            $result = $entry === null ? ActionResult::failure('ACTION_NOT_FOUND', 'Action is not registered.') : $this->runner->run($entry['class'], $candidate, $context, $step->retryableExceptions);
            if ($step->timeoutMs > 0 && (hrtime(true) - $attemptStart) / 1e6 > $step->timeoutMs) {
                // In-process PHP is cooperative: elapsed deadline failure must never automatically replay side effects.
                $result = ActionResult::failure('ACTION_TIMEOUT', 'Action exceeded its deadline.');
            }
            $attempt++;
            if ($result->ok) {
                $before = $state;
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
                foreach ($step->outputKeys as $key) {
                    if (!array_key_exists($key, $state->data)) { $state = $before; $result = ActionResult::failure('OUTPUT_CONTRACT', 'Required action output is missing.'); break; }
                }
            }
            if ($result->ok || !$result->error?->retryable || $attempt > $step->retries) {
                break;
            }
            $delay = min(30000, $step->delayMs * ($step->exponential ? 2 ** ($attempt - 1) : 1));
            if ($step->jitter) { $delay = random_int(0, (int) $delay); }
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
