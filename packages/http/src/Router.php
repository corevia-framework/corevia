<?php

declare(strict_types=1);

namespace Corevia\Http;

use Corevia\Core\ActionResult;
use Corevia\Core\Corevia;
use Corevia\Protocol\Protocol;
use Corevia\Protocol\{ContractManifest,ContractViolation};

final class Router
{
    private array $routes = [];
    private string $prefix = '';
    private string $namePrefix = '';
    private array $groupMiddleware = [];

    public function __construct(private readonly Corevia $app, private readonly array $origins = [], private readonly int $maxBytes = 65536)
    {
        $app->requireFeature('http');
        $app->capabilities->define('http', metadata: ['adapter' => self::class]);
    }

    public function add(string $method, string $path, callable|array|string $handler): Route
    {
        if (!in_array($method, ['GET', 'POST', 'PUT', 'PATCH', 'DELETE'], true)) {
            throw new \InvalidArgumentException('Unsupported route method.');
        }
        $path=$this->prefix.$path;
        if(!str_starts_with($path,'/') || str_contains($path,'?') || str_contains($path,'#') || preg_match('/[\x00-\x20]/',$path)){throw new \InvalidArgumentException('Invalid route path.');}
        preg_match_all('/\{([A-Za-z_][A-Za-z0-9_]*)\}/',$path,$parameters);
        if(count($parameters[1])!==count(array_unique($parameters[1]))){throw new \InvalidArgumentException('Duplicate path parameter.');}
        foreach($this->routes as $registered){if($registered->method===$method && $registered->path===$path){throw new \InvalidArgumentException('Duplicate route.');}}
        $closure=function(Request $request)use($handler):Response{
            $output=$this->invoke($handler,$request);
            return $output instanceof Response?$output:(is_string($output)?Response::html($output):new Response($output));
        };
        $validate=function(string $name,Route $route):void{foreach($this->routes as $existing){if($existing!==$route && $existing->routeName===$name){throw new \InvalidArgumentException('Duplicate route name.');}}};
        $route=new Route($method,$path,$closure,$validate,$this->namePrefix);$route->middlewares=$this->groupMiddleware;
        return $this->routes[]=$route;
    }

    private function invoke(callable|array|string $handler,Request $request): mixed
    {
        if(is_array($handler) && is_string($handler[0] ?? null) && class_exists($handler[0])){$handler=[$this->app->container->get($handler[0]),$handler[1]];}
        elseif(is_string($handler) && class_exists($handler)){$handler=$this->app->container->get($handler);}
        if(!is_callable($handler)){throw new \InvalidArgumentException('Route handler is not callable.');}
        $closure=\Closure::fromCallable($handler);$arguments=[];
        foreach((new \ReflectionFunction($closure))->getParameters() as $index=>$parameter){
            $type=$parameter->getType();$name=$parameter->getName();
            if($type instanceof \ReflectionNamedType && !$type->isBuiltin()){$class=$type->getName();$arguments[]=$request instanceof $class?$request:$this->app->container->get($class);}
            elseif(array_key_exists($name,$request->params)){
                $value=$request->params[$name];
                if($type instanceof \ReflectionNamedType && $type->getName()==='int'){$value=filter_var($value,FILTER_VALIDATE_INT);if($value===false){throw new \InvalidArgumentException('Invalid integer route parameter.');}}
                $arguments[]=$value;
            }elseif($parameter->isDefaultValueAvailable()){$arguments[]=$parameter->getDefaultValue();}
            elseif($index===0 && $type===null){$arguments[]=$request;}
            else{throw new \InvalidArgumentException('Missing route handler argument: '.$name);}
        }
        return $closure(...$arguments);
    }
    public function get(string $path,callable|array|string $handler): Route{return $this->add('GET',$path,$handler);}
    public function post(string $path,callable|array|string $handler): Route{return $this->add('POST',$path,$handler);}
    public function put(string $path,callable|array|string $handler): Route{return $this->add('PUT',$path,$handler);}
    public function patch(string $path,callable|array|string $handler): Route{return $this->add('PATCH',$path,$handler);}
    public function delete(string $path,callable|array|string $handler): Route{return $this->add('DELETE',$path,$handler);}
    public function view(string $path,string $view,array $data=[]): Route{$this->app->requireFeature('views');return $this->get($path,fn():Response=>Response::html($this->app->container->get(View::class)->render($view,$data)));}
    public function redirect(string $path,string $destination,int $status=302): Route{return $this->get($path,fn():Response=>Response::redirect($destination,$status));}
    public function group(string $prefix,callable $routes,array $middleware=[],string $namePrefix=''): void
    {
        foreach($middleware as $item){if(!$item instanceof Middleware){throw new \InvalidArgumentException('Expected middleware.');}}
        $previous=[$this->prefix,$this->groupMiddleware,$this->namePrefix];$this->prefix.=rtrim($prefix,'/');$this->groupMiddleware=[...$this->groupMiddleware,...$middleware];$this->namePrefix.=$namePrefix;
        try{$routes($this);}finally{[$this->prefix,$this->groupMiddleware,$this->namePrefix]=$previous;}
    }
    public function url(string $name,array $parameters=[],array $query=[]): string
    {
        foreach($this->routes as $route){if($route->routeName!==$name){continue;}
            $path=preg_replace_callback('/\{([A-Za-z_][A-Za-z0-9_]*)\}/',function(array $match)use($parameters,$route):string{
                $value=$parameters[$match[1]] ?? throw new \InvalidArgumentException('Missing URL parameter: '.$match[1]);
                if(!is_scalar($value) || (string)$value==='' || (isset($route->constraints[$match[1]]) && preg_match('#^(?:'.$route->constraints[$match[1]].')$#D',(string)$value)!==1)){throw new \InvalidArgumentException('Invalid URL parameter.');}
                return rawurlencode((string)$value);
            },$route->path);
            return $path.($query?'?'.http_build_query($query,'','&',PHP_QUERY_RFC3986):'');
        }throw new \OutOfBoundsException('Named route not found.');
    }
    public function resource(string $path,string $controller,string $name): void
    {
        $this->get($path,[$controller,'index'])->name($name.'.index');
        $this->get($path.'/create',[$controller,'create'])->name($name.'.create');
        $this->post($path,[$controller,'store'])->name($name.'.store');
        $this->get($path.'/{id}/edit',[$controller,'edit'])->name($name.'.edit');
        $this->get($path.'/{id}',[$controller,'show'])->name($name.'.show');
        $this->put($path.'/{id}',[$controller,'update'])->name($name.'.update');
        $this->patch($path.'/{id}',[$controller,'update']);
        $this->delete($path.'/{id}',[$controller,'destroy'])->name($name.'.destroy');
    }

