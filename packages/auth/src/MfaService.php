<?php
declare(strict_types=1);
namespace Corevia\Auth;
final readonly class MfaService
{
    private \Closure $clock;
    public function __construct(private IdentityStore $store,private SecretBox $box,?callable $clock=null){$this->clock=\Closure::fromCallable($clock??'time');}
    public function enabled(string $id):bool{return isset($this->store->state('user:'.$id)['mfa']['secret']);}
    public function verify(array $user,mixed $code):void
    {
        if(!$this->enabled($user['id'])){return;}
        if(!$this->store->consume([[hash('sha256','mfa|'.$user['id']),10,900]],($this->clock)())){throw new AuthFailure('RATE_LIMITED','Too many authenticator attempts. Try again later.',429);}
        if(!is_string($code)||$code===''){throw new AuthFailure('MFA_REQUIRED','Enter an authenticator or recovery code.',401);}
        $this->store->changeIdentity($user['id'],$user['auth_version']??0,function(array &$row,array &$state)use($code):IdentityChange{
            $mfa=$state['mfa']??null;if($mfa===null){return new IdentityChange();}
            $hash=hash('sha256',strtolower(str_replace('-','',trim($code))));$index=array_search($hash,$mfa['recovery']??[],true);
            if($index!==false){unset($state['mfa']['recovery'][$index]);$state['mfa']['recovery']=array_values($state['mfa']['recovery']);return new IdentityChange();}
            $secret=$this->box->open($mfa['secret'],'mfa:'.$row['id']);$counter=Totp::counter($secret,$code,($this->clock)(),$mfa['counter']??-1);
            if($counter===null){throw new AuthFailure('MFA_INVALID','Authenticator code is invalid or already used.',401);}$state['mfa']['counter']=$counter;return new IdentityChange();
        });
    }
    public function begin(array $user,AuthContext $context,string $issuer):array
    {
        if($this->enabled($user['id'])){throw new AuthFailure('MFA_ENABLED','Disable the current authenticator before replacing it.');}
        $secret=Totp::secret();$this->store->changeState('mfa-setup:'.$context->digest,function(array &$state)use($secret,$user):void{$state=['user_id'=>$user['id'],'version'=>$user['auth_version']??0,'expires'=>($this->clock)()+600,'secret'=>$this->box->seal($secret,'setup:'.$user['id'])];});
        return ['secret'=>$secret,'uri'=>'otpauth://totp/'.rawurlencode($issuer.':'.$user['email']).'?'.http_build_query(['secret'=>$secret,'issuer'=>$issuer,'algorithm'=>'SHA1','digits'=>6,'period'=>30],'','&',PHP_QUERY_RFC3986)];
    }
    public function enable(array $user,AuthContext $context,mixed $code):array
    {
        $setup=$this->store->state('mfa-setup:'.$context->digest);
        if(($setup['user_id']??null)!==$user['id'] || ($setup['version']??null)!==($user['auth_version']??0) || ($setup['expires']??0)<=($this->clock)()){throw new AuthFailure('MFA_SETUP_EXPIRED','Start authenticator setup again.');}
        $secret=$this->box->open($setup['secret'],'setup:'.$user['id']);$counter=is_string($code)?Totp::counter($secret,$code,($this->clock)()):null;
        if($counter===null){throw new AuthFailure('MFA_INVALID','Enter the current authenticator code.');}
        $codes=[];for($i=0;$i<8;$i++){$codes[]=bin2hex(random_bytes(10));}
        $outcome=$this->store->changeIdentity($user['id'],$user['auth_version']??0,function(array &$row,array &$state)use($secret,$counter,$codes):IdentityChange{
            if(isset($state['mfa']['secret'])){throw new AuthFailure('MFA_ENABLED','Authenticator is already enabled.');}$state['mfa']=['secret'=>$this->box->seal($secret,'mfa:'.$row['id']),'counter'=>$counter,'recovery'=>array_map(static fn(string $code):string=>hash('sha256',$code),$codes)];return new IdentityChange($codes,true);
        });$this->store->changeState('mfa-setup:'.$context->digest,static function(array &$state):void{$state=[];});return $outcome;
    }
    public function disable(array $user):array{return $this->store->changeIdentity($user['id'],$user['auth_version']??0,static function(array &$row,array &$state):IdentityChange{unset($state['mfa']);return new IdentityChange(null,true);});}
}
