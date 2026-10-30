<?php
declare(strict_types=1);
namespace Corevia\Auth;
/** Shared identity invariants. Transaction callbacks must have no external side effects. */
abstract readonly class AtomicAuthStore implements IdentityStore
{
    abstract protected function transaction(callable $work,?int $now=null):mixed;
    public function findUser(string $email):?array{return $this->transaction(static fn(array &$data):?array=>isset($data['users'][$email])?$data['users'][$email]+['auth_version'=>0,'email_verified_at'=>null]:null);}
    public function userById(string $id):?array{return $this->transaction(static function(array &$data)use($id):?array{foreach($data['users'] as $user){if(($user['id']??null)===$id){return $user+['auth_version'=>0,'email_verified_at'=>null];}}return null;});}
    public function createUser(string $email,string $name,string $passwordHash):?array{return $this->transaction(static function(array &$data)use($email,$name,$passwordHash):?array{if(isset($data['users'][$email])){return null;}$user=['id'=>bin2hex(random_bytes(16)),'email'=>$email,'name'=>$name,'password_hash'=>$passwordHash,'auth_version'=>0,'email_verified_at'=>null];$data['users'][$email]=$user;return $user;});}
    public function saveSession(string $digest,array $session,int $now):void{$this->transaction(static function(array &$data)use($digest,$session):void{
        if(($session['user_id']??null)!==null){$user=null;foreach($data['users'] as $row){if($row['id']===$session['user_id']){$user=$row;break;}}if($user===null || ($user['auth_version']??0)!==($session['user_version']??0)){throw new AuthFailure('SESSION_CHANGED','Sign in again.',401);}if(isset($data['state']['user:'.$user['id']]['mfa']['secret']) && !($session['mfa_verified']??false)){throw new AuthFailure('MFA_REQUIRED','An authenticator or recovery code is required.',401);}}
        if(count($data['sessions'])>=10000){throw new AuthFailure('CAPACITY','Please try again later.',503);}$data['sessions'][$digest]=$session;
    },$now);}
    public function session(string $digest,int $now):?array{return $this->transaction(static fn(array &$data):?array=>$data['sessions'][$digest]??null,$now);}
    public function deleteSession(string $digest):void{$this->transaction(static function(array &$data)use($digest):void{unset($data['sessions'][$digest]);});}
    public function consume(array $rules,int $now):bool{return $this->transaction(static function(array &$data)use($rules,$now):bool{
        foreach($rules as [$key,$limit,$window]){if(($data['limits'][$key]['count']??0)>=$limit){return false;}}
        if(count($data['limits'])+count($rules)>20000){return false;}
        foreach($rules as [$key,$limit,$window]){$data['limits'][$key]??=['count'=>0,'expires'=>$now+$window];$data['limits'][$key]['count']++;}return true;
    },$now);}
    public function issueAccountToken(string $userId,string $purpose,string $digest,int $expires,int $userVersion,int $now,?string $expectedEmail=null):bool
    {
        if(!in_array($purpose,['reset','verify'],true)){throw new \InvalidArgumentException('Unknown account token purpose.');}
        return $this->transaction(static function(array &$data)use($userId,$purpose,$digest,$expires,$userVersion,$expectedEmail):bool{
            $user=null;foreach($data['users'] as $row){if($row['id']===$userId){$user=$row;break;}}
            if($user===null || ($user['auth_version']??0)!==$userVersion || ($expectedEmail!==null && $user['email']!==$expectedEmail)){return false;}
            foreach($data['tokens'] as $id=>$record){if($record['user_id']===$userId && $record['purpose']===$purpose){unset($data['tokens'][$id]);}}
            if(count($data['tokens'])>=10000){throw new AuthFailure('CAPACITY','Please try again later.',503);}
            $data['tokens'][$digest]=['user_id'=>$userId,'purpose'=>$purpose,'email'=>$user['email'],'user_version'=>$userVersion,'expires'=>$expires];return true;
        },$now);
    }
    public function accountToken(string $digest,string $purpose,int $now):?array{return $this->transaction(static fn(array &$data):?array=>(($data['tokens'][$digest]['purpose']??null)===$purpose)?$data['tokens'][$digest]:null,$now);}
    public function completeAccountToken(string $digest,string $purpose,int $now,?string $passwordHash=null,?string $requiredUserId=null):bool
    {
        return $this->transaction(static function(array &$data)use($digest,$purpose,$now,$passwordHash,$requiredUserId):bool{
            $token=$data['tokens'][$digest]??null;if($token===null || $token['purpose']!==$purpose){return false;}
            $email=null;foreach($data['users'] as $key=>$row){if($row['id']===$token['user_id']){$email=$key;break;}}
            if($email===null){return false;}$user=$data['users'][$email];
            if(($user['auth_version']??0)!==$token['user_version'] || $user['email']!==$token['email']){return false;}
            if($purpose==='reset' && $passwordHash!==null){
                $data['users'][$email]['password_hash']=$passwordHash;$data['users'][$email]['auth_version']=($user['auth_version']??0)+1;
                foreach(['sessions','tokens'] as $section){foreach($data[$section] as $key=>$row){if(($row['user_id']??null)===$user['id']){unset($data[$section][$key]);}}}
            }elseif($purpose==='verify' && $requiredUserId===$user['id']){$data['users'][$email]['email_verified_at']=$now;unset($data['tokens'][$digest]);}
            else{return false;}return true;
        },$now);
    }
    public function state(string $key):array{return $this->transaction(static fn(array &$data):array=>$data['state'][$key]??[]);}
    public function changeState(string $key,callable $work):mixed{IdentityRules::transient($key);return $this->transaction(static function(array &$data)use($key,$work):mixed{$state=$data['state'][$key]??[];$result=$work($state);IdentityRules::state($key,$state);if($state===[]){unset($data['state'][$key]);}else{if(count($data['state'])>=20000 && !isset($data['state'][$key])){throw new \LengthException('Identity state limit reached.');}$data['state'][$key]=$state;}return $result;});}
    public function changeIdentity(string $id,int $version,callable $work):array{return $this->transaction(static function(array &$data)use($id,$version,$work):array{
        $email=null;foreach($data['users'] as $key=>$row){if($row['id']===$id){$email=$key;break;}}if($email===null || ($data['users'][$email]['auth_version']??0)!==$version){throw new AuthFailure('SESSION_CHANGED','Sign in again.',401);}
        $before=$data['users'][$email]+['auth_version'=>0,'email_verified_at'=>null];$user=$before;$old=$data['state']['user:'.$id]??[];$security=$old;$change=$work($user,$security);
        if(!$change instanceof IdentityChange){throw new \LogicException('Identity callback must return IdentityChange.');}$revoke=IdentityRules::validate($before,$user,$old,$security,$change);
        if($user['email']!==$email && isset($data['users'][$user['email']])){throw new AuthFailure('ACCOUNT_CONFLICT','That account change is unavailable.');}
        unset($data['users'][$email]);
        if($change->delete){unset($data['state']['user:'.$id]);foreach($data['state'] as $key=>$record){if(($record['user_id']??null)===$id){unset($data['state'][$key]);}}}
        else{IdentityRules::state('user:'.$id,$security);$data['users'][$user['email']]=$user;$data['state']['user:'.$id]=$security;}
        if($revoke){foreach(['sessions','tokens'] as $section){foreach($data[$section] as $key=>$record){if(($record['user_id']??null)===$id){unset($data[$section][$key]);}}}}
        return ['user'=>$change->delete?null:$user,'value'=>$change->value];
    });}
    public function sessionsFor(string $id,int $now):array{return $this->transaction(static function(array &$data)use($id):array{$result=[];foreach($data['sessions'] as $digest=>$row){if(($row['user_id']??null)===$id){$result[]=['id'=>$digest,'expires'=>$row['expires']];}}return $result;},$now);}
    public function revokeSession(string $id,string $digest):void{$this->transaction(static function(array &$data)use($id,$digest):void{if(($data['sessions'][$digest]['user_id']??null)===$id){unset($data['sessions'][$digest]);}});}
    public function changeProvider(string $id,int $version,string $provider,?string $subjectHash):array{return $this->transaction(static function(array &$data)use($id,$version,$provider,$subjectHash):array{
        $email=null;$user=null;foreach($data['users'] as $key=>$row){if($row['id']===$id){$email=$key;$user=$row;break;}}$security=$data['state']['user:'.$id]??[];$hash=$subjectHash??($security['oauth'][$provider]??null);$binding=$hash===null?[]:($data['state']['oid:'.$hash]??[]);ProviderChange::apply($user,$version,$provider,$subjectHash,$security,$binding);IdentityRules::state('user:'.$id,$security);$data['users'][$email]=$user;$data['state']['user:'.$id]=$security;
        if($hash!==null){if($binding===[]){unset($data['state']['oid:'.$hash]);}else{$data['state']['oid:'.$hash]=$binding;}}foreach(['sessions','tokens'] as $section){foreach($data[$section] as $key=>$record){if(($record['user_id']??null)===$id){unset($data[$section][$key]);}}}return $user;
    });}
    public function authority(string $id,string $digest,int $now):?array{return $this->transaction(static function(array &$data)use($id,$digest):?array{
        $session=$data['sessions'][$digest]??null;if(($session['user_id']??null)!==$id){return null;}foreach($data['users'] as $user){if($user['id']===$id && ($user['auth_version']??0)===($session['user_version']??0)){return $data['state']['user:'.$id]??[];}}return null;
    },$now);}
}
