<?php
declare(strict_types=1);
namespace Corevia\Testing;
use Corevia\Http\Response;
final readonly class TestResponse
{
    public function __construct(public Response $response){}
    public function status(int $expected):self
    {
        if($this->response->status!==$expected){throw new \RuntimeException('Unexpected HTTP status: '.$this->response->status);}
        return $this;
    }
    public function json():array
    {
        $value=is_array($this->response->body)?$this->response->body:json_decode($this->response->body,true,64,JSON_THROW_ON_ERROR);
        if(!is_array($value)){throw new \RuntimeException('Expected JSON data.');}return $value;
    }
    public function field(string $path,mixed $expected):self
    {
        $value=$this->json();foreach(explode('.',$path) as $part){if(!is_array($value)||!array_key_exists($part,$value)){throw new \RuntimeException('Missing JSON field.');}$value=$value[$part];}
        if($value!==$expected){throw new \RuntimeException('Unexpected JSON field value.');}return $this;
    }
}