    public function flow(string $method, string $path, string $flow): Route
    {
        return $this->add($method, $path, function (Request $request) use ($flow): Response {
            $result = $this->app->run($flow, $request->json(), $request->header('idempotency-key') ?: null, $request->header('x-trace-id'), $request->header('x-request-id'));
            $status = match ($result->status()) { 'failed' => 422, 'running', 'paused', 'compensating' => 202, default => 200 };
            $envelope = Protocol::response($request->header('x-request-id'), $result->status() === 'failed' ? ActionResult::failure($result->run['error']['code'], $result->run['error']['message'], $result->run['error']['retryable']) : ActionResult::success($result->run), $request->header('x-trace-id'));
            $envelope['result'] = $result->run;
            return new Response($envelope, $status);
        });
    }

    /** No action route exists unless explicitly enabled with an allowlist and authorization callback. */
    public function actionGateway(array $allowlist, callable $authorize, ?ContractManifest $contract = null): Route
    {
        $this->app->requireFeature('actions');
        return $this->add('POST', '/cvp', function (Request $request) use ($allowlist, $authorize, $contract): Response {
            $envelope = (new Protocol($this->maxBytes))->decode($request->body);
            $trace = $envelope['meta']['trace_id'] ?? $request->header('x-trace-id');
            if (($envelope['type'] ?? '') !== 'action.call' || !in_array($envelope['target'], $allowlist, true) || !$authorize($request, $envelope['target'], $contract?->requirements($envelope['target']) ?? [])) {
                return new Response(Protocol::response($envelope['id'], ActionResult::failure('FORBIDDEN', 'Action call is not authorized.'), $trace, $envelope['cvp']), 403);
            }
            if ($contract !== null) {
                try { $shape = json_decode($request->body, false, 64, JSON_THROW_ON_ERROR); $contract->assertInput($envelope['target'], $shape->payload); }
                catch (ContractViolation) { return new Response(Protocol::response($envelope['id'], ActionResult::failure('CONTRACT_INPUT_INVALID', 'Action input does not match its contract.'), $trace, $envelope['cvp']), 400); }
            }
            $result = $this->app->call($envelope['target'], $envelope['payload'], $trace, $envelope['id']);
            if ($contract !== null) {
                try { if ($result->ok) { $contract->assertOutput($envelope['target'], $result->data instanceof \Corevia\Core\State ? $result->data->data : $result->data); } else { $contract->assertError($envelope['target'], $result->error->code, $result->error->retryable); } }
                catch (ContractViolation) { return new Response(Protocol::response($envelope['id'], ActionResult::failure('CONTRACT_OUTPUT_INVALID', 'Action result does not match its contract.'), $trace, $envelope['cvp']), 500); }
            }
            return new Response(Protocol::response($envelope['id'], $result, $trace, $envelope['cvp'], $envelope['meta']['parent_trace_id'] ?? null), $result->ok ? 200 : 422);
        });
    }

