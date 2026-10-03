<?php

declare(strict_types=1);

namespace Corevia\Core;

final readonly class FlowResult implements \JsonSerializable
{
    public function __construct(public array $run)
    {
    }

    public function status(): string
    {
        return $this->run['status'];
    }

    public function jsonSerialize(): array
    {
        return $this->run;
    }
}
