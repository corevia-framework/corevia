<?php
declare(strict_types=1);
namespace Corevia\Http;
use Corevia\Core\{SyncStore,TenantScope};

/** Opt-in bearer/application API. Resolver must produce current server-authorized identity. */
final class SyncRoutes
{
    public static function mount(Router $router,SyncStore $store,callable $resolve,string $prefix='/api/v1/sync'):array
    {
        if (!preg_match('#^/[a-zA-Z0-9/_-]{1,100}$#D',$prefix)||str_ends_with($prefix,'/')) { throw new \InvalidArgumentException('Use an explicit sync API prefix.'); }
        $execute=static function(Request $request,string $ability,callable $work)use($resolve):Response {
            try{$scope=$resolve($request);if(!$scope instanceof TenantScope){throw new \LogicException('Sync resolver must return tenant authority.');}$scope->requireAbility($ability);}
            catch(\Throwable){return ApiResponse::error('FORBIDDEN','Current synchronization authority is required.',403);}
            try {
                return ApiResponse::data($work($scope))->withHeaders(['Cache-Control'=>'no-store']);
            } catch (\InvalidArgumentException|\LengthException|\JsonException) { return ApiResponse::error('VALIDATION','Invalid synchronization input.',422); }
            catch (\RuntimeException $error) {
                return match($error->getMessage()) {
                    'SYNC_REVISION_CONFLICT'=>ApiResponse::error('SYNC_REVISION_CONFLICT','Resolve this record conflict explicitly.',409),
                    'SYNC_OPERATION_CONFLICT'=>ApiResponse::error('SYNC_OPERATION_CONFLICT','Operation identifier was reused with changed input.',409),
                    'SYNC_CURSOR_REQUIRES_RESET'=>ApiResponse::error('SYNC_CURSOR_REQUIRES_RESET','Start a fresh synchronization snapshot.',409),
                    'Expired or mismatched sync cursor.','Invalid sync cursor.'=>ApiResponse::error('SYNC_CURSOR_REQUIRES_RESET','Start a fresh synchronization snapshot.',409),
                    default=>throw $error,
                };
            }
        };
        $feed=$router->get($prefix.'/changes',static fn(Request $request)=>$execute($request,'sync.read',static function($scope)use($request,$store):array {
            $query=$request->attributes['query']??[];if (!is_array($query)||array_diff(array_keys($query),['cursor','limit'])) { throw new \InvalidArgumentException('Invalid sync query.'); }
            $cursor=$query['cursor']??null;$limit=filter_var($query['limit']??100,FILTER_VALIDATE_INT);
            if (($cursor!==null&&!is_string($cursor))||$limit===false||$limit<1||$limit>100) { throw new \InvalidArgumentException('Invalid sync page.'); }
            return $store->changes($scope,$cursor,time(),$limit);
        }));
        $mutation=$router->post($prefix.'/mutations',static fn(Request $request)=>$execute($request,'sync.write',static function($scope)use($request,$store):array {
            if (strlen($request->body)>32768) { throw new \LengthException('Sync mutation exceeds input limit.'); }
            $input=$request->json();if (array_diff(array_keys($input),['operation_id','id','expected_revision','data'])||!is_string($input['operation_id']??null)
                ||!is_string($input['id']??null)||!is_int($input['expected_revision']??null)||!array_key_exists('data',$input)||($input['data']!==null&&!is_array($input['data']))) { throw new \InvalidArgumentException('Invalid sync mutation.'); }
            return $store->mutateOnce($scope,$input['operation_id'],$input['id'],$input['expected_revision'],$input['data']);
        }));
        return [$feed,$mutation];
    }
}
