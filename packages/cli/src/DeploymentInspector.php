<?php
declare(strict_types=1);
namespace Corevia\Cli;
/** Offline deployment checks; outputs check IDs only, never config/credential values. */
final class DeploymentInspector
{
    public static function inspect(string $root,array $auth,array $ai,bool $multiHost):array
    {
        $storage=getenv('COREVIA_STORAGE')?:$root.'/storage';$storageReal=realpath($storage);$public=realpath($root.'/public');$checks=[];$add=static function(string $id,bool $ok)use(&$checks):void{$checks[]=['id'=>$id,'ok'=>$ok];};
        $add('php',PHP_VERSION_ID>=80200&&extension_loaded('json'));$add('production_environment',getenv('APP_ENV')==='production');$add('display_errors_disabled',in_array(strtolower((string)ini_get('display_errors')),['','0','off'],true));$add('https_origin',str_starts_with($auth['url']??(getenv('APP_URL')?:''),'https://'));$add('private_storage',$storageReal!==false&&is_writable($storage)&&$public!==false&&!str_starts_with(strtolower(str_replace('\\','/',$storageReal)).'/',strtolower(str_replace('\\','/',$public)).'/'));
        $encrypted=($auth['mfa']??false)||($auth['delivery']??'')==='smtp'||($auth['delivery']??'')==='sqlsmtp'||($auth['oauth']??[])!==[];foreach($auth['oauth_builtin']??[] as $provider){$encrypted=$encrypted||($provider['enabled']??false);}
        $add('crypto_extension',!$encrypted||extension_loaded('openssl'));$key=$auth['key_file']??null;$add('persisted_auth_key',!$encrypted||(is_string($key)&&is_file($key)&&filesize($key)===32&&!is_link($key)));$add('production_delivery',($auth['delivery']??'disabled')!=='preview');
        $configuration=\Corevia\Core\ConfigRepository::load($root.'/config/settings',getenv('APP_ENV')?:'development');$database=$configuration->get('database',[]);$available=class_exists(\PDO::class)?\PDO::getAvailableDrivers():[];$sqlDriver=static function(?string $name)use($database):?string{$name??=$database['default']??'local';return $database['connections'][$name]['driver']??null;};
        $driver=$auth['driver']??'json';$add('auth_driver_available',$driver==='json'||($driver==='sql'&&in_array($sqlDriver($auth['connection']??null),$available,true))||($driver==='mongodb'&&extension_loaded('mongodb')));
        $add('flow_driver_available',getenv('RUN_STORE')!=='sql'||in_array($sqlDriver($configuration->get('runtime.connection')),$available,true));
        $add('ai_transport',!($ai['enabled']??false)||str_starts_with($ai['base']??'','https://')||(($ai['loopback']??false)&&in_array(parse_url($ai['base']??'',PHP_URL_HOST),['127.0.0.1','[::1]'],true)));$add('ai_configured_model',!($ai['enabled']??false)||($ai['model']??'')!=='');
        if($multiHost){$add('shared_sessions',in_array($driver,['sql','mongodb'],true));$add('shared_flow_store',getenv('RUN_STORE')==='sql');$add('shared_mail_queue',in_array($auth['delivery']??'disabled',['sqlsmtp','disabled'],true));$add('shared_key_provisioned',!$encrypted||getenv('AUTH_SHARED_KEY_PROVISIONED')==='true');}
        $artifactOk=false;try{$artifactPath=getenv('COREVIA_BUILD_ARTIFACT')?:$storage.'/application.build.json';$buildKey=getenv('COREVIA_BUILD_KEY_FILE')?:$storage.'/build-signing.key';if(is_file($artifactPath)&&is_file($buildKey)){$artifact=\Corevia\Core\ApplicationArtifact::load($artifactPath,file_get_contents($buildKey),getenv('APP_ENV')?:'development');$artifact->verifySources($root,\Corevia\Core\ApplicationArtifact::defaultSources($root));$artifactOk=true;}}catch(\Throwable){}$add('signed_sources',$artifactOk);
        return ['ready'=>!in_array(false,array_column($checks,'ok'),true),'multi_host'=>$multiHost,'checks'=>$checks,'external_services_checked'=>false,'runtime_config_graph_checked'=>false];
    }
}
