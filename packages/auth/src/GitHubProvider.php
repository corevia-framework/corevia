<?php
declare(strict_types=1);
namespace Corevia\Auth;
use Corevia\Http\{HttpClient,HttpJson};
final readonly class GitHubProvider implements OAuthProvider
{
    public function __construct(private HttpClient $http,private string $id,private string $secret){}
    public function authorizationEndpoint():string{return 'https://github.com/login/oauth/authorize';}
    public function clientId():string{return $this->id;}
    public function scopes():array{return ['read:user'];}
    public function identity(string $code,string $verifier,string $redirectUri):string
    {
        $token=HttpJson::request($this->http,'POST','https://github.com/login/oauth/access_token',['Content-Type'=>'application/x-www-form-urlencoded'],http_build_query(['client_id'=>$this->id,'client_secret'=>$this->secret,'code'=>$code,'code_verifier'=>$verifier,'redirect_uri'=>$redirectUri]));
        if(!is_string($token['access_token']??null)||$token['access_token']===''||strtolower($token['token_type']??'')!=='bearer'){throw new \RuntimeException('Provider returned no bearer credential.');}
        $user=HttpJson::request($this->http,'GET','https://api.github.com/user',['Authorization'=>'Bearer '.$token['access_token'],'X-GitHub-Api-Version'=>'2022-11-28']);$id=$user['id']??null;
        if(!(is_int($id)&&$id>0)&&!(is_string($id)&&preg_match('/^[1-9][0-9]{0,30}$/D',$id))){throw new \RuntimeException('Provider did not return a stable numeric subject.');}return (string)$id;
    }
}
