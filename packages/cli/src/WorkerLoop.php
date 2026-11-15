<?php
declare(strict_types=1);
namespace Corevia\Cli;

use Corevia\Core\{Deadline,WorkContext};

/** Bounded foreground batches for cron/supervisors; handlers must cooperate with deadlines. */
final class WorkerLoop
{
    public static function run(callable $once,int $maximum=100,?int $budgetMilliseconds=null):array
    {
        if($maximum<1||$maximum>1000){throw new \InvalidArgumentException('Worker maximum must be 1..1000.');}
        $deadline=$budgetMilliseconds===null?null:new Deadline($budgetMilliseconds);
        $processed=[];$stopped='maximum';
        for($index=0;$index<$maximum;$index++){
            try{$deadline?->check();WorkContext::current()?->check();}
            catch(\RuntimeException){$stopped='budget';break;}
            $result=null;
            try{
                if($deadline===null){$result=$once();}
                else{WorkContext::run($deadline,function()use($once,&$result):void{$result=$once();});}
            }catch(\Throwable $error){
                // Preserve a completed queue acknowledgment if only the post-call budget check expired.
                if($result===null){throw $error;}
                try{$deadline?->check();WorkContext::current()?->check();}
                catch(\RuntimeException){$stopped='budget';}
                if($stopped!=='budget'){throw $error;}
            }
            if(!is_array($result)||!is_string($result['status']??null)){throw new \LogicException('Worker returns a status array.');}
            if($result['status']==='empty'){$stopped='empty';break;}
            $processed[]=$result;
            if($stopped==='budget'){break;}
        }
        return ['processed'=>$processed,'stopped'=>$stopped];
    }
}
