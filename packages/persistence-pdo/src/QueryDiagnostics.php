<?php
declare(strict_types=1);
namespace Corevia\Persistence;
use Corevia\Core\Logger;

/** Opt-in per-request query counters. Bound values and raw SQL never enter logs. */
final class QueryDiagnostics
{
    private array $queries=[];
    private int $dropped=0;
    public function __construct(private readonly Logger $logger, private readonly string $traceId, private readonly int $threshold=5,private readonly int $capacity=1000)
    {
        if ($threshold<2||$capacity<1||$capacity>10000) { throw new \InvalidArgumentException('Diagnostic threshold must be at least two.'); }
    }
    public function record(string $sql, float $durationMs): void
    {
        $fingerprint=hash('sha256',preg_replace('/\s+/',' ',trim($sql)));
        if(!is_finite($durationMs)||$durationMs<0){throw new \InvalidArgumentException('Invalid query duration.');}
        if(!isset($this->queries[$fingerprint])&&count($this->queries)>=$this->capacity){$this->dropped++;return;}
        $entry=$this->queries[$fingerprint] ?? ['count'=>0,'duration_ms'=>0.0];
        $entry['count']++; $entry['duration_ms']+=$durationMs; $this->queries[$fingerprint]=$entry;
        if ($entry['count']===$this->threshold && preg_match('/^\s*SELECT\b/i',$sql)) { $this->logger->log('warning','Repeated query pattern; inspect for N+1',['trace_id'=>$this->traceId,'query_fingerprint'=>$fingerprint,'count'=>$entry['count']]); }
    }
    public function droppedPatterns():int { return $this->dropped; }
    public function snapshot(): array { return $this->queries; }
}
