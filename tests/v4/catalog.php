<?php
declare(strict_types=1);
namespace CoreviaV4Catalog;
require dirname(__DIR__,2).'/autoload.php';
require dirname(__DIR__).'/support/Services.php';
require dirname(__DIR__).'/support/Fixtures.php';
use Corevia\Auth\AuthFailure;
use Corevia\Http\Request;
use Corevia\Persistence\{DocumentStore,JsonDocumentStore,SqlConnection};
use Corevia\Testing\TestClient;
function check(bool $ok):void{if(!$ok){throw new \RuntimeException('Reference API contract failed.');}}
function contract(SqlConnection|DocumentStore|null $data=null,string $label='json'):void
{
    $directory=sys_get_temp_dir().'/corevia-v4-catalog-'.bin2hex(random_bytes(6));mkdir($directory);$table='cv4_catalog_'.bin2hex(random_bytes(4));
    if($data instanceof SqlConnection){$data->execute('CREATE TABLE '.$data->quoteIdentifier($table).' (id VARCHAR(32) PRIMARY KEY,owner_id VARCHAR(32) NOT NULL,title VARCHAR(200) NOT NULL)'.($data->driver()==='mysql'?' ENGINE=InnoDB':''));}
    $bootstrap=require dirname(__DIR__,2).'/examples/v4/catalog/bootstrap.php';$runtime=$bootstrap($directory,$data,$table);
    try{
        $issue=static function(string $email)use($runtime):array{
            $request=static fn(array $body)=>new Request('POST','/fixture',['content-type'=>'application/json'],json_encode($body,JSON_THROW_ON_ERROR));
            $password='fixture-password-123';$context=$runtime['auth']->register($request(['name'=>'Fixture','email'=>$email,'password'=>$password,'password_confirmation'=>$password]),$runtime['auth']->open(new Request('GET','/session')));
            $runtime['gate']->assign($context->user['id'],['catalog_editor']);
            $context=$runtime['auth']->login($request(['email'=>$email,'password'=>$password]),$runtime['auth']->open(new Request('GET','/session')));
            return $runtime['tokens']->issue($context,['items.read','items.write']);
        };
        $alice=$issue('alice@example.test');$bob=$issue('bob@example.test');$client=new TestClient($runtime['router']);
        $headers=['Authorization'=>'Bearer '.$alice['token'],'Idempotency-Key'=>'catalog-create-one'];
        $client->request('GET','/api/v1/items')->status(401);
        $client->request('POST','/api/v1/items',['title'=>2],$headers)->status(422)->field('error.code','VALIDATION');
        $created=$client->request('POST','/api/v1/items',['title'=>'First item'],$headers)->status(201)->json();
        check($client->request('POST','/api/v1/items',['title'=>'First item'],$headers)->status(201)->json()===$created);
        $client->request('POST','/api/v1/items',['title'=>'different'],$headers)->status(409);
        $client->request('DELETE','/api/v1/items/'.$created['data']['id'],headers:['Authorization'=>'Bearer '.$bob['token']])->status(404);
        check(count($client->request('GET','/api/v1/items',headers:$headers)->status(200)->json()['data'])===1);
        $client->request('DELETE','/api/v1/items/'.$created['data']['id'],headers:$headers)->status(200);
        $client->request('GET','/openapi.json')->status(200)->field('openapi','3.1.0');
        echo 'PASS '.$label." reference API auth, validation, owner isolation, idempotency and OpenAPI\n";
    }finally{
        $runtime['app']->shutdown();if($data instanceof SqlConnection){$data->execute('DROP TABLE '.$data->quoteIdentifier($table));}
        \CoreviaTests\Fixtures::remove($directory,'corevia-v4-catalog-');
    }
}
contract();
if(class_exists(\PDO::class)&&in_array('sqlite',\PDO::getAvailableDrivers(),true)){contract(new SqlConnection(new \PDO('sqlite::memory:')),'sqlite');}
if(getenv('COREVIA_DB_TESTS')==='1'){foreach(\CoreviaTests\Services::drivers() as $driver){contract(SqlConnection::connect(\CoreviaTests\Services::sql($driver)),$driver);}}
