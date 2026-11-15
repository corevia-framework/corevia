<?php
declare(strict_types=1);
namespace Corevia\Testing;
use Corevia\Http\{Request,Response,Router};

/** In-process requests exercise the real router and its execution scopes/middleware. */
final class TestClient
{
    private array $cookies=[];
    public function __construct(private readonly Router $router,private readonly string $origin='http://127.0.0.1:8081'){Guard::development();}
    public function request(string $method,string $path,?array $data=null,array $headers=[]):TestResponse
    {
        $normalized=[];foreach($headers as $key=>$value){$normalized[strtolower($key)]=$value;}$headers=$normalized;
        if(!str_starts_with($path,'/')||str_starts_with($path,'//')){throw new \InvalidArgumentException('Use a local request path.');}
        $parts=parse_url($path);$query=[];parse_str($parts['query']??'',$query);
        $defaults=['origin'=>$this->origin,'cookie'=>implode('; ',array_map(static fn($key,$value)=>$key.'='.$value,array_keys($this->cookies),$this->cookies))];
        if($data!==null){$defaults['content-type']='application/json';}
        $request=new Request(strtoupper($method),$parts['path']??'/',array_replace($defaults,$headers),$data===null?'':json_encode($data,JSON_THROW_ON_ERROR),attributes:['client_ip'=>'127.0.0.1','scheme'=>'http','query'=>$query]);
        $response=$this->router->dispatch($request);
        foreach($response->headers as $key=>$values){
            if(strtolower($key)!=='set-cookie'){continue;}
            foreach(is_array($values)?$values:[$values] as $cookie){[$pair]=explode(';',$cookie,2);[$name,$value]=explode('=',$pair,2);if($value===''){unset($this->cookies[$name]);}else{$this->cookies[$name]=$value;}}
        }
        return new TestResponse($response);
    }
    public function login(string $email,string $password):TestResponse
    {
        $session=$this->request('GET','/auth/session')->json();
        return $this->request('POST','/auth/login',['email'=>$email,'password'=>$password],['x-csrf-token'=>$session['csrf']]);
    }
}
