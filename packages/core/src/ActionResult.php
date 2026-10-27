<?php

declare(strict_types=1);

namespace Corevia\Core;

final readonly class ActionResult implements \JsonSerializable
{
    private function __construct(public bool $ok, public mixed $data, public ?Error $error, public array $metadata = [])
    {
    }

    public static function success(mixed $data = null): self
    {
        return new self(true, $data, null);
    }

    public static function failure(string $code, string $message, bool $retryable = false, array $metadata = []): self
    {
        return new self(false, null, new Error($code, $message, $retryable), $metadata);
    }

    public function jsonSerialize(): array
    {
        return ['ok' => $this->ok, 'result' => $this->data, 'error' => $this->error];
    }
}
