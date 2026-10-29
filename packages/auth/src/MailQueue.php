<?php
declare(strict_types=1);
namespace Corevia\Auth;
use Corevia\Core\ScopeManager;
/** Private single-host encrypted spool. SMTP acknowledgement gives at-least-once delivery. */
final readonly class MailQueue implements AccountDelivery
{
    private \Closure $clock;
    private ScopeManager $scopes;
    public function __construct(private string $path,private SecretBox $box,private ?MailTransport $transport=null,?callable $clock=null,?ScopeManager $scopes=null){$this->clock=\Closure::fromCallable($clock??'time');$this->scopes=$scopes??new ScopeManager();}
    public static function configured(array $settings):self{return new self($settings['mail_queue'],SecretBox::fromFile($settings['key_file']),new SmtpTransport($settings['smtp']));}
    private function locked(callable $work):mixed
    {
        $directory=dirname($this->path);if(!is_dir($directory)&&!mkdir($directory,0700,true)&&!is_dir($directory)){throw new \RuntimeException('Cannot create private mail spool.');}
        if(is_link($directory)||is_link($this->path)||is_link($this->path.'.lock')){throw new \LogicException('Mail spool cannot use symlinks.');}
        $lock=fopen($this->path.'.lock','c+b');if($lock===false||!chmod($this->path.'.lock',0600)||!flock($lock,LOCK_EX)){throw new \RuntimeException('Cannot lock mail spool.');}
        $temporary=null;
        try{
            $jobs=[];if(is_file($this->path)){if(filesize($this->path)>8388608){throw new \LengthException('Mail spool exceeds 8 MiB.');}$data=json_decode(file_get_contents($this->path),true,64,JSON_THROW_ON_ERROR);if(($data['format']??null)!==1||!is_array($data['jobs']??null)){throw new \RuntimeException('Invalid mail spool.');}$jobs=$data['jobs'];}
            foreach($jobs as $id=>$job){if(!preg_match('/^[a-f0-9]{32}$/D',(string)$id)||!is_array($job)||!is_string($job['payload']??null)||!is_int($job['expires']??null)||!is_int($job['attempts']??null)||$job['attempts']<0||$job['attempts']>6||!is_int($job['ready']??null)||!is_int($job['lease']??null)||!is_string($job['lease_id']??null)||!preg_match('/^(?:[a-f0-9]{32})?$/D',$job['lease_id'])||!is_bool($job['failed']??null)){throw new \RuntimeException('Invalid mail spool record.');}if($job['expires']<=($this->clock)()){unset($jobs[$id]);}}
            $result=$work($jobs);$json=json_encode(['format'=>1,'jobs'=>$jobs],JSON_THROW_ON_ERROR);if(strlen($json)>8388608){throw new \LengthException('Mail spool is full.');}
            $temporary=$this->path.'.'.bin2hex(random_bytes(8)).'.tmp';$file=fopen($temporary,'x+b');if($file===false){throw new \RuntimeException('Cannot create mail spool record.');}try{if(!chmod($temporary,0600)||fwrite($file,$json)!==strlen($json)||!fflush($file)){throw new \RuntimeException('Cannot persist mail spool.');}}finally{fclose($file);}if(!rename($temporary,$this->path)){throw new \RuntimeException('Cannot replace mail spool.');}$temporary=null;return $result;
        }finally{if($temporary!==null&&is_file($temporary)){unlink($temporary);}flock($lock,LOCK_UN);fclose($lock);}
    }
    public function deliver(AccountMessage $message):void
    {
        $id=bin2hex(random_bytes(16));$payload=$this->box->seal(json_encode(['recipient'=>$message->recipient,'purpose'=>$message->purpose,'expires'=>$message->expires,'link'=>$message->link()],JSON_THROW_ON_ERROR),'mail:'.$id);
        $this->locked(function(array &$jobs)use($id,$message,$payload):void{if(count($jobs)>=1000){throw new \LengthException('Mail spool has reached 1000 messages.');}$jobs[$id]=['payload'=>$payload,'expires'=>$message->expires,'attempts'=>0,'ready'=>($this->clock)(),'lease'=>0,'lease_id'=>'','failed'=>false];});
    }
    public function status():array{return $this->locked(function(array &$jobs):array{$counts=['ready'=>0,'leased'=>0,'failed'=>0];foreach($jobs as $job){$counts[$job['failed']?'failed':($job['lease']>($this->clock)()?'leased':'ready')]++;}return $counts;});}
    public function workOnce():array
    {
        if($this->transport===null){throw new \LogicException('A mail worker requires a transport.');}
        $claimed=$this->locked(function(array &$jobs):?array{foreach($jobs as $id=>&$job){if(!$job['failed']&&$job['ready']<=($this->clock)()&&$job['lease']<=($this->clock)()){$job['lease']=($this->clock)()+120;$job['lease_id']=bin2hex(random_bytes(16));return ['id'=>$id,...$job];}}return null;});
        if($claimed===null){return ['status'=>'empty'];}$failure=null;
        try{$data=json_decode($this->box->open($claimed['payload'],'mail:'.$claimed['id']),true,32,JSON_THROW_ON_ERROR);$this->scopes->run('mail.job',fn()=>$this->transport->send($claimed['id'],new AccountMessage($data['recipient'],$data['purpose'],$data['expires'],$data['link'])),['mail_id'=>$claimed['id']]);}catch(\Throwable $error){$failure=$error::class;}
        return $this->locked(function(array &$jobs)use($claimed,$failure):array{
            $id=$claimed['id'];if(!isset($jobs[$id])||!hash_equals($jobs[$id]['lease_id'],$claimed['lease_id'])){return ['status'=>'lease_lost','id'=>$id];}
            if($failure===null){unset($jobs[$id]);return ['status'=>'sent','id'=>$id];}$job=&$jobs[$id];$job['attempts']++;$job['failed']=$job['attempts']>=6;$job['ready']=($this->clock)()+min(3600,30*(2**($job['attempts']-1)));$job['lease']=0;$job['lease_id']='';$job['error_class']=$failure;return ['status'=>$job['failed']?'failed':'retry','id'=>$id];
        });
    }
}
