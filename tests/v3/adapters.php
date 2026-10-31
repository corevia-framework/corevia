<?php
declare(strict_types=1);
namespace CoreviaAdapterTests;
define('COREVIA_IDENTITY_CONTRACT_ONLY',true);require __DIR__.'/identity.php';
use Corevia\Auth\{AuthFailure,GitHubProvider,IdTokenVerifier,OidcCodeProvider,SnapshotAuthStore};
use Corevia\Auth\{AuthService,OAuthService,SecretBox};
use Corevia\Http\Request;
use Corevia\Http\HttpClient;
use Corevia\Persistence\{MongoSnapshotStore,SnapshotStore};
function check(bool $ok):void{if(!$ok){throw new \RuntimeException('Adapter assertion failed.');}}
function rejects(callable $work):void{try{$work();}catch(\Throwable){return;}throw new \RuntimeException('Expected adapter rejection.');}
final class MemorySnapshot implements SnapshotStore
{
    public int $version=0;public ?string $payload=null;public int $conflicts=0;
    public function read():array{return ['version'=>$this->version,'payload'=>$this->payload];}
    public function compareExchange(int $version,string $payload):bool{if($this->conflicts-->0){$this->version++;return false;}if($version!==$this->version){return false;}$this->payload=$payload;$this->version++;return true;}
}
final class FixtureHttp implements HttpClient
{
    public array $calls=[];public array $responses=[];
    public function stream(string $method,string $url,array $headers=[],string $body=''):iterable{$this->calls[]=compact('method','url','headers','body');yield json_encode(array_shift($this->responses),JSON_THROW_ON_ERROR);}
}
$backend=new MemorySnapshot();$store=new SnapshotAuthStore($backend);\CoreviaIdentityTests\contract($store,function_exists('openssl_encrypt'));$backend->conflicts=2;$store->changeState('retry-test',static function(array &$state):void{$state=['value'=>1];});check($store->state('retry-test')===['value'=>1]);$before=$backend->payload;$backend->conflicts=20;rejects(fn()=>$store->changeState('uncommitted',static function(array &$state):void{$state=['value'=>2];}));check($backend->payload===$before);$backend->conflicts=0;$backend->payload='{corrupt';rejects(fn()=>$store->findUser('any@example.test'));check($backend->payload==='{corrupt');
echo "PASS NoSQL CAS identity contract, conflict retries, bounded contention and corruption preservation\n";
if(function_exists('openssl_sign')){
    $directory=sys_get_temp_dir().'/corevia-oidc-'.bin2hex(random_bytes(6));mkdir($directory);
    try{file_put_contents($directory.'/openssl.cnf',"[req]\ndistinguished_name=dn\n[dn]\n");$key=openssl_pkey_new(['private_key_bits'=>2048,'private_key_type'=>OPENSSL_KEYTYPE_RSA,'config'=>$directory.'/openssl.cnf']);$details=openssl_pkey_get_details($key);$b64=static fn(string $value):string=>rtrim(strtr(base64_encode($value),'+/','-_'),'=');$jwks=['keys'=>[['kid'=>'fixture','kty'=>'RSA','use'=>'sig','alg'=>'RS256','n'=>$b64($details['rsa']['n']),'e'=>$b64($details['rsa']['e'])]]];$claims=['iss'=>'https://issuer.example','aud'=>'client-fixture','exp'=>time()+600,'iat'=>time(),'nonce'=>'nonce-fixture','sub'=>'stable-subject'];
        $sign=static function(array $claims,array $header=['alg'=>'RS256','kid'=>'fixture'])use($key,$b64):string{$raw=$b64(json_encode($header)).'.'.$b64(json_encode($claims));openssl_sign($raw,$signature,$key,OPENSSL_ALGO_SHA256);return $raw.'.'.$b64($signature);};$jwt=$sign($claims);check(IdTokenVerifier::verify($jwt,$jwks,$claims['iss'],'client-fixture','nonce-fixture')['sub']==='stable-subject');
        foreach([['iss'=>'https://attacker.example'],['aud'=>'other-client'],['azp'=>'other-client'],['exp'=>time()-1],['iat'=>time()+120],['nbf'=>time()+120],['nonce'=>'wrong'],['sub'=>42],['aud'=>['client-fixture','other']]] as $override){rejects(fn()=>IdTokenVerifier::verify($sign([...$claims,...$override]),$jwks,$claims['iss'],'client-fixture','nonce-fixture'));}
        rejects(fn()=>IdTokenVerifier::verify($sign($claims,['alg'=>'HS256','kid'=>'fixture']),$jwks,$claims['iss'],'client-fixture','nonce-fixture'));rejects(fn()=>IdTokenVerifier::verify($jwt,['keys'=>[...$jwks['keys'],...$jwks['keys']]],$claims['iss'],'client-fixture','nonce-fixture'));$parts=explode('.',$jwt);$parts[1]=$b64(json_encode([...$claims,'sub'=>'tampered']));rejects(fn()=>IdTokenVerifier::verify(implode('.',$parts),$jwks,$claims['iss'],'client-fixture','nonce-fixture'));
        $http=new FixtureHttp();$http->responses=[['access_token'=>'private-fixture-token','token_type'=>'bearer'],['id'=>1234,'email'=>'not-an-identifier@example.test']];$github=new GitHubProvider($http,'client','private-secret');check($github->identity('code','verifier','https://app.example/callback')==='1234');parse_str($http->calls[0]['body'],$form);check($form['code_verifier']==='verifier'&&$http->calls[1]['headers']['Authorization']==='Bearer private-fixture-token');
        $http->responses=[['id_token'=>$jwt],$jwks];$oidc=new OidcCodeProvider($http,'client-fixture','private-secret',$claims['iss'],'https://issuer.example/authorize','https://issuer.example/token','https://issuer.example/jwks');check($oidc->identityWithNonce('code','verifier','https://app.example/callback','nonce-fixture')==='stable-subject');rejects(fn()=>$oidc->identity('code','verifier','https://app.example/callback'));
        $identity=new SnapshotAuthStore(new MemorySnapshot());$user=$identity->createUser('oidc@example.test','OIDC',password_hash('example-password-123',PASSWORD_BCRYPT,['cost'=>4]));$auth=new AuthService($identity);$anon=$auth->open(new Request('GET','/login'));$body=static fn(array $input):Request=>new Request('POST','/auth/fixture',['content-type'=>'application/json'],json_encode($input));$context=$auth->login($body(['email'=>$user['email'],'password'=>'example-password-123']),$anon);$service=new OAuthService($identity,new SecretBox(random_bytes(32)),$auth,['company'=>$oidc]);$url=$service->start($body(['current_password'=>'example-password-123']),$context,'company');parse_str(parse_url($url,PHP_URL_QUERY),$query);check(strlen($query['nonce'])===64&&$query['code_challenge_method']==='S256');$http->responses=[['id_token'=>$sign([...$claims,'nonce'=>$query['nonce']])],$jwks];$fresh=$service->callback(new Request('GET','/auth/oauth/company/callback',attributes:['query'=>['state'=>$query['state'],'code'=>'code']]),$context,'company');check($fresh->user['id']===$user['id']);$url=$service->start($body(['current_password'=>'example-password-123']),$fresh,'company');parse_str(parse_url($url,PHP_URL_QUERY),$query);$http->responses=[['id_token'=>$jwt],$jwks];rejects(fn()=>$service->callback(new Request('GET','/auth/oauth/company/callback',attributes:['query'=>['state'=>$query['state'],'code'=>'code']]),$fresh,'company'));check($identity->userById($user['id'])['auth_version']===1);
        echo "PASS GitHub PKCE exchange and RS256 OIDC signature/issuer/audience/expiry/nonce/key-confusion fixtures\n";
    }finally{foreach(glob($directory.'/*') as $file){unlink($file);}rmdir($directory);}
}
if(getenv('COREVIA_DB_TESTS')==='1'){
    $scope='test-'.bin2hex(random_bytes(10));$uri=\CoreviaTests\Services::mongoUri();$database=\CoreviaTests\Services::database();$mongo=new MongoSnapshotStore($uri,$database,'cv_auth_test',$scope);
    try{\CoreviaIdentityTests\contract(new SnapshotAuthStore($mongo),function_exists('openssl_encrypt'));echo "PASS live MongoDB identity contract\n";}finally{$manager=new \MongoDB\Driver\Manager($uri);$bulk=new \MongoDB\Driver\BulkWrite();$bulk->delete(['_id'=>$scope],['limit'=>1]);$manager->executeBulkWrite($database.'.cv_auth_test',$bulk);}
}
