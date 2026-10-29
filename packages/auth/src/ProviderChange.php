<?php
declare(strict_types=1);
namespace Corevia\Auth;
final class ProviderChange
{
    public static function apply(?array &$user,int $version,string $provider,?string $hash,array &$security,array &$binding):void
    {
        if($user===null||($user['auth_version']??0)!==$version){throw new AuthFailure('SESSION_CHANGED','Sign in again.',401);}
        if(!preg_match('/^[a-z][a-z0-9_-]{0,31}$/D',$provider)||($hash!==null&&!preg_match('/^[a-f0-9]{64}$/D',$hash))){throw new \InvalidArgumentException('Invalid provider binding.');}
        $old=$security['oauth'][$provider]??null;
        if($hash!==null){if(($old!==null&&$old!==$hash)||(isset($binding['user_id'])&&$binding['user_id']!==$user['id'])){throw new AuthFailure('OAUTH_CONFLICT','Provider identity is already linked. Unlink it before replacing it.');}$security['oauth'][$provider]=$hash;$binding=['user_id'=>$user['id']];}
        else{unset($security['oauth'][$provider]);if(($binding['user_id']??null)===$user['id']){$binding=[];}}
        $user['auth_version']=$version+1;
    }
}
