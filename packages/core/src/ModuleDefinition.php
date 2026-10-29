<?php
declare(strict_types=1);
namespace Corevia\Core;

/** Lazy catalog metadata: constructing this definition never loads its module class. */
final readonly class ModuleDefinition implements \JsonSerializable
{
    public function __construct(public string $id, public string $class, public array $profiles, public array $dependencies = [])
    {
        if(!preg_match('/^[a-z][a-z0-9_.-]{0,127}$/D',$id) || !preg_match('/^[A-Za-z_][A-Za-z0-9_\\\\]*$/D',$class)){throw new \InvalidArgumentException('Use a stable module ID and named module class.');}
        foreach([$profiles,$dependencies] as $names){if(!array_is_list($names) || count(array_unique($names,SORT_REGULAR))!==count($names)){throw new \InvalidArgumentException('Catalog references must be unique lists.');}foreach($names as $name){if(!is_string($name) || !preg_match('/^[a-z][a-z0-9_.-]{0,127}$/D',$name)){throw new \InvalidArgumentException('Invalid catalog reference.');}}}
        foreach($profiles as $profile){RuntimeProfile::featuresFor($profile);}
    }
    public function jsonSerialize():array{$profiles=$this->profiles;$dependencies=$this->dependencies;sort($profiles,SORT_STRING);sort($dependencies,SORT_STRING);return ['id'=>$this->id,'class'=>$this->class,'profiles'=>$profiles,'dependencies'=>$dependencies];}
}
