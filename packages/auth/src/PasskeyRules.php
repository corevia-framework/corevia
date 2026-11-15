<?php
declare(strict_types=1);
namespace Corevia\Auth;
use Corevia\Core\CanonicalJson;
final class PasskeyRules
{
    public static function bytes(string $value):string
    {
        if($value===''||strlen($value)>4096||!preg_match('/^[A-Za-z0-9_-]+$/D',$value)){throw new \InvalidArgumentException('Invalid credential encoding.');}
        $decoded=base64_decode(strtr($value,'-_','+/'),true);if($decoded===false||rtrim(strtr(base64_encode($decoded),'+/','-_'),'=')!==$value){throw new \InvalidArgumentException('Use canonical credential encoding.');}return $decoded;
    }
    public static function material(array $security):array
    {
        $material=[];foreach($security['passkeys']??[] as $digest=>$record){$source=$record['source']??[];$material[$digest]=array_intersect_key($source,array_flip(['publicKeyCredentialId','credentialPublicKey','userHandle']));}ksort($material,SORT_STRING);return $material;
    }
    public static function apply(?array &$user,int $version,string $digest,?array $record,?string $expectedHash,array &$security,array &$binding):bool
    {
        if($user===null||($user['auth_version']??0)!==$version){throw new AuthFailure('SESSION_CHANGED','Sign in again.',401);}
        if(isset($security['lifecycle_deletion'])){throw new AuthFailure('ACCOUNT_UNAVAILABLE','Account access is unavailable.',403);}
        if(!preg_match('/^[a-f0-9]{64}$/D',$digest)){throw new \InvalidArgumentException('Invalid passkey digest.');}$old=$security['passkeys'][$digest]??null;
        if($record===null){if($old===null||($binding['user_id']??null)!==$user['id']){throw new AuthFailure('NOT_FOUND','Passkey not found.',404);}unset($security['passkeys'][$digest]);$binding=[];$user['auth_version']=$version+1;return true;}
        $source=$record['source']??null;
        if(!is_array($source)||!is_string($source['publicKeyCredentialId']??null)||!is_string($source['credentialPublicKey']??null)||!is_string($source['userHandle']??null)||!is_int($source['counter']??null)||$source['counter']<0||$source['counter']>4294967295
            ||hash('sha256',self::bytes($source['publicKeyCredentialId']))!==$digest||self::bytes($source['userHandle'])!==hex2bin($user['id'])||!is_string($record['label']??null)||trim($record['label'])===''||strlen($record['label'])>120||preg_match('//u',$record['label'])!==1||preg_match('/[\x00-\x1f\x7f]/',$record['label'])||!is_int($record['created']??null)||strlen(CanonicalJson::encode($record))>8192){throw new \InvalidArgumentException('Invalid verified passkey record.');}
        if($expectedHash===null){
            if($old!==null||isset($binding['user_id'])){throw new AuthFailure('PASSKEY_CONFLICT','Passkey is already registered.',409);}if(count($security['passkeys']??[])>=10){throw new AuthFailure('PASSKEY_CAPACITY','At most ten passkeys may be enrolled.',409);}$binding=['user_id'=>$user['id']];$user['auth_version']=$version+1;
        }else{
            if($old===null||($binding['user_id']??null)!==$user['id']||!hash_equals(hash('sha256',CanonicalJson::encode($old)),$expectedHash)){throw new AuthFailure('PASSKEY_CHANGED','Start passkey sign-in again.',409);}
            if(self::material(['passkeys'=>[$digest=>$old]])!==self::material(['passkeys'=>[$digest=>$record]])||$old['label']!==$record['label']||$old['created']!==$record['created']||$old['source']['counter']>0&&$source['counter']<=$old['source']['counter']){throw new AuthFailure('PASSKEY_CHANGED','Verified credential state changed unexpectedly.',409);}
        }
        $security['passkeys'][$digest]=$record;return $expectedHash===null;
    }
}
