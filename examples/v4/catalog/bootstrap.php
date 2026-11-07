<?php
declare(strict_types=1);
use Corevia\Auth\{ApiIdempotency,ApiTokenMiddleware,ApiTokens,AuthService,Gate,JsonAuthStore};
use Corevia\Core\Corevia;
use Corevia\Http\{ApiBoundary,ApiResponse,OpenApi,Request,RequestValidator,Router};
use Corevia\Persistence\{DocumentStore,JsonDocumentStore,SqlConnection};
require_once dirname(__DIR__,3).'/autoload.php';

/** Tutorial only; inject an isolated SQL/document connection to run the shared data contract. */
return static function(string $directory,SqlConnection|DocumentStore|null $data=null,string $collection='catalog_items'):array{
    if(getenv('APP_ENV')==='production'){throw new LogicException('The tutorial must run in an isolated development environment.');}
    if(!is_dir($directory)){mkdir($directory,0700,true);}
    $store=new JsonAuthStore($directory.'/identity.json');$auth=new AuthService($store);
    $gate=new Gate($store,['catalog_editor'=>['items.read','items.write']]);$tokens=new ApiTokens($store,$gate);$idempotency=new ApiIdempotency($store);
    $data??=new JsonDocumentStore($directory.'/documents');
    if($data instanceof SqlConnection){$data->quoteIdentifier($collection);}
    $app=Corevia::create($directory);$router=new Router($app,['http://127.0.0.1:8081']);$app->container->set(Router::class,$router);
    $owner=static fn(Request $request):string=>$request->attributes['api_identity']->user['id'];
    $list=static function(string $actor)use($data,$collection):array{
        if($data instanceof SqlConnection){return $data->table($collection)->where('owner_id',$actor)->orderBy('id')->limit(1000)->get(['id','title']);}
        $rows=$data->find($collection,['owner_id'=>$actor],1000);usort($rows,fn($a,$b)=>strcmp($a['_id'],$b['_id']));return array_map(fn($row)=>['id'=>$row['_id'],'title'=>$row['title']],$rows);
    };
    $router->get('/api/v1/items',fn(Request $request)=>ApiResponse::data($list($owner($request))))
        ->middleware(new ApiBoundary())->middleware(new ApiTokenMiddleware($tokens,'items.read'))
        ->describe('listCatalogItems',[],['type'=>'object'],['items.read']);
    $router->post('/api/v1/items',static function(Request $request)use($owner,$data,$collection,$idempotency){
        $input=$request->json();$errors=(new RequestValidator())->validate($input,['title'=>['required','string','min'=>1,'max'=>200]]);
        if(array_diff(array_keys($input),['title'])){$errors['_request']=['unknown_fields'];}
        if($errors){return ApiResponse::error('VALIDATION','Check the title.',422,$errors);}
        $actor=$owner($request);$result=$idempotency->execute($actor,'items.create',$request->header('idempotency-key'),$input,static function()use($actor,$input,$data,$collection):array{
            $id=bin2hex(random_bytes(16));$row=['id'=>$id,'owner_id'=>$actor,'title'=>$input['title']];
            if($data instanceof SqlConnection){$data->table($collection)->insert($row);}else{$data->put($collection,$id,$row);}
            return ['id'=>$id,'title'=>$input['title']];
        });return ApiResponse::data($result,201);
    })->middleware(new ApiBoundary())->middleware(new ApiTokenMiddleware($tokens,'items.write'))
        ->describe('createCatalogItem',['type'=>'object','properties'=>['title'=>['type'=>'string','minLength'=>1,'maxLength'=>200]],'required'=>['title'],'additionalProperties'=>false],['type'=>'object'],['items.write'],201);
    $router->delete('/api/v1/items/{id}',static function(Request $request,string $id)use($owner,$data,$collection){
        $actor=$owner($request);
        if($data instanceof SqlConnection){$deleted=$data->table($collection)->where('owner_id',$actor)->where('id',$id)->delete()>0;}
        else{$row=$data->get($collection,$id);$deleted=$row!==null&&($row['owner_id']??null)===$actor&&$data->delete($collection,$id);}
        return $deleted?ApiResponse::data(['deleted'=>true]):ApiResponse::error('NOT_FOUND','Item not found.',404);
    })->where('id','[a-f0-9]{32}')->middleware(new ApiBoundary())->middleware(new ApiTokenMiddleware($tokens,'items.write'))
        ->describe('deleteCatalogItem',[],['type'=>'object'],['items.write']);
    $router->get('/openapi.json',fn()=>new \Corevia\Http\Response(OpenApi::generate($router,'Corevia catalog reference','1.0.0')));
    $app->boot();return compact('app','router','auth','gate','tokens','data');
};
