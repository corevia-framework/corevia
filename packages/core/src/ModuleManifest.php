<?php

declare(strict_types=1);

namespace Corevia\Core;

final readonly class ModuleManifest implements \JsonSerializable
{
    /** @param list<string> $dependencies @param list<string> $requires */
    public function __construct(
        public string $id,
        public string $version = '1.0.0',
        public array $dependencies = [],
        public string $description = '',
        public array $requires = [],
        public array $defaults = [],
    ) {
        if ($id === '' || strlen($id) > 128 || !preg_match('/^\d+\.\d+\.\d+(?:-[0-9A-Za-z.-]+)?(?:\+[0-9A-Za-z.-]+)?$/D', $version)) {
            throw new \InvalidArgumentException('Invalid module ID or version.');
        }
        foreach ([$dependencies, $requires] as $names) {
            if (!array_is_list($names) || count(array_unique($names, SORT_REGULAR)) !== count($names)) {
                throw new \InvalidArgumentException('Manifest references must be unique lists.');
            }
            foreach ($names as $name) {
                if (!is_string($name) || $name === '') {
                    throw new \InvalidArgumentException('Manifest references must be names.');
                }
            }
        }
        new ConfigRepository($defaults);
    }

    public function jsonSerialize(): array
    {
        return get_object_vars($this);
    }
}
