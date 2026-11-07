<?php
declare(strict_types=1);
namespace Corevia\Auth;
use Corevia\Core\CanonicalJson;
use Corevia\Http\ApiProblem;

/** Reserve/replay public JSON mutations; uncertain effects are never automatically re-executed. */
final readonly class ApiIdempotency
{
    public function __construct(private IdentityStore $store){}
    public function execute(string $actor,string $operation,string $key,array $input,callable $work,?string $tenant=null):array
    {
        if(!preg_match('/^[a-f0-9]{32}$/D',$actor)||!preg_match('/^[a-z][a-z0-9_.:-]{0,63}$/D',$operation)
            ||!preg_match('/^[A-Za-z0-9_-]{8,128}$/D',$key)){throw new \InvalidArgumentException('Invalid idempotency identity.');}
        $wire=CanonicalJson::encode($input);if(strlen($wire)>65536){throw new \LengthException('Idempotent input exceeds 64KiB.');}
        $hash=hash('sha256',$wire);$identity=hash('sha256',CanonicalJson::encode([$actor,$tenant,$operation,$key]));$owner=bin2hex(random_bytes(16));
        $reserved=$this->store->changeState('api-idem:'.$identity,static function(array &$state)use($hash,$owner):?array{
            if($state!==[]){
                if(!hash_equals($state['input_hash'],$hash)){throw new ApiProblem('IDEMPOTENCY_CONFLICT','Key was used for different input.',409);}
                if($state['status']==='completed'){return $state['result'];}
                throw new ApiProblem('IDEMPOTENCY_PENDING','Previous effects require reconciliation.',409);
            }
            // An unfinished operation has no automatic expiry: effects may already have occurred.
            $state=['input_hash'=>$hash,'owner'=>$owner,'status'=>'running'];return null;
        });
        if($reserved!==null){return $reserved;}
        try{
            $result=$work();if(!is_array($result)||strlen(CanonicalJson::encode($result))>16384){throw new \LogicException('Only bounded public JSON results can be replayed.');}
            $this->store->changeState('api-idem:'.$identity,static function(array &$state)use($owner,$result):void{
                if(!hash_equals($state['owner']??'',$owner)||($state['status']??'')!=='running'){throw new \LogicException('Idempotency reservation changed.');}
                $state['result']=$result;$state['status']='completed';$state['expires']=time()+86400;
            });return $result;
        }catch(\Throwable $error){
            $this->store->changeState('api-idem:'.$identity,static function(array &$state)use($owner):void{if(($state['owner']??null)===$owner){$state['status']='uncertain';}});throw $error;
        }
    }
}
