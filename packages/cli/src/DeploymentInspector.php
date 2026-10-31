<?php
declare(strict_types=1);
namespace Corevia\Cli;
/** Offline deployment checks; outputs check IDs only, never config/credential values. */
final class DeploymentInspector
{
    private static function privatePath(string $path,string|false $public):bool
    {
        $resolved=realpath($path);if($resolved===false||$public===false){return false;}
        $normalize=static fn(string $value):string=>(DIRECTORY_SEPARATOR==='\\'?strtolower(str_replace('\\','/',$value)):str_replace('\\','/',$value)).'/';
        return !str_starts_with($normalize($resolved),$normalize($public));
    }
    private static function privateFile(mixed $path,string|false $public):bool
    {
        if(!is_string($path)||!is_file($path)||is_link($path)||!is_readable($path)||!self::privatePath($path,$public)){return false;}
        // Windows requires an ACL check on the actual deployment host.
        return DIRECTORY_SEPARATOR==='\\'||((fileperms($path)&0007)===0);
    }
    private static function endpoint(string $url,bool $origin=false,bool $loopback=false):bool
    {
        if(str_contains($url,'\\')||preg_match('/[\x00-\x20\x7f]/',$url)){return false;}
        $parts=parse_url($url);if($parts===false||empty($parts['host'])||isset($parts['user'])||isset($parts['pass'])||isset($parts['query'])||isset($parts['fragment'])){return false;}
        $scheme=strtolower($parts['scheme']??'');$local=$loopback&&in_array($parts['host'],['127.0.0.1','[::1]'],true);
        return ($scheme==='https'||(!$origin&&$local&&$scheme==='http'))&&(!$origin||in_array($parts['path']??'',['','/'],true));
    }
    public static function inspect(string $root,array $auth,array $ai,bool $multiHost):array
    {
        $storage=getenv('COREVIA_STORAGE')?:$root.'/storage';$storageReal=realpath($storage);$public=realpath($root.'/public');$checks=[];$add=static function(string $id,bool $ok)use(&$checks):void{$checks[]=['id'=>$id,'ok'=>$ok];};
        $add('php',PHP_VERSION_ID>=80200&&extension_loaded('json'));$add('production_environment',getenv('APP_ENV')==='production');$add('display_errors_disabled',in_array(strtolower((string)ini_get('display_errors')),['','0','off'],true));$add('https_origin',self::endpoint($auth['url']??(getenv('APP_URL')?:''),true));$add('https_request_policy',getenv('APP_HTTPS')==='true');$add('private_storage',$storageReal!==false&&is_writable($storage)&&self::privatePath($storage,$public));
        $add('storage_permissions',$storageReal!==false&&(DIRECTORY_SEPARATOR==='\\'||((fileperms($storageReal)&0007)===0)));
        $encrypted=($auth['mfa']??false)||($auth['delivery']??'')==='smtp'||($auth['delivery']??'')==='sqlsmtp'||($auth['oauth']??[])!==[];foreach($auth['oauth_builtin']??[] as $provider){$encrypted=$encrypted||($provider['enabled']??false);}
        $add('crypto_extension',!$encrypted||extension_loaded('openssl'));$key=$auth['key_file']??null;$add('persisted_auth_key',!$encrypted||(self::privateFile($key,$public)&&filesize($key)===32));$delivery=$auth['delivery']??'disabled';$add('production_delivery',is_string($delivery)&&$delivery!==''&&$delivery!=='preview');
        $configuration=\Corevia\Core\ConfigRepository::load($root.'/config/settings',getenv('APP_ENV')?:'development');$database=$configuration->get('database',[]);$available=class_exists(\PDO::class)?\PDO::getAvailableDrivers():[];$sqlDriver=static function(?string $name)use($database):?string{$name??=$database['default']??'local';return $database['connections'][$name]['driver']??null;};
        $driver=$auth['driver']??'json';$add('auth_driver_available',$driver==='json'||($driver==='sql'&&in_array($sqlDriver($auth['connection']??null),$available,true))||($driver==='mongodb'&&extension_loaded('mongodb')));
        $add('flow_driver_available',getenv('RUN_STORE')!=='sql'||in_array($sqlDriver($configuration->get('runtime.connection')),$available,true));
        $mailDriver=$sqlDriver($auth['mail_connection']??$auth['connection']??null);$queueConnection=$configuration->get('queue.connection');
        $add('mail_driver_available',($auth['delivery']??'')!=='sqlsmtp'||in_array($mailDriver,$available,true));
        $add('queue_driver_available',$queueConnection===null||in_array($sqlDriver($queueConnection),$available,true));
        $add('ai_transport',!($ai['enabled']??false)||self::endpoint($ai['base']??'',false,$ai['loopback']??false));$add('ai_configured_model',!($ai['enabled']??false)||($ai['model']??'')!=='');
        if($multiHost){$shared=static fn(?string $value):bool=>in_array($value,['mysql','pgsql'],true);$add('shared_sessions',$driver==='mongodb'||($driver==='sql'&&$shared($sqlDriver($auth['connection']??null))));$add('shared_flow_store',getenv('RUN_STORE')==='sql'&&$shared($sqlDriver($configuration->get('runtime.connection'))));$add('shared_mail_queue',($auth['delivery']??'disabled')==='disabled'||(($auth['delivery']??'')==='sqlsmtp'&&$shared($mailDriver)));$add('shared_work_queue',$queueConnection===null||$shared($sqlDriver($queueConnection)));$add('shared_key_provisioned',!$encrypted||getenv('AUTH_SHARED_KEY_PROVISIONED')==='true');}
        $artifactOk=false;$artifactPath=getenv('COREVIA_BUILD_ARTIFACT')?:$storage.'/application.build.json';$buildKey=getenv('COREVIA_BUILD_KEY_FILE')?:$storage.'/build-signing.key';$privateBuild=self::privateFile($artifactPath,$public)&&self::privateFile($buildKey,$public);$add('private_build_material',$privateBuild);
        try{if($privateBuild){$artifact=\Corevia\Core\ApplicationArtifact::load($artifactPath,file_get_contents($buildKey),getenv('APP_ENV')?:'development');$artifact->verifySources($root,\Corevia\Core\ApplicationArtifact::defaultSources($root));$artifactOk=true;}}catch(\Throwable){}$add('signed_sources',$artifactOk);
        return ['ready'=>!in_array(false,array_column($checks,'ok'),true),'multi_host'=>$multiHost,'checks'=>$checks,'external_services_checked'=>false,'runtime_config_graph_checked'=>false,'windows_acl_checked'=>false];
    }
}
