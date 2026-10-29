<?php
declare(strict_types=1);
namespace Corevia\Core;

/** A runtime policy selects modules; it does not claim external adapters are installed. */
final readonly class RuntimeProfile implements \JsonSerializable
{
    private const PRESETS=[
        'web'=>['http','views','actions'], 'api'=>['http','actions'], 'worker'=>['jobs'],
        'realtime'=>['websocket'], 'ai'=>['http','actions','python'],
        'full'=>['http','views','actions','jobs','websocket','python'],
    ];
    private function __construct(public string $name,public array $features,public array $selected,public array $definitions,public array $included,public array $excluded){}
    public static function presets():array{return self::PRESETS;}
    public static function featuresFor(string $name):array{return self::PRESETS[$name]??throw new \InvalidArgumentException('Unknown runtime profile: '.$name);}
    public static function resolve(string $name,array $catalog,array $include=[],array $exclude=[]):self
    {
        $features=self::featuresFor($name);$definitions=[];
        foreach($catalog as $definition){if(!$definition instanceof ModuleDefinition){throw new \InvalidArgumentException('The module catalog must contain ModuleDefinition entries.');}if(isset($definitions[$definition->id])){throw new \InvalidArgumentException('Duplicate catalog module: '.$definition->id);}$definitions[$definition->id]=$definition;}
        ksort($definitions,SORT_STRING);
        foreach([$include,$exclude] as $ids){if(!array_is_list($ids)){throw new \InvalidArgumentException('Profile overrides must be lists.');}foreach($ids as $id){if(!is_string($id) || !isset($definitions[$id])){throw new \InvalidArgumentException('Unknown module in profile override.');}}}
        if(array_intersect($include,$exclude)!==[]){throw new \LogicException('A module cannot be included and excluded.');}
        $selected=[];$active=[];
        $visit=function(string $id)use(&$visit,&$selected,&$active,$definitions,$exclude):void{
            if(isset($selected[$id])){return;}if(isset($active[$id])){throw new \LogicException('Circular catalog dependency: '.$id);}
            if(!isset($definitions[$id]) || in_array($id,$exclude,true)){throw new \LogicException('Unavailable profile dependency: '.$id);}
            $active[$id]=true;$dependencies=$definitions[$id]->dependencies;sort($dependencies,SORT_STRING);foreach($dependencies as $dependency){$visit($dependency);}unset($active[$id]);$selected[$id]=true;
        };
        foreach($definitions as $id=>$definition){if(!in_array($id,$exclude,true) && ($name==='full' || in_array($name,$definition->profiles,true) || in_array($id,$include,true))){$visit($id);}}
        $include=array_values(array_unique($include));$exclude=array_values(array_unique($exclude));sort($include,SORT_STRING);sort($exclude,SORT_STRING);sort($features,SORT_STRING);
        return new self($name,$features,array_keys($selected),$definitions,$include,$exclude);
    }
    public function allows(string $feature):bool{return in_array($feature,$this->features,true);}
    public function jsonSerialize():array{return ['name'=>$this->name,'features'=>$this->features,'selected'=>$this->selected,'include'=>$this->included,'exclude'=>$this->excluded,'catalog'=>array_map(static fn(ModuleDefinition $definition):array=>$definition->jsonSerialize(),$this->definitions)];}
}
