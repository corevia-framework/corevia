<?php
declare(strict_types=1);
namespace Corevia\Auth;
use Corevia\Persistence\SqlConnection;

/** Portable SQL auth; a transaction mutex serializes security-sensitive mutations. */
final class SqlAuthStore implements IdentityStore
{
    private bool $inside=false;
    private ?\Fiber $owner=null;
    public function __construct(private readonly SqlConnection $db,private readonly string $prefix='cv_auth')
    {
        if(!preg_match('/^[a-z][a-z0-9_]{0,39}$/D',$prefix) || !in_array($db->driver(),['sqlite','mysql','pgsql'],true)){throw new \InvalidArgumentException('SQL auth supports SQLite, MySQL and PostgreSQL with a safe table prefix.');}
        if($db->driver()==='mysql'){
            $names=array_map(fn(string $name):string=>$prefix.'_'.$name,['mutex','users','sessions','limits','tokens','state']);$parameters=[];foreach($names as $index=>$name){$parameters['table'.$index]=$name;}
            $rows=$db->select('SELECT ENGINE AS engine FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name IN ('.implode(',',array_map(static fn(string $key):string=>':'.$key,array_keys($parameters))).')',$parameters);
            foreach($rows as $row){if(strtoupper($row['engine']??'')!=='INNODB'){throw new \LogicException('MySQL auth tables must use InnoDB for atomic credential changes.');}}
        }
    }
    private function table(string $name):string{return $this->db->quoteIdentifier($this->prefix.'_'.$name);}
    private function available():void{if($this->db->inTransaction() && (!$this->inside || $this->owner!==\Fiber::getCurrent())){throw new \LogicException('SQL auth owns its transactions; use a dedicated auth connection outside application transactions.');}}
    public function migrate():void
    {
        $this->available();
        $db=$this->db;$mysql=$db->driver()==='mysql';$tail=$mysql?' ENGINE=InnoDB DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_bin':'';
        $db->execute('CREATE TABLE IF NOT EXISTS '.$this->table('mutex').' (id INTEGER PRIMARY KEY, version INTEGER NOT NULL)'.$tail);
        $seed='INSERT '.($mysql?'IGNORE ':'').'INTO '.$this->table('mutex').' (id,version) VALUES (1,0)'.($mysql?'':' ON CONFLICT (id) DO NOTHING');$db->execute($seed);
        $db->execute('CREATE TABLE IF NOT EXISTS '.$this->table('users').' (id VARCHAR(32) PRIMARY KEY, email VARCHAR(254) NOT NULL UNIQUE, name VARCHAR(320) NOT NULL, password_hash VARCHAR(255) NOT NULL, auth_version BIGINT NOT NULL DEFAULT 0, email_verified_at BIGINT NULL)'.$tail);
        $db->execute('CREATE TABLE IF NOT EXISTS '.$this->table('sessions').' (digest VARCHAR(64) PRIMARY KEY, user_id VARCHAR(32) NULL, csrf VARCHAR(64) NOT NULL, user_version BIGINT NOT NULL, expires BIGINT NOT NULL'.($mysql?', INDEX (user_id), INDEX (expires)':'').')'.$tail);
        $db->execute('CREATE TABLE IF NOT EXISTS '.$this->table('limits').' (digest VARCHAR(64) PRIMARY KEY, attempts INTEGER NOT NULL, expires BIGINT NOT NULL'.($mysql?', INDEX (expires)':'').')'.$tail);
        $db->execute('CREATE TABLE IF NOT EXISTS '.$this->table('tokens').' (digest VARCHAR(64) PRIMARY KEY, user_id VARCHAR(32) NOT NULL, purpose VARCHAR(16) NOT NULL, email VARCHAR(254) NOT NULL, user_version BIGINT NOT NULL, expires BIGINT NOT NULL, UNIQUE (user_id,purpose)'.($mysql?', INDEX (expires)':'').')'.$tail);
        $db->execute('CREATE TABLE IF NOT EXISTS '.$this->table('state').' (state_key VARCHAR(191) PRIMARY KEY, payload '.($mysql?'LONGTEXT':'TEXT').' NOT NULL, expires BIGINT NULL, owner_id VARCHAR(32) NULL'.($mysql?', INDEX (expires), INDEX (owner_id)':'').')'.$tail);
        if(!$mysql){foreach(['sessions'=>'user_id','sessions_expiry'=>'expires','limits'=>'expires','tokens'=>'expires','state'=>'expires','state_owner'=>'owner_id'] as $index=>$column){$table=match($index){'sessions_expiry'=>'sessions','state_owner'=>'state',default=>$index};$db->execute('CREATE INDEX IF NOT EXISTS '.$db->quoteIdentifier($this->prefix.'_'.$index.'_idx').' ON '.$this->table($table).' ('.$column.')');}}
    }
    private function locked(callable $work,?int $now=null):mixed
    {
        if($this->db->inTransaction()){throw new \LogicException('SQL auth owns its transactions; use a dedicated auth connection outside application transactions.');}
        return $this->db->transaction(function()use($work,$now):mixed{
            $this->inside=true;$this->owner=\Fiber::getCurrent();
            try{
            if($this->db->execute('UPDATE '.$this->table('mutex').' SET version=CASE WHEN version=0 THEN 1 ELSE 0 END WHERE id=1')->rowCount()!==1){throw new \RuntimeException('Run auth:migrate before using SQL auth.');}
            if($now!==null){foreach(['sessions','limits','tokens'] as $table){$this->db->execute('DELETE FROM '.$this->table($table).' WHERE expires<=:now',['now'=>$now]);}}
            $this->db->execute('DELETE FROM '.$this->table('state').' WHERE expires<=:now',['now'=>$now??time()]);
            return $work();
            }finally{$this->inside=false;$this->owner=null;}
        });
    }
    private function user(?array $row):?array{if($row===null){return null;}$row['auth_version']=(int)$row['auth_version'];$row['email_verified_at']=$row['email_verified_at']===null?null:(int)$row['email_verified_at'];return $row;}
    public function findUser(string $email):?array{$this->available();return $this->user($this->db->select('SELECT * FROM '.$this->table('users').' WHERE email=:email',['email'=>$email])[0]??null);}
    public function userById(string $id):?array{$this->available();return $this->user($this->db->select('SELECT * FROM '.$this->table('users').' WHERE id=:id',['id'=>$id])[0]??null);}
    public function createUser(string $email,string $name,string $passwordHash):?array{return $this->locked(function()use($email,$name,$passwordHash):?array{
        if($this->findUser($email)!==null){return null;}$id=bin2hex(random_bytes(16));$this->db->execute('INSERT INTO '.$this->table('users').' (id,email,name,password_hash,auth_version,email_verified_at) VALUES (:id,:email,:name,:hash,0,NULL)',['id'=>$id,'email'=>$email,'name'=>$name,'hash'=>$passwordHash]);return $this->userById($id);
    });}
    public function saveSession(string $digest,array $session,int $now):void{$this->locked(function()use($digest,$session):void{
        $version=$session['user_version']??0;
        if($session['user_id']!==null){$user=$this->userById($session['user_id']);if($user===null || $user['auth_version']!==$version){throw new AuthFailure('SESSION_CHANGED','Sign in again.',401);}if(isset($this->state('user:'.$user['id'])['mfa']['secret']) && !($session['mfa_verified']??false)){throw new AuthFailure('MFA_REQUIRED','An authenticator or recovery code is required.',401);}}
        $this->deleteSession($digest);$this->db->execute('INSERT INTO '.$this->table('sessions').' (digest,user_id,csrf,user_version,expires) VALUES (:digest,:user,:csrf,:version,:expires)',['digest'=>$digest,'user'=>$session['user_id'],'csrf'=>$session['csrf'],'version'=>$version,'expires'=>$session['expires']]);
    },$now);}
    public function session(string $digest,int $now):?array{$this->available();$row=$this->db->select('SELECT user_id,csrf,user_version,expires FROM '.$this->table('sessions').' WHERE digest=:digest AND expires>:now',['digest'=>$digest,'now'=>$now])[0]??null;if($row!==null){$row['user_version']=(int)$row['user_version'];$row['expires']=(int)$row['expires'];}return $row;}
    public function deleteSession(string $digest):void{$this->available();$this->db->execute('DELETE FROM '.$this->table('sessions').' WHERE digest=:digest',['digest'=>$digest]);}
    public function consume(array $rules,int $now):bool{return $this->locked(function()use($rules,$now):bool{
        foreach($rules as [$key,$limit,$window]){$row=$this->db->select('SELECT attempts FROM '.$this->table('limits').' WHERE digest=:digest',['digest'=>$key])[0]??null;if($row!==null && (int)$row['attempts']>=$limit){return false;}}
        foreach($rules as [$key,$limit,$window]){$row=$this->db->select('SELECT attempts FROM '.$this->table('limits').' WHERE digest=:digest',['digest'=>$key])[0]??null;if($row===null){$this->db->execute('INSERT INTO '.$this->table('limits').' (digest,attempts,expires) VALUES (:digest,1,:expires)',['digest'=>$key,'expires'=>$now+$window]);}else{$this->db->execute('UPDATE '.$this->table('limits').' SET attempts=attempts+1 WHERE digest=:digest',['digest'=>$key]);}}return true;
    },$now);}
    public function issueAccountToken(string $userId,string $purpose,string $digest,int $expires,int $userVersion,int $now,?string $expectedEmail=null):bool
    {
        if(!in_array($purpose,['reset','verify'],true)){throw new \InvalidArgumentException('Unknown account token purpose.');}
        return $this->locked(function()use($userId,$purpose,$digest,$expires,$userVersion,$expectedEmail):bool{
            $user=$this->userById($userId);if($user===null || $user['auth_version']!==$userVersion || ($expectedEmail!==null && $user['email']!==$expectedEmail)){return false;}
            $this->db->execute('DELETE FROM '.$this->table('tokens').' WHERE user_id=:user AND purpose=:purpose',['user'=>$userId,'purpose'=>$purpose]);
            $this->db->execute('INSERT INTO '.$this->table('tokens').' (digest,user_id,purpose,email,user_version,expires) VALUES (:digest,:user,:purpose,:email,:version,:expires)',['digest'=>$digest,'user'=>$userId,'purpose'=>$purpose,'email'=>$user['email'],'version'=>$userVersion,'expires'=>$expires]);return true;
        },$now);
    }
    public function accountToken(string $digest,string $purpose,int $now):?array{$this->available();$row=$this->db->select('SELECT * FROM '.$this->table('tokens').' WHERE digest=:digest AND purpose=:purpose AND expires>:now',['digest'=>$digest,'purpose'=>$purpose,'now'=>$now])[0]??null;if($row!==null){$row['user_version']=(int)$row['user_version'];$row['expires']=(int)$row['expires'];}return $row;}
    public function completeAccountToken(string $digest,string $purpose,int $now,?string $passwordHash=null,?string $requiredUserId=null):bool{return $this->locked(function()use($digest,$purpose,$now,$passwordHash,$requiredUserId):bool{
        $token=$this->accountToken($digest,$purpose,$now);if($token===null){return false;}$user=$this->userById($token['user_id']);
        if($user===null || $user['auth_version']!==$token['user_version'] || $user['email']!==$token['email']){return false;}
        if($purpose==='reset' && $passwordHash!==null){
            $this->db->execute('UPDATE '.$this->table('users').' SET password_hash=:hash,auth_version=auth_version+1 WHERE id=:user',['hash'=>$passwordHash,'user'=>$user['id']]);
            foreach(['sessions','tokens'] as $table){$this->db->execute('DELETE FROM '.$this->table($table).' WHERE user_id=:user',['user'=>$user['id']]);}
        }elseif($purpose==='verify' && $requiredUserId===$user['id']){$this->db->execute('UPDATE '.$this->table('users').' SET email_verified_at=:now WHERE id=:user',['now'=>$now,'user'=>$user['id']]);$this->db->execute('DELETE FROM '.$this->table('tokens').' WHERE digest=:digest',['digest'=>$digest]);}
        else{return false;}return true;
    },$now);}
    public function state(string $key):array{$this->available();$row=$this->db->select('SELECT payload FROM '.$this->table('state').' WHERE state_key=:key AND (expires IS NULL OR expires>:now)',['key'=>$key,'now'=>time()])[0]??null;return $row===null?[]:json_decode($row['payload'],true,64,JSON_THROW_ON_ERROR);}
    private function writeState(string $key,array $state):void{$json=IdentityRules::state($key,$state);$this->db->execute('DELETE FROM '.$this->table('state').' WHERE state_key=:key',['key'=>$key]);if($state!==[]){$this->db->execute('INSERT INTO '.$this->table('state').' (state_key,payload,expires,owner_id) VALUES (:key,:payload,:expires,:owner)',['key'=>$key,'payload'=>$json,'expires'=>$state['expires']??null,'owner'=>str_starts_with($key,'user:')?substr($key,5):($state['user_id']??null)]);}}
    public function changeState(string $key,callable $work):mixed{IdentityRules::transient($key);return $this->locked(function()use($key,$work):mixed{$state=$this->state($key);$result=$work($state);$this->writeState($key,$state);return $result;});}
    public function changeIdentity(string $id,int $version,callable $work):array{return $this->locked(function()use($id,$version,$work):array{
        $before=$this->userById($id);if($before===null || $before['auth_version']!==$version){throw new AuthFailure('SESSION_CHANGED','Sign in again.',401);}$user=$before;$old=$this->state('user:'.$id);$security=$old;$change=$work($user,$security);
        if(!$change instanceof IdentityChange){throw new \LogicException('Identity callback must return IdentityChange.');}$revoke=IdentityRules::validate($before,$user,$old,$security,$change);
        $existing=$this->findUser($user['email']);if($existing!==null && $existing['id']!==$id){throw new AuthFailure('ACCOUNT_CONFLICT','That account change is unavailable.');}
        if($change->delete){$this->db->execute('DELETE FROM '.$this->table('users').' WHERE id=:id',['id'=>$id]);$this->db->execute('DELETE FROM '.$this->table('state').' WHERE owner_id=:id OR state_key=:key',['id'=>$id,'key'=>'user:'.$id]);}
        else{$this->db->execute('UPDATE '.$this->table('users').' SET name=:name,email=:email,password_hash=:hash,auth_version=:version,email_verified_at=:verified WHERE id=:id',['name'=>$user['name'],'email'=>$user['email'],'hash'=>$user['password_hash'],'version'=>$user['auth_version'],'verified'=>$user['email_verified_at'],'id'=>$id]);$this->writeState('user:'.$id,$security);}
        if($revoke){foreach(['sessions','tokens'] as $table){$this->db->execute('DELETE FROM '.$this->table($table).' WHERE user_id=:id',['id'=>$id]);}}return ['user'=>$change->delete?null:$user,'value'=>$change->value];
    });}
    public function sessionsFor(string $id,int $now):array{$this->available();$rows=$this->db->select('SELECT digest,expires FROM '.$this->table('sessions').' WHERE user_id=:id AND expires>:now',['id'=>$id,'now'=>$now]);return array_map(static fn(array $row):array=>['id'=>$row['digest'],'expires'=>(int)$row['expires']],$rows);}
    public function revokeSession(string $id,string $digest):void{$this->available();$this->db->execute('DELETE FROM '.$this->table('sessions').' WHERE digest=:digest AND user_id=:id',['digest'=>$digest,'id'=>$id]);}
    public function changeProvider(string $id,int $version,string $provider,?string $subjectHash):array{return $this->locked(function()use($id,$version,$provider,$subjectHash):array{
        $user=$this->userById($id);$security=$this->state('user:'.$id);$hash=$subjectHash??($security['oauth'][$provider]??null);$binding=$hash===null?[]:$this->state('oid:'.$hash);ProviderChange::apply($user,$version,$provider,$subjectHash,$security,$binding);$this->writeState('user:'.$id,$security);if($hash!==null){$this->writeState('oid:'.$hash,$binding);}$this->db->execute('UPDATE '.$this->table('users').' SET auth_version=:version WHERE id=:id',['version'=>$user['auth_version'],'id'=>$id]);foreach(['sessions','tokens'] as $table){$this->db->execute('DELETE FROM '.$this->table($table).' WHERE user_id=:id',['id'=>$id]);}return $user;
    });}
    public function authority(string $id,string $digest,int $now):?array{return $this->locked(function()use($id,$digest,$now):?array{$user=$this->userById($id);$session=$this->session($digest,$now);return $user!==null && ($session['user_id']??null)===$id && $user['auth_version']===($session['user_version']??0)?$this->state('user:'.$id):null;},$now);}
}
