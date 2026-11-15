<?php
declare(strict_types=1);
namespace Corevia\Core;

/** Explicitly retry-safe operations only. Results are buffered; never replay a partially emitted stream. */
final readonly class RetryExecutor
{
    private \Closure $sleep;
    private \Closure $random;
    public function __construct(private ResilienceGate $gate,private int $attempts=3,private int $baseDelayMs=100,
        private int $maxDelayMs=5000,?callable $sleep=null,?callable $random=null)
    {
        if ($attempts<1||$attempts>10||$baseDelayMs<0||$baseDelayMs>10000||$maxDelayMs<$baseDelayMs||$maxDelayMs>60000) {
            throw new \InvalidArgumentException('Invalid retry bounds.');
        }
        $this->sleep=\Closure::fromCallable($sleep??static fn(int $ms)=>usleep($ms*1000));
        $this->random=\Closure::fromCallable($random??random_int(...));
    }

    /** The application classifies transient failures; uncertain non-idempotent effects must not retry. */
    public function run(callable $operation,Deadline $deadline,bool $retrySafe,callable $transient):mixed
    {
        for ($attempt=1;$attempt<=$this->attempts;$attempt++) {
            $deadline->check();$permit=$this->gate->enter();
            try { $result=$operation($attempt,$deadline);$deadline->check(); }
            catch (\Throwable $error) {
                $this->gate->fail($permit);
                $deadline->check();
                if (!$retrySafe||$attempt===$this->attempts||!$transient($error)) { throw $error; }
                $ceiling=min($this->maxDelayMs,$this->baseDelayMs*(2**($attempt-1)));
                $delay=($this->random)(0,$ceiling);
                if (!is_int($delay)||$delay<0||$delay>$ceiling) { throw new \LogicException('Invalid retry jitter.'); }
                if ($delay>=$deadline->remainingMilliseconds()) { throw new \RuntimeException('RETRY_DEADLINE_EXHAUSTED'); }
                if (!$this->gate->takeRetry()) { throw new \RuntimeException('RETRY_BUDGET_EXHAUSTED'); }
                // Cancellation remains responsive during backoff, including custom sleepers.
                while ($delay>0) { $slice=min(50,$delay);$deadline->check();($this->sleep)($slice);$delay-=$slice; }
                continue;
            }
            $this->gate->succeed($permit);return $result;
        }
        throw new \LogicException('Unreachable retry state.');
    }
}
