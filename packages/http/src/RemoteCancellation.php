<?php
declare(strict_types=1);
namespace Corevia\Http;

/** Explicit control request to the same Python worker process; not a success/rollback acknowledgment. */
final readonly class RemoteCancellation
{
    public function __construct(private HttpClient $http,private string $endpoint,private array $headers=[],bool $allowLoopback=false)
    {
        $parts=parse_url($endpoint);$local=$allowLoopback&&in_array($parts['host']??'',['127.0.0.1','[::1]'],true);
        if(!$parts||!isset($parts['host'])||isset($parts['user'])||isset($parts['pass'])||isset($parts['query'])||isset($parts['fragment'])||preg_match('/[\\\\\x00-\x20]/',$endpoint)||!(($parts['scheme']??'')==='https'||($local&&($parts['scheme']??'')==='http'))){throw new \InvalidArgumentException('Use a fixed HTTPS cancellation endpoint or explicit literal loopback.');}
    }
    public function request(string $requestId,string $capability):void
    {
        if(strlen($requestId)<1||strlen($requestId)>128||preg_match('/[\x00-\x1f\x7f]/',$requestId)||!preg_match('/^[0-9a-f]{64}$/D',$capability)){throw new \InvalidArgumentException('Invalid cancellation handle.');}
        $body=json_encode(['request_id'=>$requestId,'capability'=>$capability],JSON_THROW_ON_ERROR);$reply='';
        foreach($this->http->stream('POST',$this->endpoint,[...$this->headers,'Content-Type'=>'application/json'],$body)as $chunk){$reply.=$chunk;if(strlen($reply)>1024){throw new \LengthException('Cancellation response exceeds limit.');}}
        $result=json_decode($reply,true,8,JSON_THROW_ON_ERROR);
        if($result!==['accepted'=>true]){throw new \RuntimeException('CANCELLATION_NOT_ACCEPTED');}
    }
}
