<?php
declare(strict_types=1);
namespace Corevia\Auth;
use Corevia\Core\CanonicalJson;
use Corevia\Http\Request;

/** Usernameless resident passkeys. Existing authenticator/MFA requirements remain in force. */
final readonly class Passkeys
{
    private \Closure $clock;
    public function __construct(private PasskeyStore $store,private AuthService $auth,private PasskeyVerifier $verifier,?callable $clock=null){$this->clock=\Closure::fromCallable($clock??time(...));}
    private function now():int{return ($this->clock)();}
    private function key(AuthContext $context,string $kind):string{return 'passkey-'.$kind.':'.$context->digest;}
    private function owner(AuthContext $context):array
    {
        if($context->user===null||$this->store->authority($context->user['id'],$context->digest,$this->now())===null){throw new AuthFailure('UNAUTHENTICATED','Sign in again.',401);}
        if($this->store->state('api-token:'.$context->digest)!==[]){throw new AuthFailure('FORBIDDEN','Manage passkeys through an account session.',403);}
        return $this->store->userById($context->user['id'])??throw new AuthFailure('UNAUTHENTICATED','Sign in again.',401);
    }
    private function limit(AuthContext $context):void{if(!$this->store->consume([['passkey-ceremony:'.hash('sha256',$context->digest),10,900]],$this->now())){throw new AuthFailure('RATE_LIMITED','Too many passkey attempts.',429);}}
    private function take(AuthContext $context,string $kind):array
    {
        $now=$this->now();return $this->store->changeState($this->key($context,$kind),static function(array &$state)use($now):array{if(($state['expires']??0)<=$now){throw new AuthFailure('PASSKEY_EXPIRED','Start the passkey ceremony again.',401);}$value=$state;$state=[];return $value;});
    }
    public function beginRegistration(Request $request,AuthContext $context):array
    {
        $this->owner($context);$user=$this->auth->confirm($request,$context);$input=$request->input();$label=$input['label']??'';
        if(array_diff(array_keys($input),['_token','current_password','code','label'])||!is_string($label)||trim($label)===''||strlen($label)>120||preg_match('//u',$label)!==1||preg_match('/[\x00-\x1f\x7f]/',$label)){throw new AuthFailure('VALIDATION','Give this passkey a short device label.');}
        $this->limit($context);$keys=$this->store->state('user:'.$user['id'])['passkeys']??[];if(count($keys)>=10){throw new AuthFailure('PASSKEY_CAPACITY','Remove an existing passkey first.',409);}
        $options=$this->verifier->registrationOptions($user,random_bytes(32),array_column(array_values($keys),'source'));
        $record=['options'=>$options,'user_id'=>$user['id'],'version'=>$user['auth_version']??0,'label'=>trim($label),'expires'=>$this->now()+300];$this->store->changeState($this->key($context,'register'),static function(array &$state)use($record):void{$state=$record;});return $options;
    }
    public function finishRegistration(Request $request,AuthContext $context):AuthContext
    {
        $user=$this->owner($context);$record=$this->take($context,'register');$input=$request->input();
        if(($record['user_id']??null)!==$user['id']||($record['version']??null)!==($user['auth_version']??0)||array_diff(array_keys($input),['_token','credential'])||!is_array($input['credential']??null)){throw new AuthFailure('PASSKEY_EXPIRED','Start the passkey ceremony again.',401);}
        try{$source=$this->verifier->register($input['credential'],$record['options']);$digest=hash('sha256',PasskeyRules::bytes($source['publicKeyCredentialId']));}
        catch(\Throwable){throw new AuthFailure('PASSKEY_FAILED','Passkey verification failed. Start again.',401);}
        $user=$this->store->changePasskey($user['id'],$record['version'],$digest,['source'=>$source,'label'=>$record['label'],'created'=>$this->now()]);return $this->auth->replaceSession($context,$user,true);
    }
    public function beginLogin(AuthContext $context):array
    {
        if($context->user!==null){throw new AuthFailure('ALREADY_AUTHENTICATED','Sign out before switching accounts.',409);}$this->limit($context);$options=$this->verifier->requestOptions(random_bytes(32));$record=['options'=>$options,'expires'=>$this->now()+300];
        $this->store->changeState($this->key($context,'login'),static function(array &$state)use($record):void{$state=$record;});return $options;
    }
    public function finishLogin(Request $request,AuthContext $context):AuthContext
    {
        if($context->user!==null){throw new AuthFailure('ALREADY_AUTHENTICATED','Sign out before switching accounts.',409);}$challenge=$this->take($context,'login');$input=$request->input();
        try{
            if(array_diff(array_keys($input),['_token','credential','code'])||!is_array($input['credential']??null)||!is_string($input['credential']['rawId']??null)){throw new \InvalidArgumentException('Invalid credential payload.');}
            $digest=hash('sha256',PasskeyRules::bytes($input['credential']['rawId']));$id=$this->store->state('pkey:'.$digest)['user_id']??null;$user=is_string($id)?$this->store->userById($id):null;
            $record=$user===null?null:($this->store->state('user:'.$id)['passkeys'][$digest]??null);if(!is_array($record)||isset($this->store->state('user:'.$id)['lifecycle_deletion'])){throw new \RuntimeException('No active credential.');}
            $source=$this->verifier->verify($input['credential'],$challenge['options'],$record['source']);$updated=$record;$updated['source']=$source;
            $user=$this->store->changePasskey($id,$user['auth_version']??0,$digest,$updated,hash('sha256',CanonicalJson::encode($record)));
        }catch(\Throwable){throw new AuthFailure('PASSKEY_FAILED','Passkey verification failed. Start again.',401);}
        return $this->auth->authenticate($context,$user,$input['code']??null);
    }
    public function listing(AuthContext $context):array
    {
        $user=$this->owner($context);$result=[];foreach($this->store->state('user:'.$user['id'])['passkeys']??[] as $id=>$record){$result[]=['id'=>$id,'label'=>$record['label'],'created'=>$record['created'],'backed_up'=>$record['source']['backupStatus']??false];}return $result;
    }
    public function remove(Request $request,AuthContext $context):AuthContext
    {
        $this->owner($context);$user=$this->auth->confirm($request,$context);$input=$request->input();$id=$input['id']??null;
        if(!is_string($id)||!preg_match('/^[a-f0-9]{64}$/D',$id)||array_diff(array_keys($input),['_token','current_password','code','id'])){throw new AuthFailure('VALIDATION','Choose an enrolled passkey.');}
        $user=$this->store->changePasskey($user['id'],$user['auth_version']??0,$id,null);return $this->auth->replaceSession($context,$user,true);
    }
}
