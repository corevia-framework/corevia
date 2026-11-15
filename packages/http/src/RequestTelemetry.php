<?php
declare(strict_types=1);
namespace Corevia\Http;
use Corevia\Core\{Deadline,Telemetry,TraceContext,WorkContext};

/** Attach per route with a static operation name. Incoming time budgets can only shorten server limits. */
final readonly class RequestTelemetry implements Middleware
{
    public function __construct(private Telemetry $telemetry,private string $operation,private int $maximumMs=5000,private ?string $streamOperation=null)
    {
        if ($maximumMs<1||$maximumMs>300000) { throw new \InvalidArgumentException('HTTP work budget must be 1..300000 ms.'); }
    }
    public function process(Request $request,callable $next):Response
    {
        $incoming=$request->header('x-corevia-timeout-ms');$budget=$this->maximumMs;
        if ($incoming!==''&&preg_match('/^[1-9][0-9]{0,5}$/D',$incoming)) { $budget=min($budget,(int)$incoming); }
        $parent=TraceContext::parse($request->header('traceparent'));
        return WorkContext::run(new Deadline($budget),fn()=>$this->telemetry->span($this->operation,function($trace)use($request,$next):Response {
            $context=WorkContext::current();$response=$next($request->withAttribute('work_context',$context));
            if ($response->body instanceof \Closure) {
                $producer=$response->body;
                $body=function()use($context,$producer):void {
                    WorkContext::resume($context,function()use($producer):void {
                        if ($this->streamOperation===null) { $producer(); }
                        else { $this->telemetry->span($this->streamOperation,fn()=>$producer()); }
                    });
                };
                $response=new Response($body,$response->status,$response->headers);
            }
            return $response->withHeaders(['traceparent'=>$trace->header()]);
        },$parent));
    }
}
