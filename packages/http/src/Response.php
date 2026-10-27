<?php

declare(strict_types=1);

namespace Corevia\Http;

final readonly class Response
{
    public function __construct(public array|string $body, public int $status = 200, public array $headers = [])
    {
        if ($status < 100 || $status > 599) { throw new \InvalidArgumentException('Invalid HTTP status.'); }
        foreach ($headers as $name=>$value) { if(!is_string($name) || !preg_match('/^[A-Za-z0-9-]+$/D',$name) || !is_string($value) || preg_match('/[\r\n]/',$value)){throw new \InvalidArgumentException('Invalid response header.');} }
    }

    public static function html(string $html,int $status=200): self { return new self($html,$status,['Content-Type'=>'text/html; charset=utf-8']); }
    public static function redirect(string $url,int $status=302): self
    {
        if(!in_array($status,[301,302,303,307,308],true) || !str_starts_with($url,'/') || str_starts_with($url,'//') || str_contains($url,'\\') || preg_match('/[\x00-\x20]/',$url)){throw new \InvalidArgumentException('Redirects require a local absolute path.');}
        return new self('',$status,['Location'=>$url]);
    }

    public function withHeaders(array $headers): self
    {
        return new self($this->body, $this->status, [...$this->headers, ...$headers]);
    }

    public function send(): void
    {
        http_response_code($this->status);
        header('Content-Type: '.(is_array($this->body)?'application/json':'text/html').'; charset=utf-8');
        foreach ($this->headers as $name => $value) {
            header($name . ': ' . $value);
        }
        if(($_SERVER['REQUEST_METHOD'] ?? '')!=='HEAD'){echo is_array($this->body)?json_encode($this->body, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES):$this->body;}
    }
}
