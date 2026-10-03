<?php

declare(strict_types=1);

namespace Corevia\Core;

final readonly class Error implements \JsonSerializable
{
    public function __construct(public string $code, public string $message, public bool $retryable = false)
    {
    }

    public function jsonSerialize(): array
    {
        return get_object_vars($this);
    }
}
