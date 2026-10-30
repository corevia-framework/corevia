<?php
declare(strict_types=1);
namespace Corevia\Auth;
use Corevia\Persistence\{DatabaseManager,MongoSnapshotStore};
final class AuthStores
{
    public static function configured(array $settings,?DatabaseManager $database=null):IdentityStore{return match($settings['driver']??'json'){
        'json'=>new JsonAuthStore($settings['store']),
        'sql'=>new SqlAuthStore(($database??throw new \LogicException('SQL auth requires DatabaseManager.'))->sql($settings['connection']??null),$settings['prefix']??'cv_auth'),
        'mongodb'=>new SnapshotAuthStore(new MongoSnapshotStore($settings['mongo']['uri'],$settings['mongo']['database'],$settings['mongo']['collection']??'cv_auth',$settings['mongo']['scope']??'main')),
        default=>throw new \InvalidArgumentException('Choose json, sql or mongodb auth storage.'),
    };}
}
