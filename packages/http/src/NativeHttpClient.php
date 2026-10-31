<?php
declare(strict_types=1);
namespace Corevia\Http;
/** Verified HTTPS, no redirects, bounded bodies and total read deadline. */
final readonly class NativeHttpClient implements HttpClient
{
    public function __construct(private bool $allowLoopback=false,private ?string $caFile=null,private int $timeout=60){if($timeout<1||$timeout>120){throw new \InvalidArgumentException('HTTP timeout must be 1..120 seconds.');}}
    public function stream(string $method,string $url,array $headers=[],string $body=''):iterable
    {
        $parts=parse_url($url);$host=trim($parts['host']??'','[]');$local=$this->allowLoopback&&in_array($host,['127.0.0.1','::1'],true);
        if(!in_array($method,['GET','POST'],true)||empty($host)||isset($parts['user'])||isset($parts['fragment'])||preg_match('/[\\\\\x00-\x20]/',$url)||!(($parts['scheme']??'')==='https'||($local&&($parts['scheme']??'')==='http'))){throw new \InvalidArgumentException('Use a fixed HTTPS endpoint, or explicitly enabled literal loopback HTTP.');}
        if(strlen($body)>1048576){throw new \LengthException('HTTP request exceeds 1 MiB.');}$lines=['Connection: close','User-Agent: Corevia/3'];
        foreach($headers as $name=>$value){if(!preg_match('/^[A-Za-z0-9-]+$/D',$name)||in_array(strtolower($name),['host','content-length','connection','transfer-encoding'],true)||!is_string($value)||strlen($value)>16384||preg_match('/[\r\n\x00]/',$value)){throw new \InvalidArgumentException('Invalid outbound HTTP header.');}$lines[]=$name.': '.$value;}
        $ssl=['verify_peer'=>true,'verify_peer_name'=>true,'allow_self_signed'=>false];if($this->caFile!==null){$ssl['cafile']=$this->caFile;}
        $context=stream_context_create(['http'=>['method'=>$method,'header'=>implode("\r\n",$lines),'content'=>$body,'ignore_errors'=>true,'follow_location'=>0,'max_redirects'=>0,'timeout'=>$this->timeout,'protocol_version'=>1.1],'ssl'=>$ssl]);$deadline=microtime(true)+$this->timeout;
        $file=@fopen($url,'rb',false,$context);if($file===false){throw new \RuntimeException('HTTP connection failed.');}
        try{$metadata=stream_get_meta_data($file);$status=0;$expected=null;foreach($metadata['wrapper_data']??[] as $line){if(preg_match('/^HTTP\/\S+\s+([0-9]{3})(?:\s|$)/',$line,$match)){$status=(int)$match[1];$expected=null;}elseif(preg_match('/^Content-Length:\s*([0-9]+)\s*$/iD',$line,$match)){if(strlen($match[1])>8||($expected!==null&&$expected!==(int)$match[1])){throw new \RuntimeException('Invalid HTTP content length.');}$expected=(int)$match[1];}}if($status<200||$status>299){throw new \RuntimeException('HTTP endpoint rejected the request (status '.$status.').');}if($expected!==null&&$expected>8388608){throw new \LengthException('HTTP response exceeds 8 MiB.');}
            // Blocking HTTP reads can report timed_out alongside valid buffered
            // data. Read available bytes without waiting to fill the buffer and
            // enforce the total deadline ourselves, including idle intervals.
            if(!stream_set_blocking($file,false)){throw new \RuntimeException('HTTP streaming mode unavailable.');}
            $size=0;
            while(!feof($file)&&($expected===null||$size<$expected)){
                if(microtime(true)>=$deadline){throw new \RuntimeException('HTTP deadline exceeded.');}
                $chunk=@fread($file,16384);
                if($chunk===false){throw new \RuntimeException('HTTP response read failed.');}
                if(microtime(true)>=$deadline){throw new \RuntimeException('HTTP deadline exceeded.');}
                if($chunk===''){if(feof($file)){break;}usleep(1000);continue;}
                $size+=strlen($chunk);if($size>8388608){throw new \LengthException('HTTP response exceeds 8 MiB.');}
                yield $chunk;
            }
            if($expected!==null&&$size!==$expected){throw new \RuntimeException('Truncated HTTP response.');}
        }finally{fclose($file);}
    }
}
