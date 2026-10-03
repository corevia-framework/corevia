<?php

declare(strict_types=1);

namespace Corevia\Protocol;

use Corevia\Core\ActionResult;

final class Protocol
{
    public const VERSION = '1.0';
    public const TYPES = ['action.call', 'action.result', 'event', 'subscribe', 'unsubscribe', 'ping', 'pong'];

    public function __construct(public readonly int $maxBytes = 65536)
    {
    }

    public function decode(string $json): array
    {
        if (strlen($json) > $this->maxBytes) {
            throw new \InvalidArgumentException('PAYLOAD_TOO_LARGE');
        }
        try {
            $value = json_decode($json, true, 64, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new \InvalidArgumentException('INVALID_JSON');
        }
        if (!is_array($value) || array_is_list($value)) {
            throw new \InvalidArgumentException('INVALID_ENVELOPE');
        }
        $this->validate($value);
        return $value;
    }

    public function validate(array $value): void
    {
        if (($value['cvp'] ?? null) !== self::VERSION) {
            throw new \InvalidArgumentException('UNSUPPORTED_VERSION');
        }
        if (!is_string($value['id'] ?? null) || $value['id'] === '' || strlen($value['id']) > 128) {
            throw new \InvalidArgumentException('INVALID_REQUEST_ID');
        }
        if (isset($value['meta']) && (!is_array($value['meta']) || (isset($value['meta']['trace_id']) && (!is_string($value['meta']['trace_id']) || strlen($value['meta']['trace_id']) > 128)))) {
            throw new \InvalidArgumentException('INVALID_META');
        }
        if (array_key_exists('ok', $value)) {
            if (!is_bool($value['ok']) || !array_key_exists('result', $value) || !array_key_exists('error', $value)) {
                throw new \InvalidArgumentException('INVALID_RESPONSE');
            }
            if ($value['ok'] && $value['error'] !== null) {
                throw new \InvalidArgumentException('INVALID_RESPONSE');
            }
            if (!$value['ok'] && (!is_array($value['error']) || !is_string($value['error']['code'] ?? null) || !is_string($value['error']['message'] ?? null) || !is_bool($value['error']['retryable'] ?? null))) {
                throw new \InvalidArgumentException('INVALID_RESPONSE');
            }
            return;
        }
        if (!in_array($value['type'] ?? null, self::TYPES, true)) {
            throw new \InvalidArgumentException('INVALID_MESSAGE_TYPE');
        }
        if (!is_array($value['payload'] ?? null)) {
            throw new \InvalidArgumentException('INVALID_PAYLOAD');
        }
        if (in_array($value['type'], ['action.call', 'subscribe', 'unsubscribe', 'event'], true) && (!is_string($value['target'] ?? null) || $value['target'] === '' || strlen($value['target']) > 128)) {
            throw new \InvalidArgumentException('INVALID_TARGET');
        }
    }

    private function jsonSafe(mixed $value): void
    {
        if (is_object($value) || is_resource($value) || (is_float($value) && !is_finite($value))) {
            throw new \InvalidArgumentException('NON_JSON_VALUE');
        }
        if (is_array($value)) {
            foreach ($value as $item) {
                $this->jsonSafe($item);
            }
        }
    }

    public function encode(array $value): string
    {
        $this->validate($value);
        $this->jsonSafe($value);
        $json = json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        if (strlen($json) > $this->maxBytes) {
            throw new \InvalidArgumentException('PAYLOAD_TOO_LARGE');
        }
        return $json;
    }

    public static function request(string $type, string $target = '', array $payload = [], ?string $id = null, ?string $traceId = null): array
    {
        return ['cvp' => self::VERSION, 'id' => $id ?? 'req_' . bin2hex(random_bytes(12)), 'type' => $type, 'target' => $target, 'payload' => $payload, 'meta' => ['trace_id' => $traceId ?? bin2hex(random_bytes(16))]];
    }

    public static function response(string $id, ActionResult $result, ?string $traceId = null): array
    {
        $data = $result->data instanceof \Corevia\Core\State ? $result->data->jsonSerialize() : $result->data;
        return ['cvp' => self::VERSION, 'id' => $id, 'ok' => $result->ok, 'result' => $data, 'error' => $result->error?->jsonSerialize(), 'meta' => ['trace_id' => $traceId]];
    }
}
