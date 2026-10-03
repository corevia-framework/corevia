<?php

declare(strict_types=1);

namespace Corevia\Core;

class State implements \JsonSerializable
{
    public function __construct(public array $data = [])
    {
    }

    public function jsonSerialize(): array
    {
        return $this->data;
    }
}
