<?php
declare(strict_types=1);
namespace Corevia\Testing;
use Corevia\Core\{JobClaim,WorkQueue};
final class FakeQueue implements WorkQueue
{
    private array $jobs=[];
    public function __construct(private readonly Clock $time=new Clock()){Guard::development();}
    public function clock():int{return ($this->time)();}
    public function dispatch(string $task,array $input,bool $idempotent,int $now):string
    {
        if(!$idempotent){throw new \LogicException('Explicit idempotent handlers are required.');}
        $id=bin2hex(random_bytes(16));$this->jobs[$id]=['task'=>$task,'input'=>$input,'ready'=>$now,'status'=>'pending','attempt'=>0,'token'=>'','lease'=>0];return $id;
    }
    public function claim(int $now,int $seconds=60):?JobClaim
    {
        if($seconds<10||$seconds>3600){throw new \InvalidArgumentException('Lease lifetime must be 10..3600 seconds.');}
        foreach($this->jobs as $id=>&$job){
            if($job['ready']>$now||!($job['status']==='pending'||$job['status']==='claimed'&&$job['lease']<=$now)){continue;}
            $job['status']='claimed';$job['token']=bin2hex(random_bytes(16));$job['lease']=$now+$seconds;$job['attempt']++;
            return new JobClaim($id,$job['task'],$job['attempt'],$job['input'],$job['token']);
        }return null;
    }
    private function owns(JobClaim $claim,int $now):bool{return isset($this->jobs[$claim->id])&&$this->jobs[$claim->id]['status']==='claimed'&&$this->jobs[$claim->id]['lease']>$now&&hash_equals($this->jobs[$claim->id]['token'],$claim->token());}
    public function renew(JobClaim $claim,int $now,int $seconds=60):bool{if($seconds<10||$seconds>3600){throw new \InvalidArgumentException('Lease lifetime must be 10..3600 seconds.');}if(!$this->owns($claim,$now)){return false;}$this->jobs[$claim->id]['lease']=$now+$seconds;return true;}
    public function complete(JobClaim $claim,array $result,int $now):bool{if(!$this->owns($claim,$now)){return false;}$this->jobs[$claim->id]['status']='completed';$this->jobs[$claim->id]['result']=$result;return true;}
    public function fail(JobClaim $claim,int $now,bool $permanent=false):bool{if(!$this->owns($claim,$now)){return false;}$this->jobs[$claim->id]['status']=$permanent?'failed':'pending';return true;}
    public function jobs():array{return $this->jobs;}
}
