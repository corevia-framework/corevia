<?php

declare(strict_types=1);

namespace Corevia\Http;

final readonly class Response
{
    public function __construct(public array|string|\Closure $body, public int $status = 200, public array $headers = [])
    {
        if ($status < 100 || $status > 599) { throw new \InvalidArgumentException('Invalid HTTP status.'); }
        foreach ($headers as $name=>$value) {
            if(!is_string($name) || !preg_match('/^[A-Za-z0-9-]+$/D',$name) || (is_array($value) && (strtolower($name)!=='set-cookie' || !array_is_list($value)))){throw new \InvalidArgumentException('Invalid response header.');}
            foreach(is_array($value)?$value:[$value] as $item){if(!is_string($item) || preg_match('/[\r\n]/',$item)){throw new \InvalidArgumentException('Invalid response header.');}}
        }
    }

    public static function html(string $html,int $status=200): self { return new self($html,$status,['Content-Type'=>'text/html; charset=utf-8']); }
    /** Producer runs at send time; resolve its resources within its own stream scope. */
    public static function events(callable $producer):self
    {
        return new self(static function()use($producer):void{
            $previousAbort=ignore_user_abort(true);
            $emit=static function(string $event,array $data):bool{if(!preg_match('/^[a-z][a-z0-9_.-]{0,31}$/D',$event)){throw new \InvalidArgumentException('Invalid SSE event name.');}if(connection_aborted()){return false;}echo 'event: '.$event."\n".'data: '.json_encode($data,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES)."\n\n";if(ob_get_level()>0){@ob_flush();}flush();return !connection_aborted();};
            try{$producer($emit);$emit('done',[]);}catch(\Throwable){$emit('error',['code'=>'STREAM_FAILED']);}finally{ignore_user_abort((bool)$previousAbort);}
        },200,['Content-Type'=>'text/event-stream; charset=utf-8','Cache-Control'=>'no-store','X-Accel-Buffering'=>'no','X-Content-Type-Options'=>'nosniff']);
    }
    public static function redirect(string $url,int $status=302): self
    {
        if(!in_array($status,[301,302,303,307,308],true) || !str_starts_with($url,'/') || str_starts_with($url,'//') || str_contains($url,'\\') || preg_match('/[\x00-\x20]/',$url)){throw new \InvalidArgumentException('Redirects require a local absolute path.');}
        return new self('',$status,['Location'=>$url]);
    }

    public function withHeaders(array $headers): self
    {
        return new self($this->body, $this->status, [...$this->headers, ...$headers]);
    }

    public function withCookie(string $cookie):self
    {
        $headers=$this->headers;$cookies=[];
        foreach($headers as $name=>$value){if(strtolower($name)==='set-cookie'){$cookies=[...$cookies,...(is_array($value)?$value:[$value])];unset($headers[$name]);}}
        $cookies[]=$cookie;return new self($this->body,$this->status,[...$headers,'Set-Cookie'=>count($cookies)===1?$cookies[0]:$cookies]);
    }
    public function send(): void
    {
        http_response_code($this->status);
        header('Content-Type: '.(is_array($this->body)?'application/json':'text/html').'; charset=utf-8');
        foreach ($this->headers as $name => $value) {
            foreach(is_array($value)?$value:[$value] as $item){header($name . ': ' . $item,strtolower($name)!=='set-cookie');}
        }
        if(($_SERVER['REQUEST_METHOD'] ?? '')!=='HEAD'){if($this->body instanceof \Closure){($this->body)();}else{echo is_array($this->body)?json_encode($this->body, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES):$this->body;}}
    }
}
