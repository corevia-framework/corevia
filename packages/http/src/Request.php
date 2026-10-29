<?php

declare(strict_types=1);

namespace Corevia\Http;

final readonly class Request
{
    public function __construct(public string $method, public string $path, public array $headers = [], public string $body = '', public array $params = [], public array $attributes = [])
    {
    }

    public static function fromGlobals(int $maxBytes = 65536): self
    {
        $body = file_get_contents('php://input', false, null, 0, $maxBytes + 1);
        if ($body === false || strlen($body) > $maxBytes) {
            throw new \LengthException('PAYLOAD_TOO_LARGE');
        }
        $headers = [];
        foreach (getallheaders() as $key => $value) {
            $headers[strtolower($key)] = $value;
        }
        $scheme=(!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS']!=='off') || getenv('APP_HTTPS')==='true'?'https':'http';
        return new self($_SERVER['REQUEST_METHOD'], parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?: '/', $headers, $body, attributes:['scheme'=>$scheme,'client_ip'=>$_SERVER['REMOTE_ADDR']??'']);
    }

    public function header(string $name, string $default = ''): string
    {
        foreach ($this->headers as $key => $value) {
            if (strtolower($key) === strtolower($name)) {
                return $value;
            }
        }
        return $default;
    }

    public function json(): array
    {
        $input = json_decode($this->body, true, 64, JSON_THROW_ON_ERROR);
        if (!is_array($input)) {
            throw new \InvalidArgumentException('Expected a JSON object.');
        }
        return $input;
    }

    public function withParams(array $params): self
    {
        return new self($this->method, $this->path, $this->headers, $this->body, $params, $this->attributes);
    }
    public function withAttribute(string $key,mixed $value): self { return new self($this->method,$this->path,$this->headers,$this->body,$this->params,[...$this->attributes,$key=>$value]); }
    public function input(): array
    {
        $type=strtolower(explode(';',$this->header('content-type'))[0]);
        if($type==='application/json'){return $this->json();}
        if($type!=='application/x-www-form-urlencoded'){throw new \InvalidArgumentException('Use JSON or URL-encoded form input.');}
        parse_str($this->body,$values);return $values;
    }
    public function cookie(string $name): ?string
    {
        foreach(explode(';',$this->header('cookie')) as $cookie){$parts=explode('=',trim($cookie),2);if(count($parts)===2 && $parts[0]===$name){return rawurldecode($parts[1]);}}return null;
    }
}
