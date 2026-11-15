<?php
declare(strict_types=1);
namespace Corevia\Cli;

/** Read-only capability diagnostics. Operator declarations never become measured test passes. */
final class HostingInspector
{
    private const FEATURES = ['cron','workers','python','websocket','outbound_https'];

    public static function policy(array $policy): array
    {
        if (array_diff(array_keys($policy), ['extensions','features','minimum_memory_mb','declarations'])) {
            throw new \InvalidArgumentException('Unknown hosting policy field.');
        }
        foreach (['extensions','features'] as $key) {
            $values = $policy[$key] ?? [];
            if (!is_array($values) || !array_is_list($values) || count($values)>32) {
                throw new \InvalidArgumentException('Hosting requirements must be bounded lists.');
            }
            foreach ($values as $value) {
                if (!is_string($value) || !preg_match('/^[a-z][a-z0-9_]{0,63}$/D',$value)
                    || ($key==='features' && !in_array($value,self::FEATURES,true))) {
                    throw new \InvalidArgumentException('Invalid hosting requirement.');
                }
            }
            $policy[$key]=array_values(array_unique($values));sort($policy[$key],SORT_STRING);
        }
        $minimum=$policy['minimum_memory_mb']??32;
        if(!is_int($minimum)||$minimum<1||$minimum>1048576){throw new \InvalidArgumentException('Invalid minimum memory requirement.');}
        $policy['minimum_memory_mb']=$minimum;
        $declarations=$policy['declarations']??[];
        if(!is_array($declarations)||array_diff(array_keys($declarations),[...self::FEATURES,'https','document_root'])){
            throw new \InvalidArgumentException('Invalid hosting declarations.');
        }
        foreach($declarations as $value){if(!is_bool($value)){throw new \InvalidArgumentException('Hosting declarations must be booleans.');}}
        ksort($declarations,SORT_STRING);$policy['declarations']=$declarations;
        return $policy;
    }

    public static function memoryBytes(string $value): ?int
    {
        $value=trim($value);
        if($value==='-1'){return null;}
        if(!preg_match('/^([0-9]+)\s*([KMG]?)$/Di',$value,$match)){throw new \InvalidArgumentException('Unrecognized PHP memory limit.');}
        $multiplier=match(strtoupper($match[2])){'K'=>1024,'M'=>1048576,'G'=>1073741824,default=>1};
        if((float)$match[1]>PHP_INT_MAX/$multiplier){throw new \InvalidArgumentException('PHP memory limit exceeds diagnostic bounds.');}
        return (int)$match[1]*$multiplier;
    }

    public static function evaluate(string $target,array $policy,array $facts): array
    {
        if(!in_array($target,['shared','vps','aws','azure'],true)){throw new \InvalidArgumentException('Choose shared, vps, aws or azure.');}
        $policy=self::policy($policy);$checks=[];
        $add=static function(string $id,bool $ok)use(&$checks):void{$checks[]=['id'=>$id,'status'=>$ok?'passed':'failed','evidence'=>'measured'];};
        $add('php_version',$facts['php_version_id']>=80200);
        foreach(array_unique(['json',...$policy['extensions']]) as $extension){$add('extension.'.$extension,in_array($extension,$facts['extensions'],true));}
        foreach(['json_encode','json_decode','random_bytes','hash','hash_hmac','fopen','flock','rename'] as $function){$add('function.'.$function,in_array($function,$facts['functions'],true));}
        foreach(['public_directory','private_layout','private_writable_storage'] as $id){$add($id,$facts[$id]);}
        $add('memory_limit',$facts['memory_bytes']===null||$facts['memory_bytes']>=$policy['minimum_memory_mb']*1048576);
        if(in_array('websocket',$policy['features'],true)){$add('function.stream_socket_server',in_array('stream_socket_server',$facts['functions'],true));}
        if(in_array('outbound_https',$policy['features'],true)){$add('extension.openssl',in_array('openssl',$facts['extensions'],true));}
        $baseline=!in_array('failed',array_column($checks,'status'),true);
        foreach(['https','document_root',...$policy['features']] as $capability){
            $declared=$policy['declarations'][$capability]??null;
            $checks[]=['id'=>'host.'.$capability,'status'=>$declared===false?'failed':'unverified',
                'evidence'=>$declared===null?'unknown':'operator_declaration','declared'=>$declared];
        }
        return ['format'=>1,'target'=>$target,'baseline_compatible'=>$baseline,
            'blocked'=>in_array('failed',array_column($checks,'status'),true),
            'production_ready'=>false,'target_validated'=>false,'requires_target_validation'=>true,
            'checks'=>$checks,'runtime'=>['php'=>$facts['php_version'],'sapi'=>$facts['sapi'],
                'os'=>$facts['os'],'memory_limit_bytes'=>$facts['memory_bytes'],
                'execution_limit_seconds'=>$facts['execution_seconds']],
            'external_services_checked'=>false,'windows_acl_checked'=>false,
            'limits'=>['CLI measurements describe this PHP process, not an independently verified web runtime.',
                'Provider permissions, routing, HTTPS, cron and service availability require target acceptance.',
                'No application configuration was executed, no files were created and no services were contacted.']];
    }

    public static function inspect(string $root,string $target,array $policy=[],?string $publicPath=null): array
    {
        $public=realpath($publicPath??$root.'/public');$storage=realpath($root.'/storage');
        $normalize=static fn(string $path):string=>(PHP_OS_FAMILY==='Windows'?strtolower(str_replace('\\','/',$path)):str_replace('\\','/',$path)).'/';
        $outside=static fn(string $path):bool=>$public!==false&&!str_starts_with($normalize($path),$normalize($public));
        $private=$public!==false;
        foreach(['config','packages','vendor','storage','autoload.php','composer.json'] as $part){
            $path=realpath($root.'/'.$part);if($path!==false&&!$outside($path)){$private=false;}
        }
        $functions=array_values(array_filter(['json_encode','json_decode','random_bytes','hash','hash_hmac','fopen','flock','rename','stream_socket_server'],'function_exists'));
        $extensions=array_map('strtolower',get_loaded_extensions());sort($extensions,SORT_STRING);
        return self::evaluate($target,$policy,['php_version_id'=>PHP_VERSION_ID,'php_version'=>PHP_VERSION,
            'sapi'=>PHP_SAPI,'os'=>PHP_OS_FAMILY,'extensions'=>$extensions,'functions'=>$functions,
            'public_directory'=>$public!==false&&is_dir($public),'private_layout'=>$private,
            'private_writable_storage'=>$storage!==false&&is_dir($storage)&&!is_link($root.'/storage')&&is_writable($storage)&&$outside($storage),
            'memory_bytes'=>self::memoryBytes((string)ini_get('memory_limit')),
            'execution_seconds'=>(int)ini_get('max_execution_time')]);
    }
}
