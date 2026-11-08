<?php
declare(strict_types=1);
namespace Corevia\Persistence;

use Corevia\Core\{Deadline,ScheduleEntry,ScopeManager,WorkContext};

/** A dedicated scheduler queue; same named scope must use the same definitions on every host. */
final readonly class SqlScheduler
{
    private array $entries;
    private string $queueTask;

    public function __construct(private SqlCoordinator $queue,private ScopeManager $scopes,private string $name,array $entries)
    {
        if (!preg_match('/^[a-z][a-z0-9_.-]{0,47}$/D',$name)||count($entries)>100) { throw new \InvalidArgumentException('Invalid scheduler definition.'); }
        $definitions=[];
        foreach ($entries as $entry) {
            if (!$entry instanceof ScheduleEntry||isset($definitions[$entry->id])) { throw new \InvalidArgumentException('Schedules require unique explicit IDs.'); }
            $definitions[$entry->id]=$entry;
        }
        ksort($definitions,SORT_STRING);$this->entries=$definitions;$this->queueTask='corevia.schedule.'.$name;
    }

    /** One bounded cron invocation. Uses the database clock; intentionally does not catch up missed minutes. */
    public function tick():array
    {
        $now=$this->queue->clock();$jobs=[];
        foreach ($this->entries as $entry) {
            $occurrence=$entry->occurrence($now);
            if ($occurrence===null) { continue; }
            $key='schedule:'.$this->name.':'.$entry->id.':'.$occurrence;
            $jobs[$entry->id]=$this->queue->dispatchOnce($key,$this->queueTask,[
                'entry'=>$entry->id,'definition'=>$entry->fingerprint,'occurrence'=>$occurrence,
            ],true,$now);
        }
        return $jobs;
    }

    /** Handlers must be explicitly supplied and idempotent. Overlapping occurrences are skipped. */
    public function workOnce(array $handlers):array
    {
        $now=$this->queue->clock();$claim=$this->queue->claim($now,3600);
        if ($claim===null) { return ['status'=>'idle']; }
        $input=$claim->input();$entry=is_string($input['entry']??null)?($this->entries[$input['entry']]??null):null;
        if ($claim->task!==$this->queueTask||$entry===null||($input['definition']??null)!==$entry->fingerprint
            ||!is_callable($handlers[$entry->task]??null)) {
            $this->queue->fail($claim,$now,true);return ['status'=>'invalid_definition','job_id'=>$claim->id];
        }
        $lease=$this->queue->acquire('schedule:'.$this->name.':'.$entry->id,$now,$entry->timeoutSeconds+60);
        if ($lease===null) {
            $ack=$this->queue->complete($claim,['skipped'=>'overlap'],$this->queue->clock());
            return ['status'=>$ack?'skipped_overlap':'lost_ownership','job_id'=>$claim->id];
        }
        try {
            $deadline=new Deadline($entry->timeoutSeconds*1000);
            $result=$this->scopes->run('job',
                fn($scope)=>WorkContext::run($deadline,fn()=>$handlers[$entry->task]($entry->input(),$claim,$deadline,$scope,$lease->fence)),
                ['job_id'=>$claim->id,'schedule_id'=>$entry->id]);
            if (!is_array($result)) { throw new \LogicException('Scheduled handlers return an array.'); }
            $deadline->check();$finished=$this->queue->clock();
            if (!$this->queue->renewLease($lease,$finished,10)) { return ['status'=>'lost_ownership','job_id'=>$claim->id]; }
            $ack=$this->queue->complete($claim,$result,$finished);
            return ['status'=>$ack?'completed':'lost_ownership','job_id'=>$claim->id];
        } catch (\Throwable $error) {
            $ack=$this->queue->fail($claim,$this->queue->clock());
            return ['status'=>$ack?'failed':'lost_ownership','job_id'=>$claim->id,'error_class'=>$error::class];
        } finally {
            $this->queue->release($lease,$this->queue->clock());
        }
    }
}
