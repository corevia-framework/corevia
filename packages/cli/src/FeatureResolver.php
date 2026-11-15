<?php
declare(strict_types=1);
namespace Corevia\Cli;
use Corevia\Core\{CanonicalJson,Version};

/** Metadata-only extension planning; no code discovery, downloads or hooks. */
final class FeatureResolver
{
    public static function catalog(): array
    {
        $base=['format'=>1,'framework'=>['min'=>'3.0.0','before'=>'5.0.0'],'php'=>['min'=>'8.2.0','before'=>'9.0.0'],'provides'=>[],'requires'=>[],'conflicts'=>[],'extensions'=>[],'hooks'=>[]];
        return [
            'web'=>array_replace($base,['id'=>'web','provides'=>['http.web']]),
            'api'=>array_replace($base,['id'=>'api','conflicts'=>['web','auth','ai'],'provides'=>['http.api']]),
            'auth'=>array_replace($base,['id'=>'auth','requires'=>['web'],'conflicts'=>['api'],'provides'=>['auth.session']]),
            'ai'=>array_replace($base,['id'=>'ai','requires'=>['auth'],'conflicts'=>['api'],'provides'=>['ai.streaming']]),
        ];
    }

    public static function resolve(array $requested,array $catalog,?string $framework=null,?string $php=null): array
    {
        if(!array_is_list($requested)||count($requested)>64||count($catalog)>128){throw new \InvalidArgumentException('Bounded feature lists are required.');}
        foreach($catalog as $id=>$item){
            if(!is_string($id)||!preg_match('/^[a-z][a-z0-9_.-]{0,63}$/D',$id)||!is_array($item)||($item['id']??null)!==$id||($item['format']??null)!==1){throw new \InvalidArgumentException('Invalid extension identity.');}
            if(array_diff(array_keys($item),['format','id','framework','php','requires','provides','conflicts','extensions','hooks'])){throw new \InvalidArgumentException('Unknown extension metadata field.');}
            foreach(['framework','php'] as $runtime){
                $range=$item[$runtime]??null;
                if(!is_array($range)||count($range)!==2||!isset($range['min'],$range['before'])){throw new \InvalidArgumentException('Use explicit min/before runtime bounds.');}
                foreach($range as $value){if(!is_string($value)||!preg_match('/^\d+\.\d+\.\d+$/D',$value)){throw new \InvalidArgumentException('Invalid runtime version bound.');}}
                if(version_compare($range['min'],$range['before'],'>=')){throw new \InvalidArgumentException('Empty runtime version range.');}
            }
            foreach(['requires','provides','conflicts','extensions','hooks'] as $field){
                $list=$item[$field]??null;
                if(!is_array($list)||!array_is_list($list)||count($list)>64){throw new \InvalidArgumentException('Invalid extension metadata list.');}
                foreach($list as $value){if(!is_string($value)||!preg_match('/^[a-z][a-z0-9_.-]{0,63}$/D',$value)){throw new \InvalidArgumentException('Invalid extension requirement.');}}
                $item[$field]=array_values(array_unique($list));sort($item[$field],SORT_STRING);
            }
            if($item['hooks']!==[]){throw new \LogicException('Executable extension hooks are unsupported; metadata is not a trust grant.');}
            $catalog[$id]=$item;
        }
        $active=[];$visiting=[];$order=[];
        $visit=static function(string $id)use(&$visit,&$active,&$visiting,&$order,$catalog,$framework,$php):void{
            if(isset($active[$id])){return;}if(isset($visiting[$id])){throw new \LogicException('Extension dependency cycle.');}
            $item=$catalog[$id]??throw new \InvalidArgumentException('Unknown selected feature.');
            foreach(['framework'=>$framework??Version::VERSION,'php'=>$php??PHP_VERSION] as $runtime=>$version){
                if(version_compare($version,$item[$runtime]['min'],'<')||version_compare($version,$item[$runtime]['before'],'>=')){throw new \LogicException('Extension runtime range is incompatible.');}
            }
            $visiting[$id]=true;$dependencies=$item['requires'];sort($dependencies,SORT_STRING);
            foreach($dependencies as $dependency){$visit($dependency);}
            unset($visiting[$id]);$active[$id]=true;$order[]=$id;
        };
        foreach($requested as $id){if(!is_string($id)||!isset($catalog[$id])){throw new \InvalidArgumentException('Unknown selected feature.');}}
        $requested=array_values(array_unique($requested));sort($requested,SORT_STRING);
        foreach($requested as $id){$visit($id);}
        $capabilities=[];$extensions=[];
        foreach($order as $id){
            foreach($catalog[$id]['conflicts'] as $conflict){if(isset($active[$conflict])){throw new \LogicException('Selected features conflict.');}}
            foreach($catalog[$id]['provides'] as $capability){if(isset($capabilities[$capability])){throw new \LogicException('Ambiguous capability provider.');}$capabilities[$capability]=$id;}
            $extensions=[...$extensions,...$catalog[$id]['extensions']];
        }
        ksort($capabilities,SORT_STRING);$extensions=array_values(array_unique($extensions));sort($extensions,SORT_STRING);
        $selected=[];foreach($order as $id){$selected[$id]=$catalog[$id];}
        return ['format'=>1,'requested'=>$requested,'order'=>$order,'capabilities'=>$capabilities,'extensions'=>$extensions,
            'manifest_fingerprint'=>hash('sha256',CanonicalJson::encode($selected)),'hooks_executed'=>false];
    }
}
