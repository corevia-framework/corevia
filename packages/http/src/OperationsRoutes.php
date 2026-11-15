<?php
declare(strict_types=1);
namespace Corevia\Http;
use Corevia\Core\{Corevia,Telemetry};

/** Explicit opt-in private operations routes. Every request requires host-supplied authorization. */
final class OperationsRoutes
{
    public static function mount(Router $router,Corevia $app,Telemetry $telemetry,callable $authorize,
        array $readiness=[],string $environment='production',?callable $queries=null,?callable $jobs=null):void
    {
        if (!in_array($environment,['development','testing','production'],true)||count($readiness)>32) { throw new \InvalidArgumentException('Invalid operations configuration.'); }
        foreach ($readiness as $name=>$check) {
            if (!is_string($name)||!preg_match('/^[a-z][a-z0-9_.-]{0,63}$/D',$name)||!is_callable($check)) { throw new \InvalidArgumentException('Register named readiness checks.'); }
        }
        $guard=static function(Request $request,callable $work)use($authorize):Response {
            try { $allowed=$authorize($request)===true; } catch (\Throwable) { $allowed=false; }
            if (!$allowed) { return new Response(['error'=>'NOT_FOUND'],404,['Cache-Control'=>'no-store']); }
            return $work()->withHeaders(['Cache-Control'=>'no-store','X-Content-Type-Options'=>'nosniff']);
        };
        $router->get('/_corevia/live',fn(Request $r)=>$guard($r,fn()=>new Response(['live'=>true])));
        $router->get('/_corevia/ready',fn(Request $r)=>$guard($r,static function()use($readiness,$app):Response {
            $ready=$app->kernel->state()===\Corevia\Core\LifecycleState::Ready;$states=['runtime'=>$ready];
            foreach ($readiness as $name=>$check) { try { $ok=$check()===true; } catch (\Throwable) { $ok=false; }$states[$name]=$ok;$ready=$ready&&$ok; }
            return new Response(['ready'=>$ready,'checks'=>$states],$ready?200:503);
        }));
        $router->get('/_corevia/metrics',fn(Request $r)=>$guard($r,fn()=>new Response($telemetry->prometheus(),200,['Content-Type'=>'text/plain; version=0.0.4; charset=utf-8'])));
        if ($environment==='production') { return; }
        $router->get('/_corevia/inspect',fn(Request $r)=>$guard($r,static function()use($router,$app,$telemetry,$queries,$jobs):Response {
            $queryMetadata=[];
            foreach (($queries===null?[]:$queries()) as $fingerprint=>$row) {
                if (count($queryMetadata)>=100) { break; }
                if (is_string($fingerprint)&&preg_match('/^[a-f0-9]{64}$/D',$fingerprint)&&is_array($row)&&is_int($row['count']??null)&&$row['count']>=0
                    &&is_numeric($row['duration_ms']??null)&&is_finite((float)$row['duration_ms'])&&(float)$row['duration_ms']>=0) {
                    $queryMetadata[$fingerprint]=['count'=>$row['count'],'duration_ms'=>(float)$row['duration_ms']];
                }
            }
            $counts=[];$status=$jobs===null?[]:$jobs();
            foreach (['pending','running','done','dead'] as $state) { $value=$status[$state]??0;$counts[$state]=is_int($value)&&$value>=0?$value:0; }
            return new Response(['routes'=>array_slice($router->routes(),0,500),'flows'=>array_slice(array_keys($app->flows()),0,500),
                'queries'=>$queryMetadata,'jobs'=>$counts,'telemetry'=>$telemetry->snapshot()]);
        }));
    }
}
