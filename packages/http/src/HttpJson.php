<?php
declare(strict_types=1);
namespace Corevia\Http;
final class HttpJson
{
    public static function request(HttpClient $client,string $method,string $url,array $headers=[],string $body=''):array
    {
        $raw='';foreach($client->stream($method,$url,['Accept'=>'application/json',...$headers],$body) as $chunk){$raw.=$chunk;if(strlen($raw)>1048576){throw new \LengthException('JSON response exceeds 1 MiB.');}}
        try{$value=json_decode($raw,true,64,JSON_THROW_ON_ERROR);}catch(\Throwable){throw new \RuntimeException('Invalid JSON response.');}if(!is_array($value)||array_is_list($value)){throw new \RuntimeException('Expected a JSON response object.');}return $value;
    }
}
