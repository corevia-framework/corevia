<?php
declare(strict_types=1);
namespace Corevia\Auth;

/** Single-node reference store; separate lock plus private atomic replacement. */
final readonly class JsonAuthStore implements AccountStore
{
    public function __construct(private string $path){}
    private function transaction(callable $work,?int $now=null):mixed
    {
        $directory=dirname($this->path);
        if(!is_dir($directory) && !mkdir($directory,0700,true) && !is_dir($directory)){throw new \RuntimeException('Cannot create private auth storage.');}
        if(is_link($this->path) || is_link($this->path.'.lock')){throw new \RuntimeException('Auth storage cannot use symlinks.');}
        $lock=fopen($this->path.'.lock','c+b');if($lock===false || !chmod($this->path.'.lock',0600) || !flock($lock,LOCK_EX)){throw new \RuntimeException('Cannot lock auth storage.');}
        try{
            $data=['format'=>1,'users'=>[],'sessions'=>[],'limits'=>[]];
            if(is_file($this->path)){
                clearstatcache(true,$this->path);if(filesize($this->path)>16777216){throw new \RuntimeException('Auth storage exceeds its single-node size limit.');}
                $data=json_decode(file_get_contents($this->path),true,64,JSON_THROW_ON_ERROR);
                if(!is_array($data) || ($data['format']??null)!==1){throw new \RuntimeException('Invalid auth storage format.');}
                foreach(['users','sessions','limits'] as $key){if(!isset($data[$key]) || !is_array($data[$key])){throw new \RuntimeException('Invalid auth records.');}}
            }
            $data['tokens']??=[];if(!is_array($data['tokens'])){throw new \RuntimeException('Invalid account token records.');}
            if($now!==null){foreach(['sessions','limits','tokens'] as $key){foreach($data[$key] as $id=>$record){if(!is_array($record) || !is_int($record['expires']??null)){throw new \RuntimeException('Invalid auth expiry record.');}if($record['expires']<=$now){unset($data[$key][$id]);}}}}
            $result=$work($data);$wire=json_encode($data,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES);
            if(strlen($wire)>16777216){throw new \RuntimeException('Auth storage exceeds its single-node size limit.');}
            $temporary=$this->path.'.'.bin2hex(random_bytes(8)).'.tmp';$file=fopen($temporary,'x+b');if($file===false){throw new \RuntimeException('Cannot create auth records.');}
            try{try{if(!chmod($temporary,0600) || fwrite($file,$wire)!==strlen($wire) || !fflush($file)){throw new \RuntimeException('Cannot persist auth records.');}}finally{fclose($file);}if(!rename($temporary,$this->path)){throw new \RuntimeException('Cannot replace auth records.');}}
            finally{if(is_file($temporary)){unlink($temporary);}}
            return $result;
        }finally{flock($lock,LOCK_UN);fclose($lock);}
    }
    public function findUser(string $email):?array{return $this->transaction(static fn(array &$data):?array=>isset($data['users'][$email])?$data['users'][$email]+['auth_version'=>0,'email_verified_at'=>null]:null);}
    public function userById(string $id):?array{return $this->transaction(static function(array &$data)use($id):?array{foreach($data['users'] as $user){if(($user['id']??null)===$id){return $user+['auth_version'=>0,'email_verified_at'=>null];}}return null;});}
    public function createUser(string $email,string $name,string $passwordHash):?array{return $this->transaction(static function(array &$data)use($email,$name,$passwordHash):?array{if(isset($data['users'][$email])){return null;}$user=['id'=>bin2hex(random_bytes(16)),'email'=>$email,'name'=>$name,'password_hash'=>$passwordHash,'auth_version'=>0,'email_verified_at'=>null];$data['users'][$email]=$user;return $user;});}
    public function saveSession(string $digest,array $session,int $now):void{$this->transaction(static function(array &$data)use($digest,$session):void{
        if(($session['user_id']??null)!==null){$user=null;foreach($data['users'] as $row){if($row['id']===$session['user_id']){$user=$row;break;}}if($user===null || ($user['auth_version']??0)!==($session['user_version']??0)){throw new AuthFailure('SESSION_CHANGED','Sign in again.',401);}}
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
}
