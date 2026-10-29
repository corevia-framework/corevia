<?php
declare(strict_types=1);
namespace Corevia\Auth;
/** TLS-only SMTP. No transcripts or credentials are attached to exceptions. */
final readonly class SmtpTransport implements MailTransport
{
    public function __construct(private array $settings)
    {
        if(!function_exists('openssl_encrypt')){throw new \LogicException('SMTP TLS requires OpenSSL.');}
        if(!preg_match('/^[A-Za-z0-9.-]+$/D',$settings['host']??'') || !in_array($settings['encryption']??'', ['smtps','starttls'],true) || ($settings['port']??0)<1 || $settings['port']>65535 || !filter_var($settings['from']??'',FILTER_VALIDATE_EMAIL)){throw new \InvalidArgumentException('Configure SMTP host, port, TLS mode and a valid sender.');}
        foreach(['username','password','from'] as $key){if(!is_string($settings[$key]??'')||preg_match('/[\r\n\x00]/',$settings[$key]??'')){throw new \InvalidArgumentException('Invalid SMTP setting.');}}
    }
    public function send(string $id,AccountMessage $message):void
    {
        if(!preg_match('/^[a-f0-9]{32}$/D',$id)||!filter_var($message->recipient,FILTER_VALIDATE_EMAIL)||preg_match('/[\r\n\x00]/',$message->recipient)){throw new \InvalidArgumentException('Invalid mail envelope.');}
        $settings=$this->settings;$ssl=['verify_peer'=>true,'verify_peer_name'=>true,'allow_self_signed'=>false,'peer_name'=>$settings['host'],'SNI_enabled'=>true];if(!empty($settings['cafile'])){$ssl['cafile']=$settings['cafile'];}
        $context=stream_context_create(['ssl'=>$ssl]);$deadline=microtime(true)+30;$stream=@stream_socket_client('tcp://'.$settings['host'].':'.$settings['port'],$errno,$error,10,STREAM_CLIENT_CONNECT,$context);
        if($stream===false){throw new \RuntimeException('SMTP connection failed.');}
        $budget=static function()use($stream,$deadline):void{$left=$deadline-microtime(true);if($left<=0){throw new \RuntimeException('SMTP deadline exceeded.');}stream_set_timeout($stream,(int)$left,(int)(($left-floor($left))*1000000));};
        $write=static function(string $line)use($stream,$budget):void{$bytes=$line."\r\n";while($bytes!==''){$budget();$sent=@fwrite($stream,$bytes);if($sent===false||$sent===0){throw new \RuntimeException('SMTP write failed.');}$bytes=substr($bytes,$sent);}};
        $read=static function(array $allowed)use($stream,$budget):string{$response='';$expected=null;for($i=0;$i<100;$i++){$budget();$line=@fgets($stream,4097);if($line===false||strlen($line)>4096||!preg_match('/^([0-9]{3})([ -])[^\r\n]*\r\n$/D',$line,$match)){throw new \RuntimeException('Invalid SMTP response.');}$code=(int)$match[1];if($expected!==null&&$expected!==$code){throw new \RuntimeException('Invalid multiline SMTP response.');}$expected=$code;$response.=$line;if($match[2]===' '){if(!in_array($code,$allowed,true)){throw new \RuntimeException('SMTP command rejected.');}return $response;}}throw new \RuntimeException('SMTP response limit exceeded.');};
        $tls=static function()use($stream,$budget):void{$budget();if(@stream_socket_enable_crypto($stream,true,STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT|STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT)!==true){throw new \RuntimeException('SMTP TLS verification failed.');}};
        try{
            if($settings['encryption']==='smtps'){$tls();}$read([220]);$write('EHLO corevia.local');$capabilities=$read([250]);
            if($settings['encryption']==='starttls'){if(!preg_match('/^250[ -]STARTTLS\b/im',$capabilities)){throw new \RuntimeException('SMTP server does not advertise required STARTTLS.');}$write('STARTTLS');$read([220]);$tls();$write('EHLO corevia.local');$capabilities=$read([250]);}
            $username=$settings['username']??'';$password=$settings['password']??'';
            if($username!==''){if(preg_match('/^250[ -]AUTH[^\r\n]*\bPLAIN\b/im',$capabilities)){$write('AUTH PLAIN '.base64_encode("\0".$username."\0".$password));$read([235]);}elseif(preg_match('/^250[ -]AUTH[^\r\n]*\bLOGIN\b/im',$capabilities)){$write('AUTH LOGIN');$read([334]);$write(base64_encode($username));$read([334]);$write(base64_encode($password));$read([235]);}else{throw new \RuntimeException('SMTP authentication is unavailable.');}}
            $write('MAIL FROM:<'.$settings['from'].'>');$read([250]);$write('RCPT TO:<'.$message->recipient.'>');$read([250,251]);$write('DATA');$read([354]);
            $domain=substr(strrchr($settings['from'],'@'),1);$headers=['From: <'.$settings['from'].'>','To: <'.$message->recipient.'>','Subject: '.$message->subject(),'Date: '.gmdate('D, d M Y H:i:s').' +0000','Message-ID: <'.$id.'@'.$domain.'>','MIME-Version: 1.0','Content-Type: text/plain; charset=UTF-8','Content-Transfer-Encoding: base64'];
            $body=implode("\r\n",$headers)."\r\n\r\n".rtrim(chunk_split(base64_encode($message->text()),76,"\r\n"),"\r\n");$write(preg_replace('/(?m)^\./','..',$body)."\r\n.");$read([250]);
            // A server may disconnect after accepting DATA; delivery is already acknowledged.
            try{$write('QUIT');$read([221]);}catch(\Throwable){}
        }finally{fclose($stream);}
    }
}
