<?php

declare(strict_types=1);

namespace Corevia\Protocol;

use Corevia\Core\ActionResult;

final class RemoteAction
{
    public function __construct(private readonly string $url, private readonly ?string $token = null, private readonly float $timeout = 5.0, private readonly Protocol $protocol = new Protocol(), private readonly string $version = Protocol::VERSION, private readonly ?\Closure $sign = null)
    {
        if ($timeout <= 0 || $timeout > 300 || !in_array($version, Protocol::SUPPORTED, true) || !in_array(parse_url($url, PHP_URL_SCHEME), ['http', 'https'], true)) {
            throw new \InvalidArgumentException('Remote calls require HTTP(S) and a positive timeout.');
        }
        $host = parse_url($url, PHP_URL_HOST);
        if (!is_string($host) || preg_match('/[\x00-\x20]/', $url) || parse_url($url, PHP_URL_USER) !== null || parse_url($url, PHP_URL_PASS) !== null) { throw new \InvalidArgumentException('Invalid remote endpoint.'); }
        if (!in_array(strtolower($host), ['localhost','127.0.0.1','::1','[::1]'], true) && $token === null && $sign === null) { throw new \InvalidArgumentException('Remote endpoints require a bearer token or signing policy.'); }
    }

    public function call(string $name, array $payload, ?string $traceId = null, ?string $requestId = null, ?string $parentTraceId = null): ActionResult
    {
        $request = Protocol::request('action.call', $name, $payload, $requestId, $traceId, $this->version, $parentTraceId, max(1, (int) ($this->timeout * 1000)));
        $wire = $this->protocol->encode($request);
        $headers = "Content-Type: application/json\r\n";
        if ($this->token !== null) {
            if (preg_match('/[\r\n]/', $this->token)) {
                return ActionResult::failure('INVALID_AUTH', 'Invalid token.');
            }
            $headers .= 'Authorization: Bearer ' . $this->token . "\r\n";
        }
        if ($this->sign !== null) {
            foreach (($this->sign)($wire) as $name => $value) {
                if (!is_string($name) || !preg_match('/^[A-Za-z0-9-]+$/D', $name) || !is_string($value) || preg_match('/[\r\n]/', $value)) { return ActionResult::failure('INVALID_AUTH', 'Invalid signature headers.'); }
                $headers .= $name . ': ' . $value . "\r\n";
            }
        }
        $context = stream_context_create(['http' => ['method' => 'POST', 'header' => $headers, 'content' => $wire, 'timeout' => $this->timeout, 'ignore_errors' => true, 'follow_location' => 0]]);
        set_error_handler(static function (): bool { return true; });
        $started = hrtime(true);
        try {
            $stream = fopen($this->url, 'rb', false, $context);
            if ($stream === false) {
                if ((hrtime(true) - $started) / 1e9 >= $this->timeout) { return ActionResult::failure('REMOTE_TIMEOUT', 'Remote runtime timed out.', true); }
                return ActionResult::failure('REMOTE_UNAVAILABLE', 'Remote runtime unavailable.', true);
            }
            try {
                $raw = stream_get_contents($stream, $this->protocol->maxBytes + 1);
                if ($raw === false || (stream_get_meta_data($stream)['timed_out'] ?? false)) {
                    return ActionResult::failure('REMOTE_TIMEOUT', 'Remote runtime timed out.', true);
                }
            } finally {
                fclose($stream);
            }
        } finally {
            restore_error_handler();
        }
        try {
            $response = $this->protocol->decode($raw);
            if ($response['id'] !== $request['id'] || $response['cvp'] !== $this->version || !array_key_exists('ok', $response)) {
                throw new \InvalidArgumentException('Mismatched response.');
            }
            return $response['ok'] ? ActionResult::success($response['result']) : ActionResult::failure($response['error']['code'], $response['error']['message'], $response['error']['retryable']);
        } catch (\Throwable) {
            return ActionResult::failure('REMOTE_PROTOCOL_ERROR', 'Invalid remote response.');
        }
    }
}
