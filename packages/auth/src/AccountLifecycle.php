<?php
declare(strict_types=1);
namespace Corevia\Auth;
use Corevia\Core\CanonicalJson;
use Corevia\Http\Request;

/** Explicit application export and resumable erasure; work/status/cancel are operator APIs. */
final readonly class AccountLifecycle
{
    private array $adapters;
    private string $plan;
    private \Closure $clock;
    private ?\Closure $audit;
    public function __construct(private IdentityStore $store,private AuthService $auth,array $adapters,private string $receiptKey,
        ?callable $clock=null,?callable $audit=null,private int $receiptTtl=2592000,private int $leaseSeconds=300)
    {
        if(strlen($receiptKey)<32||!array_is_list($adapters)||count($adapters)>32||$receiptTtl<60||$receiptTtl>7776000||$leaseSeconds<30||$leaseSeconds>3600){throw new \InvalidArgumentException('Invalid account lifecycle policy.');}
        $registered=[];$versions=[];
        foreach($adapters as $adapter){
            if(!$adapter instanceof AccountDataAdapter||!preg_match('/^[a-z][a-z0-9_-]{0,31}$/D',$adapter->name())||$adapter->name()==='identity'||!preg_match('/^[a-zA-Z0-9][a-zA-Z0-9_.:-]{0,63}$/D',$adapter->revision())||isset($registered[$adapter->name()])){throw new \InvalidArgumentException('Register unique, versioned application data adapters.');}
            $registered[$adapter->name()]=$adapter;$versions[$adapter->name()]=$adapter->revision();
        }
        ksort($registered,SORT_STRING);ksort($versions,SORT_STRING);$this->adapters=$registered;$this->plan=hash('sha256',CanonicalJson::encode($versions));
        $this->clock=\Closure::fromCallable($clock??time(...));$this->audit=$audit===null?null:\Closure::fromCallable($audit);
    }
    private function now():int{return ($this->clock)();}
    private function subject(string $id):string{return hash_hmac('sha256','account-lifecycle|'.$id,$this->receiptKey);}
    private function receipt(string $operation):string{if(!preg_match('/^[a-f0-9]{32}$/D',$operation)){throw new \InvalidArgumentException('Invalid erasure operation ID.');}return 'account-lifecycle:'.$operation;}
    private function emit(string $event,string $id,string $operation,?string $adapter=null):void
    {
        if($this->audit===null){return;}$metadata=['operation_id'=>$operation,'subject_ref'=>$this->subject($id)];if($adapter!==null){$metadata['adapter']=$adapter;}
        ($this->audit)($event,$metadata,hash('sha256',$operation.'|'.$event.'|'.$adapter));
    }
    public function export(Request $request,AuthContext $context):array
    {
        $user=$this->auth->confirm($request,$context);$bundle=['format'=>1,'identity'=>array_intersect_key($user,array_flip(['id','name','email','email_verified_at'])),'application'=>[]];$bytes=0;
        foreach($this->adapters as $name=>$adapter){$value=$adapter->export($user['id']);$size=strlen(json_encode($value,JSON_THROW_ON_ERROR,32));$bytes+=$size;if($size>1048576||$bytes>8388608){throw new \LengthException('Use a bounded application-specific archive workflow for large exports.');}$bundle['application'][$name]=$value;}
        if($this->store->authority($user['id'],$context->digest,$this->now())===null){throw new AuthFailure('UNAUTHENTICATED','Account authority changed. Sign in again.',401);}
        $this->emit('account.exported',$user['id'],bin2hex(random_bytes(16)));return $bundle;
    }
    public function requestDeletion(Request $request,AuthContext $context):array
    {
        $input=$request->input();if(array_diff(array_keys($input),['_token','current_password','code','confirmation'])||($input['confirmation']??null)!=='DELETE'){throw new AuthFailure('VALIDATION','Type DELETE to request account erasure.');}
        $user=$this->auth->confirm($request,$context);$operation=bin2hex(random_bytes(16));$key=$this->receipt($operation);$subject=$this->subject($user['id']);
        $this->store->changeState($key,static function(array &$state)use($subject):void{if($state!==[]){throw new \LogicException('Erasure operation collision.');}$state=['subject'=>$subject,'status'=>'preparing'];});
        try{$this->store->changeIdentity($user['id'],$user['auth_version']??0,function(array &$row,array &$security)use($operation):IdentityChange{
            if(isset($security['lifecycle_deletion'])){throw new AuthFailure('DELETION_PENDING','Erasure is already requested.',409);}
            $security['lifecycle_deletion']=['operation'=>$operation,'plan'=>$this->plan,'status'=>'pending','completed'=>[],'irreversible'=>false,'owner'=>'','lease'=>0];return new IdentityChange(null,true);
        });}catch(\Throwable $error){$this->store->changeState($key,static function(array &$state):void{$state['status']='reconciliation_required';});throw $error;}
        try{$this->record($user['id'],$operation,'pending');$this->emit('account.deletion.requested',$user['id'],$operation);}
        catch(\Throwable){return ['operation_id'=>$operation,'status'=>'pending','audit_or_receipt_pending'=>true];}
        return ['operation_id'=>$operation,'status'=>'pending'];
    }
    private function record(string $id,string $operation,string $status):void
    {
        $subject=$this->subject($id);$expires=$this->now()+$this->receiptTtl;
        $this->store->changeState($this->receipt($operation),static function(array &$state)use($subject,$status,$expires):void{
            if($state!==[]&&($state['subject']??null)!==$subject){throw new \LogicException('Erasure receipt subject mismatch.');}
            $state=['subject'=>$subject,'status'=>$status];if(in_array($status,['complete','cancelled'],true)){$state['expires']=$expires;}
        });
    }
    private function user(string $id):array{return $this->store->userById($id)??throw new \RuntimeException('Account identity is absent; reconcile the erasure receipt.');}
    private function job(string $id,string $operation):array
    {
        $job=$this->store->state('user:'.$id)['lifecycle_deletion']??[];
        if(($job['operation']??null)!==$operation||($job['plan']??null)!==$this->plan){throw new \RuntimeException('Erasure operation/adapter plan changed; operator review is required.');}return $job;
    }
    private function progress(string $id,string $operation,string $owner,callable $change):array
    {
        $user=$this->user($id);$now=$this->now();
        return $this->store->changeIdentity($id,$user['auth_version']??0,function(array &$row,array &$security)use($operation,$owner,$change,$now):IdentityChange{
            $job=&$security['lifecycle_deletion'];if(($job['operation']??null)!==$operation||($job['plan']??null)!==$this->plan||($job['owner']??null)!==$owner||($job['lease']??0)<=$now){throw new \RuntimeException('Erasure worker lease was lost.');}
            $change($job);return new IdentityChange($job);
        })['value'];
    }
    public function work(string $id,string $operation):array
    {
        $receipt=$this->store->state($this->receipt($operation));if(($receipt['subject']??null)!==$this->subject($id)){throw new \RuntimeException('Erasure receipt subject mismatch.');}
        if($this->store->userById($id)===null){if(!in_array($receipt['status']??null,['erasure_complete','complete'],true)){throw new \RuntimeException('Missing account requires erasure reconciliation.');}$this->record($id,$operation,'complete');$this->emit('account.deletion.completed',$id,$operation);return ['status'=>'complete'];}
        $this->job($id,$operation);$user=$this->user($id);$owner=bin2hex(random_bytes(16));$now=$this->now();
        $this->store->changeIdentity($id,$user['auth_version']??0,function(array &$row,array &$security)use($operation,$owner,$now):IdentityChange{
            $job=&$security['lifecycle_deletion'];if(($job['operation']??null)!==$operation||($job['plan']??null)!==$this->plan){throw new \RuntimeException('Erasure plan changed.');}
            if(($job['owner']??'')!==''&&($job['lease']??0)>$now){throw new \RuntimeException('Another erasure worker owns this operation.');}
            $job['owner']=$owner;$job['lease']=$now+$this->leaseSeconds;$job['status']='processing';return new IdentityChange();
        });
        try{
            $this->emit('account.deletion.requested',$id,$operation);
            // Check every registered store before the first irreversible step.
            foreach($this->adapters as $adapter){$adapter->assertDeletable($id);}
            foreach($this->adapters as $name=>$adapter){
                $job=$this->progress($id,$operation,$owner,function(array &$job):void{$job['lease']=$this->now()+$this->leaseSeconds;});if(in_array($name,$job['completed'],true)){$this->emit('account.deletion.step',$id,$operation,$name);continue;}
                $this->progress($id,$operation,$owner,static function(array &$job):void{$job['irreversible']=true;});$adapter->erase($id,$operation.':'.$name);
                $this->progress($id,$operation,$owner,static function(array &$job)use($name):void{$job['completed'][]=$name;});$this->emit('account.deletion.step',$id,$operation,$name);
            }
            $this->progress($id,$operation,$owner,static function(array &$job):void{$job['irreversible']=true;});$this->record($id,$operation,'erasure_complete');
            $user=$this->user($id);$now=$this->now();$this->store->changeIdentity($id,$user['auth_version']??0,function(array &$row,array &$security)use($operation,$owner,$now):IdentityChange{
                $job=$security['lifecycle_deletion']??[];if(($job['operation']??null)!==$operation||($job['owner']??null)!==$owner||($job['lease']??0)<=$now||count($job['completed']??[])!==count($this->adapters)){throw new \RuntimeException('Erasure completion lost worker ownership.');}return new IdentityChange(null,true,true);
            });
            $this->record($id,$operation,'complete');$this->emit('account.deletion.completed',$id,$operation);return ['status'=>'complete'];
        }catch(\Throwable $error){
            if($this->store->userById($id)===null){throw $error;}
            $status=$error instanceof DataRetentionHold?'held':'failed';
            $this->progress($id,$operation,$owner,static function(array &$job)use($status,$error):void{$job['status']=$status;$job['error_class']=$error::class;$job['owner']='';$job['lease']=0;});
            return ['status'=>$status,'error_code'=>$error instanceof DataRetentionHold?'RETENTION_HOLD':'ADAPTER_OR_AUDIT_FAILED'];
        }
    }
    public function cancel(string $id,string $operation):void
    {
        $this->job($id,$operation);$user=$this->user($id);$now=$this->now();
        $this->store->changeIdentity($id,$user['auth_version']??0,static function(array &$row,array &$security)use($operation,$now):IdentityChange{
            $job=$security['lifecycle_deletion']??[];if(($job['operation']??null)!==$operation||($job['irreversible']??true)||($job['owner']??'')!==''&&($job['lease']??0)>$now){throw new \RuntimeException('Erasure already started; cancellation requires backup recovery.');}unset($security['lifecycle_deletion']);return new IdentityChange(null,true);
        });$this->record($id,$operation,'cancelled');$this->emit('account.deletion.cancelled',$id,$operation);
    }
    public function status(string $id,string $operation):array
    {
        $receipt=$this->store->state($this->receipt($operation));if(($receipt['subject']??null)!==$this->subject($id)){throw new \RuntimeException('Erasure receipt subject mismatch.');}
        if($this->store->userById($id)===null||in_array($receipt['status'],['complete','cancelled'],true)){return ['status'=>$receipt['status']];}$job=$this->job($id,$operation);return ['status'=>$job['status'],'completed_adapters'=>$job['completed'],'irreversible'=>$job['irreversible']];
    }
    public function pending(string $id):?string{return $this->store->state('user:'.$id)['lifecycle_deletion']['operation']??null;}
}
