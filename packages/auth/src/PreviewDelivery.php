<?php
declare(strict_types=1);
namespace Corevia\Auth;

/** Private local-development mailbox. Never an HTTP endpoint or production transport. */
final readonly class PreviewDelivery implements AccountDelivery
{
    public function __construct(private string $directory){if(getenv('APP_ENV')==='production'){throw new \LogicException('Preview delivery is disabled in production. Configure an AccountDelivery adapter.');}}
    public function deliver(AccountMessage $message):void
    {
        if(!is_dir($this->directory) && !mkdir($this->directory,0700,true) && !is_dir($this->directory)){throw new \RuntimeException('Cannot create private delivery directory.');}
        if(is_link($this->directory)){throw new \RuntimeException('Delivery directory cannot be a symlink.');}
        $path=$this->directory.'/'.bin2hex(random_bytes(16)).'.json';$temporary=$path.'.tmp';$file=fopen($temporary,'x+b');if($file===false){throw new \RuntimeException('Cannot create delivery preview.');}
        try{
            try{$wire=json_encode(['recipient'=>$message->recipient,'purpose'=>$message->purpose,'created_at'=>time(),'expires'=>$message->expires,'subject'=>$message->subject(),'link'=>$message->link()],JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES);if(!chmod($temporary,0600) || fwrite($file,$wire)!==strlen($wire) || !fflush($file)){throw new \RuntimeException('Cannot persist delivery preview.');}}finally{fclose($file);}
            if(!rename($temporary,$path)){throw new \RuntimeException('Cannot publish delivery preview.');}
        }finally{if(is_file($temporary)){unlink($temporary);}}
    }
}
