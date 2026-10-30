<?php
declare(strict_types=1);
namespace Corevia\Auth;
use Corevia\Persistence\SnapshotStore;
/** Optimistic NoSQL adapter. Callbacks can be retried; no external side effects inside them. */
final readonly class SnapshotAuthStore extends AtomicAuthStore
{
    public function __construct(private SnapshotStore $backend){}
    protected function transaction(callable $work,?int $now=null):mixed
    {
        for($attempt=0;$attempt<16;$attempt++){
            $snapshot=$this->backend->read();$wire=$snapshot['payload'];if($wire!==null&&strlen($wire)>8388608){throw new \LengthException('Auth aggregate exceeds 8 MiB.');}
            $data=$wire===null?['format'=>1,'users'=>[],'sessions'=>[],'limits'=>[],'tokens'=>[],'state'=>[]]:json_decode($wire,true,64,JSON_THROW_ON_ERROR);
            if(($data['format']??null)!==1){throw new \RuntimeException('Invalid auth aggregate.');}foreach(['users','sessions','limits','tokens','state'] as $section){if(!is_array($data[$section]??null)){throw new \RuntimeException('Invalid auth records.');}}
            foreach($data['state'] as $key=>$state){IdentityRules::state($key,$state);if(isset($state['expires'])&&$state['expires']<=($now??time())){unset($data['state'][$key]);}}
            if($now!==null){foreach(['sessions','tokens','limits'] as $section){foreach($data[$section] as $key=>$row){if(!is_int($row['expires']??null)){throw new \RuntimeException('Invalid auth expiry.');}if($row['expires']<=$now){unset($data[$section][$key]);}}}}
            $value=$work($data);$json=json_encode($data,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES);if(strlen($json)>8388608){throw new \LengthException('Auth aggregate exceeds 8 MiB.');}
            if($this->backend->compareExchange($snapshot['version'],$json)){return $value;}
        }throw new AuthFailure('STORE_BUSY','Account storage is busy. Try again.',503);
    }
}
