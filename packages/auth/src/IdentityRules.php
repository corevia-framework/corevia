<?php
declare(strict_types=1);
namespace Corevia\Auth;
/** Shared invariants, applied inside both adapter transactions. */
final class IdentityRules
{
    public static function transient(string $key):void{if(str_starts_with($key,'user:')||str_starts_with($key,'oid:')){throw new \LogicException('Use identity/provider transactions for reserved credential state.');}}
    public static function state(string $key,array $state):string{if(!preg_match('/^[A-Za-z0-9:_.-]{1,191}$/D',$key)){throw new \InvalidArgumentException('Invalid identity state key.');}if(array_key_exists('expires',$state)&&(str_starts_with($key,'user:')||str_starts_with($key,'oid:'))){throw new \LogicException('Persistent credential state cannot expire.');}if(isset($state['user_id'])&&(!is_string($state['user_id'])||!preg_match('/^[a-f0-9]{32}$/D',$state['user_id']))){throw new \InvalidArgumentException('Invalid identity state owner.');}$json=json_encode($state,JSON_THROW_ON_ERROR);if(strlen($json)>65536){throw new \LengthException('Identity state exceeds 64 KiB.');}return $json;}
    public static function validate(array $before,array &$after,array $oldSecurity,array $security,IdentityChange $change):bool
    {
        if(($after['id']??null)!==$before['id']){throw new \LogicException('Account IDs cannot be changed.');}
        foreach(['name','email','password_hash'] as $field){if(!is_string($after[$field]??null) || $after[$field]===''){throw new \LogicException('Invalid account mutation.');}}
        $revoke=$change->revoke || $change->delete || $before['email']!==$after['email'] || $before['password_hash']!==$after['password_hash'] || ($oldSecurity['mfa']['secret']??null)!==($security['mfa']['secret']??null) || ($oldSecurity['roles']??[])!==($security['roles']??[]) || ($oldSecurity['tenants']??[])!==($security['tenants']??[]) || ($oldSecurity['oauth']??[])!==($security['oauth']??[]);
        if($before['email']!==$after['email']){$after['email_verified_at']=null;}
        $after['auth_version']=($before['auth_version']??0)+($revoke?1:0);return $revoke;
    }
}