    public function routes(): array
    {
        return array_map(static fn (Route $route): array => ['method' => $route->method, 'path' => $route->path, 'name' => $route->routeName], $this->routes);
    }

    public function dispatch(Request $request): Response
    {
        if ($this->app->scopes->current() === null) { return $this->app->scope('http', fn (): Response => $this->dispatch($request)); }
        $safeId = static fn (string $id): string => preg_match('/^[A-Za-z0-9_.:-]{1,128}$/D', $id) ? $id : bin2hex(random_bytes(16));
        $id = $safeId($request->header('x-request-id'));
        $trace = $safeId($request->header('x-trace-id'));
        $headers = ['X-Request-Id' => $id, 'X-Trace-Id' => $trace, 'X-Content-Type-Options' => 'nosniff'];
        $request = new Request($request->method, $request->path, [...array_change_key_case($request->headers), 'x-request-id' => $id, 'x-trace-id' => $trace], $request->body, $request->params, $request->attributes);
        $error = static fn (string $code, int $status): Response => new Response(Protocol::response($id, ActionResult::failure($code, $code), $trace), $status, $headers);
        $origin = $request->header('origin');
        if ($origin !== '') {
            $sameOrigin=$request->header('host')!=='' && strcasecmp($origin,($request->attributes['scheme'] ?? 'http').'://'.$request->header('host'))===0;
            if (!$sameOrigin && !in_array($origin, $this->origins, true)) {
                return $error('ORIGIN_DENIED', 403);
            }
            $headers += ['Access-Control-Allow-Origin' => $origin, 'Vary' => 'Origin', 'Access-Control-Allow-Methods' => 'GET, POST, PUT, PATCH, DELETE', 'Access-Control-Allow-Headers' => 'Content-Type, Authorization, Idempotency-Key, X-Request-Id, X-Trace-Id', 'Access-Control-Expose-Headers' => 'X-Request-Id, X-Trace-Id'];
            if ($request->method === 'OPTIONS') {
                return new Response([], 204, $headers);
            }
        }
        if (strlen($request->body) > $this->maxBytes) {
            return $error('PAYLOAD_TOO_LARGE', 413);
        }
        try {
            $pathMatched = false;
            foreach ($this->routes as $route) {
                $names = [];
                $pattern = preg_replace_callback('/\\\{([A-Za-z_][A-Za-z0-9_]*)\\\}/', static function (array $match) use (&$names,$route): string {
                    $names[] = $match[1];
                    return '(?P<'.$match[1].'>'.($route->constraints[$match[1]] ?? '[^/]+').')';
                }, preg_quote($route->path, '#'));
                if (!preg_match('#^' . $pattern . '$#D', $request->path, $matches)) {
                    continue;
                }
                $pathMatched = true;
                if ($route->method !== $request->method && !($request->method==='HEAD' && $route->method==='GET')) {
                    continue;
                }
                $params=[];foreach($names as $name){$params[$name]=rawurldecode($matches[$name]);}
                $request = $request->withParams($params);
                $next = $route->handler;
                foreach (array_reverse($route->middlewares) as $middleware) {
                    $downstream = $next;
                    $next = static fn (Request $r): Response => $middleware->process($r, $downstream);
                }
                return $next($request)->withHeaders($headers);
            }
            return $error($pathMatched ? 'METHOD_NOT_ALLOWED' : 'NOT_FOUND', $pathMatched ? 405 : 404);
        } catch (\JsonException|\InvalidArgumentException $exception) {
            return $error('INVALID_REQUEST', 400);
        } catch (\DomainException $exception) {
            return $error($exception->getMessage() === 'IDEMPOTENCY_CONFLICT' ? 'IDEMPOTENCY_CONFLICT' : 'CONFLICT', 409);
        } catch (\Throwable $exception) {
            $this->app->logger->log('error', 'HTTP request failed', ['request_id' => $id, 'trace_id' => $trace, 'exception' => $exception::class]);
            return $error('INTERNAL_ERROR', 500);
        }
    }
}
