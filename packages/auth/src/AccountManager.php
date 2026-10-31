<?php
declare(strict_types=1);
namespace Corevia\Auth;
use Corevia\Http\Request;
final readonly class AccountManager
{
    public function __construct(private IdentityStore $store,private AuthService $auth){}
    public function update(Request $request,AuthContext $context,string $operation):array
    {
        $input=$request->input();$fields=match($operation){'profile'=>['name'],'email'=>['email'],'password'=>['password','password_confirmation'],'delete'=>['confirmation'],default=>throw new AuthFailure('VALIDATION','Unknown account operation.')};
        if(array_diff(array_keys($input),['_token','current_password','code',...$fields])!==[]){throw new AuthFailure('VALIDATION','Unsupported account fields.');}
        $user=$this->auth->confirm($request,$context);$passwordHash=$operation==='password'?AuthService::passwordHash($input['password']??null,$input['password_confirmation']??null):null;
        return $this->store->changeIdentity($user['id'],$user['auth_version']??0,static function(array &$row,array &$state)use($operation,$input,$passwordHash):IdentityChange{
            if($operation==='profile'){$name=$input['name']??null;if(!is_string($name)||trim($name)===''||preg_match('//u',$name)!==1||preg_match('/[\x00-\x1f\x7f]/',$name)||preg_match_all('/./us',$name)>80){throw new AuthFailure('VALIDATION','Use a valid name of at most 80 characters.');}$row['name']=trim($name);}
            if($operation==='email'){$email=$input['email']??null;if(!is_string($email)||strlen($email)>254||!filter_var(trim($email),FILTER_VALIDATE_EMAIL)){throw new AuthFailure('VALIDATION','Use a valid email address.');}$row['email']=strtolower(trim($email));}
            if($operation==='password'){$row['password_hash']=$passwordHash;}
            if($operation==='delete' && ($input['confirmation']??null)!=='DELETE'){throw new AuthFailure('VALIDATION','Type DELETE to confirm account deletion.');}
            return new IdentityChange(null,true,$operation==='delete');
        });
    }
    private function handle(string $user,string $digest):string{return hash('sha256','session-list|'.$user.'|'.$digest);}
    public function sessions(AuthContext $context):array
    {
        if($context->user===null){throw new AuthFailure('UNAUTHENTICATED','Sign in first.',401);}$id=$context->user['id'];return array_map(fn(array $row):array=>['id'=>$this->handle($id,$row['id']),'expires'=>$row['expires'],'current'=>$row['id']===$context->digest],$this->store->sessionsFor($id,time()));
    }
    public function revoke(Request $request,AuthContext $context):void
    {
        $user=$this->auth->confirm($request,$context);$target=$request->input()['session']??null;if(!is_string($target)){throw new AuthFailure('VALIDATION','Choose a session or others.');}
        foreach($this->store->sessionsFor($user['id'],time()) as $row){if(($target==='others' && $row['id']!==$context->digest)||hash_equals($this->handle($user['id'],$row['id']),$target)){$this->store->revokeSession($user['id'],$row['id']);}}
    }
}
