<?php
declare(strict_types=1);
namespace Corevia\Core;

/** Explicit, bounded, process-local instrumentation. Raw requests, SQL and prompts have no API here. */
final class Telemetry
{
    private array $spans=[];
    private array $metrics=[];
    private int $dropped=0;
    private int $started;
    public function __construct(public readonly string $service,private readonly array $operations,private readonly int $capacity=256,private readonly int $samplePerThousand=1000)
    {
        if (!preg_match('/^[a-zA-Z0-9][a-zA-Z0-9_.-]{0,63}$/D',$service)||count($operations)>128||$capacity<1||$capacity>1024||$samplePerThousand<0||$samplePerThousand>1000) { throw new \InvalidArgumentException('Invalid telemetry bounds.'); }
        $this->started=(int)floor(microtime(true)*1000000)*1000;
        foreach ($operations as $name=>$kind) {
            if (!is_string($name)||!preg_match('/^[a-zA-Z][a-zA-Z0-9_.:-]{0,95}$/D',$name)||!in_array($kind,['internal','server','client','producer','consumer'],true)) { throw new \InvalidArgumentException('Register static operation names and span kinds.'); }
        }
    }
    public function span(string $operation,callable $work,?TraceContext $parent=null):mixed
    {
        if (!isset($this->operations[$operation])) { throw new \InvalidArgumentException('Register this telemetry operation first.'); }
        $parent??=WorkContext::current()?->trace;$context=$parent?->child()??TraceContext::create();
        $sampled=$context->sampled&&hexdec(substr($context->traceId,0,8))%1000<$this->samplePerThousand;
        $context=new TraceContext($context->traceId,$context->spanId,$sampled);
        $start=(int)floor(microtime(true)*1000000)*1000;$mono=hrtime(true);$error=null;
        try {
            // Nested instrumentation inherits its caller's deadline rather than minting a larger budget.
            $budget=WorkContext::current()?->remainingMilliseconds()??3600000;
            return WorkContext::run(new Deadline($budget),fn()=>$work($context),$context);
        } catch (\Throwable $failure) { $error=$failure;throw $failure; }
        finally {
            $elapsed=max(0,hrtime(true)-$mono);$key=$operation.($error===null?':ok':':error');
            $metric=$this->metrics[$key]??['operation'=>$operation,'outcome'=>$error===null?'ok':'error','count'=>0,'duration_ns'=>0];
            $metric['count']++;$metric['duration_ns']+=$elapsed;$this->metrics[$key]=$metric;
            if ($sampled) {
                if (count($this->spans)>=$this->capacity) { $this->dropped++; }
                else { $this->spans[]=['traceId'=>$context->traceId,'spanId'=>$context->spanId,'parentSpanId'=>$parent?->spanId,
                    'name'=>$operation,'kind'=>$this->operations[$operation],'startTimeUnixNano'=>(string)$start,'endTimeUnixNano'=>(string)($start+$elapsed),
                    'errorType'=>$error===null?null:$error::class]; }
            }
        }
    }
    public function snapshot():array { return ['service'=>$this->service,'start_unix_ns'=>(string)$this->started,'end_unix_ns'=>(string)max($this->started,(int)floor(microtime(true)*1000000)*1000),'metrics'=>array_values($this->metrics),'spans'=>$this->spans,'dropped_spans'=>$this->dropped]; }
    /** Explicit drain for a bounded worker batch/request. Failed exporters must not create unbounded queues. */
    public function drain():array { $snapshot=$this->snapshot();$this->spans=[];$this->metrics=[];$this->dropped=0;$this->started=(int)floor(microtime(true)*1000000)*1000;return $snapshot; }
    public function prometheus():string
    {
        $lines=['# TYPE corevia_operations_total counter','# TYPE corevia_operation_duration_seconds_sum counter'];
        foreach ($this->metrics as $metric) {
            $labels='{service="'.$this->service.'",operation="'.$metric['operation'].'",outcome="'.$metric['outcome'].'"}';
            $lines[]='corevia_operations_total'.$labels.' '.$metric['count'];
            $lines[]='corevia_operation_duration_seconds_sum'.$labels.' '.sprintf('%.9F',$metric['duration_ns']/1e9);
        }
        $lines[]='# TYPE corevia_trace_dropped_total counter';$lines[]='corevia_trace_dropped_total '.$this->dropped;
        return implode("\n",$lines)."\n";
    }
}
