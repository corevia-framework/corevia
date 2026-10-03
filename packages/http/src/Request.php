<?php

declare(strict_types=1);

namespace Corevia\Http;

final readonly class Request
{
    public function __construct(public string $method, public string $path, public array $headers = [], public string $body = '', public array $params = [])
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
        return new self($_SERVER['REQUEST_METHOD'], parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?: '/', $headers, $body);
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
        return new self($this->method, $this->path, $this->headers, $this->body, $params);
    }
}
