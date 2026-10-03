<?php

declare(strict_types=1);

namespace Corevia\Http;

use Corevia\Core\ActionResult;
use Corevia\Core\Corevia;
use Corevia\Protocol\Protocol;

final class Router
{
    private array $routes = [];

    public function __construct(private readonly Corevia $app, private readonly array $origins = [], private readonly int $maxBytes = 65536)
    {
    }

    public function add(string $method, string $path, callable $handler): Route
    {
        if (!in_array($method, ['GET', 'POST', 'PUT', 'PATCH', 'DELETE'], true)) {
            throw new \InvalidArgumentException('Unsupported route method.');
        }
        return $this->routes[] = new Route($method, $path, \Closure::fromCallable($handler));
    }

    public function flow(string $method, string $path, string $flow): Route
    {
        return $this->add($method, $path, function (Request $request) use ($flow): Response {
            $result = $this->app->run($flow, $request->json(), $request->header('idempotency-key') ?: null, $request->header('x-trace-id'), $request->header('x-request-id'));
            $status = match ($result->status()) { 'failed' => 422, 'running' => 202, default => 200 };
            $envelope = Protocol::response($request->header('x-request-id'), $result->status() === 'failed' ? ActionResult::failure($result->run['error']['code'], $result->run['error']['message'], $result->run['error']['retryable']) : ActionResult::success($result->run), $request->header('x-trace-id'));
            $envelope['result'] = $result->run;
            return new Response($envelope, $status);
        });
    }

    /** No action route exists unless explicitly enabled with an allowlist and authorization callback. */
    public function actionGateway(array $allowlist, callable $authorize): Route
    {
        return $this->add('POST', '/cvp', function (Request $request) use ($allowlist, $authorize): Response {
            $envelope = (new Protocol($this->maxBytes))->decode($request->body);
            $trace = $envelope['meta']['trace_id'] ?? $request->header('x-trace-id');
            if (($envelope['type'] ?? '') !== 'action.call' || !in_array($envelope['target'], $allowlist, true) || !$authorize($request, $envelope['target'])) {
                return new Response(Protocol::response($envelope['id'], ActionResult::failure('FORBIDDEN', 'Action call is not authorized.'), $trace), 403);
            }
            $result = $this->app->call($envelope['target'], $envelope['payload'], $trace, $envelope['id']);
            return new Response(Protocol::response($envelope['id'], $result, $trace), $result->ok ? 200 : 422);
        });
    }

    public function routes(): array
    {
        return array_map(static fn (Route $route): array => ['method' => $route->method, 'path' => $route->path], $this->routes);
    }

    public function dispatch(Request $request): Response
    {
        $safeId = static fn (string $id): string => preg_match('/^[A-Za-z0-9_.:-]{1,128}$/D', $id) ? $id : bin2hex(random_bytes(16));
        $id = $safeId($request->header('x-request-id'));
        $trace = $safeId($request->header('x-trace-id'));
        $headers = ['X-Request-Id' => $id, 'X-Trace-Id' => $trace, 'X-Content-Type-Options' => 'nosniff'];
        $request = new Request($request->method, $request->path, [...array_change_key_case($request->headers), 'x-request-id' => $id, 'x-trace-id' => $trace], $request->body, $request->params);
        $error = static fn (string $code, int $status): Response => new Response(Protocol::response($id, ActionResult::failure($code, $code), $trace), $status, $headers);
        $origin = $request->header('origin');
        if ($origin !== '') {
            if (!in_array($origin, $this->origins, true)) {
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
                $pattern = preg_replace_callback('/\\\{([A-Za-z_][A-Za-z0-9_]*)\\\}/', static function (array $match) use (&$names): string {
                    $names[] = $match[1];
                    return '([^/]+)';
                }, preg_quote($route->path, '#'));
                if (!preg_match('#^' . $pattern . '$#D', $request->path, $matches)) {
                    continue;
                }
                $pathMatched = true;
                if ($route->method !== $request->method) {
                    continue;
                }
                array_shift($matches);
                $request = $request->withParams(array_combine($names, array_map('rawurldecode', $matches)) ?: []);
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
