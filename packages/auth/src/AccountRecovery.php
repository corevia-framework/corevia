<?php
declare(strict_types=1);
namespace Corevia\Auth;
use Corevia\Core\Logger;
use Corevia\Http\Request;

final readonly class AccountRecovery
{
    private \Closure $clock;
    public function __construct(private AccountStore $store,private AccountDelivery $delivery,private AuthService $auth,private ?Logger $logger=null,?callable $clock=null){$this->clock=\Closure::fromCallable($clock??'time');}
    private function limits(Request $request,string $operation,string $identity):void
    {
        $ip=$request->attributes['client_ip']??'local';if(!is_string($ip) || filter_var($ip,FILTER_VALIDATE_IP)===false){$ip='local';}
        if(!$this->store->consume([[hash('sha256',$operation.'|ip|'.$ip),30,900],[hash('sha256',$operation.'|identity|'.$identity),5,900]],($this->clock)())){throw new AuthFailure('RATE_LIMITED','Too many attempts. Try again later.',429);}
    }
    public function isVerified(AuthContext $context):bool{return $context->user!==null && ($this->store->userById($context->user['id'])['email_verified_at']??null)!==null;}
    private function send(array $user,string $purpose):void
    {
        try{
        $now=($this->clock)();$token=bin2hex(random_bytes(32));$expires=$now+($purpose==='reset'?1800:86400);
        if(!$this->store->issueAccountToken($user['id'],$purpose,hash('sha256',$token),$expires,$user['auth_version']??0,$now,$user['email'])){return;}
        $path=$purpose==='reset'?'/reset-password':'/verify-email';
        $this->delivery->deliver(new AccountMessage($user['email'],$purpose,$expires,$this->auth->origin.$path.'#token='.$token));}
        catch(\Throwable $error){try{$this->logger?->log('error','Account delivery failed',['purpose'=>$purpose,'exception_class'=>$error::class]);}catch(\Throwable){}/* Generic client reply avoids exposing account existence or transport details. */}
    }
    public function afterRegistration(AuthContext $context):void{if($context->user!==null){$user=$this->store->userById($context->user['id']);if($user!==null){$this->send($user,'verify');}}}
    public function requestReset(Request $request):void
    {
        $input=$request->input();$email=is_string($input['email']??null)?strtolower(trim($input['email'])):'';
        $this->limits($request,'reset-request',substr($email,0,254));
        if(strlen($email)>254 || filter_var($email,FILTER_VALIDATE_EMAIL)===false){return;}
        $user=$this->store->findUser($email);if($user!==null){$this->send($user,'reset');}
    }
    public function requestVerification(Request $request,AuthContext $context):void
    {
        if($context->user===null){throw new AuthFailure('UNAUTHENTICATED','Sign in first.',401);}
        $this->limits($request,'verify-request',$context->user['id']);$user=$this->store->userById($context->user['id']);
        if($user!==null && ($user['email_verified_at']??null)===null){$this->send($user,'verify');}
    }
    private function token(Request $request,string $purpose):array
    {
        $input=$request->input();$token=$input['token']??null;$this->limits($request,$purpose.'-complete',is_string($token)?hash('sha256',$token):'invalid');
        if(!is_string($token) || !preg_match('/^[a-f0-9]{64}$/D',$token)){throw $this->invalid();}
        $digest=hash('sha256',$token);$record=$this->store->accountToken($digest,$purpose,($this->clock)());if($record===null){throw $this->invalid();}return [$digest,$record];
    }
    private function invalid():AuthFailure{return new AuthFailure('TOKEN_INVALID','This link has expired or has already been used. Request a new link.');}
    public function resetPassword(Request $request):void
    {
        [$digest]=$this->token($request,'reset');$input=$request->input();$hash=AuthService::passwordHash($input['password']??null,$input['password_confirmation']??null);
        if(!$this->store->completeAccountToken($digest,'reset',($this->clock)(),$hash)){throw $this->invalid();}
    }
    public function verifyEmail(Request $request,AuthContext $context):void
    {
        if($context->user===null){throw new AuthFailure('UNAUTHENTICATED','Sign in to the account that requested this link.',401);}
        [$digest,$record]=$this->token($request,'verify');
        if($record['user_id']!==$context->user['id']){throw new AuthFailure('ACCOUNT_REQUIRED','Sign in to the account that requested this link.',403);}
        if(!$this->store->completeAccountToken($digest,'verify',($this->clock)(),requiredUserId:$context->user['id'])){throw $this->invalid();}
    }
}
