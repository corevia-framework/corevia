<?php
declare(strict_types=1);
namespace Corevia\Auth;
use Corevia\Http\HttpClient;
final class BuiltInProviders
{
    public static function configured(array $settings,HttpClient $http):array
    {
        $providers=[];foreach($settings as $name=>$config){if(!($config['enabled']??false)){continue;}$id=$config['id']??'';$secret=$config['secret']??'';if(!is_string($id)||$id===''||!is_string($secret)||$secret===''){throw new \LogicException('Enabled OAuth providers require private client credentials.');}$providers[$name]=match($name){'github'=>new GitHubProvider($http,$id,$secret),'google'=>OidcCodeProvider::google($http,$id,$secret),default=>throw new \InvalidArgumentException('Unknown built-in provider.')};}return $providers;
    }
}
