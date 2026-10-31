<?php
declare(strict_types=1);
namespace Corevia\Auth;
use Corevia\Core\{JobWorker,ScopeManager};
use Corevia\Persistence\{DatabaseManager,SqlCoordinator};
/** Shared encrypted mail delivery with separate queue tables. */
final readonly class SqlMailQueue implements AccountDelivery
{
    public function __construct(private SqlCoordinator $queue,private SecretBox $box,private MailTransport $transport){}
    public static function configured(array $settings,DatabaseManager $database):self{return new self(new SqlCoordinator($database->sql($settings['mail_connection']??$settings['connection']??null),$settings['mail_prefix']??'cv_mail'),SecretBox::fromFile($settings['key_file']),new SmtpTransport($settings['smtp']));}
    public function migrate():void{$this->queue->migrate();}
    public function deliver(AccountMessage $message):void{$id=bin2hex(random_bytes(16));$payload=$this->box->seal(json_encode(['recipient'=>$message->recipient,'purpose'=>$message->purpose,'expires'=>$message->expires,'link'=>$message->link()],JSON_THROW_ON_ERROR),'mail:'.$id);$this->queue->dispatch('mail.deliver',['message_id'=>$id,'envelope'=>$payload],true,$this->queue->clock());}
    public function status():array{return $this->queue->status();}
    public function workOnce():array{return (new JobWorker($this->queue,new ScopeManager(),['mail.deliver'=>function(array $input):array{$id=$input['message_id'];$data=json_decode($this->box->open($input['envelope'],'mail:'.$id),true,32,JSON_THROW_ON_ERROR);if($data['expires']<=$this->queue->clock()){return ['expired'=>true];}$this->transport->send($id,new AccountMessage($data['recipient'],$data['purpose'],$data['expires'],$data['link']));return [];}]))->once();}
}
