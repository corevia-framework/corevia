<?php
declare(strict_types=1);
namespace Corevia\Http;
final class ApiResponse
{
    public static function data(array $data,int $status=200,array $meta=[]):Response{return new Response(['data'=>$data,'meta'=>$meta],$status,['Cache-Control'=>'no-store']);}
    public static function error(string $code,string $message,int $status=400,array $fields=[]):Response
    {
        if(!preg_match('/^[A-Z][A-Z0-9_]{0,63}$/D',$code)||$status<400||$status>599){throw new \InvalidArgumentException('Invalid API error.');}
        return new Response(['error'=>['code'=>$code,'message'=>$message,'fields'=>$fields]],$status,['Cache-Control'=>'no-store']);
    }
    public static function version(string $version,?string $sunset=null):array
    {
        if(!preg_match('/^[1-9][0-9]{0,2}$/D',$version)){throw new \InvalidArgumentException('Invalid API version.');}
        $headers=['API-Version'=>$version];
        if($sunset!==null){$date=\DateTimeImmutable::createFromFormat('!Y-m-d',$sunset,new \DateTimeZone('UTC'));if(!$date||$date->format('Y-m-d')!==$sunset){throw new \InvalidArgumentException('Invalid API sunset date.');}$headers['Sunset']=$date->format('D, d M Y H:i:s').' GMT';$headers['Deprecation']='@'.min(time(),$date->getTimestamp());}
        return $headers;
    }
}
