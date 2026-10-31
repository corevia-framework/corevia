<?php
declare(strict_types=1);
namespace Corevia\Auth;
use Corevia\Http\Request;
final readonly class OAuthService
{
    public function __construct(private IdentityStore $store,private SecretBox $box,private AuthService $auth,private array $providers)
    {
        foreach($providers as $name=>$provider){if(!preg_match('/^[a-z][a-z0-9_-]{0,31}$/D',$name)||!$provider instanceof OAuthProvider){throw new \InvalidArgumentException('Register named OAuthProvider instances.');}}
    }
    public function providers():array{return array_keys($this->providers);}
    private function provider(string $name):OAuthProvider{return $this->providers[$name]??throw new AuthFailure('OAUTH_PROVIDER','Provider is unavailable.',404);}
    private function redirect(string $name):string{return $this->auth->origin.'/auth/oauth/'.$name.'/callback';}
    public function start(Request $request,AuthContext $context,string $name):string
    {
        $provider=$this->provider($name);$user=$context->user===null?null:$this->auth->confirm($request,$context);
        if(!$this->store->consume([[hash('sha256','oauth-start|'.$context->digest),10,900]],time())){throw new AuthFailure('RATE_LIMITED','Too many provider login attempts.',429);}
        $endpoint=$provider->authorizationEndpoint();$parts=parse_url($endpoint);if(($parts['scheme']??null)!=='https'||empty($parts['host'])||isset($parts['user'])||isset($parts['fragment'])||isset($parts['query'])){throw new \LogicException('Provider authorization endpoint must be an HTTPS URL without query, fragment or credentials.');}
        $state=bin2hex(random_bytes(32));$verifier=rtrim(strtr(base64_encode(random_bytes(32)),'+/','-_'),'=');$key='oauth:'.hash('sha256',$state);
        $nonce=$provider instanceof OidcProvider?bin2hex(random_bytes(32)):null;
        $this->store->changeState($key,function(array &$record)use($name,$context,$user,$verifier,$nonce):void{$record=['expires'=>time()+600,'provider'=>$name,'session'=>$context->digest,'user_id'=>$user['id']??null,'version'=>$user['auth_version']??0,'verifier'=>$this->box->seal($verifier,'oauth:'.$context->digest),'nonce'=>$nonce];});
        $parameters=['response_type'=>'code','client_id'=>$provider->clientId(),'redirect_uri'=>$this->redirect($name),'scope'=>implode(' ',$provider->scopes()),'state'=>$state,'code_challenge'=>rtrim(strtr(base64_encode(hash('sha256',$verifier,true)),'+/','-_'),'='),'code_challenge_method'=>'S256'];if($nonce!==null){$parameters['nonce']=$nonce;}return $endpoint.'?'.http_build_query($parameters,'','&',PHP_QUERY_RFC3986);
    }
    public function callback(Request $request,AuthContext $context,string $name):?AuthContext
    {
        $provider=$this->provider($name);$query=$request->attributes['query']??[];$state=$query['state']??null;$code=$query['code']??null;
        if(!is_string($state)||!preg_match('/^[a-f0-9]{64}$/D',$state)||!is_string($code)||$code===''||strlen($code)>4096){throw new AuthFailure('OAUTH_STATE','Provider login expired. Start again.');}
        $flow=$this->store->changeState('oauth:'.hash('sha256',$state),static function(array &$record)use($context,$name):array{
            if(($record['expires']??0)<=time()||($record['session']??null)!==$context->digest||($record['provider']??null)!==$name){throw new AuthFailure('OAUTH_STATE','Provider login expired. Start again.');}$flow=$record;$record=[];return $flow;
        });
        try{$verifier=$this->box->open($flow['verifier'],'oauth:'.$context->digest);$subject=$provider instanceof OidcProvider?$provider->identityWithNonce($code,$verifier,$this->redirect($name),$flow['nonce']??''):$provider->identity($code,$verifier,$this->redirect($name));}catch(\Throwable){throw new AuthFailure('OAUTH_EXCHANGE','Provider login could not be completed. Start again.',502);}
        if($subject===''||strlen($subject)>512||preg_match('/[\x00-\x1f]/',$subject)){throw new AuthFailure('OAUTH_IDENTITY','Provider did not supply a stable identity.');}
        $subjectHash=hash('sha256',$name.'|'.$subject);$mapping='oid:'.$subjectHash;
        if($flow['user_id']!==null){
            if(($context->user['id']??null)!==$flow['user_id']){throw new AuthFailure('OAUTH_STATE','Sign in and link the provider again.',401);}
            $user=$this->store->changeProvider($flow['user_id'],$flow['version'],$name,$subjectHash);return $this->auth->replaceSession($context,$user,true);
        }
        $id=$this->store->state($mapping)['user_id']??null;$user=is_string($id)?$this->store->userById($id):null;
        if($user===null||($this->store->state('user:'.$id)['oauth'][$name]??null)!==$subjectHash){throw new AuthFailure('OAUTH_LINK_REQUIRED','Sign in with your password and link this provider from account settings first.',403);}
        // MFA accounts get no authenticated session until the additional factor is verified.
        if(isset($this->store->state('user:'.$id)['mfa']['secret'])){$this->store->changeState('oauth-mfa:'.$context->digest,static function(array &$record)use($user):void{$record=['user_id'=>$user['id'],'version'=>$user['auth_version']??0,'expires'=>time()+300];});return null;}
        return $this->auth->authenticate($context,$user);
    }
    public function finishMfa(Request $request,AuthContext $context):AuthContext
    {
        $key='oauth-mfa:'.$context->digest;$lease=bin2hex(random_bytes(16));$pending=$this->store->changeState($key,static function(array &$record)use($lease):array{if(($record['expires']??0)<=time()||isset($record['busy'])){throw new AuthFailure('OAUTH_STATE','Start provider login again.',401);}$record['busy']=$lease;return $record;});
        try{$user=$this->store->userById($pending['user_id']);if($user===null||$pending['version']!==($user['auth_version']??0)){throw new AuthFailure('OAUTH_STATE','Start provider login again.',401);}$fresh=$this->auth->authenticate($context,$user,$request->input()['code']??null);}
        catch(\Throwable $error){$this->store->changeState($key,static function(array &$record)use($lease):void{if(($record['busy']??null)===$lease){unset($record['busy']);}});throw $error;}
        $this->store->changeState($key,static function(array &$record)use($lease):void{if(($record['busy']??null)===$lease){$record=[];}});return $fresh;
    }
    public function unlink(Request $request,AuthContext $context,string $name):void
    {
        $this->provider($name);$user=$this->auth->confirm($request,$context);$this->store->changeProvider($user['id'],$user['auth_version']??0,$name,null);
    }
}
